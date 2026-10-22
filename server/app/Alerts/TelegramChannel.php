<?php

declare(strict_types=1);

namespace App\Alerts;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class TelegramChannel implements AlertChannel
{
    public function name(): string
    {
        return 'Telegram';
    }

    public function isConfigured(): bool
    {
        return $this->token() !== '' && $this->chatId() !== '';
    }

    public function send(string $subject, string $text): ?string
    {
        try {
            // Token ada di URL, jadi jangan memakai ->throw(): pesan pengecualiannya akan memuat token.
            $response = Http::timeout(10)->asForm()->post("https://api.telegram.org/bot{$this->token()}/sendMessage", [
                'chat_id' => $this->chatId(),
                'text' => mb_substr($text, 0, 4000),
                'disable_web_page_preview' => true,
            ]);
        } catch (ConnectionException) {
            return 'Tidak dapat menghubungi api.telegram.org (periksa internet/DNS server).';
        }

        if ($response->successful()) {
            return null;
        }

        return match ($response->status()) {
            401, 404 => 'Token bot Telegram salah.',
            400, 403 => 'Chat ID salah, atau bot belum pernah diajak chat/belum masuk grup.',
            default => 'Telegram menolak pesan (HTTP '.$response->status().').',
        };
    }

    private function token(): string
    {
        return trim((string) config('netwatch.alerts.telegram_token'));
    }

    private function chatId(): string
    {
        return trim((string) config('netwatch.alerts.telegram_chat_id'));
    }
}
