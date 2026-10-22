<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Agent\BrowsingController;
use App\Http\Controllers\Api\Agent\CommandResultController;
use App\Http\Controllers\Api\Agent\EnrollController;
use App\Http\Controllers\Api\Agent\HeartbeatController;
use App\Http\Controllers\Api\Agent\AssetsController;
use App\Http\Controllers\Api\Agent\InventoryController;
use App\Http\Controllers\Api\Agent\WinlogController;
use App\Http\Controllers\Api\Agent\ProcessSnapshotController;
use Illuminate\Support\Facades\Route;

// API untuk agent di komputer user. Prefix /api ditambahkan otomatis oleh Laravel.
Route::prefix('agent/v1')->group(function (): void {
    Route::post('enroll', EnrollController::class)->middleware('throttle:agent-enroll');

    Route::middleware(['agent', 'throttle:agent-api'])->group(function (): void {
        Route::post('heartbeat', HeartbeatController::class);
        Route::post('processes', ProcessSnapshotController::class);
        Route::post('browsing', BrowsingController::class);
        Route::post('inventory', InventoryController::class);
        Route::post('assets', AssetsController::class);
        Route::post('winlog', WinlogController::class);
        Route::post('commands/{command}/result', CommandResultController::class);
    });
});
