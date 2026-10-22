<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\EnrollRequest;
use App\Services\EnrollmentService;
use App\Services\Exceptions\EnrollmentException;
use Illuminate\Http\JsonResponse;

final class EnrollController extends Controller
{
    public function __invoke(EnrollRequest $request, EnrollmentService $enrollment): JsonResponse
    {
        try {
            $result = $enrollment->enroll($request->validated(), $request->ip());
        } catch (EnrollmentException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json([
            'device_id' => $result['device']->public_id,
            'api_token' => $result['plain_token'],
            'heartbeat_interval' => config('netwatch.heartbeat_interval'),
            'process_snapshot_interval' => config('netwatch.process_snapshot_interval'),
        ], 201);
    }
}
