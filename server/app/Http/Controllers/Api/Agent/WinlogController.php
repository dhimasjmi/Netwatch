<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Agent;

use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Services\FeatureFlags;
use App\Http\Middleware\AuthenticateAgent;
use App\Http\Requests\Api\WinlogRequest;
use App\Services\WinlogRecorder;
use Illuminate\Http\JsonResponse;

final class WinlogController extends Controller
{
    public function __invoke(WinlogRequest $request, WinlogRecorder $recorder, FeatureFlags $features): JsonResponse
    {
        // Saklar dimatikan di server: data dibuang tanpa galat agar agent tidak mengulang kiriman.
        if (! $features->enabled(Feature::Winlog)) {
            return response()->json(['stored' => 0, 'skipped' => 0]);
        }

        return response()->json($recorder->record(AuthenticateAgent::deviceFrom($request), $request->validated('events')), 201);
    }
}
