<?php

declare(strict_types=1);

namespace NetWatch\Agent\Policy;

use Closure;
use InvalidArgumentException;
use NetWatch\Agent\Platform\PlatformException;
use NetWatch\Agent\Platform\PlatformInterface;
use NetWatch\Agent\Support\Logger;
use Throwable;

/**
 * Menerapkan kebijakan blokir dari server dan melaporkan hasilnya.
 *
 * Hash yang dilaporkan ke server berasal dari pembacaan aturan yang benar-benar terpasang (hosts, kebijakan browser,
 * firewall), BUKAN dari catatan lokal. Dengan begitu state yang hilang (reinstal, berkas terhapus) atau aturan yang
 * diubah/dihapus manual terdeteksi sebagai penyimpangan dan server mengirim ulang keadaan yang seharusnya.
 *
 * Saat server tidak terjangkau agent tidak melakukan apa pun: aturan terakhir tetap berlaku (enforcement tidak
 * melemah karena jaringan putus). Pencabutan hanya terjadi atas perintah server (kebijakan kosong), uninstall, atau skrip pemulihan.
 */
final class PolicyApplier
{
    /** Jeda sebelum mencoba lagi kebijakan yang sama setelah gagal, agar PowerShell tidak dijalankan tiap heartbeat. */
    public const RETRY_AFTER_SECONDS = 300;

    /** Seberapa sering kondisi terpasang dibaca ulang (membuka PowerShell, jadi tidak tiap heartbeat). */
    public const VERIFY_INTERVAL_SECONDS = 300;

    private readonly Closure $clock;
    private ?string $actualHash = null;
    private ?string $verifyError = null;
    private int $verifiedAt = -1;
    private bool $releasedOffline = false;

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly string $stateFile,
        private readonly string $serverHost,
        private readonly Logger $logger,
        ?Closure $clock = null,
        private readonly int $offlineReleaseSeconds = 600,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Laporan untuk heartbeat: hash dari aturan yang terpasang saat ini dan galat terakhir (penerapan atau verifikasi).
     * hash null berarti kondisi tidak bisa dibaca; server tidak boleh menganggapnya bersih.
     *
     * @return array{hash: string|null, error: string|null}
     */
    public function report(): array
    {
        $now = ($this->clock)();

        if ($this->verifiedAt < 0 || $now - $this->verifiedAt >= self::VERIFY_INTERVAL_SECONDS) {
            $this->verify();
        }

        $state = PolicyState::load($this->stateFile);

        return ['hash' => $this->actualHash, 'error' => $state->error ?? $this->verifyError] + ($this->releasedOffline ? ['offline_release' => true] : []);
    }

    /**
     * Server tidak terjangkau selama $offlineSeconds berturut-turut: PC dianggap di luar jaringan kantor, jadi blokir
     * dilepas (pengguna berhak mengakses situs itu di luar kantor). Begitu heartbeat berhasil lagi, laporan hash kosong
     * membuat server mengirim ulang kebijakan dan blokir dipasang kembali (mekanisme deteksi penyimpangan yang sama).
     * Tidak pernah melempar. Mengembalikan true bila aturan dilepas pada panggilan ini.
     */
    public function releaseWhenOffline(int $offlineSeconds): bool
    {
        if ($this->offlineReleaseSeconds <= 0 || $offlineSeconds < $this->offlineReleaseSeconds || $this->releasedOffline) {
            return false;
        }

        try {
            $this->platform->applyBlockPolicy([], []);
            $this->verify();
        } catch (Throwable $e) {
            $this->logger->warning('Gagal melepas blokir saat di luar jaringan kantor (dicoba lagi): ' . mb_substr($e->getMessage(), 0, 300));

            return false;
        }

        if ($this->actualHash !== BlockPolicy::canonicalHash([], [])) {
            $this->logger->warning('Pelepasan blokir di luar jaringan kantor belum tuntas; dicoba lagi.');

            return false;
        }

        $this->releasedOffline = true;
        $this->logger->info(sprintf('Server tidak terjangkau %d detik: PC dianggap di luar jaringan kantor, blokir dilepas.', $offlineSeconds));

        return true;
    }

    /**
     * Server tidak meminta kebijakan baru (sudah sinkron atau aturan bermasalah sudah dihapus):
     * galat lama tidak relevan lagi dan jangan dilaporkan terus.
     */
    public function nothingToApply(): void
    {
        $this->releasedOffline = false;
        $state = PolicyState::load($this->stateFile);

        if ($state->error !== null) {
            $this->save(new PolicyState($state->hash, null, $state->attemptedHash, $state->attemptedAt));
        }
    }

