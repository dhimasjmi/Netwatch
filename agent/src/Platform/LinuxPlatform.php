<?php

declare(strict_types=1);

namespace NetWatch\Agent\Platform;

use NetWatch\Agent\Inventory\AssetNormalizer;
use NetWatch\Agent\Dto\NetworkInterfaceStat;
use NetWatch\Agent\Dto\ProcessInfo;
use NetWatch\Agent\Dto\SystemMetrics;
use NetWatch\Agent\Support\ProcessRunner;
use RuntimeException;

final class LinuxPlatform implements PlatformInterface
{
    private const CPU_SAMPLE_US = 250_000;
    private const BYTES_PER_GB = 1_073_741_824;

    public function __construct(private readonly ProcessRunner $runner = new ProcessRunner())
    {
    }

    public function family(): string
    {
        return 'linux';
    }

    public function hostname(): string
    {
        return (string) gethostname();
    }

    public function osVersion(): string
    {
        $release = @file_get_contents('/etc/os-release');

        if ($release !== false && preg_match('/^PRETTY_NAME="?([^"\n]+)"?$/m', $release, $match) === 1) {
            return mb_substr($match[1], 0, 100);
        }

        return mb_substr(php_uname('s') . ' ' . php_uname('r'), 0, 100);
    }

    public function macAddress(): ?string
    {
        foreach (glob('/sys/class/net/*/address') ?: [] as $file) {
            if (str_contains($file, '/lo/')) {
                continue;
            }

            $mac = strtolower(trim((string) @file_get_contents($file)));

            if (preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac) === 1 && $mac !== '00:00:00:00:00:00') {
                return $mac;
            }
        }

