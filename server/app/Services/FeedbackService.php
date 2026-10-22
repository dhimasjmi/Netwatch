<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketFeedback;
use App\Support\FeedbackCode;
use Illuminate\Support\Facades\DB;

/** Permintaan penilaian tiket: kode sekali pakai (disimpan sebagai hash) dan pencatatan rating. */
final class FeedbackService
{
    private const VALID_DAYS = 14;

    public function __construct(private readonly EventRecorder $events)
    {
    }

    /** Membuat (atau mengganti) kode penilaian. Null bila tiket ini sudah dinilai. Kode asli hanya dikembalikan di sini. */
    public function issue(Ticket $ticket): ?string
    {
        $existing = TicketFeedback::query()->where('ticket_id', $ticket->id)->first();

        if ($existing?->submitted_at !== null) {
            return null;
        }

        $code = FeedbackCode::generate();
        $values = ['code_hash' => FeedbackCode::hash($code), 'expires_at' => now()->addDays(self::VALID_DAYS), 'sent_via' => null, 'created_at' => now()];

        $existing !== null
            ? $existing->forceFill($values)->save()
            : TicketFeedback::query()->create(['ticket_id' => $ticket->id] + $values);

        return $code;
    }

    public function markSent(Ticket $ticket, string $via): void
    {
        TicketFeedback::query()->where('ticket_id', $ticket->id)->update(['sent_via' => $via]);
    }

    public function find(string $input): ?TicketFeedback
    {
        $code = FeedbackCode::normalize($input);

        return $code === null ? null : TicketFeedback::query()->with('ticket')->where('code_hash', FeedbackCode::hash($code))->first();
    }

    /** Menyimpan rating satu kali; false bila kode sudah dipakai atau kedaluwarsa (termasuk balapan dua pengiriman bersamaan). */
    public function submit(TicketFeedback $feedback, int $rating, ?string $comment): bool
    {
        $saved = DB::transaction(fn (): int => TicketFeedback::query()
            ->where('id', $feedback->id)->whereNull('submitted_at')->where('expires_at', '>', now())
            ->update(['rating' => $rating, 'comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : null, 'submitted_at' => now()]));

        if ($saved === 0) {
            return false;
        }

        $ticket = $feedback->ticket;
        $this->events->record('ticket.feedback', "Penilaian {$rating}/5 untuk tiket {$ticket->number()}.", $ticket->device, null, ['ticket_id' => $ticket->id, 'rating' => $rating]);

        return true;
    }
}
