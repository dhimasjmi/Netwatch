<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateAgent;
use App\Http\Requests\Api\CommandResultRequest;
use App\Models\DeviceCommand;
use App\Services\CommandService;
use App\Services\Exceptions\CommandRejectedException;
use Illuminate\Http\JsonResponse;

final class CommandResultController extends Controller
{
    public function __invoke(
        CommandResultRequest $request,
        DeviceCommand $command,
        CommandService $commands,
    ): JsonResponse {
        // 404 (bukan 403) agar agent lain tidak bisa menebak keberadaan perintah.
        abort_unless($command->device_id === AuthenticateAgent::deviceFrom($request)->id, 404);

        try {
            $commands->complete(
                $command,
                $request->validated('status') === 'succeeded',
                $request->validated('result') ?? [],
                $request->validated('error'),
            );
        } catch (CommandRejectedException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'OK']);
    }
}
