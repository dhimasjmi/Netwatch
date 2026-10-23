<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Agent;

use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Services\FeatureFlags;
use App\Http\Middleware\AuthenticateAgent;
use App\Http\Requests\Api\AssetsRequest;
use App\Services\AssetRecorder;
use Illuminate\Http\JsonResponse;

final class AssetsController extends Controller
{
    public function __invoke(AssetsRequest $request, AssetRecorder $assets, FeatureFlags $features): JsonResponse
    {
        // Fitur dimatikan admin: kiriman dibuang tanpa galat agar agent tidak mengulang.
        if (! $features->enabled(Feature::Inventory)) {
            return response()->json(['skipped' => true]);
        }

        $counts = $assets->sync(AuthenticateAgent::deviceFrom($request), $request->validated('hardware'), $request->validated('software'));

        return response()->json($counts, 201);
    }
}
