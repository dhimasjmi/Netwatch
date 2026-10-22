<?php

declare(strict_types=1);

namespace App\Services\Probe;

use App\Models\MonitorTarget;
use App\Support\PingProbe;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Ping ICMP lewat perintah ping bawaan OS (tanpa shell) dan cek port TCP lewat soket non-blokir.
 * Dikerjakan per kelompok secara paralel, jadi ratusan target tetap selesai dalam hitungan detik.
 */
final class SystemTargetProber implements TargetProber
{
    private const CHUNK = 25;

    public function probe(array $targets): array
    {
        $icmp = [];
        $tcp = [];
        foreach ($targets as $target) {
            $target->method === 'tcp' ? $tcp[] = $target : $icmp[] = $target;
        }

        return $this->icmp($icmp) + $this->tcp($tcp);
    }

    /**
     * @param  list<MonitorTarget>  $targets
     * @return array<int, ProbeResult>
     */
    private function icmp(array $targets): array
    {
        $windows = PHP_OS_FAMILY === 'Windows';
        $timeout = (float) config('netwatch.monitor.timeout_seconds');
        $results = [];

        foreach (array_chunk($targets, self::CHUNK) as $chunk) {
            $running = [];
            foreach ($chunk as $target) {
                $command = PingProbe::command($target->address, $windows);
                if ($command === null) {
                    $results[$target->id] = new ProbeResult(false);

                    continue;
                }
                $process = new Process($command, null, null, null, $timeout);
                $process->start();
                $running[$target->id] = $process;
            }

            foreach ($running as $id => $process) {
                try {
                    $process->wait();
                    $output = $process->getOutput();
                    $ok = PingProbe::succeeded((int) $process->getExitCode(), $output);
                    $results[$id] = new ProbeResult($ok, $ok ? PingProbe::milliseconds($output) : null);
                } catch (Throwable) { // timeout atau ping tidak terpasang: anggap tidak merespons
                    $results[$id] = new ProbeResult(false);
                }
            }
        }

        return $results;
    }

    /**
     * @param  list<MonitorTarget>  $targets
     * @return array<int, ProbeResult>
     */
    private function tcp(array $targets): array
    {
        $results = [];
        foreach (array_chunk($targets, 100) as $chunk) {
            $results += $this->tcpChunk($chunk);
        }

        return $results;
    }

    /**
     * @param  list<MonitorTarget>  $targets
     * @return array<int, ProbeResult>
     */
    private function tcpChunk(array $targets): array
    {
        $results = [];
        $sockets = [];
        $started = [];

        foreach ($targets as $target) {
            if (PingProbe::command($target->address, false) === null || $target->port === null) {
                $results[$target->id] = new ProbeResult(false);

                continue;
            }
            $host = str_contains($target->address, ':') ? '['.$target->address.']' : $target->address;
            $socket = @stream_socket_client("tcp://{$host}:{$target->port}", $errno, $error, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            if ($socket === false) {
                $results[$target->id] = new ProbeResult(false);

                continue;
            }
            stream_set_blocking($socket, false);
            $sockets[$target->id] = $socket;
            $started[$target->id] = hrtime(true);
        }

        $deadline = microtime(true) + (float) config('netwatch.monitor.timeout_seconds');

        while ($sockets !== [] && microtime(true) < $deadline) {
            $read = null;
            $write = array_values($sockets);
            $except = array_values($sockets); // Windows melaporkan koneksi gagal lewat daftar ini
            $wait = (int) min(200_000, max(0, ($deadline - microtime(true)) * 1_000_000));
            if (@stream_select($read, $write, $except, 0, $wait) === false) {
                break;
            }

            foreach ($write as $socket) {
                $id = array_search($socket, $sockets, true);
                if ($id === false) {
                    continue;
                }
                // Soket bisa ditulis juga saat koneksi ditolak; hanya yang punya alamat peer yang benar-benar tersambung.
                $connected = @stream_socket_get_name($socket, true) !== false;
                $results[$id] = new ProbeResult($connected, $connected ? round((hrtime(true) - $started[$id]) / 1_000_000, 1) : null);
                unset($sockets[$id]);
                fclose($socket);
            }

            foreach ($except as $socket) {
                $id = array_search($socket, $sockets, true);
                if ($id === false) {
                    continue;
                }
                $results[$id] = new ProbeResult(false);
                unset($sockets[$id]);
                fclose($socket);
            }
        }

        foreach ($sockets as $id => $socket) { // lewat batas waktu
            $results[$id] = new ProbeResult(false);
            fclose($socket);
        }

        return $results;
    }
}
