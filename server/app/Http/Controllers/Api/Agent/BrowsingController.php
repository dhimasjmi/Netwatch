<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateAgent;
use App\Http\Requests\Api\BrowsingRequest;
use App\Services\BrowsingRecorder;
use Illuminate\Http\JsonResponse;

final class BrowsingController extends Controller
{
    public function __invoke(BrowsingRequest $request, BrowsingRecorder $recorder): JsonResponse
    {
        // Saklar dimatikan di server: data dibuang tanpa galat agar agent lama tidak mengulang kiriman.
        if (! config('netwatch.browsing.enabled')) {
            return response()->json(['stored' => 0, 'rejected' => 0]);
        }

        return response()->json($recorder->record(AuthenticateAgent::deviceFrom($request), $request->validated('visits')), 201);
    }
}
