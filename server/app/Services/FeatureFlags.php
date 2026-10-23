<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Feature;
use App\Models\FeatureFlag;
use App\Models\User;
use Throwable;

/**
 * Status fitur: pengaturan dari panel admin menimpa nilai bawaan (.env). Dibaca sekali per proses lalu diingat;
 * kegagalan membaca database (mis. migrasi belum dijalankan) jatuh ke nilai bawaan agar agent dan halaman lain tetap hidup.
 */
final class FeatureFlags
{
    /** @var array<string, bool>|null */
    private ?array $overrides = null;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function enabled(Feature $feature): bool
    {
        return $this->overrides()[$feature->value] ?? $feature->defaultEnabled();
    }

    public function isOverridden(Feature $feature): bool
    {
        return array_key_exists($feature->value, $this->overrides());
    }

    public function set(Feature $feature, bool $enabled, User $actor): void
    {
        FeatureFlag::query()->updateOrCreate(['key' => $feature->value], ['enabled' => $enabled, 'updated_by' => $actor->id, 'updated_at' => now()]);
        $this->overrides = null;

        $this->audit->record($enabled ? 'feature.enabled' : 'feature.disabled', $actor, null, ['feature' => $feature->value]);
    }

    /** Hapus pengaturan panel sehingga kembali mengikuti .env. */
    public function reset(Feature $feature, User $actor): void
    {
        FeatureFlag::query()->where('key', $feature->value)->delete();
        $this->overrides = null;

        $this->audit->record('feature.reset', $actor, null, ['feature' => $feature->value]);
    }

    /** @return array<string, FeatureFlag> pengaturan tersimpan per kunci, untuk tampilan panel */
    public function records(): array
    {
        return FeatureFlag::query()->get()->keyBy('key')->all();
    }

    /** @return array<string, bool> */
    private function overrides(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        try {
            return $this->overrides = FeatureFlag::query()->pluck('enabled', 'key')->map(fn ($v) => (bool) $v)->all();
        } catch (Throwable $e) {
            report($e);

            return $this->overrides = [];
        }
    }
}
