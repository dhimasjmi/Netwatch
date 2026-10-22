<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AlertDispatcher;
use Illuminate\Console\Command;

final class TestAlertCommand extends Command
{
    protected $signature = 'netwatch:test-alert';

    protected $description = 'Kirim pesan uji ke semua saluran notifikasi yang sudah diisi di .env';

    public function handle(AlertDispatcher $alerts): int
    {
        $channels = $alerts->activeChannels();

        if ($channels === []) {
            $this->warn('Belum ada saluran yang diisi. Isi NETWATCH_TELEGRAM_TOKEN + NETWATCH_TELEGRAM_CHAT_ID, atau NETWATCH_ALERT_MAIL_TO (dan MAIL_*) di .env.');

            return self::FAILURE;
        }

        $failed = false;
        foreach ($channels as $channel) {
            $error = $channel->send('NetWatch: uji notifikasi', '✅ Uji notifikasi NetWatch berhasil. Saluran ini siap menerima peringatan.');
            $error === null ? $this->info($channel->name().': terkirim') : $this->error($channel->name().': '.$error);
            $failed = $failed || $error !== null;
        }

        if (!config('netwatch.alerts.enabled')) {
            $this->warn('Catatan: NETWATCH_ALERTS_ENABLED masih false, peringatan otomatis belum aktif.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
