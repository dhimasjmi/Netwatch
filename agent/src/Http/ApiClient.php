<?php

declare(strict_types=1);

namespace NetWatch\Agent\Http;

use InvalidArgumentException;

/** Klien HTTP JSON ke server NetWatch. TLS diverifikasi; redirect tidak diikuti agar token tidak bocor. */
final class ApiClient implements ApiTransport
{
    private const API_PREFIX = '/api/agent/v1/';
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT = 20;
    private const MAX_RESPONSE_BYTES = 2_097_152;

    private readonly string $baseUrl;

    /** @throws InvalidArgumentException bila URL bukan https (kecuali http diizinkan eksplisit) */
    public function __construct(
        string $serverUrl,
        private readonly ?string $token = null,
        private readonly ?string $caFile = null,
        bool $allowInsecureHttp = false,
        private readonly string $userAgent = 'NetWatch-Agent',
    ) {
        $parts = parse_url($serverUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!isset($parts['host']) || !in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('URL server tidak valid: ' . $serverUrl);
        }

        if ($scheme === 'http' && !$allowInsecureHttp) {
            throw new InvalidArgumentException(
                'Server harus memakai HTTPS. Gunakan --allow-insecure-http hanya untuk uji coba di jaringan tertutup.'
            );
        }

        $this->baseUrl = rtrim($serverUrl, '/') . self::API_PREFIX;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function post(string $path, array $body = []): array
    {
        $headers = ['Accept: application/json', 'Content-Type: application/json'];

        if ($this->token !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $handle = curl_init($this->baseUrl . ltrim($path, '/'));
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_MAXFILESIZE => self::MAX_RESPONSE_BYTES,
        ]);

        if ($this->caFile !== null) {
            curl_setopt($handle, CURLOPT_CAINFO, $this->caFile);
        }

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($raw === false) {
            throw new ApiException('Tidak dapat terhubung ke server: ' . $error);
        }

        $decoded = json_decode((string) $raw, true);

        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) && isset($decoded['message']) ? (string) $decoded['message'] : 'Respons tidak valid';
            throw new ApiException("Server menolak permintaan (HTTP {$status}): {$message}", $status);
        }

        if (!is_array($decoded)) {
            throw new ApiException('Respons server bukan JSON yang valid.', $status);
        }

        return $decoded;
    }
}
