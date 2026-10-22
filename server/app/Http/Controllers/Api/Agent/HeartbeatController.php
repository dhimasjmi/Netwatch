<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateAgent;
use App\Http\Requests\Api\HeartbeatRequest;
use App\Models\DeviceCommand;
use App\Services\CommandService;
use App\Services\HeartbeatService;
use App\Services\PolicySync;
use Illuminate\Http\JsonResponse;

final class HeartbeatController extends Controller
{
    public function __invoke(
        HeartbeatRequest $request,
        HeartbeatService $heartbeats,
        CommandService $commands,
        PolicySync $policies,
    ): JsonResponse {
        $device = AuthenticateAgent::deviceFrom($request);
        $validated = $request->validated();

        $heartbeats->record($device, $validated['metrics'], $request->ip(), $validated['agent_version'] ?? null, $validated['interfaces'] ?? []);

        $policy = $policies->sync($device, $validated['policy'] ?? null);

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'heartbeat_interval' => config('netwatch.heartbeat_interval'),
            'process_snapshot_interval' => config('netwatch.process_snapshot_interval'),
            'browsing_enabled' => (bool) config('netwatch.browsing.enabled'),
            'winlog_enabled' => (bool) config('netwatch.winlog.enabled'),
            ...($policy === null ? [] : ['policy' => $policy]),
            'commands' => $commands->claimPending($device)
                ->map(static fn (DeviceCommand $c): array => [
                    'id' => $c->public_id,
                    'type' => $c->type->value,
                    'payload' => $c->payload ?? [],
                ])
                ->values(),
        ]);
    }
}
