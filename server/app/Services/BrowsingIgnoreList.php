<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BrowsingIgnoredHost;
use App\Models\User;
use App\Support\HostMatcher;
use Illuminate\Support\Facades\Cache;

/** Daftar domain latar belakang yang tidak disimpan sebagai riwayat browsing (mengurangi volume data). */
final class BrowsingIgnoreList
{
    private const CACHE_KEY = 'netwatch.browsing.ignored';
    private const CACHE_SECONDS = 300;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** @return array<string, true> */
    public function domains(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, static fn (): array => array_fill_keys(
            BrowsingIgnoredHost::query()->pluck('domain')->all(),
            true,
        ));
    }

    public function isIgnored(?string $host): bool
    {
        return HostMatcher::matches($host, $this->domains());
    }

    public function add(User $actor, string $domain, ?string $note): BrowsingIgnoredHost
    {
        $row = BrowsingIgnoredHost::query()->firstOrCreate(
            ['domain' => $domain],
            ['note' => $note !== null && $note !== '' ? mb_substr($note, 0, 100) : null, 'created_by' => $actor->id, 'created_at' => now()],
        );
        Cache::forget(self::CACHE_KEY);

        if ($row->wasRecentlyCreated) {
            $this->audit->record('browsing.ignore_added', $actor, null, ['domain' => $domain]);
        }

        return $row;
    }

    public function remove(User $actor, BrowsingIgnoredHost $row): void
    {
        $row->delete();
        Cache::forget(self::CACHE_KEY);
        $this->audit->record('browsing.ignore_removed', $actor, null, ['domain' => $row->domain]);
    }
}
