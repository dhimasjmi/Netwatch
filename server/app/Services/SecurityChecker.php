<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CheckStatus;
use App\Enums\ConnectionStatus;
use App\Enums\UserRole;
use App\Models\Device;
use App\Models\EnrollmentToken;
use App\Models\Event;
use App\Models\User;
use App\Support\AgentVersion;
use App\Support\SecurityCheck;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Pemeriksaan konfigurasi dan kondisi armada yang relevan untuk keamanan. Hanya membaca, tidak mengubah apa pun. */
final class SecurityChecker
{
    private const BROWSING_ROWS_WARNING = 3_000_000;

    /** @return list<SecurityCheck> */
    public function run(): array
    {
        return [
            $this->debugMode(),
            $this->environment(),
            $this->https(),
            $this->sessionCookies(),
            $this->databaseRole(),
            $this->administrators(),
            $this->enrollmentTokens(),
            $this->outdatedAgents(),
            $this->staleDevices(),
            $this->schedulerHealth(),
            $this->browsingStorage(),
            $this->failedLogins(),
        ];
    }

    /** @param  list<SecurityCheck>  $checks */
    public static function worst(array $checks): CheckStatus
    {
        $worst = CheckStatus::Ok;

        foreach ($checks as $check) {
            if ($check->status === CheckStatus::Fail) {
                return CheckStatus::Fail;
            }
            if ($check->status === CheckStatus::Warning) {
                $worst = CheckStatus::Warning;
            }
        }

        return $worst;
    }

    private function debugMode(): SecurityCheck
    {
        return config('app.debug')
            ? new SecurityCheck('Mode debug', CheckStatus::Fail, 'APP_DEBUG aktif: halaman galat bisa membocorkan kata sandi database dan isi konfigurasi.', 'Set APP_DEBUG=false di .env.')
            : new SecurityCheck('Mode debug', CheckStatus::Ok, 'Nonaktif.');
    }

    private function environment(): SecurityCheck
    {
        return app()->isProduction()
            ? new SecurityCheck('Lingkungan', CheckStatus::Ok, 'APP_ENV=production.')
            : new SecurityCheck('Lingkungan', CheckStatus::Warning, 'APP_ENV bukan production (acceptable untuk pilot).', 'Set APP_ENV=production di server sungguhan agar proteksi tambahan aktif.');
    }

    private function https(): SecurityCheck
    {
        $urlIsHttps = str_starts_with((string) config('app.url'), 'https://');

        if ($urlIsHttps && config('netwatch.force_https')) {
            return new SecurityCheck('HTTPS', CheckStatus::Ok, 'APP_URL memakai https dan HTTPS dipaksa.');
        }

        return new SecurityCheck('HTTPS', CheckStatus::Warning,
            'Lalu lintas dashboard dan agent (termasuk token) belum dienkripsi.',
            'Pasang Nginx/Caddy dengan sertifikat (CA internal), set APP_URL=https://..., NETWATCH_FORCE_HTTPS=true. Wajib sebelum dipakai di jaringan sungguhan.');
    }

    private function sessionCookies(): SecurityCheck
    {
        $problems = [];

        if (! config('session.secure')) {
            $problems[] = 'cookie sesi belum "secure"';
        }
        if (! config('session.http_only')) {
            $problems[] = 'cookie sesi bisa dibaca JavaScript';
        }
        if (! in_array(config('session.same_site'), ['lax', 'strict'], true)) {
            $problems[] = 'SameSite tidak lax/strict';
        }
        if (! config('session.encrypt')) {
            $problems[] = 'isi sesi belum dienkripsi';
        }

        return $problems === []
            ? new SecurityCheck('Cookie & sesi', CheckStatus::Ok, 'Secure, HttpOnly, SameSite, dan terenkripsi.')
            : new SecurityCheck('Cookie & sesi', CheckStatus::Warning, ucfirst(implode('; ', $problems)).'.', 'Atur SESSION_SECURE_COOKIE, SESSION_SAME_SITE=strict, dan SESSION_ENCRYPT=true di .env (secure hanya bila sudah HTTPS).');
    }

    private function databaseRole(): SecurityCheck
    {
        try {
            $row = DB::selectOne('SELECT rolsuper FROM pg_roles WHERE rolname = current_user');
        } catch (Throwable) {
            return new SecurityCheck('Hak akses database', CheckStatus::Warning, 'Tidak bisa diperiksa (bukan PostgreSQL atau akses ditolak).');
        }

        return ($row->rolsuper ?? false)
            ? new SecurityCheck('Hak akses database', CheckStatus::Warning, 'Aplikasi terhubung sebagai superuser PostgreSQL.', 'Buat role khusus (hanya hak atas database netwatch) agar celah aplikasi tidak berarti kendali penuh atas server database.')
            : new SecurityCheck('Hak akses database', CheckStatus::Ok, 'Aplikasi memakai role non-superuser.');
    }

    private function administrators(): SecurityCheck
    {
        $count = User::query()->where('role', UserRole::Admin->value)->where('is_active', true)->count();

        return match (true) {
            $count === 0 => new SecurityCheck('Administrator', CheckStatus::Fail, 'Tidak ada administrator aktif.', 'Buat dengan: php artisan netwatch:create-user'),
            $count === 1 => new SecurityCheck('Administrator', CheckStatus::Warning, 'Hanya 1 administrator aktif.', 'Tambah satu admin cadangan agar akses tidak hilang bila satu akun terkunci.'),
            $count > 5 => new SecurityCheck('Administrator', CheckStatus::Warning, "{$count} administrator aktif.", 'Kurangi hak admin; operator cukup untuk tugas harian.'),
            default => new SecurityCheck('Administrator', CheckStatus::Ok, "{$count} administrator aktif."),
        };
    }

