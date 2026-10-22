<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\CommandType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\StoreDeviceCommandRequest;
use App\Models\Device;
use App\Services\CommandService;
use App\Services\Exceptions\CommandRejectedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

final class DeviceCommandController extends Controller
{
    public function store(
        StoreDeviceCommandRequest $request,
        Device $device,
        CommandService $commands,
    ): JsonResponse|RedirectResponse {
        /** @var CommandType $type Dijamin non-null oleh validasi `type`. */
        $type = $request->commandType();

        try {
            $command = $commands->issue($device, $request->user(), $type, $request->payload());
        } catch (CommandRejectedException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withErrors(['command' => $e->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json(['id' => $command->public_id, 'status' => $command->status->value], 201);
        }

        return back()->with('status', "Perintah \"{$type->label()}\" dijadwalkan.");
    }
}
