<?php

declare(strict_types=1);

namespace App\Services;

use App\Alerts\AlertChannel;
use App\Models\Event;
use App\Support\AlertMessage;
use App\Support\Duration;

/**
 * Mengirim notifikasi dari event yang belum diproses. Dipanggil terjadwal (bukan di dalam heartbeat),
 * sehingga gangguan Telegram/email tidak pernah memperlambat atau menggagalkan pencatatan status.
 */
final class AlertDispatcher
{
    private const BATCH = 200;

    /** @param iterable<AlertChannel> $channels */
    public function __construct(private readonly iterable $channels)
    {
    }

    /** @return list<AlertChannel> */
    public function activeChannels(): array
    {
        $active = [];
        foreach ($this->channels as $channel) {
            if ($channel->isConfigured()) {
                $active[] = $channel;
            }
        }

        return $active;
    }

    /**
     * @return array{sent: int, skipped: int, errors: list<string>}
     */
    public function run(): array
    {
        $result = ['sent' => 0, 'skipped' => 0, 'errors' => []];
        $config = (array) config('netwatch.alerts');
        $channels = $this->activeChannels();

        if (!$config['enabled'] || $channels === []) {
            return $result;
        }

        $events = Event::query()->with('device')
            ->whereNull('notified_at')
            ->whereIn('code', (array) $config['codes'])
            ->orderBy('id')->limit(self::BATCH)->get();

        $items = [];
        $sendIds = [];
        $skipIds = [];

        foreach ($events as $event) {
            $item = $this->itemFor($event, (int) $config['max_age_seconds']);
            if ($item === null) {
                $skipIds[] = $event->id;

                continue;
            }
            $items[] = $item;
            $sendIds[] = $event->id;
        }

        if ($items !== []) {
            $text = AlertMessage::build($items, (int) $config['group_threshold']);
            $delivered = false;

            foreach ($channels as $channel) {
                $error = $channel->send('NetWatch: '.count($items).' pemberitahuan', $text);
                if ($error === null) {
                    $delivered = true;
                } else {
                    $result['errors'][] = $channel->name().': '.$error;
                }
            }

            // Bila semua saluran gagal, event dibiarkan menunggu dan dicoba lagi menit berikutnya (sampai kedaluwarsa).
            if ($delivered) {
                Event::query()->whereIn('id', $sendIds)->update(['notified_at' => now(), 'notification_sent' => true]);
                $result['sent'] = count($sendIds);
            }
        }

        if ($skipIds !== []) {
            Event::query()->whereIn('id', $skipIds)->update(['notified_at' => now()]);
            $result['skipped'] = count($skipIds);
        }

        return $result;
    }

    /**
     * Item pesan untuk event ini, atau null bila sengaja dilewati (kedaluwarsa, maintenance, sudah pulih, dsb.).
     *
     * @return array<string, mixed>|null
     */
    private function itemFor(Event $event, int $maxAge): ?array
    {
        if ($event->occurred_at->lt(now()->subSeconds($maxAge))) {
            return null;
        }

        // Tiket baru tidak selalu berasal dari perangkat terdaftar, jadi tidak disaring berdasarkan perangkat.
        if ($event->code === 'ticket.created') {
            return ['kind' => 'other', 'text' => '🎫 '.$event->message];
        }

        if ($event->code === 'target.offline') {
            return ['kind' => 'other', 'text' => '🔴 '.$event->message];
        }

        if ($event->code === 'target.online') {
            // Kabar "merespons kembali" hanya berarti bila kabar "tidak merespons"-nya dulu benar-benar terkirim.
            $offlineWasSent = Event::query()->where('code', 'target.offline')->where('details->target_id', $event->details['target_id'] ?? 0)
                ->where('id', '<', $event->id)->orderByDesc('id')->value('notification_sent');

            return $offlineWasSent ? ['kind' => 'other', 'text' => '✅ '.$event->message] : null;
        }

        $device = $event->device;
        if ($device === null || $device->revoked_at !== null) {
            return null;
        }

        if ($device->isInMaintenance()) {
            return null;
        }

        $at = $event->occurred_at->toDisplay();

        if ($event->code === 'device.offline') {
            // Sudah pulih sebelum notifikasi sempat dikirim (gangguan sesaat): tidak perlu mengganggu.
            if ($device->last_seen_at !== null && $device->last_seen_at->gt($event->occurred_at->copy()->addSeconds((int) config('netwatch.offline_after')))) {
                return null;
            }

            return ['kind' => 'offline', 'name' => $device->displayLabel(), 'at' => $at,
                'ping_ok' => $device->ping_ok, 'ping_ms' => $device->ping_ms];
        }

        if ($event->code === 'device.online') {
            // Kabar "online" hanya berarti bila kabar "offline"-nya dulu benar-benar sampai.
            $offlineWasSent = Event::query()->where('device_id', $device->id)->where('code', 'device.offline')
                ->where('id', '<', $event->id)->orderByDesc('id')->value('notification_sent');
            if (!$offlineWasSent) {
                return null;
            }
            $seconds = $event->details['downtime_seconds'] ?? null;

            return ['kind' => 'online', 'name' => $device->displayLabel(), 'at' => $at,
                'downtime' => is_numeric($seconds) ? Duration::human((int) $seconds) : null];
        }

        if ($event->code === 'alert.resolved') {
            // Kabar "normal kembali" hanya berarti bila kabar "menyala"-nya dulu benar-benar terkirim.
            $firingWasSent = Event::query()->where('device_id', $device->id)->where('code', 'alert.firing')
                ->where('details->rule_id', $event->details['rule_id'] ?? 0)->where('id', '<', $event->id)
                ->orderByDesc('id')->value('notification_sent');
            if (! $firingWasSent) {
                return null;
            }
        }

        return ['kind' => 'other', 'text' => ($event->code === 'alert.firing' ? '⚠️ ' : '✅ ').$event->message];
    }
}
