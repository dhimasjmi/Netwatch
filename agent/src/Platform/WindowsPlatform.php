<?php

declare(strict_types=1);

namespace NetWatch\Agent\Platform;

use NetWatch\Agent\Inventory\AssetNormalizer;
use NetWatch\Agent\Dto\NetworkInterfaceStat;
use NetWatch\Agent\Dto\ProcessInfo;
use NetWatch\Agent\Dto\SystemMetrics;
use NetWatch\Agent\Policy\HostsFile;
use NetWatch\Agent\Support\ProcessRunner;
use RuntimeException;
use Throwable;

/**
 * Implementasi Windows. Agent berjalan sebagai SYSTEM lewat Scheduled Task, sehingga bisa
 * membaca semua proses dan mengakhiri proses pengguna mana pun.
 * Telemetri diambil lewat PowerShell/CIM (wmic sudah dihapus di Windows 11 terbaru).
 */
final class WindowsPlatform implements PlatformInterface
{
    private const POWERSHELL_TIMEOUT = 30;
    private const UTF8_PREAMBLE = '[Console]::OutputEncoding = [System.Text.Encoding]::UTF8; $ErrorActionPreference = "Stop";';

    private const METRICS_SCRIPT = <<<'PS'
$os = Get-CimInstance -ClassName Win32_OperatingSystem
$cpu = Get-CimInstance -ClassName Win32_Processor | Measure-Object -Property LoadPercentage -Average
$disk = Get-CimInstance -ClassName Win32_LogicalDisk -Filter ("DeviceID='{0}'" -f $env:SystemDrive)
$user = (Get-CimInstance -ClassName Win32_ComputerSystem).UserName
# Dikumpulkan di skrip yang sama agar tidak menambah proses PowerShell per heartbeat.
try {
    $nics = @(Get-NetAdapter -Physical | Where-Object Status -eq 'Up' | ForEach-Object {
        $st = Get-NetAdapterStatistics -Name $_.Name
        [pscustomobject]@{ name = $_.Name; rx = [int64]$st.ReceivedBytes; tx = [int64]$st.SentBytes; speed = [int64]$_.Speed }
    })
} catch { $nics = @() }
[pscustomobject]@{
    cpu_percent      = [double]$cpu.Average
    memory_total_kb  = [int64]$os.TotalVisibleMemorySize
    memory_free_kb   = [int64]$os.FreePhysicalMemory
    disk_total_bytes = [int64]$disk.Size
    disk_free_bytes  = [int64]$disk.FreeSpace
    uptime_seconds   = [int64]((Get-Date) - $os.LastBootUpTime).TotalSeconds
    logged_in_user   = $user
    interfaces       = $nics
} | ConvertTo-Json -Compress
PS;

