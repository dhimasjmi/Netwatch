<?php

declare(strict_types=1);

namespace NetWatch\Agent\Config;

use RuntimeException;

/** Konfigurasi agent (immutable). Disimpan sebagai JSON dengan izin berkas 0600. */
final class AgentConfig
{
    public function __construct(
        public readonly string $serverUrl,
        public readonly ?string $deviceId,
        public readonly ?string $apiToken,
        public readonly ?string $caFile,
        public readonly bool $allowInsecureHttp,
        public readonly ?string $logFile,
        /** Lama server tidak terjangkau sebelum blokir dilepas (PC dianggap di luar jaringan kantor); 0 = tidak pernah dilepas. */
        public readonly int $offlineReleaseSeconds = 600,
    ) {
    }

    public static function defaultPath(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return (getenv('ProgramData') ?: 'C:\\ProgramData') . '\\NetWatch\\agent.json';
        }

        return '/etc/netwatch-agent/agent.json';
    }

    public static function defaultLogFile(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return (getenv('ProgramData') ?: 'C:\\ProgramData') . '\\NetWatch\\agent.log';
        }

        return '/var/log/netwatch-agent.log';
    }

    public function isEnrolled(): bool
    {
        return $this->deviceId !== null && $this->apiToken !== null;
    }

    public function withCredentials(string $deviceId, string $apiToken): self
    {
        return new self($this->serverUrl, $deviceId, $apiToken, $this->caFile, $this->allowInsecureHttp, $this->logFile, $this->offlineReleaseSeconds);
    }

    /** @throws RuntimeException */
    public static function load(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Berkas konfigurasi tidak ditemukan atau tidak bisa dibaca: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!is_array($data) || !isset($data['server_url']) || !is_string($data['server_url'])) {
            throw new RuntimeException("Berkas konfigurasi tidak valid: {$path}");
        }

        return new self(
            $data['server_url'],
            isset($data['device_id']) ? (string) $data['device_id'] : null,
            isset($data['api_token']) ? (string) $data['api_token'] : null,
            isset($data['ca_file']) ? (string) $data['ca_file'] : null,
            (bool) ($data['allow_insecure_http'] ?? false),
            isset($data['log_file']) ? (string) $data['log_file'] : self::defaultLogFile(),
            isset($data['offline_release_seconds']) ? max(0, (int) $data['offline_release_seconds']) : 600,
        );
    }

    /** Simpan secara atomik (tulis ke berkas sementara, lalu rename) dengan izin ketat. */
    public function save(string $path): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Tidak dapat membuat direktori: {$directory}");
        }

        $json = json_encode([
            'server_url' => $this->serverUrl,
            'device_id' => $this->deviceId,
            'api_token' => $this->apiToken,
            'ca_file' => $this->caFile,
            'allow_insecure_http' => $this->allowInsecureHttp,
            'log_file' => $this->logFile,
            'offline_release_seconds' => $this->offlineReleaseSeconds,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $temp = $path . '.tmp';

        if (file_put_contents($temp, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException("Tidak dapat menulis konfigurasi: {$temp}");
        }

        @chmod($temp, 0600);

        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException("Tidak dapat menyimpan konfigurasi: {$path}");
        }
    }
}
