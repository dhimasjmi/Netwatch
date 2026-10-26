<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\BackupRetention;
use PHPUnit\Framework\TestCase;

final class BackupRetentionTest extends TestCase
{
    public function test_keeps_newest_per_day_week_and_month_and_lists_the_rest(): void
    {
        $t = static fn (string $s): int => (int) strtotime($s.' UTC');
        $files = [
            'a' => $t('2026-10-20 01:00'), 'b' => $t('2026-10-21 01:00'),
            'c' => $t('2026-10-22 01:00'), 'd' => $t('2026-10-22 13:00'),
        ];

        $obsolete = BackupRetention::obsolete($files, 2, 1, 1);
        sort($obsolete);

        $this->assertSame(['a', 'c'], $obsolete);   // d (terbaru hari itu) dan b (hari sebelumnya) dipertahankan
    }

    public function test_newest_is_never_deleted_even_with_zero_limits(): void
    {
        $files = ['x' => 1000, 'y' => 2000, 'z' => 3000];

        $obsolete = BackupRetention::obsolete($files, 0, 0, 0);
        sort($obsolete);

        $this->assertSame(['x', 'y'], $obsolete);
        $this->assertSame([], BackupRetention::obsolete([], 7, 4, 6));
    }

    public function test_weekly_and_monthly_slots_keep_older_files(): void
    {
        $t = static fn (string $s): int => (int) strtotime($s.' UTC');
        $files = ['sep' => $t('2026-09-15 01:00'), 'aug' => $t('2026-08-15 01:00'), 'okt' => $t('2026-10-22 01:00')];

        $this->assertSame([], BackupRetention::obsolete($files, 1, 1, 3));              // satu per bulan dipertahankan
        $this->assertSame(['aug'], BackupRetention::obsolete($files, 1, 1, 2));
    }

    public function test_name_roundtrip_and_foreign_files_are_ignored(): void
    {
        $time = 1792630800;
        $name = BackupRetention::nameFor($time);

        $this->assertMatchesRegularExpression('/^netwatch-\d{8}-\d{6}\.dump$/', $name);
        $this->assertSame($time, BackupRetention::timeFromName($name));
        $this->assertNull(BackupRetention::timeFromName($name.'.sha256'));
        $this->assertNull(BackupRetention::timeFromName('catatan.dump'));
        $this->assertNull(BackupRetention::timeFromName('netwatch-20261340-250000.dump'));
    }
}