    /**
     * Data (domain/IP yang sudah divalidasi) dikirim lewat stdin sebagai JSON, bukan disisipkan ke teks skrip,
     * sehingga tidak ada celah penyisipan perintah.
     */
    /**
     * Kepemilikan dicatat di HKLM\SOFTWARE\NetWatch\Policy (Domains, Ips, AddedChrome, AddedEdge), terpisah dari state agent,
     * sehingga tetap bisa dibersihkan walau state hilang. Entri URLBlocklist milik pihak lain (GPO/MDM/admin) tidak disentuh:
     * hanya entri "nomor=domain" yang dulu kita tulis sendiri, dan nilainya masih sama, yang dihapus.
     */
    private const BLOCK_POLICY_SCRIPT = <<<'PS'
$ErrorActionPreference = 'Stop'
$data = [Console]::In.ReadToEnd() | ConvertFrom-Json
$domains = @($data.domains | Where-Object { $_ })
$ips = @($data.ips | Where-Object { $_ })
$own = 'HKLM:\SOFTWARE\NetWatch\Policy'
$vendors = [ordered]@{ Chrome = 'HKLM:\SOFTWARE\Policies\Google\Chrome\URLBlocklist'; Edge = 'HKLM:\SOFTWARE\Policies\Microsoft\Edge\URLBlocklist' }

function Get-Owned([string]$name) {
    if (-not (Test-Path $own)) { return @() }
    return @((Get-ItemProperty -Path $own -Name $name -ErrorAction SilentlyContinue).$name | Where-Object { $_ })
}
function Set-Owned([string]$name, [string[]]$values) {
    if ($values.Count -eq 0) {
        if (Test-Path $own) { Remove-ItemProperty -Path $own -Name $name -ErrorAction SilentlyContinue }
        return
    }
    if (-not (Test-Path $own)) { New-Item -Path $own -Force | Out-Null }
    New-ItemProperty -Path $own -Name $name -Value $values -PropertyType MultiString -Force | Out-Null
}

# 1. Windows Firewall: satu aturan keluar yang memblokir seluruh IP/rentang. Hanya aturan berawalan "NetWatch-" yang disentuh.
Get-NetFirewallRule -Name 'NetWatch-*' -ErrorAction SilentlyContinue | Remove-NetFirewallRule
if ($ips.Count -gt 0) {
    New-NetFirewallRule -Name 'NetWatch-BlockIP' -DisplayName 'NetWatch - Blokir IP' -Direction Outbound -Action Block -Profile Any -RemoteAddress $ips | Out-Null
}

# 2. Kebijakan Chrome dan Edge: URLBlocklist memblokir domain beserta seluruh subdomainnya.
foreach ($vendor in $vendors.Keys) {
    $key = $vendors[$vendor]
    foreach ($entry in (Get-Owned "Added$vendor")) {
        $sep = $entry.IndexOf('=')
        if ($sep -lt 1 -or -not (Test-Path $key)) { continue }
        $propName = $entry.Substring(0, $sep)
        $propValue = $entry.Substring($sep + 1)
        $current = (Get-ItemProperty -Path $key -Name $propName -ErrorAction SilentlyContinue).$propName
        if ($current -eq $propValue) { Remove-ItemProperty -Path $key -Name $propName -Force }
    }

    $added = @()
    if ($domains.Count -gt 0) {
        if (-not (Test-Path $key)) { New-Item -Path $key -Force | Out-Null }
        $props = @((Get-Item -Path $key).Property)
        $existing = @($props | ForEach-Object { [string](Get-ItemProperty -Path $key -Name $_).$_ })
        $numbers = @($props | Where-Object { $_ -match '^\d+$' } | ForEach-Object { [int]$_ })
        $next = if ($numbers.Count -gt 0) { ($numbers | Measure-Object -Maximum).Maximum + 1 } else { 1 }
        foreach ($domain in $domains) {
            if ($existing -contains [string]$domain) { continue }
            New-ItemProperty -Path $key -Name ([string]$next) -Value ([string]$domain) -PropertyType String -Force | Out-Null
            $added += ('{0}={1}' -f $next, $domain)
            $next++
        }
    } elseif (Test-Path $key) {
        $item = Get-Item -Path $key
        if ($item.ValueCount -eq 0 -and $item.SubKeyCount -eq 0) { Remove-Item -Path $key -Force }
    }
    Set-Owned "Added$vendor" ([string[]]$added)
}

Set-Owned 'Domains' ([string[]]$domains)
Set-Owned 'Ips' ([string[]]$ips)
if ($domains.Count -eq 0 -and $ips.Count -eq 0 -and (Test-Path $own) -and (Get-Item -Path $own).ValueCount -eq 0) { Remove-Item -Path $own -Force }
PS;

