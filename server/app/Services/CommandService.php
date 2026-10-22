<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CommandStatus;
use App\Enums\CommandType;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use App\Services\Exceptions\CommandRejectedException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class CommandService
{
    private const CLAIM_BATCH_SIZE = 10;

    public function __construct(private readonly AuditLogger $audit, private readonly EventRecorder $events)
    {
    }

    /**
     * Masukkan perintah ke antrean perangkat. Agent mengambilnya pada heartbeat berikutnya.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws CommandRejectedException
     */
    public function issue(Device $device, User $actor, CommandType $type, array $payload = []): DeviceCommand
    {
        if ($device->revoked_at !== null) {
            throw new CommandRejectedException('Perangkat ini sudah dicabut akses-nya.');
        }

        if ($type === CommandType::TerminateProcess) {
            $this->assertProcessIsNotProtected((string) ($payload['process_name'] ?? ''));
        }

        $command = DeviceCommand::query()->create([
            'device_id' => $device->id,
            'requested_by' => $actor->id,
            'type' => $type,
            'payload' => $payload === [] ? null : $payload,
            'status' => CommandStatus::Pending,
            'expires_at' => now()->addSeconds((int) config('netwatch.command_ttl')),
        ]);

        $this->audit->record('command.issued', $actor, $device, [
            'command_id' => $command->public_id,
            'type' => $type->value,
            'payload' => $payload,
        ]);

        $this->events->record('command.issued', "Perintah \"{$type->label()}\" dikirim oleh {$actor->name}.", $device, $actor, [
            'command_id' => $command->public_id, 'type' => $type->value,
        ]);

        return $command;
    }

    /**
     * Ambil perintah yang menunggu secara atomik: dua heartbeat bersamaan tidak akan
     * mendapat perintah yang sama.
     *
     * @return Collection<int, DeviceCommand>
     */
    public function claimPending(Device $device): Collection
    {
        return DB::transaction(function () use ($device): Collection {
            $commands = DeviceCommand::query()
                ->where('device_id', $device->id)
                ->where('status', CommandStatus::Pending->value)
                ->where('expires_at', '>', now())
                ->orderBy('created_at')
                ->limit(self::CLAIM_BATCH_SIZE)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            if ($commands->isNotEmpty()) {
                DeviceCommand::query()
                    ->whereIn('id', $commands->modelKeys())
                    ->update([
                        'status' => CommandStatus::Dispatched->value,
                        'dispatched_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            return $commands;
        });
    }

    /**
     * @param  array<string, mixed>  $result
     *
     * @throws CommandRejectedException
     */
    public function complete(DeviceCommand $command, bool $succeeded, array $result, ?string $error): void
    {
        if ($command->status !== CommandStatus::Dispatched) {
            throw new CommandRejectedException('Perintah ini tidak sedang menunggu hasil.');
        }

        $command->forceFill([
            'status' => $succeeded ? CommandStatus::Succeeded : CommandStatus::Failed,
            'result' => $result === [] ? null : $result,
            'error_message' => $succeeded ? null : $error,
            'completed_at' => now(),
        ])->save();

        if (! $succeeded) {
            $this->events->record('command.failed', 'Perintah "'.$command->type->label().'" gagal: '.($error ?? 'tanpa pesan galat').'.', $command->device, null, [
                'command_id' => $command->public_id, 'type' => $command->type->value,
            ]);
        }
    }

    /** Tandai perintah yang tidak pernah diambil/diselesaikan. Mengembalikan jumlah baris yang diubah. */
    public function expireStale(): int
    {
        $ttl = (int) config('netwatch.command_ttl');

        $this->recordStaleEvents($ttl);

        $expired = DeviceCommand::query()
            ->where('status', CommandStatus::Pending->value)
            ->where('expires_at', '<=', now())
            ->update([
                'status' => CommandStatus::Expired->value,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        $timedOut = DeviceCommand::query()
            ->where('status', CommandStatus::Dispatched->value)
            ->where('dispatched_at', '<=', now()->subSeconds($ttl))
            ->update([
                'status' => CommandStatus::Failed->value,
                'error_message' => 'Agent tidak melaporkan hasil tepat waktu.',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        return $expired + $timedOut;
    }

    /** Catat event untuk perintah yang akan kedaluwarsa/gagal waktu, sebelum statusnya diubah massal. */
    private function recordStaleEvents(int $ttl): void
    {
        DeviceCommand::query()->with('device')
            ->where('status', CommandStatus::Pending->value)
            ->where('expires_at', '<=', now())
            ->each(fn (DeviceCommand $c) => $this->events->record(
                'command.expired', 'Perintah "'.$c->type->label().'" kedaluwarsa karena tidak diambil agent.', $c->device, null,
                ['command_id' => $c->public_id, 'type' => $c->type->value],
            ));

        DeviceCommand::query()->with('device')
            ->where('status', CommandStatus::Dispatched->value)
            ->where('dispatched_at', '<=', now()->subSeconds($ttl))
            ->each(fn (DeviceCommand $c) => $this->events->record(
                'command.failed', 'Perintah "'.$c->type->label().'" gagal: agent tidak melaporkan hasil tepat waktu.', $c->device, null,
                ['command_id' => $c->public_id, 'type' => $c->type->value],
            ));
    }

    /** @throws CommandRejectedException */
    private function assertProcessIsNotProtected(string $processName): void
    {
        $normalized = strtolower((string) preg_replace('/\.exe$/i', '', trim($processName)));

        if ($normalized === '') {
            throw new CommandRejectedException('Nama proses wajib diisi.');
        }

        if (in_array($normalized, config('netwatch.protected_processes'), true)) {
            throw new CommandRejectedException("Proses \"{$processName}\" dilindungi dan tidak boleh diakhiri.");
        }
    }
}
