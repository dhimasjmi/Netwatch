<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\CommandStatus;
use App\Enums\DashboardWidget;
use App\Enums\TargetStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\DashboardWidgetsRequest;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\MonitorTarget;
use App\Services\BrowsingDashboardService;
use App\Services\DashboardWidgets;
use App\Services\FleetBandwidthService;
use App\Support\SeriesPoints;
use App\Support\TargetStatusDecider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/** Dashboard ringkasan dari menu Perangkat, Bandwidth, Browsing, dan Jaringan; widget dipilih per pengguna. */
final class DashboardController extends Controller
{
    private const TOP_ROWS = 5;

    public function __construct(
        private readonly DashboardWidgets $widgets,
        private readonly FleetBandwidthService $bandwidth,
        private readonly BrowsingDashboardService $browsing,
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $selected = $this->widgets->selected($user);
        $keys = array_map(fn (DashboardWidget $w): string => $w->value, $selected);

        // Data hanya dihitung untuk widget terpilih; sumber yang dipakai dua widget (bandwidth, browsing) dimuat sekali.
        $needs = fn (DashboardWidget ...$ws): bool => array_intersect(array_map(fn (DashboardWidget $w): string => $w->value, $ws), $keys) !== [];

        $onlineSince = now()->subSeconds((int) config('netwatch.offline_after'));
        $data = [];
        if ($needs(DashboardWidget::DevicesSummary)) {
            $data['devices_summary'] = $this->deviceSummary($onlineSince);
        }
        if ($needs(DashboardWidget::DevicesOffline)) {
            $data['devices_offline'] = Device::query()->active()
                ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<=', $onlineSince))
                ->orderBy('last_seen_at')->limit(10)->get();
        }
        if ($needs(DashboardWidget::DevicesCommands)) {
            $data['devices_commands'] = DeviceCommand::query()->with(['device', 'requester'])->latest()->limit(10)->get();
        }
        if ($needs(DashboardWidget::BandwidthSummary, DashboardWidget::BandwidthTop)) {
            $bw = $this->safely('bandwidth', fn (): array => $this->bandwidthData());
            $data['bandwidth_summary'] = $data['bandwidth_top'] = $bw;
        }
        if ($needs(DashboardWidget::BrowsingSummary, DashboardWidget::BrowsingTop)) {
            $br = $this->safely('browsing', fn (): array => $this->browsing->report(['window' => '1h', 'device' => null, 'kind' => null, 'q' => null, 'page' => 1, 'per_page' => 10]));
            $data['browsing_summary'] = $data['browsing_top'] = $br;
        }
        if ($needs(DashboardWidget::NetworkSummary, DashboardWidget::NetworkProblems)) {
            $net = $this->safely('network', fn (): array => $this->networkData());
            $data['network_summary'] = $data['network_problems'] = $net;
        }

        return view('dashboard', [
            'selected' => $selected,
            'summary' => array_values(array_filter($selected, fn (DashboardWidget $w): bool => $w->isSummary())),
            'panels' => array_values(array_filter($selected, fn (DashboardWidget $w): bool => ! $w->isSummary())),
            'available' => $this->widgets->available($user),
            'chosen' => $keys,
            'data' => $data,
            'isCustom' => is_array($user->dashboard_widgets),
        ]);
    }

    public function update(DashboardWidgetsRequest $request): RedirectResponse
    {
        $request->input('action') === 'reset'
            ? $this->widgets->reset($request->user())
            : $this->widgets->save($request->user(), $request->keys());

        return redirect()->route('dashboard')->with('status', $request->input('action') === 'reset' ? 'Dashboard dikembalikan ke tampilan bawaan.' : 'Tampilan dashboard disimpan.');
    }

    /** @return array<string, int> */
    private function deviceSummary(\DateTimeInterface $onlineSince): array
    {
        $total = Device::query()->active()->count();
        $online = Device::query()->active()->where('last_seen_at', '>', $onlineSince)->count();

        return [
            'total' => $total,
            'online' => $online,
            'offline' => $total - $online,
            'pending' => DeviceCommand::query()->whereIn('status', [CommandStatus::Pending->value, CommandStatus::Dispatched->value])->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function bandwidthData(): array
    {
        $report = $this->bandwidth->report(['window' => '1h', 'group' => null, 'q' => null, 'sort' => 'total', 'dir' => 'desc', 'page' => 1, 'per_page' => self::TOP_ROWS, 'devices' => [], 'only' => false]);
        $rx = array_column($report['trend'], 1);
        $tx = array_column($report['trend'], 2);
        $max = (float) max([1, ...$rx, ...$tx]);

        return $report + ['spark_rx' => SeriesPoints::points($rx, 200, 40, $max), 'spark_tx' => SeriesPoints::points($tx, 200, 40, $max)];
    }

    /** @return array<string, mixed> */
    private function networkData(): array
    {
        $counts = MonitorTarget::query()->where('is_enabled', true)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $enabled = (int) $counts->sum();
        $online = (int) ($counts[TargetStatus::Online->value] ?? 0);
        $slow = (int) ($counts[TargetStatus::Warning->value] ?? 0);

        return [
            'total' => MonitorTarget::query()->count(),
            'online' => $online + $slow,
            'offline' => (int) ($counts[TargetStatus::Offline->value] ?? 0),
            'slow' => $slow,
            'availability' => TargetStatusDecider::availability($enabled, $online + $slow),
            'problems' => MonitorTarget::query()->where('is_enabled', true)->whereIn('status', [TargetStatus::Offline->value, TargetStatus::Warning->value])
                ->orderByRaw("CASE status WHEN 'offline' THEN 0 ELSE 1 END")->orderBy('name')->limit(8)->get(),
        ];
    }

    /**
     * Satu widget yang gagal dimuat tidak boleh merusak seluruh dashboard.
     *
     * @param  callable(): array<string, mixed>  $load
     * @return array<string, mixed>|null
     */
    private function safely(string $name, callable $load): ?array
    {
        try {
            return $load();
        } catch (Throwable $e) {
            Log::warning("Widget dashboard {$name} gagal dimuat.", ['exception' => $e::class, 'message' => $e->getMessage()]);

            return null;
        }
    }
}