    /** Kondisi terpasang: IP hanya bila aturan firewall NetWatch ada dan aktif; domain hanya bila ada di KEDUA browser. */
    private const INSPECT_POLICY_SCRIPT = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue'
$own = 'HKLM:\SOFTWARE\NetWatch\Policy'
$vendors = @('HKLM:\SOFTWARE\Policies\Google\Chrome\URLBlocklist', 'HKLM:\SOFTWARE\Policies\Microsoft\Edge\URLBlocklist')
function Get-Owned([string]$name) {
    if (-not (Test-Path $own)) { return @() }
    return @((Get-ItemProperty -Path $own -Name $name).$name | Where-Object { $_ })
}
$domains = @(Get-Owned 'Domains')
$ips = @(Get-Owned 'Ips')
$rule = Get-NetFirewallRule -Name 'NetWatch-BlockIP'
if ($null -eq $rule -or [string]$rule.Enabled -ne 'True') { $ips = @() }
$values = @()
foreach ($key in $vendors) {
    $set = @()
    if (Test-Path $key) { $set = @((Get-Item -Path $key).Property | ForEach-Object { [string](Get-ItemProperty -Path $key -Name $_).$_ }) }
    $values += , $set
}
$present = @($domains | Where-Object { $d = [string]$_; ($values[0] -contains $d) -and ($values[1] -contains $d) })
@{ browser = $present; ips = $ips } | ConvertTo-Json -Compress -Depth 4
PS;

    private const PROCESSES_SCRIPT = <<<'PS'
try { $procs = Get-Process -IncludeUserName } catch { $procs = Get-Process }
$list = foreach ($p in $procs) {
    [pscustomobject]@{
        pid         = $p.Id
        name        = $p.ProcessName
        cpu_seconds = [math]::Round([double]$p.CPU, 1)
        memory_mb   = [math]::Round($p.WorkingSet64 / 1MB, 1)
        user        = $p.UserName
    }
}
ConvertTo-Json -InputObject @($list) -Compress
PS;

    private const OS_VERSION_SCRIPT = '$os = Get-CimInstance -ClassName Win32_OperatingSystem; "$($os.Caption) $($os.Version)"';

    private const MAC_SCRIPT = '(Get-NetAdapter -Physical | Where-Object Status -eq "Up" | Select-Object -First 1).MacAddress';


    /** Koneksi TCP keluar + nama proses + nama domain dari cache DNS klien. */
    private const CONNECTIONS_SCRIPT = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue'
$dns = @{}
Get-DnsClientCache | Where-Object { ($_.Type -eq 1 -or $_.Type -eq 28) -and $_.Data } | ForEach-Object { $dns[[string]$_.Data] = [string]$_.Entry }
$procs = @{}
Get-Process | ForEach-Object { $procs[[int]$_.Id] = $_.ProcessName }
$rows = @(Get-NetTCPConnection -State Established | ForEach-Object {
    [pscustomobject]@{ ip = [string]$_.RemoteAddress; port = [int]$_.RemotePort; process = $procs[[int]$_.OwningProcess]; host = $dns[[string]$_.RemoteAddress] }
})
@{ connections = $rows } | ConvertTo-Json -Compress -Depth 4
PS;

    /** Port listening (TCP dan UDP) dengan nama proses, plus layanan Windows. */
    private const INVENTORY_SCRIPT = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue'
$procs = @{}
Get-Process | ForEach-Object { $procs[[int]$_.Id] = $_.ProcessName }
$ports = @()
$ports += @(Get-NetTCPConnection -State Listen | ForEach-Object {
    [pscustomobject]@{ protocol = 'tcp'; address = [string]$_.LocalAddress; port = [int]$_.LocalPort; process = $procs[[int]$_.OwningProcess] }
})
$ports += @(Get-NetUDPEndpoint | Where-Object { $_.LocalPort -lt 49152 } | ForEach-Object {
    [pscustomobject]@{ protocol = 'udp'; address = [string]$_.LocalAddress; port = [int]$_.LocalPort; process = $procs[[int]$_.OwningProcess] }
})
$services = @(Get-CimInstance Win32_Service | ForEach-Object {
    [pscustomobject]@{ name = [string]$_.Name; display = [string]$_.DisplayName; state = [string]$_.State; start = [string]$_.StartMode }
})
@{ ports = $ports; services = $services } | ConvertTo-Json -Compress -Depth 4
PS;

