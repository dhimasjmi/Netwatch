<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EventCategory;
use App\Enums\EventSeverity;
use App\Models\Device;
use App\Models\Event;
use App\Models\User;
use App\Support\EventCatalog;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

final class EventRecorder
{
    /**
     * Catat satu event. Kegagalan mencatat tidak boleh menggagalkan proses utama (heartbeat, login, dst.),
     * jadi galat hanya dilaporkan. Jangan memasukkan token atau kata sandi ke $details.
     *
     * @param  array<string, mixed>  $details
     */
    public function record(
        string $code,
        string $message,
        ?Device $device = null,
        ?User $user = null,
        array $details = [],
        ?CarbonInterface $at = null,
        ?EventSeverity $severity = null,
        ?EventCategory $category = null,
    ): void {
        $definition = EventCatalog::definition($code); // kode tak dikenal = bug; biarkan melempar saat pengembangan/tes

        try {
            // Transaksi bersarang = savepoint: bila INSERT gagal, transaksi pemanggil di PostgreSQL tidak ikut batal.
            DB::transaction(fn () => Event::query()->create([
                'device_id' => $device?->id,
                'user_id' => $user?->id,
                'category' => $category ?? $definition['category'],
                'severity' => $severity ?? $definition['severity'],
                'code' => $code,
                'message' => mb_substr($message, 0, 500),
                'details' => $details === [] ? null : $details,
                'occurred_at' => $at ?? now(),
            ]));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
