<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateAgent;
use App\Http\Requests\Api\InventoryRequest;
use App\Services\InventoryRecorder;
use Illuminate\Http\JsonResponse;

final class InventoryController extends Controller
{
    public function __invoke(InventoryRequest $request, InventoryRecorder $inventory): JsonResponse
    {
        $counts = $inventory->sync(AuthenticateAgent::deviceFrom($request), $request->validated('ports'), $request->validated('services'));

        return response()->json($counts, 201);
    }
}