    /** @param  array<string, mixed>  $payload kebijakan dari respons heartbeat; tidak pernah melempar */
    public function apply(array $payload): void
    {
        $state = PolicyState::load($this->stateFile);

        try {
            $policy = BlockPolicy::fromArray($payload, $this->serverHost);
        } catch (InvalidArgumentException $e) {
            $this->logger->warning('Kebijakan dari server ditolak: ' . $e->getMessage());
            $this->save(new PolicyState($state->hash, 'Kebijakan ditolak agent: ' . $e->getMessage(), $state->attemptedHash, $state->attemptedAt));

            return;
        }

        $now = ($this->clock)();
        $wanted = BlockPolicy::canonicalHash($policy->domains, $policy->ips);
        $this->releasedOffline = false; // server terjangkau lagi dan meminta kebijakan: blokir dipasang kembali

        if ($this->verifiedAt < 0) {
            $this->verify();
        }

        if ($this->actualHash === $wanted) {
            if ($state->error !== null) {
                $this->save(new PolicyState($wanted, null, $state->attemptedHash, $state->attemptedAt));
            }

            return; // Sudah persis terpasang; tidak perlu menyentuh sistem.
        }

        if ($state->error !== null && $state->attemptedHash === $policy->hash && $now - $state->attemptedAt < self::RETRY_AFTER_SECONDS) {
            return;
        }

        $this->logger->info(sprintf('Menerapkan kebijakan blokir (%d domain, %d IP).', count($policy->domains), count($policy->ips)));

        try {
            $this->platform->applyBlockPolicy($policy->domains, $policy->ips);
        } catch (PlatformException $e) {
            $this->logger->warning('Gagal menerapkan kebijakan blokir: ' . $e->getMessage());
            $this->save(new PolicyState($state->hash, mb_substr($e->getMessage(), 0, 450), $policy->hash, $now));

            return;
        } catch (Throwable $e) {
            $this->logger->error('Kesalahan tak terduga saat menerapkan kebijakan: ' . $e::class . ': ' . $e->getMessage());
            $this->save(new PolicyState($state->hash, 'Kesalahan tak terduga: ' . $e::class, $policy->hash, $now));

            return;
        }

        $this->verify();

        if ($this->actualHash !== $wanted) {
            $message = 'Verifikasi setelah penerapan gagal: aturan terpasang tidak sama dengan kebijakan' . ($this->verifyError !== null ? ' (' . $this->verifyError . ')' : '') . '.';
            $this->logger->warning($message);
            $this->save(new PolicyState($state->hash, mb_substr($message, 0, 450), $policy->hash, $now));

            return;
        }

        $this->save(new PolicyState($policy->hash, null, $policy->hash, $now));
        $this->logger->info('Kebijakan blokir diterapkan dan terverifikasi.');
    }

    /**
     * Hapus semua blokir NetWatch dari komputer ini (dipanggil saat agent dihapus) lalu pastikan benar-benar bersih.
     * State hanya direset bila pembersihan terbukti berhasil.
     *
     * @throws PlatformException
     */
    public function clear(): void
    {
        $this->platform->applyBlockPolicy([], []);
        $this->verify();

        if ($this->actualHash !== BlockPolicy::canonicalHash([], [])) {
            throw new PlatformException('Pembersihan belum tuntas: ' . ($this->verifyError ?? 'masih ada aturan NetWatch yang terpasang') . '.');
        }

        $this->save(new PolicyState(null, null, null, 0));
    }

    /** Baca aturan terpasang. Hasil tidak konsisten antar-mekanisme (mis. hosts dihapus manual) menjadi hash "menyimpang". */
    private function verify(): void
    {
        $this->verifiedAt = ($this->clock)();
        $this->verifyError = null;

        try {
            $actual = $this->platform->inspectBlockPolicy();
        } catch (Throwable $e) {
            $this->actualHash = null;
            $this->verifyError = 'Tidak dapat memverifikasi aturan terpasang: ' . mb_substr($e->getMessage(), 0, 300);
            $this->logger->warning($this->verifyError);

            return;
        }

        $hosts = $actual['hosts'];
        $browser = $actual['browser'];
        sort($hosts, SORT_STRING);
        sort($browser, SORT_STRING);

        if ($hosts !== $browser) {
            // Salah satu mekanisme tertinggal/hilang. Hash ini tidak akan pernah sama dengan hash server.
            $this->actualHash = hash('sha256', 'drift:' . json_encode([$hosts, $browser, $actual['ips']]));
            $this->logger->warning(sprintf('Aturan terpasang tidak konsisten (hosts %d domain, browser %d domain); akan diterapkan ulang.', count($hosts), count($browser)));

            return;
        }

        $this->actualHash = BlockPolicy::canonicalHash($hosts, $actual['ips']);
    }

    private function save(PolicyState $state): void
    {
        $state->save($this->stateFile);
    }
}