    /** Ringkasan perangkat keras dan daftar program terpasang (registri Uninstall 64/32-bit, tanpa komponen sistem dan pembaruan). */
    private const ASSETS_SCRIPT = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue'
$cs = Get-CimInstance Win32_ComputerSystem
$bios = Get-CimInstance Win32_BIOS
$os = Get-CimInstance Win32_OperatingSystem
$cpu = @(Get-CimInstance Win32_Processor)
$disks = @(Get-CimInstance Win32_DiskDrive | ForEach-Object { [pscustomobject]@{ model = [string]$_.Model; size_gb = [math]::Round([double]$_.Size / 1GB, 0) } })
$hardware = [pscustomobject]@{
    manufacturer = [string]$cs.Manufacturer; model = [string]$cs.Model; serial = [string]$bios.SerialNumber
    cpu = [string]$cpu[0].Name; cpu_cores = [int](($cpu | Measure-Object NumberOfCores -Sum).Sum); cpu_threads = [int](($cpu | Measure-Object NumberOfLogicalProcessors -Sum).Sum)
    memory_mb = [int64]([double]$cs.TotalPhysicalMemory / 1MB)
    os = [string]$os.Caption; os_version = [string]$os.Version; os_build = [string]$os.BuildNumber
    disks = $disks
}
$keys = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*', 'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*'
$software = @(Get-ItemProperty $keys | Where-Object { $_.DisplayName -and $_.SystemComponent -ne 1 -and -not $_.ParentKeyName -and $_.DisplayName -notmatch '^(Update for|Security Update|Hotfix)' } | ForEach-Object {
    [pscustomobject]@{ name = [string]$_.DisplayName; version = [string]$_.DisplayVersion; publisher = [string]$_.Publisher; installed = [string]$_.InstallDate }
})
@{ hardware = $hardware; software = $software } | ConvertTo-Json -Compress -Depth 5
PS;

    /** Event Log: error/kritis dari System dan Application, plus warning dari penyedia jaringan/hardware tertentu. Waktu awal lewat stdin. */
    private const EVENT_LOG_SCRIPT = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue'
$data = [Console]::In.ReadToEnd() | ConvertFrom-Json
$start = [DateTimeOffset]::FromUnixTimeSeconds([int64]$data.after).LocalDateTime
$warnProviders = 'Tcpip','Microsoft-Windows-Dhcp-Client','Microsoft-Windows-DNS-Client','Microsoft-Windows-NetworkProfile','Microsoft-Windows-WLAN-AutoConfig','disk','Ntfs','Microsoft-Windows-WHEA-Logger','Microsoft-Windows-Kernel-Power'
$found = @()
foreach ($log in 'System','Application') {
    $found += @(Get-WinEvent -FilterHashtable @{ LogName = $log; Level = 1,2; StartTime = $start } -MaxEvents 300)
    if ($log -eq 'System') {
        foreach ($provider in $warnProviders) {
            $found += @(Get-WinEvent -FilterHashtable @{ LogName = $log; Level = 3; ProviderName = $provider; StartTime = $start } -MaxEvents 50)
        }
    }
}
$rows = @($found | Where-Object { $_ } | ForEach-Object {
    $message = ''
    if ($_.Message) { $message = ($_.Message -split "`r?`n")[0] }
    if ($message.Length -gt 300) { $message = $message.Substring(0, 300) }
    [pscustomobject]@{
        log = [string]$_.LogName; provider = [string]$_.ProviderName; id = [int]$_.Id; level = [int]$_.Level
        time = [int64]([DateTimeOffset]$_.TimeCreated).ToUnixTimeSeconds(); record = [int64]$_.RecordId; message = $message
    }
})
@{ events = $rows } | ConvertTo-Json -Compress -Depth 4
PS;