    private function enrollmentTokens(): SecurityCheck
    {
        $active = EnrollmentToken::query()->get()->filter(fn (EnrollmentToken $t): bool => $t->isUsable())->count();

        return $active === 0
            ? new SecurityCheck('Token pendaftaran', CheckStatus::Ok, 'Tidak ada token pendaftaran yang aktif.')
            : new SecurityCheck('Token pendaftaran', CheckStatus::Warning, "{$active} token masih bisa dipakai mendaftarkan perangkat.", 'Cabut token yang sudah tidak diperlukan di menu Token Pendaftaran.');
    }

    private function outdatedAgents(): SecurityCheck
    {
        $latest = (string) config('netwatch.agent_latest_version');
        $outdated = Device::query()->active()->get(['hostname', 'agent_version'])
            ->filter(fn (Device $d): bool => AgentVersion::isOutdated($d->agent_version, $latest));

        return $outdated->isEmpty()
            ? new SecurityCheck('Versi agent', CheckStatus::Ok, "Semua agent aktif memakai versi {$latest} atau lebih baru.")
            : new SecurityCheck('Versi agent', CheckStatus::Warning,
                $outdated->count()." perangkat memakai agent di bawah {$latest}: ".$outdated->pluck('hostname')->take(5)->implode(', ').($outdated->count() > 5 ? ', …' : '').'.',
                'Pasang ulang dengan installer terbaru (lihat docs/PENYEBARAN-AGENT.md).');
    }

    private function staleDevices(): SecurityCheck
    {
        $days = (int) config('netwatch.stale_device_days');
        $stale = Device::query()->active()->where('last_seen_at', '<', now()->subDays($days))->count();

        return $stale === 0
            ? new SecurityCheck('Perangkat usang', CheckStatus::Ok, "Tidak ada perangkat yang hilang lebih dari {$days} hari.")
            : new SecurityCheck('Perangkat usang', CheckStatus::Warning, "{$stale} perangkat tidak terlihat lebih dari {$days} hari tetapi tokennya masih berlaku.", 'Cabut akses perangkat yang sudah tidak dipakai agar tokennya tidak bisa disalahgunakan.');
    }

    /** Tabel browsing mentah yang sangat besar = retensi/rollup tidak berjalan atau volume terlalu tinggi. */
    private function browsingStorage(): SecurityCheck
    {
        try {
            // Perkiraan cepat dari statistik PostgreSQL (tanpa menghitung seluruh tabel).
            $rows = (int) (DB::selectOne("SELECT reltuples::bigint AS n FROM pg_class WHERE relname = 'browsing_visits'")->n ?? 0);
        } catch (\Throwable) {
            return new SecurityCheck('Volume data browsing', CheckStatus::Warning, 'Tidak bisa diperiksa (bukan PostgreSQL atau akses ditolak).');
        }

        return $rows > self::BROWSING_ROWS_WARNING
            ? new SecurityCheck('Volume data browsing', CheckStatus::Warning, 'Tabel browsing mentah sekitar '.number_format($rows, 0, ',', '.').' baris.',
                'Pastikan "php artisan netwatch:prune" berjalan (scheduler aktif), turunkan NETWATCH_RETENTION_BROWSING, atau tambah domain yang diabaikan di halaman Browsing.')
            : new SecurityCheck('Volume data browsing', CheckStatus::Ok, 'Tabel browsing mentah sekitar '.number_format(max(0, $rows), 0, ',', '.').' baris.');
    }

    /** Perangkat berstatus online padahal heartbeat lama berhenti = pengecekan terjadwal tidak berjalan. */
    private function schedulerHealth(): SecurityCheck
    {
        $limit = now()->subSeconds((int) config('netwatch.offline_after') * 3);
        $stuck = Device::query()->active()
            ->where('last_seen_at', '<', $limit)
            ->whereHas('statusHistory', fn ($q) => $q->whereNull('ended_at')->where('status', ConnectionStatus::Online->value))
            ->count();

        return $stuck === 0
            ? new SecurityCheck('Penjadwal (scheduler)', CheckStatus::Ok, 'Pengecekan status perangkat berjalan.')
            : new SecurityCheck('Penjadwal (scheduler)', CheckStatus::Warning, "{$stuck} perangkat masih tercatat online padahal sudah lama tidak mengirim heartbeat.", 'Jalankan "php artisan schedule:work" (atau cron tiap menit). Tanpa ini status offline dan retensi data tidak berjalan.');
    }

    private function failedLogins(): SecurityCheck
    {
        $count = Event::query()->where('code', 'auth.login_failed')->where('occurred_at', '>=', now()->subDay())->count();

        return match (true) {
            $count >= 50 => new SecurityCheck('Login gagal (24 jam)', CheckStatus::Fail, "{$count} percobaan login gagal.", 'Kemungkinan serangan tebak kata sandi. Lihat menu Event kategori Keamanan, lalu blokir IP sumbernya.'),
            $count >= 10 => new SecurityCheck('Login gagal (24 jam)', CheckStatus::Warning, "{$count} percobaan login gagal.", 'Periksa menu Event kategori Keamanan untuk IP dan akun yang dituju.'),
            default => new SecurityCheck('Login gagal (24 jam)', CheckStatus::Ok, "{$count} percobaan login gagal."),
        };
    }
}
