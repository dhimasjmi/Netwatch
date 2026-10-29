<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\TargetKind;
use App\Enums\TargetStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\MonitorTargetRequest;
use App\Models\MonitorTarget;
use App\Services\TargetMonitorService;
use App\Support\LatencyBars;
use App\Support\TargetStatusDecider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Network Operations Center: ringkasan dan daftar perangkat jaringan yang di-ping dari server. */
final class NetworkMonitorController extends Controller
{
    private const BAR_COUNT = 30;
    private const HISTORY_MINUTES = 60;

    public function __construct(private readonly TargetMonitorService $monitor)
    {
    }

    public function index(Request $request): View
    {
        $filter = (string) $request->query('status', 'all');
        $query = MonitorTarget::query()->orderByRaw("CASE status WHEN 'offline' THEN 0 WHEN 'warning' THEN 1 WHEN 'unknown' THEN 2 ELSE 3 END")->orderBy('name');

        if (TargetStatus::tryFrom($filter) !== null) {
            $query->where('status', $filter);
        }
        if (($search = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('address', 'ilike', $like));
        }

        $counts = MonitorTarget::query()->where('is_enabled', true)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $enabled = (int) $counts->sum();
        $up = (int) ($counts[TargetStatus::Online->value] ?? 0) + (int) ($counts[TargetStatus::Warning->value] ?? 0);

        $targets = $query->get();

        return view('network.index', [
            'targets' => $targets,
            'bars' => $this->bars($targets->pluck('id')->all()),
            'availability24h' => $this->availability(),
            'total' => MonitorTarget::query()->count(),
            'online' => (int) ($counts[TargetStatus::Online->value] ?? 0),
            'offline' => (int) ($counts[TargetStatus::Offline->value] ?? 0),
            'slow' => (int) ($counts[TargetStatus::Warning->value] ?? 0),
            'fleetAvailability' => TargetStatusDecider::availability($enabled, $up),
            'down' => $this->downTargets(),
            'kinds' => TargetKind::cases(),
            'filter' => $filter,
            'search' => $search,
        ]);
    }

    /**
     * Target aktif yang sedang offline/lambat, tidak terpengaruh filter halaman. Dipakai alert suara di browser
     * supaya filter atau pencarian tidak membuat alarm bunyi/diam secara keliru.
     *
     * @return list<array{id: int, name: string, status: string}>
     */
    private function downTargets(): array
    {
        return MonitorTarget::query()
            ->where('is_enabled', true)
            ->whereIn('status', [TargetStatus::Offline->value, TargetStatus::Warning->value])
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'status'])
            ->map(fn (MonitorTarget $t): array => ['id' => $t->id, 'name' => $t->name, 'status' => $t->status->value])
            ->all();
    }

    public function store(MonitorTargetRequest $request): RedirectResponse
    {
        $target = $this->monitor->create($request->user(), $request->targetData());

        return redirect()->route('network.index')->with('status', "Target \"{$target->name}\" ditambahkan. Dicek otomatis tiap menit.");
    }

    public function update(MonitorTargetRequest $request, MonitorTarget $target): RedirectResponse
    {
        $this->monitor->update($request->user(), $target, $request->targetData());

        return back()->with('status', 'Target diperbarui.');
    }

    public function toggle(Request $request, MonitorTarget $target): RedirectResponse
    {
        $this->monitor->setEnabled($request->user(), $target, ! $target->is_enabled);

        return back()->with('status', $target->is_enabled ? 'Pemantauan diaktifkan.' : 'Pemantauan dihentikan.');
    }

    public function ping(Request $request, MonitorTarget $target): RedirectResponse
    {
        $target = $this->monitor->checkNow($target, $request->user());
        $message = $target->status->isUp()
            ? "{$target->name} merespons".($target->latency_ms !== null ? ' dalam '.round($target->latency_ms, 1).' ms.' : '.')
            : "{$target->name} belum merespons (status: {$target->status->label()}, kegagalan beruntun: {$target->consecutive_failures}).";

        return back()->with('status', $message);
    }

    public function destroy(Request $request, MonitorTarget $target): RedirectResponse
    {
        $this->monitor->delete($request->user(), $target);

        return redirect()->route('network.index')->with('status', 'Target dihapus.');
    }

    /**
     * Batang riwayat ~30 pengecekan terakhir per target (satu kueri untuk semua kartu).
     *
     * @param  list<int>  $ids
     * @return array<int, list<array{x: int, y: int, height: int, class: string}>>
     */
    private function bars(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $perTarget = [];
        DB::table('monitor_target_checks')
            ->whereIn('monitor_target_id', $ids)->where('checked_at', '>=', now()->subMinutes(self::HISTORY_MINUTES))
            ->orderBy('checked_at')->get(['monitor_target_id', 'ok', 'latency_ms'])
            ->each(function ($row) use (&$perTarget): void {
                $perTarget[(int) $row->monitor_target_id][] = ['ok' => in_array($row->ok, [true, 't', '1', 1, 'true'], true), 'latency' => $row->latency_ms !== null ? (float) $row->latency_ms : null];
            });

        $warn = (float) config('netwatch.monitor.warn_latency_ms');

        return array_map(fn (array $checks): array => LatencyBars::build(array_slice($checks, -self::BAR_COUNT), $warn), $perTarget);
    }

    /** @return array<int, float|null> ketersediaan 24 jam terakhir per id target */
    private function availability(): array
    {
        return DB::table('monitor_target_checks')
            ->where('checked_at', '>=', now()->subDay())
            ->selectRaw('monitor_target_id, count(*) as total, count(*) filter (where ok) as ok_count')
            ->groupBy('monitor_target_id')->get()
            ->mapWithKeys(fn ($row) => [(int) $row->monitor_target_id => TargetStatusDecider::availability((int) $row->total, (int) $row->ok_count)])
            ->all();
    }
}