        return null;
    }

    public function collectMetrics(): SystemMetrics
    {
        [$memTotalMb, $memUsedMb] = self::parseMeminfo((string) @file_get_contents('/proc/meminfo'));

        $diskTotal = (float) (@disk_total_space('/') ?: 0);
        $diskFree = (float) (@disk_free_space('/') ?: 0);

        return new SystemMetrics(
            cpuPercent: $this->sampleCpuPercent(),
            memoryUsedMb: $memUsedMb,
            memoryTotalMb: $memTotalMb,
            diskUsedGb: (int) round(($diskTotal - $diskFree) / self::BYTES_PER_GB),
            diskTotalGb: (int) round($diskTotal / self::BYTES_PER_GB),
            uptimeSeconds: (int) explode(' ', (string) @file_get_contents('/proc/uptime'))[0],
            loggedInUser: $this->loggedInUsers(),
        );
    }

    public function collectNetworkInterfaces(): array
    {
        $stats = self::parseNetDev((string) @file_get_contents('/proc/net/dev'));

        return array_map(function (NetworkInterfaceStat $stat): NetworkInterfaceStat {
            // speed (Mbit/s) bernilai -1 untuk interface nirkabel/virtual; abaikan bila tidak valid.
            $mbps = (int) trim((string) @file_get_contents("/sys/class/net/{$stat->name}/speed"));

            return new NetworkInterfaceStat($stat->name, $stat->rxBytes, $stat->txBytes, $mbps > 0 ? $mbps * 1_000_000 : null);
        }, $stats);
    }

    /**
     * Parse /proc/net/dev. Interface loopback dan virtual (docker, veth, bridge) dilewati.
     *
     * @return list<NetworkInterfaceStat>
     */
    public static function parseNetDev(string $content): array
    {
        $stats = [];

        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^\s*([^:\s]+):\s*(\d+)\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+(\d+)/', $line, $m) !== 1) {
                continue;
            }

            if ($m[1] === 'lo' || preg_match('/^(docker|veth|br-|virbr|tun|tap)/', $m[1]) === 1) {
                continue;
            }

            $stats[] = new NetworkInterfaceStat($m[1], (int) $m[2], (int) $m[3]);
        }

        return $stats;
    }

    public function listProcesses(): array
    {
        $result = $this->runner->run(['ps', '-eo', 'pid=,user:32=,rss=,cputimes=,comm=']);

        if (!$result->isSuccessful()) {
            throw new PlatformException('Gagal membaca daftar proses: ' . trim($result->stderr));
        }

        return self::parsePsOutput($result->stdout);
    }

    public function listConnections(): array
    {
        $result = $this->runner->run(['ss', '-H', '-t', '-n', '-p', 'state', 'established']);

        if (!$result->isSuccessful()) {
            throw new PlatformException('Gagal membaca koneksi (ss): ' . trim($result->stderr));
        }

        return self::parseSs($result->stdout);
    }

    /** @return list<array{ip: string, port: int|null, process: string|null, host: string|null}> */
    public static function parseSs(string $output): array
    {
        $rows = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            // "0 0 192.168.1.5:44322 93.184.216.34:443 users:(("chrome",pid=1,fd=5))"
            if (preg_match('/^\s*\d+\s+\d+\s+\S+\s+(\[?[0-9a-fA-F:.]+\]?):(\d+)(?:\s+users:\(\("([^"]*)")?/', $line, $m) !== 1) {
                continue;
            }

            $ip = trim($m[1], '[]');

            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $rows[] = ['ip' => strtolower($ip), 'port' => (int) $m[2], 'process' => ($m[3] ?? '') !== '' ? $m[3] : null, 'host' => null];
            }
        }

        return $rows;
    }

    public function browserHistoryFiles(): array
    {
        $files = [];
        $patterns = [
            'chrome' => ['/home/*/.config/google-chrome/*/History', '/home/*/.config/chromium/*/History'],
            'edge' => ['/home/*/.config/microsoft-edge/*/History'],
        ];

        foreach ($patterns as $browser => $globs) {
            foreach ($globs as $glob) {
                foreach (glob($glob) ?: [] as $path) {
                    if (preg_match('#/(Default|Profile \d+)/History$#', $path) === 1 && is_file($path)) {
                        $files[] = ['browser' => $browser, 'path' => $path];
                    }
                }
            }
        }

        return $files;
    }

    public function collectInventory(): array
    {
        $ports = [];

        foreach (['tcp' => ['-H', '-t', '-l', '-n', '-p'], 'udp' => ['-H', '-u', '-l', '-n', '-p']] as $protocol => $flags) {
            $result = $this->runner->run(['ss', ...$flags]);

            if (!$result->isSuccessful()) {
                throw new PlatformException('Gagal membaca port (ss): ' . trim($result->stderr));
            }

            array_push($ports, ...self::parseSsListening($result->stdout, $protocol));
        }

        return ['ports' => $ports, 'services' => $this->listServices()];
    }

    public function collectAssets(): array
    {
        $dmi = static fn (string $f): ?string => ($v = @file_get_contents('/sys/class/dmi/id/' . $f)) !== false ? trim($v) : null;
        $cpuinfo = (string) @file_get_contents('/proc/cpuinfo');
        $meminfo = (string) @file_get_contents('/proc/meminfo');
        $release = (string) @file_get_contents('/etc/os-release');

        $disks = [];
        try {
            $lsblk = $this->runner->run(['lsblk', '-dnb', '-e', '7,11', '-o', 'MODEL,SIZE']);
        } catch (\RuntimeException) {
            $lsblk = null; // lsblk tidak tersedia
        }
        foreach ($lsblk !== null && $lsblk->isSuccessful() ? (preg_split('/\R/', trim($lsblk->stdout)) ?: []) : [] as $line) {
            if (preg_match('/^(.*?)\s+(\d+)$/', trim($line), $m) === 1) {
                $disks[] = ['model' => trim($m[1]) !== '' ? trim($m[1]) : 'Disk', 'size_gb' => (int) round((float) $m[2] / 1073741824)];
            }
        }

        $hardware = AssetNormalizer::hardware([
            'manufacturer' => $dmi('sys_vendor'), 'model' => $dmi('product_name'), 'serial' => $dmi('product_serial'),
            'cpu' => preg_match('/^model name\s*:\s*(.+)$/m', $cpuinfo, $c) === 1 ? $c[1] : null,
            'cpu_cores' => null,
            'cpu_threads' => preg_match_all('/^processor\s*:/m', $cpuinfo) ?: null,
            'memory_mb' => preg_match('/^MemTotal:\s+(\d+) kB/m', $meminfo, $mm) === 1 ? intdiv((int) $mm[1], 1024) : null,
            'os' => preg_match('/^PRETTY_NAME="?([^"\n]+)"?$/m', $release, $o) === 1 ? $o[1] : null,
            'os_version' => php_uname('r'),
            'os_build' => null,
            'disks' => $disks,
        ]);

        return ['hardware' => $hardware, 'software' => AssetNormalizer::software($this->listPackages())];
    }

    /** @return list<array{name: string, version: string|null, publisher: null, installed: null}> */
    private function listPackages(): array
    {
        foreach ([['dpkg-query', '-W', '-f=${Package}\t${Version}\n'], ['rpm', '-qa', '--qf', '%{NAME}\t%{VERSION}-%{RELEASE}\n']] as $command) {
            try {
                $result = $this->runner->run($command);
            } catch (\RuntimeException) {
                continue; // perintah tidak ada
            }

            if ($result->isSuccessful()) {
                return self::parsePackages($result->stdout);
            }
        }

        return [];
    }

    /** @return list<array{name: string, version: string|null, publisher: null, installed: null}> */
    public static function parsePackages(string $output): array
    {
        $rows = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            [$name, $version] = array_pad(explode("\t", $line, 2), 2, null);
            if (is_string($name) && $name !== '') {
                $rows[] = ['name' => $name, 'version' => $version, 'publisher' => null, 'installed' => null];
            }
        }

        return $rows;
    }

    /** @return list<array{protocol: string, address: string, port: int, process: string|null}> */
    public static function parseSsListening(string $output, string $protocol): array
    {
        $rows = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            // "LISTEN 0 4096 0.0.0.0:22 0.0.0.0:* users:(("sshd",pid=1,fd=3))"; alamat bisa "[::]:22", "*:22", "127.0.0.53%lo:53"
            if (preg_match('/^\s*\S+\s+\d+\s+\d+\s+(\S+):(\d+)\s+\S+(?:\s+users:\(\("([^"]*)")?/', $line, $m) !== 1) {
                continue;
            }

            $address = $m[1] === '*' ? '0.0.0.0' : trim(explode('%', $m[1])[0], '[]');

            if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $rows[] = ['protocol' => $protocol, 'address' => $address, 'port' => (int) $m[2], 'process' => ($m[3] ?? '') !== '' ? $m[3] : null];
            }
        }

        return $rows;
    }

    /** @return list<array{name: string, display: string|null, state: string, start: string|null}> */
    private function listServices(): array
    {
        try {
            $units = $this->runner->run(['systemctl', 'list-units', '--type=service', '--all', '--plain', '--no-legend', '--no-pager']);
            $files = $this->runner->run(['systemctl', 'list-unit-files', '--type=service', '--plain', '--no-legend', '--no-pager']);
        } catch (\RuntimeException) {
            return []; // tanpa systemd
        }

        return $units->isSuccessful() ? self::parseSystemctl($units->stdout, $files->isSuccessful() ? $files->stdout : '') : [];
    }

    /** @return list<array{name: string, display: string|null, state: string, start: string|null}> */
    public static function parseSystemctl(string $units, string $unitFiles): array
    {
        $enabled = [];

        foreach (preg_split('/\R/', trim($unitFiles)) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];

            if (count($parts) >= 2) {
                $enabled[$parts[0]] = $parts[1];
            }
        }

        $services = [];

        foreach (preg_split('/\R/', trim($units)) ?: [] as $line) {
            // UNIT LOAD ACTIVE SUB DESCRIPTION...
            if (preg_match('/^\s*(\S+\.service)\s+(\S+)\s+(\S+)\s+(\S+)\s*(.*)$/', $line, $m) !== 1 || $m[2] === 'not-found') {
                continue;
            }

            $services[] = [
                'name' => $m[1],
                'display' => trim($m[5]) !== '' ? trim($m[5]) : null,
                'state' => $m[4] === 'running' ? 'Running' : ucfirst($m[4]),
                'start' => match ($enabled[$m[1]] ?? null) { 'enabled' => 'Auto', 'disabled', 'masked' => 'Disabled', null => null, default => 'Manual' },
            ];
        }

        return $services;
    }

    /** journald: hanya prioritas err ke atas (error, critical, alert, emergency) sejak waktu tertentu, maksimal 500 baris. */
    public function readEventLog(int $afterUnix): array
    {
        try {
            $result = $this->runner->run(['journalctl', '-p', 'err', '--since', '@' . max(0, $afterUnix), '-o', 'json', '--no-pager', '-n', '500'], null, [], 30);
        } catch (\RuntimeException) {
            return []; // tanpa systemd/journald
        }

        if (!$result->isSuccessful()) {
            throw new PlatformException('Gagal membaca journald: ' . trim($result->stderr));
        }

        return self::parseJournalJson($result->stdout);
    }

    /**
     * Satu objek JSON per baris. Dipetakan ke bentuk yang sama dengan Windows Event Log: level 1 = kritis (prioritas 0-2), 2 = error.
     * Nomor record = waktu journal dalam mikrodetik (naik monoton), dipakai sebagai kursor dan kunci deduplikasi.
     *
     * @return list<array{log: string, provider: string, id: int, level: int, time: int, record: int, message: string}>
     */
    public static function parseJournalJson(string $output): array
    {
        $events = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $row = json_decode($line, true);

            if (!is_array($row) || !is_numeric($row['__REALTIME_TIMESTAMP'] ?? null)) {
                continue;
            }

            $micros = (int) $row['__REALTIME_TIMESTAMP'];
            $provider = (string) ($row['SYSLOG_IDENTIFIER'] ?? $row['_COMM'] ?? 'journald');
            $message = $row['MESSAGE'] ?? '';

            $events[] = [
                'log' => 'System',
                'provider' => mb_substr($provider, 0, 200),
                'id' => 0,
                'level' => (int) ($row['PRIORITY'] ?? 3) <= 2 ? 1 : 2,
                'time' => intdiv($micros, 1_000_000),
                'record' => $micros,
                'message' => is_string($message) ? mb_substr($message, 0, 1000) : '(pesan biner)',
            ];
        }

        return $events;
    }

    public function terminateProcess(int $pid): void
    {
        $result = $this->runner->run(['kill', '-TERM', (string) $pid]);

        if (!$result->isSuccessful()) {
            throw new PlatformException('Gagal mengakhiri proses: ' . trim($result->stderr));
        }
    }

    public function showMessage(string $message): void
    {
        $result = $this->runner->run(['wall'], "NetWatch: {$message}\n");

        if (!$result->isSuccessful()) {
            throw new PlatformException('Gagal mengirim pesan dengan wall: ' . trim($result->stderr));
        }
    }

    public function inspectBlockPolicy(): array
    {
        return ['hosts' => [], 'browser' => [], 'ips' => []]; // Linux belum menerapkan kebijakan apa pun.
    }

    public function applyBlockPolicy(array $domains, array $ips): void
    {
        if ($domains === [] && $ips === []) {
            return; // Tidak ada yang perlu dibersihkan: Linux belum pernah menerapkan kebijakan.
        }

        throw new PlatformException('Penerapan kebijakan blokir belum didukung di Linux.');
    }

    public function powerAction(string $action, int $delaySeconds): void
    {
        $minutes = max(1, (int) ceil($delaySeconds / 60));
        $flag = $action === 'restart' ? '-r' : '-h';

        $result = $this->runner->run(['shutdown', $flag, "+{$minutes}", 'NetWatch: dijadwalkan oleh administrator']);

        if (!$result->isSuccessful()) {
            throw new PlatformException('Gagal menjadwalkan daya: ' . trim($result->stderr));
        }
    }

    /**
     * @return array{0: int, 1: int} [total MB, terpakai MB]
     */
    public static function parseMeminfo(string $meminfo): array
    {
        $values = [];

        foreach (explode("\n", $meminfo) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $m) === 1) {
                $values[$m[1]] = (int) $m[2];
            }
        }

        $totalKb = $values['MemTotal'] ?? 0;
        $availableKb = $values['MemAvailable'] ?? ($values['MemFree'] ?? 0);

        return [intdiv($totalKb, 1024), intdiv(max(0, $totalKb - $availableKb), 1024)];
    }

    /**
     * Hitung persen CPU dari dua cuplikan baris "cpu" di /proc/stat.
     *
     * @return float|null null bila cuplikan tidak valid
     */
    public static function cpuPercentBetween(string $before, string $after): ?float
    {
        $first = self::parseCpuLine($before);
        $second = self::parseCpuLine($after);

        if ($first === null || $second === null) {
            return null;
        }

        $totalDelta = $second['total'] - $first['total'];
        $idleDelta = $second['idle'] - $first['idle'];

        if ($totalDelta <= 0) {
            return 0.0;
        }

        return round(max(0.0, min(100.0, (1 - $idleDelta / $totalDelta) * 100)), 2);
    }

    /** @return list<ProcessInfo> */
    public static function parsePsOutput(string $output): array
    {
        $processes = [];

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\S+)\s+(\d+)\s+(\d+)\s+(.+?)\s*$/', $line, $m) !== 1) {
                continue;
            }

            $processes[] = new ProcessInfo((int) $m[1], $m[5], (float) $m[4], round(((int) $m[3]) / 1024, 1), $m[2]);
        }

        return $processes;
    }

    /** @return array{total: int, idle: int}|null */
    private static function parseCpuLine(string $stat): ?array
    {
        if (preg_match('/^cpu\s+(.+)$/m', $stat, $m) !== 1) {
            return null;
        }

        $fields = array_map('intval', preg_split('/\s+/', trim($m[1])) ?: []);

        if (count($fields) < 5) {
            return null;
        }

        // idle + iowait dianggap waktu menganggur.
        return ['total' => array_sum($fields), 'idle' => $fields[3] + ($fields[4] ?? 0)];
    }

    private function sampleCpuPercent(): float
    {
        $before = (string) @file_get_contents('/proc/stat');
        usleep(self::CPU_SAMPLE_US);
        $after = (string) @file_get_contents('/proc/stat');

        return self::cpuPercentBetween($before, $after) ?? 0.0;
    }

    private function loggedInUsers(): ?string
    {
        try {
            $result = $this->runner->run(['who'], null, [], 5);
        } catch (RuntimeException) {
            return null;
        }

        if (!$result->isSuccessful()) {
            return null;
        }

        $users = [];

        foreach (explode("\n", $result->stdout) as $line) {
            $name = strtok(trim($line), ' ');

            if ($name !== false && $name !== '') {
                $users[$name] = true;
            }
        }

        return $users === [] ? null : implode(', ', array_keys($users));
    }
}
