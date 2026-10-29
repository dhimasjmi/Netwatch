<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EventSeverity;
use App\Enums\TargetKind;
use App\Enums\TargetStatus;
use App\Models\MonitorTarget;
use App\Models\MonitorTargetCheck;
use App\Models\User;
use App\Services\Probe\ProbeResult;
use App\Services\Probe\TargetProber;
use App\Support\TargetStatusDecider;
use Illuminate\Support\Facades\DB;

/** Mengecek target jaringan, memperbarui status, menyimpan riwayat, dan mencatat event saat naik/turun. */
final class TargetMonitorService
{
    public function __construct(
        private readonly TargetProber $prober,
        private readonly EventRecorder $events,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return int jumlah target yang dicek */
    public function runAll(): int
    {
        $targets = MonitorTarget::query()->where('is_enabled', true)->orderBy('id')
            ->limit((int) config('netwatch.monitor.max_targets'))->get()->all();

        return $this->check($targets);
    }

    /** Ping langsung oleh admin dari dashboard; hasilnya dicatat seperti pengecekan terjadwal. */
    public function checkNow(MonitorTarget $target, ?User $actor = null): MonitorTarget
    {
        $this->check([$target]);
        $this->audit->record('monitor_target.pinged', $actor, null, ['target_id' => $target->id, 'name' => $target->name]);

        return $target->refresh();
    }

    /**
     * @param  list<MonitorTarget>  $targets
     */
    private function check(array $targets): int
    {
        if ($targets === []) {
            return 0;
        }

        $results = $this->prober->probe($targets);
        $now = now();
        $rows = [];

        foreach ($targets as $target) {
            $result = $results[$target->id] ?? new ProbeResult(false);
            $this->apply($target, $result);
            $rows[] = ['monitor_target_id' => $target->id, 'checked_at' => $now, 'ok' => $result->ok, 'latency_ms' => $result->latencyMs];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            MonitorTargetCheck::query()->insert($chunk);
        }

        return count($targets);
    }

    private function apply(MonitorTarget $target, ProbeResult $result): void
    {
        $previous = $target->status;
        $decision = TargetStatusDecider::next(
            $previous,
            $target->consecutive_failures,
            $result->ok,
            $result->latencyMs,
            (float) config('netwatch.monitor.warn_latency_ms'),
            (int) config('netwatch.monitor.fail_threshold'),
        );
        $now = now();
        $since = $target->status_since;

        DB::transaction(function () use ($target, $result, $decision, $previous, $now, $since): void {
            $target->forceFill([
                'status' => $decision['status'],
                'consecutive_failures' => $decision['failures'],
                'latency_ms' => $result->ok ? $result->latencyMs : null,
                'last_checked_at' => $now,
                'status_since' => $decision['status'] === $previous ? ($target->status_since ?? $now) : $now,
            ])->save();
        });

        $this->recordTransition($target, $previous, $decision['status'], $since, $result->latencyMs);
    }

    private function recordTransition(MonitorTarget $target, TargetStatus $from, TargetStatus $to, ?\Illuminate\Support\Carbon $since, ?float $latencyMs): void
    {
        $details = ['target_id' => $target->id, 'name' => $target->name, 'address' => $target->address, 'method' => $target->methodLabel()];

        if ($to === TargetStatus::Offline && $from !== TargetStatus::Offline) {
            $this->events->record('target.offline', "{$target->name} ({$target->address}) tidak merespons", null, null, $details);

            return;
        }

        // Online → Lambat dan Lambat → Online dicatat sebagai event (pertama kali naik dari "belum dicek" tidak).
        if ($from === TargetStatus::Online && $to === TargetStatus::Warning) {
            $limit = (int) config('netwatch.monitor.warn_latency_ms');
            $this->events->record('target.slow', "{$target->name} ({$target->address}) lambat: ".round((float) $latencyMs, 1)." ms (batas {$limit} ms)", null, null,
                $details + ['latency_ms' => $latencyMs, 'limit_ms' => $limit]);

            return;
        }

        if ($from === TargetStatus::Warning && $to === TargetStatus::Online) {
            $this->events->record('target.normal', "{$target->name} ({$target->address}) kembali normal: ".round((float) $latencyMs, 1).' ms', null, null,
                $details + ['latency_ms' => $latencyMs]);

            return;
        }

        if ($from === TargetStatus::Offline && $to->isUp()) {
            $downtime = $since !== null ? max(0, (int) $since->diffInSeconds(now())) : null;
            $this->events->record('target.online', "{$target->name} ({$target->address}) merespons kembali", null, null,
                $details + ($downtime !== null ? ['downtime_seconds' => $downtime] : []));
        }
    }

    // ---------- CRUD (dipanggil controller admin) ----------

    /** @param  array{name: string, address: string, kind: string, method: string, port: int|null, notes: string|null}  $data */
    public function create(User $actor, array $data): MonitorTarget
    {
        $target = MonitorTarget::query()->create($data + ['is_enabled' => true, 'created_by' => $actor->id]);
        $this->audit->record('monitor_target.created', $actor, null, ['target_id' => $target->id, 'name' => $target->name, 'address' => $target->address]);

        return $target;
    }

    /** @param  array{name: string, address: string, kind: string, method: string, port: int|null, notes: string|null}  $data */
    public function update(User $actor, MonitorTarget $target, array $data): void
    {
        $target->forceFill($data)->save();
        // Alamat/cara cek berubah: status lama tidak lagi berlaku.
        $target->forceFill(['status' => TargetStatus::Unknown, 'consecutive_failures' => 0, 'latency_ms' => null, 'status_since' => null, 'last_checked_at' => null])->save();
        $this->audit->record('monitor_target.updated', $actor, null, ['target_id' => $target->id, 'name' => $target->name, 'address' => $target->address]);
    }

    public function setEnabled(User $actor, MonitorTarget $target, bool $enabled): void
    {
        $target->forceFill(['is_enabled' => $enabled])->save();
        $this->audit->record($enabled ? 'monitor_target.enabled' : 'monitor_target.disabled', $actor, null, ['target_id' => $target->id, 'name' => $target->name]);
    }

    public function delete(User $actor, MonitorTarget $target): void
    {
        $target->delete();
        $this->audit->record('monitor_target.deleted', $actor, null, ['target_id' => $target->id, 'name' => $target->name]);
    }
}
