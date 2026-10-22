<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AlertRule;
use App\Models\AlertState;
use App\Models\Device;
use App\Models\DeviceHeartbeat;
use App\Support\AlertDecision;
use Illuminate\Support\Collection;

/**
 * Mengevaluasi aturan alert ambang terhadap riwayat heartbeat. Dipanggil terjadwal tiap menit.
 * Alert hanya "menyala" sekali per kejadian dan "pulih" sekali; notifikasinya lewat event + AlertDispatcher.
 */
final class AlertEvaluator
{
    /** Toleransi jarak antar-heartbeat saat menilai apakah jendela durasi sudah tercakup data. */
    private const COVERAGE_SLACK_SECONDS = 180;

    /** Sampel "terbaru" untuk menyatakan pulih, agar satu sampel rendah tidak langsung mematikan alert. */
    private const RECENT_SECONDS = 180;

    public function __construct(private readonly EventRecorder $events)
    {
    }

    /** @return array{fired: int, resolved: int} */
    public function run(): array
    {
        $result = ['fired' => 0, 'resolved' => 0];

        // Aturan yang dinonaktifkan atau dihapus tidak boleh meninggalkan alert menyala selamanya.
        AlertState::query()->whereIn('alert_rule_id', AlertRule::query()->where('is_enabled', false)->select('id'))->delete();

        foreach (AlertRule::query()->where('is_enabled', true)->get() as $rule) {
            $counts = $this->evaluateRule($rule);
            $result['fired'] += $counts['fired'];
            $result['resolved'] += $counts['resolved'];
        }

        return $result;
    }

    /** @return array{fired: int, resolved: int} */
    private function evaluateRule(AlertRule $rule): array
    {
        $counts = ['fired' => 0, 'resolved' => 0];
        $devices = $this->devicesInScope($rule)->keyBy('id');

        // Perangkat yang keluar dari cakupan (dipindah grup, dll.) melepas keadaannya diam-diam.
        AlertState::query()->where('alert_rule_id', $rule->id)->whereNotIn('device_id', $devices->keys())->delete();

        if ($devices->isEmpty()) {
            return $counts;
        }

        $now = now();
        $expression = $rule->metric->sqlPercent();
        $windowStart = $now->copy()->subMinutes(max($rule->duration_minutes, 5));
        $recentStart = $now->copy()->subSeconds(self::RECENT_SECONDS);
        $durationStart = $now->copy()->subMinutes($rule->duration_minutes);
        $coveredBefore = $durationStart->copy()->addSeconds(self::COVERAGE_SLACK_SECONDS);

        $rows = DeviceHeartbeat::query()
            ->whereIn('device_id', $devices->keys())
            ->where('recorded_at', '>=', $windowStart)
            ->selectRaw(
                "device_id, min(recorded_at) AS first_at, min({$expression}) FILTER (WHERE recorded_at >= ?) AS lowest_recent, ".
                "max({$expression}) FILTER (WHERE recorded_at >= ?) AS recent_max, ".
                "(array_agg({$expression} ORDER BY recorded_at DESC))[1] AS latest",
                [$durationStart, $recentStart],
            )
            ->groupBy('device_id')
            ->get()
            ->keyBy('device_id');

        $states = AlertState::query()->where('alert_rule_id', $rule->id)->get()->keyBy('device_id');

        foreach ($devices as $device) {
            $row = $rows->get($device->id);

            // Perangkat offline atau sedang maintenance tidak dinilai: datanya basi atau gangguannya disengaja.
            if ($row === null || ! $device->isOnline() || $device->isInMaintenance()) {
                continue;
            }

            $state = $states->get($device->id);
            $firing = $state?->firing_since !== null;
            $first = \Carbon\CarbonImmutable::parse((string) $row->first_at);
            $lowest = $row->lowest_recent !== null ? (float) $row->lowest_recent : null;

            $decision = AlertDecision::decide(
                $firing,
                $first->lessThanOrEqualTo($coveredBefore),
                $lowest,
                $row->recent_max !== null ? (float) $row->recent_max : null,
                $rule->threshold,
            );

            $value = $row->latest !== null ? round((float) $row->latest, 1) : null;

            if ($decision === AlertDecision::FIRE) {
                AlertState::query()->updateOrCreate(
                    ['alert_rule_id' => $rule->id, 'device_id' => $device->id],
                    ['firing_since' => $now, 'last_value' => $value, 'updated_at' => $now],
                );
                $this->events->record('alert.firing', sprintf(
                    '%s: %s mencapai %s%% (ambang %d%% selama %d menit) pada %s.',
                    $rule->name, $rule->metric->label(), $value ?? '?', $rule->threshold, $rule->duration_minutes, $device->displayLabel(),
                ), $device, null, ['rule_id' => $rule->id, 'metric' => $rule->metric->value, 'value' => $value, 'threshold' => $rule->threshold, 'minutes' => $rule->duration_minutes], null, $rule->severity);
                $counts['fired']++;
            } elseif ($decision === AlertDecision::RESOLVE && $state !== null) {
                $since = $state->firing_since;
                $state->forceFill(['firing_since' => null, 'last_value' => $value, 'updated_at' => $now])->save();
                $this->events->record('alert.resolved', sprintf(
                    '%s: %s pada %s kembali normal (%s%%).', $rule->name, $rule->metric->label(), $device->displayLabel(), $value ?? '?',
                ), $device, null, ['rule_id' => $rule->id, 'metric' => $rule->metric->value, 'value' => $value, 'fired_at' => $since?->toIso8601String()]);
                $counts['resolved']++;
            } elseif ($firing && $state !== null && $value !== null) {
                $state->forceFill(['last_value' => $value, 'updated_at' => $now])->save();
            }
        }

        return $counts;
    }

    /** @return Collection<int, Device> */
    private function devicesInScope(AlertRule $rule): Collection
    {
        return Device::query()->active()
            ->when($rule->device_id !== null, fn ($q) => $q->where('id', $rule->device_id))
            ->when($rule->device_group_id !== null, fn ($q) => $q->where('device_group_id', $rule->device_group_id))
            ->get();
    }
}
