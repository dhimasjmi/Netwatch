<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DashboardWidget;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** Widget dashboard yang boleh dipakai dan dipilih seorang pengguna (disimpan per pengguna). */
final class DashboardWidgets
{
    public function __construct(private readonly FeatureFlags $features)
    {
    }

    /**
     * Semua widget yang boleh dipilih: fitur aktif dan sesuai hak akses.
     *
     * @return list<DashboardWidget>
     */
    public function available(User $user): array
    {
        $isAdmin = Gate::forUser($user)->allows('manage-fleet');

        return array_values(array_filter(
            DashboardWidget::cases(),
            fn (DashboardWidget $w): bool => ($w->feature() === null || $this->features->enabled($w->feature())) && (! $w->adminOnly() || $isAdmin),
        ));
    }

    /**
     * Widget yang tampil di dashboard pengguna ini (urutan tetap mengikuti enum).
     *
     * @return list<DashboardWidget>
     */
    public function selected(User $user): array
    {
        $stored = $user->dashboard_widgets;

        return array_values(array_filter(
            $this->available($user),
            fn (DashboardWidget $w): bool => is_array($stored) ? in_array($w->value, $stored, true) : $w->defaultOn(),
        ));
    }

    /** @param  list<string>  $keys */
    public function save(User $user, array $keys): void
    {
        $allowed = array_map(fn (DashboardWidget $w): string => $w->value, $this->available($user));
        // Pilihan untuk widget yang sementara tidak tersedia (fitur dimatikan admin) dipertahankan agar kembali saat fitur dinyalakan.
        $kept = array_diff(is_array($user->dashboard_widgets) ? $user->dashboard_widgets : [], $allowed);
        $user->forceFill(['dashboard_widgets' => array_values(array_unique([...$kept, ...array_intersect($allowed, $keys)]))])->save();
    }

    public function reset(User $user): void
    {
        $user->forceFill(['dashboard_widgets' => null])->save();
    }
}
