<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Agent;

use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Services\FeatureFlags;
use App\Http\Middleware\AuthenticateAgent;
use App\Http\Requests\Api\InventoryRequest;
use App\Services\InventoryRecorder;
use Illuminate\Http\JsonResponse;

final class InventoryController extends Controller
{
    public function __invoke(InventoryRequest $request, InventoryRecorder $inventory, FeatureFlags $features): JsonResponse
    {
        if (! $features->enabled(Feature::Inventory)) {
            return response()->json(['skipped' => true]);
        }

        $counts = $inventory->sync(AuthenticateAgent::deviceFrom($request), $request->validated('ports'), $request->validated('services'));

        return response()->json($counts, 201);
    }
}
