<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateAgent;
use App\Http\Requests\Api\ProcessSnapshotRequest;
use App\Services\ProcessSnapshotService;
use Illuminate\Http\JsonResponse;

final class ProcessSnapshotController extends Controller
{
    public function __invoke(ProcessSnapshotRequest $request, ProcessSnapshotService $snapshots): JsonResponse
    {
        $snapshot = $snapshots->store(
            AuthenticateAgent::deviceFrom($request),
            $request->validated('processes'),
        );

        return response()->json(['process_count' => $snapshot->process_count], 201);
    }
}
