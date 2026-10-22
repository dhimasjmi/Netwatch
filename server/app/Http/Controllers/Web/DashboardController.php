<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\CommandStatus;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $onlineSince = now()->subSeconds((int) config('netwatch.offline_after'));

        $total = Device::query()->active()->count();
        $online = Device::query()->active()->where('last_seen_at', '>', $onlineSince)->count();

        return view('dashboard', [
            'total' => $total,
            'online' => $online,
            'offline' => $total - $online,
            'pendingCommands' => DeviceCommand::query()
                ->whereIn('status', [CommandStatus::Pending->value, CommandStatus::Dispatched->value])
                ->count(),
            'offlineDevices' => Device::query()->active()
                ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<=', $onlineSince))
                ->orderBy('last_seen_at')
                ->limit(10)
                ->get(),
            'recentCommands' => DeviceCommand::query()
                ->with(['device', 'requester'])
                ->latest()
                ->limit(10)
                ->get(),
        ]);
    }
}