    public function __construct(private readonly ProcessRunner $runner = new ProcessRunner())
    {
    }

    public function family(): string
    {
        return 'windows';
    }

    public function hostname(): string
    {
        return (string) gethostname();
    }

    public function osVersion(): string
    {
        try {
            return mb_substr(trim($this->powershell(self::OS_VERSION_SCRIPT)), 0, 100);
        } catch (Throwable) {
            return mb_substr(php_uname('s') . ' ' . php_uname('r'), 0, 100);
        }
    }

    public function macAddress(): ?string
    {
        try {
            $mac = strtolower(str_replace('-', ':', trim($this->powershell(self::MAC_SCRIPT))));
        } catch (Throwable) {
            return null;
        }

        return preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac) === 1 ? $mac : null;
    }

    /** @var list<NetworkInterfaceStat> */
    private array $lastInterfaces = [];

    public function collectMetrics(): SystemMetrics
    {
        $json = $this->powershell(self::METRICS_SCRIPT);
        $this->lastInterfaces = self::parseInterfacesJson($json);

        return self::parseMetricsJson($json);
    }

    /** Memakai hasil collectMetrics() terakhir (satu panggilan PowerShell per heartbeat). */
    public function collectNetworkInterfaces(): array
    {
        return $this->lastInterfaces;
    }

    /** @return list<NetworkInterfaceStat> */
    public static function parseInterfacesJson(string $json): array
    {
        $data = json_decode(ltrim($json, "\xEF\xBB\xBF \t\r\n"), true);
        $rows = is_array($data) && is_array($data['interfaces'] ?? null) ? $data['interfaces'] : [];

        // Satu elemen bisa terserialisasi sebagai objek tunggal, bukan array.
        if ($rows !== [] && !array_is_list($rows) && isset($rows['name'])) {
            $rows = [$rows];
        }

        $stats = [];

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['name'], $row['rx'], $row['tx'])) {
                $speed = isset($row['speed']) ? (int) $row['speed'] : null;
                $stats[] = new NetworkInterfaceStat((string) $row['name'], (int) $row['rx'], (int) $row['tx'], $speed);
            }
        }

        return $stats;
    }

    public function listProcesses(): array
    {
        return self::parseProcessesJson($this->powershell(self::PROCESSES_SCRIPT));
    }

    public function listConnections(): array
    {
        return self::parseConnectionsJson($this->powershell(self::CONNECTIONS_SCRIPT));
    }

    /** @return list<array{ip: string, port: int|null, process: string|null, host: string|null}> */
    public static function parseConnectionsJson(string $json): array
    {
        $data = json_decode(ltrim($json, "\xEF\xBB\xBF \t\r\n"), true);
        $rows = is_array($data) && is_array($data['connections'] ?? null) ? $data['connections'] : [];

        if ($rows !== [] && !array_is_list($rows) && isset($rows['ip'])) {
            $rows = [$rows];
        }

        $result = [];

        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['ip'] ?? null) && filter_var($row['ip'], FILTER_VALIDATE_IP) !== false) {
                $result[] = [
                    'ip' => strtolower($row['ip']),
                    'port' => isset($row['port']) && is_numeric($row['port']) ? (int) $row['port'] : null,
                    'process' => is_string($row['process'] ?? null) && $row['process'] !== '' ? mb_substr($row['process'], 0, 100) : null,
                    'host' => is_string($row['host'] ?? null) && $row['host'] !== '' ? mb_substr($row['host'], 0, 253) : null,
                ];
            }
        }

        return $result;
    }

    public function browserHistoryFiles(): array
    {
        $drive = preg_match('/^[A-Za-z]:$/', (string) getenv('SystemDrive')) === 1 ? (string) getenv('SystemDrive') : 'C:';
        $files = [];

        foreach (['chrome' => 'Google/Chrome', 'edge' => 'Microsoft/Edge'] as $browser => $folder) {
            foreach (glob("{$drive}/Users/*/AppData/Local/{$folder}/User Data/*/History") ?: [] as $path) {
                // Hanya profil nyata (Default, Profile N); folder lain seperti "System Profile" dilewati.
                if (preg_match('#/(Default|Profile \d+)/History$#', $path) === 1 && is_file($path)) {
                    $files[] = ['browser' => $browser, 'path' => $path];
                }
            }
        }

        return $files;
    }

    public function collectInventory(): array
    {
        return self::parseInventoryJson($this->powershell(self::INVENTORY_SCRIPT));
    }

    /**
     * @return array{ports: list<array{protocol: string, address: string, port: int, process: string|null}>, services: list<array{name: string, display: string|null, state: string, start: string|null}>}
     */
    public static function parseInventoryJson(string $json): array
    {
        $data = self::decode($json);
        $ports = [];
        $services = [];

        foreach (self::asList($data['ports'] ?? [], 'port') as $row) {
            if (
                is_array($row) && isset($row['protocol'], $row['address'], $row['port'])
                && in_array(strtolower((string) $row['protocol']), ['tcp', 'udp'], true)
                && filter_var(explode('%', (string) $row['address'])[0], FILTER_VALIDATE_IP) !== false
                && (int) $row['port'] >= 1 && (int) $row['port'] <= 65535
            ) {
                $ports[] = [
                    'protocol' => strtolower((string) $row['protocol']),
                    'address' => (string) $row['address'],
                    'port' => (int) $row['port'],
                    'process' => is_string($row['process'] ?? null) && $row['process'] !== '' ? $row['process'] : null,
                ];
            }
        }

        foreach (self::asList($data['services'] ?? [], 'name') as $row) {
            if (is_array($row) && isset($row['name'], $row['state']) && $row['name'] !== '') {
                $services[] = [
                    'name' => (string) $row['name'],
                    'display' => is_string($row['display'] ?? null) && $row['display'] !== '' ? $row['display'] : null,
                    'state' => (string) $row['state'],
                    'start' => is_string($row['start'] ?? null) && $row['start'] !== '' ? $row['start'] : null,
                ];
            }
        }

        return ['ports' => $ports, 'services' => $services];
    }

    public function collectAssets(): array
    {
        return self::parseAssetsJson($this->powershell(self::ASSETS_SCRIPT));
    }

    /**
     * @return array{hardware: array<string, mixed>|null, software: list<array{name: string, version: string|null, publisher: string|null, installed: string|null}>|null}
     */
    public static function parseAssetsJson(string $json): array
    {
        $data = self::decode($json);
        $hardware = null;

        if (is_array($data['hardware'] ?? null)) {
            $h = $data['hardware'];
            $hardware = AssetNormalizer::hardware([
                'manufacturer' => $h['manufacturer'] ?? null, 'model' => $h['model'] ?? null, 'serial' => $h['serial'] ?? null,
                'cpu' => $h['cpu'] ?? null, 'cpu_cores' => $h['cpu_cores'] ?? null, 'cpu_threads' => $h['cpu_threads'] ?? null,
                'memory_mb' => $h['memory_mb'] ?? null,
                'os' => $h['os'] ?? null, 'os_version' => $h['os_version'] ?? null, 'os_build' => $h['os_build'] ?? null,
                'disks' => self::asList($h['disks'] ?? [], 'model'),
            ]);
        }

        $software = [];
        foreach (self::asList($data['software'] ?? [], 'name') as $row) {
            if (is_array($row)) {
                $software[] = $row;
            }
        }

        return ['hardware' => $hardware, 'software' => AssetNormalizer::software($software)];
    }

    public function readEventLog(int $afterUnix): array
    {
        return self::parseEventLogJson($this->powershell(self::EVENT_LOG_SCRIPT, json_encode(['after' => $afterUnix], JSON_THROW_ON_ERROR)));
    }

    /** @return list<array{log: string, provider: string, id: int, level: int, time: int, record: int, message: string}> */
    public static function parseEventLogJson(string $json): array
    {
        $json = trim($json);

        if ($json === '') {
            return []; // tidak ada event yang cocok: PowerShell tidak mengeluarkan apa pun
        }

        $events = [];

        foreach (self::asList(self::decode($json)['events'] ?? [], 'provider') as $row) {
            if (is_array($row) && isset($row['log'], $row['provider'], $row['id'], $row['level'], $row['time'], $row['record']) && in_array($row['log'], ['System', 'Application'], true)) {
                $events[] = [
                    'log' => (string) $row['log'], 'provider' => (string) $row['provider'], 'id' => (int) $row['id'], 'level' => (int) $row['level'],
                    'time' => (int) $row['time'], 'record' => (int) $row['record'], 'message' => (string) ($row['message'] ?? ''),
                ];
            }
        }

        return $events;
    }

    /** ConvertTo-Json menulis satu elemen sebagai objek tunggal, bukan array. */
    private static function asList(mixed $value, string $marker): array
    {
        if (!is_array($value)) {
            return [];
        }

        return $value !== [] && !array_is_list($value) && isset($value[$marker]) ? [$value] : $value;
    }

    public function terminateProcess(int $pid): void
    {
        $result = $this->runner->run(['taskkill', '/PID', (string) $pid, '/F']);

        if (!$result->isSuccessful()) {
            throw new PlatformException('Gagal mengakhiri proses: ' . trim($result->stdout . ' ' . $result->stderr));
        }
    }

    public function showMessage(string $message): void
    {
        try {
            // msg.exe tersedia di Windows Pro/Enterprise/Education, tidak di edisi Home.
            $result = $this->runner->run(['msg', '*', '/TIME:60', $message]);
        } catch (RuntimeException $e) {
            throw new PlatformException('msg.exe tidak tersedia di edisi Windows ini: ' . $e->getMessage());
        }

        if (!$result->isSuccessful()) {
            throw new PlatformException('Gagal menampilkan pesan: ' . trim($result->stdout . ' ' . $result->stderr));
        }
    }

    public function applyBlockPolicy(array $domains, array $ips): void
    {
        $errors = [];

        try {
            if (HostsFile::apply(self::hostsPath(), $domains)) {
                $this->flushDns();
            }
        } catch (Throwable $e) {
            $errors[] = 'hosts: ' . $e->getMessage();
        }

        try {
            $this->powershell(self::BLOCK_POLICY_SCRIPT, json_encode(['domains' => $domains, 'ips' => $ips], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $errors[] = 'firewall/browser: ' . mb_substr($e->getMessage(), 0, 300);
        }

        if ($errors !== []) {
            throw new PlatformException(implode(' | ', $errors));
        }
    }

    public function inspectBlockPolicy(): array
    {
        $hosts = is_file(self::hostsPath()) ? file_get_contents(self::hostsPath()) : '';

        if ($hosts === false) {
            throw new PlatformException('Tidak dapat membaca file hosts.');
        }

        $data = self::decode($this->powershell(self::INSPECT_POLICY_SCRIPT));

        return [
            'hosts' => HostsFile::domainsIn($hosts),
            'browser' => self::stringsOf($data['browser'] ?? []),
            'ips' => self::stringsOf($data['ips'] ?? []),
        ];
    }

    /** @return list<string> terurut; PowerShell mengubah array berisi satu elemen menjadi skalar bila tidak hati-hati */
    private static function stringsOf(mixed $value): array
    {
        $list = array_values(array_unique(array_map('strval', array_filter(is_array($value) ? $value : [$value], 'is_scalar'))));
        $list = array_values(array_filter($list, static fn (string $v): bool => $v !== ''));
        sort($list, SORT_STRING);

        return $list;
    }

    private static function hostsPath(): string
    {
        return (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\drivers\\etc\\hosts';
    }

    private function flushDns(): void
    {
        try {
            $this->runner->run(['ipconfig.exe', '/flushdns'], null, [], 10);
        } catch (RuntimeException) {
            // Hanya mempercepat berlakunya blokir; cache DNS akan kedaluwarsa sendiri.
        }
    }

    public function powerAction(string $action, int $delaySeconds): void
    {
        $result = $this->runner->run([
            'shutdown', $action === 'restart' ? '/r' : '/s',
            '/t', (string) max(1, $delaySeconds),
            '/c', 'NetWatch: dijadwalkan oleh administrator',
        ]);

        if (!$result->isSuccessful()) {
            throw new PlatformException('Gagal menjadwalkan daya: ' . trim($result->stdout . ' ' . $result->stderr));
        }
    }

    /** @throws PlatformException bila JSON tidak valid */
    public static function parseMetricsJson(string $json): SystemMetrics
    {
        $data = self::decode($json);
        $totalKb = (int) ($data['memory_total_kb'] ?? 0);
        $freeKb = (int) ($data['memory_free_kb'] ?? 0);
        $diskTotal = (float) ($data['disk_total_bytes'] ?? 0);
        $diskFree = (float) ($data['disk_free_bytes'] ?? 0);
        $user = isset($data['logged_in_user']) && $data['logged_in_user'] !== '' ? (string) $data['logged_in_user'] : null;

        return new SystemMetrics(
            cpuPercent: (float) ($data['cpu_percent'] ?? 0),
            memoryUsedMb: intdiv(max(0, $totalKb - $freeKb), 1024),
            memoryTotalMb: intdiv($totalKb, 1024),
            diskUsedGb: (int) round(($diskTotal - $diskFree) / 1_073_741_824),
            diskTotalGb: (int) round($diskTotal / 1_073_741_824),
            uptimeSeconds: (int) ($data['uptime_seconds'] ?? 0),
            loggedInUser: $user,
        );
    }

    /**
     * @return list<ProcessInfo>
     *
     * @throws PlatformException bila JSON tidak valid
     */
    public static function parseProcessesJson(string $json): array
    {
        $rows = self::decode($json);

        // ConvertTo-Json mengembalikan objek tunggal (bukan array) bila hanya ada satu elemen.
        if ($rows !== [] && array_is_list($rows) === false && isset($rows['pid'])) {
            $rows = [$rows];
        }

        $processes = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['pid'], $row['name'])) {
                continue;
            }

            $processes[] = new ProcessInfo(
                (int) $row['pid'],
                (string) $row['name'],
                isset($row['cpu_seconds']) ? (float) $row['cpu_seconds'] : null,
                isset($row['memory_mb']) ? (float) $row['memory_mb'] : null,
                isset($row['user']) && $row['user'] !== '' ? (string) $row['user'] : null,
            );
        }

        return $processes;
    }

    /** @return array<mixed> */
    private static function decode(string $json): array
    {
        $json = ltrim($json, "\xEF\xBB\xBF \t\r\n");
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new PlatformException('Keluaran PowerShell bukan JSON yang valid.');
        }

        return $data;
    }

    /**
     * Skrip dikirim lewat -EncodedCommand (Base64 UTF-16LE) agar aman dari masalah quoting.
     *
     * @throws PlatformException
     */
    private function powershell(string $script, ?string $stdin = null): string
    {
        $encoded = base64_encode((string) iconv('UTF-8', 'UTF-16LE', self::UTF8_PREAMBLE . "\n" . $script));

        try {
            $result = $this->runner->run(
                ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', $encoded],
                $stdin,
                [],
                self::POWERSHELL_TIMEOUT,
            );
        } catch (RuntimeException $e) {
            throw new PlatformException($e->getMessage());
        }

        if (!$result->isSuccessful()) {
            throw new PlatformException('PowerShell gagal: ' . trim($result->stderr));
        }

        return $result->stdout;
    }
}
