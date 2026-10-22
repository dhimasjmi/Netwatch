<?php

declare(strict_types=1);

namespace NetWatch\Agent\Enrollment;

use NetWatch\Agent\Config\AgentConfig;
use NetWatch\Agent\Http\ApiClient;
use NetWatch\Agent\Http\ApiException;
use NetWatch\Agent\Platform\PlatformInterface;
use NetWatch\Agent\Version;
use RuntimeException;

/** Mendaftarkan komputer ini ke server memakai token pendaftaran sekali pakai. */
final class Enroller
{
    public function __construct(private readonly PlatformInterface $platform)
    {
    }

    /** @throws RuntimeException bila server menolak atau respons tidak lengkap */
    public function enroll(AgentConfig $config, string $enrollmentToken): AgentConfig
    {
        $client = new ApiClient($config->serverUrl, null, $config->caFile, $config->allowInsecureHttp);

        try {
            $response = $client->post('enroll', array_filter([
                'enrollment_token' => $enrollmentToken,
                'hostname' => $this->platform->hostname(),
                'os_family' => $this->platform->family(),
                'os_version' => $this->platform->osVersion(),
                'agent_version' => Version::CURRENT,
                'ip_address' => $this->localIp($config->serverUrl),
                'mac_address' => $this->platform->macAddress(),
            ], static fn (mixed $value): bool => $value !== null));
        } catch (ApiException $e) {
            throw new RuntimeException('Pendaftaran gagal: ' . $e->getMessage(), 0, $e);
        }

        if (!isset($response['device_id'], $response['api_token'])) {
            throw new RuntimeException('Respons pendaftaran dari server tidak lengkap.');
        }

        return $config->withCredentials((string) $response['device_id'], (string) $response['api_token']);
    }

    /**
     * IP lokal yang dipakai untuk menjangkau server. Soket UDP "connect" tidak mengirim paket
     * apa pun; ia hanya meminta OS memilih antarmuka yang akan dipakai.
     */
    private function localIp(string $serverUrl): ?string
    {
        $host = parse_url($serverUrl, PHP_URL_HOST);

        if (!is_string($host)) {
            return null;
        }

        $address = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : gethostbyname($host);
        $socket = @stream_socket_client("udp://{$address}:9", $errno, $errstr, 1);

        if ($socket === false) {
            return null;
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $ip = preg_replace('/:\d+$/', '', $name);

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }
}
