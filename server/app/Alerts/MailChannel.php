<?php

declare(strict_types=1);

namespace App\Alerts;

use Illuminate\Support\Facades\Mail;
use Throwable;

final class MailChannel implements AlertChannel
{
    public function name(): string
    {
        return 'Email';
    }

    public function isConfigured(): bool
    {
        return $this->recipients() !== [] && config('mail.default') !== 'log' && config('mail.default') !== 'array';
    }

    public function send(string $subject, string $text): ?string
    {
        try {
            Mail::raw($text, fn ($message) => $message->to($this->recipients())->subject($subject));
        } catch (Throwable $e) {
            report($e);

            return 'Gagal mengirim email (periksa pengaturan MAIL_* di .env dan log aplikasi).';
        }

        return null;
    }

    /** @return list<string> */
    private function recipients(): array
    {
        return array_values(array_filter(
            (array) config('netwatch.alerts.mail_to'),
            static fn ($a): bool => filter_var($a, FILTER_VALIDATE_EMAIL) !== false,
        ));
    }
}
