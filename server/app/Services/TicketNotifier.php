<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CommandType;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Exceptions\CommandRejectedException;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Mengirim kode penilaian ke pemohon lewat email dan/atau popup di PC-nya. Kegagalan dilaporkan sebagai pesan, tidak melempar. */
final class TicketNotifier
{
    public function __construct(private readonly CommandService $commands, private readonly FeedbackService $feedback)
    {
    }

    public function feedbackUrl(string $code): string
    {
        return route('feedback.show', ['code' => $code]);
    }

    public function emailAvailable(Ticket $ticket): bool
    {
        return $ticket->requester_email !== null && ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    /** @return string|null pesan kegagalan, null bila terkirim */
    public function sendEmail(Ticket $ticket, string $code, ?string $note): ?string
    {
        if ($ticket->requester_email === null) {
            return 'Email tidak dikirim: pemohon tidak mengisi alamat email.';
        }
        if (! $this->emailAvailable($ticket)) {
            return 'Email tidak dikirim: pengaturan MAIL_* di .env belum dikonfigurasi.';
        }

        $text = "Halo {$ticket->requester_name},\n\nTiket {$ticket->number()} ({$ticket->subject}) sudah selesai ditangani.\n"
            .($note !== null && $note !== '' ? "\nCatatan penanganan:\n{$note}\n" : '')
            ."\nMohon beri penilaian layanan (1 menit, tautan berlaku 14 hari dan sekali pakai):\n{$this->feedbackUrl($code)}\n\nKode penilaian: {$code}\n\nTerima kasih.";

        try {
            Mail::raw($text, fn ($m) => $m->to($ticket->requester_email)->subject("Tiket {$ticket->number()} selesai - mohon beri penilaian"));
        } catch (Throwable $e) {
            report($e);

            return 'Email gagal dikirim (periksa pengaturan MAIL_* dan log aplikasi).';
        }

        $this->feedback->markSent($ticket, 'email');

        return null;
    }

    /** @return string|null pesan kegagalan, null bila dijadwalkan */
    public function sendPopup(Ticket $ticket, User $actor, string $code): ?string
    {
        $device = $ticket->device;
        if ($device === null) {
            return 'Popup tidak dikirim: tiket tidak terkait perangkat terdaftar.';
        }

        $message = "Tiket {$ticket->number()} sudah selesai. Mohon beri penilaian: buka ikon \"Buat Tiket IT\" di Desktop, isi kolom \"Kode penilaian\" dengan {$code}, lalu klik Beri penilaian.";

        try {
            $this->commands->issue($device, $actor, CommandType::SendMessage, ['message' => mb_substr($message, 0, 500)]);
        } catch (CommandRejectedException $e) {
            return 'Popup tidak dikirim: '.$e->getMessage();
        }

        $this->feedback->markSent($ticket, 'popup');

        return null;
    }
}
