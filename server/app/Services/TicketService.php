<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Device;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TicketService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EventRecorder $events,
        private readonly FeedbackService $feedback,
        private readonly TicketNotifier $notifier,
    ) {
    }

    /**
     * Tiket dari pengguna lewat form publik (tanpa login) atau dibuatkan admin.
     *
     * @param  array{requester_name: string, requester_email: ?string, subject: string, description: string, category: string, priority?: string}  $data
     */
    public function create(array $data, ?Device $device, string $source, ?User $creator, ?string $ip): Ticket
    {
        $ticket = Ticket::query()->create($data + [
            'device_id' => $device?->id, 'source' => $source, 'created_by' => $creator?->id, 'ip_address' => $ip, 'status' => TicketStatus::Open->value,
        ]);

        $this->events->record('ticket.created', "Tiket {$ticket->number()} dari {$ticket->requester_name}"
            .($device !== null ? " ({$device->displayLabel()})" : '').": {$ticket->subject}", $device, $creator, ['ticket_id' => $ticket->id]);

        if ($creator !== null) {
            $this->audit->record('ticket.created', $creator, $device, ['ticket_id' => $ticket->id]);
        }

        return $ticket;
    }

    public function comment(User $actor, Ticket $ticket, string $body, bool $internal): TicketComment
    {
        $comment = TicketComment::query()->create(['ticket_id' => $ticket->id, 'user_id' => $actor->id, 'body' => $body, 'is_internal' => $internal, 'created_at' => now()]);
        $ticket->touch();
        $this->audit->record('ticket.commented', $actor, $ticket->device, ['ticket_id' => $ticket->id, 'internal' => $internal]);

        return $comment;
    }

    /**
     * Mengubah status (selain "selesai"), prioritas, dan penanggung jawab. Membuka kembali tiket selesai menghapus waktu selesainya.
     *
     * @param  array{status?: string, priority?: string, assigned_to?: int|null}  $changes
     */
    public function update(User $actor, Ticket $ticket, array $changes): Ticket
    {
        $before = $ticket->only(['status', 'priority', 'assigned_to']);
        $ticket->fill($changes);

        if ($ticket->isDirty('status') && $ticket->status !== TicketStatus::Resolved) {
            $ticket->resolved_at = null;
            $ticket->resolved_by = null;
        }

        $ticket->save();
        $this->audit->record('ticket.updated', $actor, $ticket->device, ['ticket_id' => $ticket->id, 'changes' => array_keys($ticket->getChanges())] + ['before' => array_map(static fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $before)]);

        return $ticket;
    }

    /**
     * Menyelesaikan tiket dan menyiapkan permintaan penilaian.
     *
     * @return array{code: string|null, url: string|null, notes: list<string>} kode asli hanya ada di sini; notes = hasil pengiriman
     */
    public function resolve(User $actor, Ticket $ticket, ?string $note, bool $email, bool $popup): array
    {
        DB::transaction(function () use ($actor, $ticket, $note): void {
            $ticket->forceFill(['status' => TicketStatus::Resolved->value, 'resolved_at' => now(), 'resolved_by' => $actor->id])->save();

            if ($note !== null && $note !== '') {
                TicketComment::query()->create(['ticket_id' => $ticket->id, 'user_id' => $actor->id, 'body' => $note, 'is_internal' => false, 'created_at' => now()]);
            }
        });

        $this->audit->record('ticket.resolved', $actor, $ticket->device, ['ticket_id' => $ticket->id]);
        $this->events->record('ticket.resolved', "Tiket {$ticket->number()} diselesaikan oleh {$actor->name}.", $ticket->device, $actor, ['ticket_id' => $ticket->id]);

        return $this->requestFeedback($actor, $ticket, $note, $email, $popup);
    }

    /**
     * Membuat kode penilaian baru dan mengirimkannya. Kode lama otomatis tidak berlaku.
     *
     * @return array{code: string|null, url: string|null, notes: list<string>}
     */
    public function requestFeedback(User $actor, Ticket $ticket, ?string $note, bool $email, bool $popup): array
    {
        $code = $this->feedback->issue($ticket);

        if ($code === null) {
            return ['code' => null, 'url' => null, 'notes' => ['Tiket ini sudah dinilai oleh pemohon.']];
        }

        $notes = [];
        foreach ([$email ? $this->notifier->sendEmail($ticket, $code, $note) : null, $popup ? $this->notifier->sendPopup($ticket, $actor, $code) : null] as $failure) {
            if ($failure !== null) {
                $notes[] = $failure;
            }
        }

        $this->audit->record('ticket.feedback_requested', $actor, $ticket->device, ['ticket_id' => $ticket->id, 'email' => $email, 'popup' => $popup]);

        return ['code' => $code, 'url' => $this->notifier->feedbackUrl($code), 'notes' => $notes];
    }
}
