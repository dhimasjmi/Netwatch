<?php

declare(strict_types=1);

// Tes ringan tanpa Composer/PHPUnit: php tests/run.php
require __DIR__ . '/../src/autoload.php';

use NetWatch\Agent\Browsing\BrowsingReporter;
use NetWatch\Agent\Browsing\BrowsingState;
use NetWatch\Agent\Browsing\ConnectionTracker;
use NetWatch\Agent\Browsing\HistoryReader;
use NetWatch\Agent\Browsing\Sanitizer;
use NetWatch\Agent\Command\CommandExecutor;
use NetWatch\Agent\Command\ProcessReporter;
use NetWatch\Agent\Dto\NetworkInterfaceStat;
use NetWatch\Agent\Dto\ProcessInfo;
use NetWatch\Agent\Platform\LinuxPlatform;
use NetWatch\Agent\Platform\WindowsPlatform;
use NetWatch\Agent\Dto\SystemMetrics;
use NetWatch\Agent\Http\ApiClient;
use NetWatch\Agent\Platform\PlatformInterface;
use NetWatch\Agent\Platform\PlatformException;
use NetWatch\Agent\Policy\BlockPolicy;
use NetWatch\Agent\Winlog\WinlogReporter;
use NetWatch\Agent\Winlog\WinlogState;
use NetWatch\Agent\Policy\HostsFile;
use NetWatch\Agent\Policy\PolicyApplier;
use NetWatch\Agent\Support\Logger;
use NetWatch\Agent\Support\ProtectedProcesses;

final class FakePlatform implements PlatformInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function family(): string { return 'linux'; }
    public function hostname(): string { return 'test'; }
    public function osVersion(): string { return 'test'; }
    public function macAddress(): ?string { return null; }
    public function collectMetrics(): SystemMetrics { return new SystemMetrics(1.0, 1, 1, 1, 1, 1, null); }
    public function collectNetworkInterfaces(): array { return []; }
    public function listProcesses(): array { return [new ProcessInfo(4242, 'notepad.exe', 1.0, 10.0, 'u')]; }
    /** @var list<array{ip: string, port: int|null, process: string|null, host: string|null}> */
    public array $connections = [];
    /** @var list<array{browser: 'chrome'|'edge', path: string}> */
    public array $historyFiles = [];
    public function listConnections(): array { return $this->connections; }
    public function browserHistoryFiles(): array { return $this->historyFiles; }
    public function terminateProcess(int $pid): void { $this->calls[] = "kill:{$pid}"; }
    public function showMessage(string $message): void { $this->calls[] = "msg:{$message}"; }
    public function powerAction(string $action, int $delaySeconds): void { $this->calls[] = "{$action}:{$delaySeconds}"; }

    public ?string $failPolicyWith = null;
    public bool $failInspect = false;
    /** Simulasi cleanup yang tidak tuntas: hosts tidak ikut terhapus. */
    public bool $leaveHostsBehind = false;
    /** @var array{hosts: list<string>, browser: list<string>, ips: list<string>} kondisi "komputer" yang disimulasikan */
    public array $installed = ['hosts' => [], 'browser' => [], 'ips' => []];
    public function applyBlockPolicy(array $domains, array $ips): void
    {
        if ($this->failPolicyWith !== null) {
            throw new PlatformException($this->failPolicyWith);
        }
        $this->calls[] = 'policy:' . implode(',', $domains) . '|' . implode(',', $ips);
        $this->installed = ['hosts' => $this->leaveHostsBehind ? $this->installed['hosts'] : $domains, 'browser' => $domains, 'ips' => $ips];
    }
    public function inspectBlockPolicy(): array
    {
        if ($this->failInspect) {
            throw new PlatformException('PowerShell tidak tersedia');
        }
        return $this->installed;
    }
    public function collectInventory(): array { return $this->inventory; }
    /** @var array{hardware: array<string, mixed>|null, software: list<array<string, mixed>>|null} */
    public array $assets = ['hardware' => null, 'software' => null];
    public function collectAssets(): array { return $this->assets; }
    /** @var array{ports: list<array<string, mixed>>, services: list<array<string, mixed>>} */
    public array $inventory = ['ports' => [], 'services' => []];
    /** @var list<array{log: string, provider: string, id: int, level: int, time: int, record: int, message: string}> */
    public array $eventLog = [];
    public function readEventLog(int $afterUnix): array { return array_values(array_filter($this->eventLog, fn ($e) => $e['time'] > $afterUnix - 1)); }
}

$failures = 0;
function check(string $name, bool $condition): void
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . "  {$name}\n";
    $failures += $condition ? 0 : 1;
}

$platform = new FakePlatform();
$api = new ApiClient('http://127.0.0.1:1', 'x', null, true); // report() tidak dipanggil pada kasus gagal
$executor = new CommandExecutor($platform, new ProcessReporter($platform, $api));

$r = $executor->execute('run_shell', ['cmd' => 'id']);
check('tipe tak dikenal ditolak', !$r->succeeded && $platform->calls === []);

$r = $executor->execute('terminate_process', ['pid' => 4242, 'process_name' => 'lsass.exe']);
check('proses terlindungi ditolak', !$r->succeeded && $platform->calls === []);

$r = $executor->execute('terminate_process', ['pid' => 4242, 'process_name' => 'chrome.exe']);
check('PID yang sudah dipakai proses lain ditolak', !$r->succeeded && $platform->calls === []);

$r = $executor->execute('terminate_process', ['pid' => 1, 'process_name' => 'init']);
check('PID 1 ditolak', !$r->succeeded);

$r = $executor->execute('terminate_process', ['pid' => 'abc', 'process_name' => 'x']);
check('payload tidak valid ditolak', !$r->succeeded);

$r = $executor->execute('send_message', ['message' => '   ']);
check('pesan kosong ditolak', !$r->succeeded);

$r = $executor->execute('send_message', ['message' => 'Halo']);
check('pesan valid dikirim', $r->succeeded && $platform->calls === ['msg:Halo']);

$platform->calls = [];
$executor->execute('restart_computer', ['delay_seconds' => 999999]);
check('delay daya dibatasi 3600 dtk', $platform->calls === ['restart:3600']);

check('normalisasi nama proses', ProtectedProcesses::isProtected('LSASS.EXE') && !ProtectedProcesses::isProtected('notepad.exe'));

try {
    new ApiClient('http://server.local');
    check('HTTP polos ditolak', false);
} catch (InvalidArgumentException) {
    check('HTTP polos ditolak', true);
}


$netDev = "Inter-|   Receive                                                |  Transmit\n face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed\n    lo: 1000 10 0 0 0 0 0 0 1000 10 0 0 0 0 0 0\n  eth0: 5000 50 0 0 0 0 0 0 7000 70 0 0 0 0 0 0\ndocker0: 1 1 0 0 0 0 0 0 1 1 0 0 0 0 0 0\n";
$nics = LinuxPlatform::parseNetDev($netDev);
check('parseNetDev: lo & docker dilewati, eth0 terbaca', count($nics) === 1 && $nics[0]->name === 'eth0' && $nics[0]->rxBytes === 5000 && $nics[0]->txBytes === 7000);

$win = WindowsPlatform::parseInterfacesJson('{"interfaces":{"name":"Wi-Fi","rx":10,"tx":20,"speed":300000000}}');
check('parseInterfacesJson: objek tunggal', count($win) === 1 && $win[0]->name === 'Wi-Fi' && $win[0]->toArray()['speed_bps'] === 300000000);
check('parseInterfacesJson: kosong', WindowsPlatform::parseInterfacesJson('{"interfaces":[]}') === []);

// ---------- kebijakan blokir ----------

$hashA = str_repeat('a', 64);
$hashB = str_repeat('b', 64);

$p = BlockPolicy::fromArray(['hash' => $hashA, 'domains' => ['facebook.com'], 'ips' => ['203.0.113.0/24', '2001:db8::1']], 'netwatch.perusahaan.local');
check('kebijakan valid diterima', $p->domains === ['facebook.com'] && count($p->ips) === 2);

$bad = [
    'hash buruk' => ['hash' => 'xyz', 'domains' => [], 'ips' => []],
    'domain dengan sisipan' => ['hash' => $hashA, 'domains' => ['a.com"; calc'], 'ips' => []],
    'domain tanpa titik' => ['hash' => $hashA, 'domains' => ['localhost'], 'ips' => []],
    'ip sisipan' => ['hash' => $hashA, 'domains' => [], 'ips' => ['1.2.3.4; calc']],
    'cidr lewat batas' => ['hash' => $hashA, 'domains' => [], 'ips' => ['1.2.3.4/33']],
    'bukan teks' => ['hash' => $hashA, 'domains' => [['x']], 'ips' => []],
    'bukan daftar' => ['hash' => $hashA, 'domains' => 'a.com', 'ips' => []],
    'domain induk server' => ['hash' => $hashA, 'domains' => ['perusahaan.local'], 'ips' => []],
    'ip server' => ['hash' => $hashA, 'domains' => [], 'ips' => ['192.0.2.10']],
    'terlalu banyak' => ['hash' => $hashA, 'domains' => array_map(fn ($i) => "a{$i}.com", range(1, 1001)), 'ips' => []],
];
foreach ($bad as $name => $data) {
    $host = $name === 'ip server' ? '192.0.2.10' : 'netwatch.perusahaan.local';
    try {
        BlockPolicy::fromArray($data, $host);
        check("kebijakan ditolak: {$name}", false);
    } catch (InvalidArgumentException) {
        check("kebijakan ditolak: {$name}", true);
    }
}

$hosts = "127.0.0.1 localhost\r\n::1 localhost\r\n";
$rendered = HostsFile::render($hosts, ['facebook.com', 'www.tiktok.com']);
check('hosts: baris asli tetap', str_starts_with($rendered, $hosts));
check('hosts: domain dan www diblokir IPv4+IPv6', str_contains($rendered, "0.0.0.0 facebook.com\r\n:: facebook.com\r\n0.0.0.0 www.facebook.com\r\n:: www.facebook.com\r\n"));
check('hosts: www tidak digandakan', substr_count($rendered, 'www.www.') === 0 && str_contains($rendered, '0.0.0.0 www.tiktok.com'));
check('hosts: idempoten', HostsFile::render($rendered, ['facebook.com', 'www.tiktok.com']) === $rendered);
check('hosts: daftar kosong mengembalikan isi semula', HostsFile::render($rendered, []) === $hosts);
check('hosts: diganti, bukan ditumpuk', substr_count(HostsFile::render($rendered, ['x.com']), '# BEGIN NETWATCH') === 1 && !str_contains(HostsFile::render($rendered, ['x.com']), 'facebook'));
$broken = $hosts . "\r\n# BEGIN NETWATCH (rusak)\r\n0.0.0.0 lama.com\r\n";
check('hosts: blok tanpa penanda akhir dibersihkan', HostsFile::render($broken, []) === $hosts);
check('hosts: berkas kosong', HostsFile::render('', []) === '' && str_starts_with(HostsFile::render('', ['a.com']), '# BEGIN NETWATCH'));

$tmpHosts = tempnam(sys_get_temp_dir(), 'hosts');
file_put_contents($tmpHosts, $hosts);
check('hosts: apply menulis perubahan', HostsFile::apply($tmpHosts, ['facebook.com']) === true && str_contains((string) file_get_contents($tmpHosts), 'facebook.com'));
check('hosts: apply tanpa perubahan mengembalikan false', HostsFile::apply($tmpHosts, ['facebook.com']) === false);
HostsFile::apply($tmpHosts, []);
check('hosts: apply kosong memulihkan berkas', file_get_contents($tmpHosts) === $hosts);
@unlink($tmpHosts);

$stateFile = sys_get_temp_dir() . '/nw-policy-' . uniqid() . '.json';
$now = 1_000_000;
$fake = new FakePlatform();
$mk = function (?FakePlatform $platform = null, ?string $file = null) use (&$now, &$fake, &$stateFile): PolicyApplier {
    return new PolicyApplier($platform ?? $fake, $file ?? $stateFile, 'netwatch.perusahaan.local', new Logger(null), function () use (&$now): int { return $now; });
};
$applier = $mk();
$emptyHash = BlockPolicy::canonicalHash([], []);
$hashA = BlockPolicy::canonicalHash(['facebook.com'], ['203.0.113.9']);
$hashB = BlockPolicy::canonicalHash(['x.com'], []);
$payloadA = ['hash' => $hashA, 'domains' => ['facebook.com'], 'ips' => ['203.0.113.9']];
$payloadB = ['hash' => $hashB, 'domains' => ['x.com'], 'ips' => []];

check('hash kanonik sama dengan server (vektor tetap)', BlockPolicy::canonicalHash(['instagram.com', 'facebook.com', 'facebook.com'], ['203.0.113.0/24'])
    === hash('sha256', '{"d":["facebook.com","instagram.com"],"i":["203.0.113.0/24"]}'));
check('komputer bersih melaporkan hash kebijakan kosong (bukan null)', $applier->report() === ['hash' => $emptyHash, 'error' => null]);

$applier->apply($payloadA);
check('kebijakan diterapkan, diverifikasi, dan hash dilaporkan', $fake->calls === ['policy:facebook.com|203.0.113.9'] && $applier->report() === ['hash' => $hashA, 'error' => null]);

$applier->apply($payloadA);
check('kebijakan yang sudah terpasang tidak diterapkan ulang (idempoten)', count($fake->calls) === 1);

$fake->failPolicyWith = 'hosts: akses ditolak';
$applier->apply($payloadB);
check('kegagalan dilaporkan dan kondisi lama tetap terlapor', $applier->report() === ['hash' => $hashA, 'error' => 'hosts: akses ditolak']);

$fake->failPolicyWith = null;
$callsBefore = count($fake->calls);
$now += 100;
$applier->apply($payloadB);
check('percobaan ulang ditahan 5 menit', count($fake->calls) === $callsBefore);

$now += PolicyApplier::RETRY_AFTER_SECONDS;
$applier->apply($payloadB);
check('percobaan ulang berhasil setelah jeda', count($fake->calls) === $callsBefore + 1 && $applier->report() === ['hash' => $hashB, 'error' => null]);

$applier->apply(['hash' => $hashA, 'domains' => ['perusahaan.local'], 'ips' => []]);
check('kebijakan berbahaya ditolak dan tidak diterapkan', count($fake->calls) === $callsBefore + 1 && str_contains((string) $applier->report()['error'], 'ditolak'));

$applier->nothingToApply();
check('galat lama dihapus saat server tidak meminta apa pun', $applier->report()['error'] === null && $applier->report()['hash'] === $hashB);

// Regresi stale-block: state lokal hilang tetapi aturan masih terpasang.
@unlink($stateFile);
$afterLoss = $mk();
check('state hilang: hash dilaporkan dari aturan terpasang, bukan null', $afterLoss->report()['hash'] === $hashB);

// Domain dihapus dari grup di server: policy baru menggantikan, tidak ada sisa.
$callsBefore = count($fake->calls);
$afterLoss->apply($payloadA);
check('kebijakan berganti: aturan lama diganti penuh', end($fake->calls) === 'policy:facebook.com|203.0.113.9' && $fake->installed['browser'] === ['facebook.com'] && $afterLoss->report()['hash'] === $hashA);

// Policy dikosongkan / dihapus di server.
$afterLoss->apply(['hash' => $emptyHash, 'domains' => [], 'ips' => []]);
check('kebijakan kosong membersihkan semua mekanisme', $fake->installed === ['hosts' => [], 'browser' => [], 'ips' => []] && $afterLoss->report()['hash'] === $emptyHash);

// Penyimpangan: hosts dihapus manual, browser masih ada.
$afterLoss->apply($payloadA);
$fake->installed['hosts'] = [];
$now += PolicyApplier::VERIFY_INTERVAL_SECONDS;
$drift = $afterLoss->report()['hash'];
check('hosts dihapus manual terdeteksi sebagai menyimpang (hash berbeda dari server)', $drift !== $hashA && $drift !== $emptyHash && $drift !== null);
$afterLoss->apply($payloadA);
check('penyimpangan dipulihkan dengan menerapkan ulang', $fake->installed['hosts'] === ['facebook.com'] && $afterLoss->report()['hash'] === $hashA);

// Server offline: agent tidak melakukan apa pun, aturan tetap terpasang (enforcement tidak melemah).
$callsBefore = count($fake->calls);
$now += 10 * PolicyApplier::VERIFY_INTERVAL_SECONDS;
$afterLoss->nothingToApply();
check('server offline: tidak ada pencabutan otomatis', count($fake->calls) === $callsBefore && $fake->installed['browser'] === ['facebook.com']);

// Di luar jaringan kantor: server tidak terjangkau cukup lama -> blokir dilepas; kembali terhubung -> dipasang lagi.
$away = new FakePlatform();
$awayFile = sys_get_temp_dir() . '/nw-policy-away-' . uniqid() . '.json';
$awayApplier = new PolicyApplier($away, $awayFile, 'netwatch.perusahaan.local', new Logger(null), function () use (&$now): int { return $now; }, 600);
$awayApplier->apply($payloadA);
check('luar kantor: belum melepas sebelum batas waktu', $awayApplier->releaseWhenOffline(599) === false && $away->installed['browser'] === ['facebook.com']);
check('luar kantor: dilepas setelah server tidak terjangkau 10 menit', $awayApplier->releaseWhenOffline(600) === true && $away->installed === ['hosts' => [], 'browser' => [], 'ips' => []]);
$awayReport = $awayApplier->report();
check('luar kantor: dilaporkan hash kosong + penanda offline_release', $awayReport['hash'] === $emptyHash && ($awayReport['offline_release'] ?? false) === true);
$callsAway = count($away->calls);
check('luar kantor: tidak melepas berulang-ulang', $awayApplier->releaseWhenOffline(900) === false && count($away->calls) === $callsAway);
$awayApplier->apply($payloadA);
check('kembali ke kantor: kebijakan dipasang lagi dan penanda dihapus', $away->installed['browser'] === ['facebook.com'] && !isset($awayApplier->report()['offline_release']));
$never = new PolicyApplier($away, $awayFile, 'netwatch.perusahaan.local', new Logger(null), function () use (&$now): int { return $now; }, 0);
check('offline_release_seconds=0: blokir tidak pernah dilepas otomatis', $never->releaseWhenOffline(100000) === false && $away->installed['browser'] === ['facebook.com']);
$away->failPolicyWith = 'akses ditolak';
$stuckAway = new PolicyApplier($away, $awayFile, 'netwatch.perusahaan.local', new Logger(null), function () use (&$now): int { return $now; }, 600);
check('luar kantor: gagal melepas tidak melempar dan dicoba lagi', $stuckAway->releaseWhenOffline(700) === false);
@unlink($awayFile);

// Pembacaan gagal: hash null (tidak pernah dianggap bersih) dan ada galat jelas.
$fake->failInspect = true;
$now += PolicyApplier::VERIFY_INTERVAL_SECONDS;
$unreadable = $afterLoss->report();
check('verifikasi gagal dilaporkan sebagai hash null + galat', $unreadable['hash'] === null && str_contains((string) $unreadable['error'], 'verifikasi'));
$fake->failInspect = false;

// Apply tampak sukses tetapi aturan tidak terpasang: tidak boleh dinyatakan Applied.
$liar = new FakePlatform();
$liar->leaveHostsBehind = true;
$liarApplier = $mk($liar, sys_get_temp_dir() . '/nw-policy-liar-' . uniqid() . '.json');
$liarApplier->apply($payloadA);
check('verifikasi pasca-apply menolak penerapan yang tidak terpasang penuh', str_contains((string) $liarApplier->report()['error'], 'Verifikasi'));

// Uninstall / reinstall.
$afterLoss->clear();
check('clear menerapkan kebijakan kosong dan terverifikasi bersih', end($fake->calls) === 'policy:|' && $fake->installed === ['hosts' => [], 'browser' => [], 'ips' => []] && $afterLoss->report() === ['hash' => $emptyHash, 'error' => null]);
$reinstall = $mk($fake, sys_get_temp_dir() . '/nw-policy-new-' . uniqid() . '.json');
check('reinstal (state baru) pada komputer bersih melaporkan bersih', $reinstall->report()['hash'] === $emptyHash);

// Kegagalan cleanup: clear melempar dan state pemulihan tidak direset.
$stuck = new FakePlatform();
$stuck->installed = ['hosts' => ['facebook.com'], 'browser' => ['facebook.com'], 'ips' => []];
$stuck->leaveHostsBehind = true;
$stuckFile = sys_get_temp_dir() . '/nw-policy-stuck-' . uniqid() . '.json';
(new \NetWatch\Agent\Policy\PolicyState($hashA, null, $hashA, 5))->save($stuckFile);
$threw = false;
try { $mk($stuck, $stuckFile)->clear(); } catch (PlatformException $e) { $threw = str_contains($e->getMessage(), 'belum tuntas'); }
check('cleanup tidak tuntas melempar dan state tidak direset', $threw && \NetWatch\Agent\Policy\PolicyState::load($stuckFile)->hash === $hashA);

check('hosts: domainsIn membaca daftar domain dari blok', HostsFile::domainsIn(HostsFile::render("127.0.0.1 localhost\r\n", ['b.com', 'a.com'])) === ['a.com', 'b.com']
    && HostsFile::domainsIn("0.0.0.0 x.com\r\n") === []
    && HostsFile::domainsIn("# BEGIN NETWATCH (lama)\r\n0.0.0.0 a.com\r\n# END NETWATCH\r\n") === []);
@unlink($stateFile); @unlink($stuckFile);

check('Linux menolak kebijakan non-kosong', (function (): bool {
    try { (new LinuxPlatform())->applyBlockPolicy(['a.com'], []); return false; } catch (PlatformException) { return true; }
})());
check('Linux mengabaikan pembersihan kosong', (function (): bool {
    (new LinuxPlatform())->applyBlockPolicy([], []); return true;
})());

// ---------- riwayat browsing ----------

check('Sanitizer: query, fragmen, dan kredensial dibuang', Sanitizer::url('https://u:p@Example.com/cari/hasil?q=rahasia&t=abc#x') === 'https://example.com/cari/hasil');
check('Sanitizer: URL tanpa path', Sanitizer::url('http://example.com') === 'http://example.com/');
check('Sanitizer: skema internal dan lokal dibuang', Sanitizer::url('chrome://settings') === null && Sanitizer::url('file:///C:/a.txt') === null
    && Sanitizer::url('edge://newtab') === null && Sanitizer::url('http://localhost:8000/x') === null && Sanitizer::url('javascript:alert(1)') === null);
check('Sanitizer: waktu Chrome <-> Unix', Sanitizer::chromeToUnix(Sanitizer::unixToChrome(1_760_000_000)) === 1_760_000_000
    && Sanitizer::chromeToUnix(13_370_000_000_000_000) === 1_725_526_400);
check('Sanitizer: hanya IP publik', Sanitizer::isPublicIp('93.184.216.34') && Sanitizer::isPublicIp('2606:2800:220:1::1')
    && !Sanitizer::isPublicIp('192.168.1.5') && !Sanitizer::isPublicIp('10.1.1.1') && !Sanitizer::isPublicIp('127.0.0.1')
    && !Sanitizer::isPublicIp('169.254.1.1') && !Sanitizer::isPublicIp('224.0.0.1') && !Sanitizer::isPublicIp('100.64.0.1')
    && !Sanitizer::isPublicIp('fe80::1') && !Sanitizer::isPublicIp('fd00::1') && !Sanitizer::isPublicIp('::1') && !Sanitizer::isPublicIp('bukan-ip'));

$tracker = new ConnectionTracker('203.0.113.9', 1800);
$conn = fn (string $ip, ?string $proc = 'chrome') => ['ip' => $ip, 'port' => 443, 'process' => $proc, 'host' => null];
$first = $tracker->fresh([$conn('93.184.216.34'), $conn('93.184.216.34'), $conn('192.168.1.1'), $conn('203.0.113.9'), $conn('8.8.8.8', 'teams')], 1000, 10);
check('Tracker: pribadi, server sendiri, dan duplikat dibuang', count($first) === 2);
$tracker->markReported($first, 1000);
check('Tracker: tidak diulang dalam jendela', $tracker->fresh([$conn('93.184.216.34')], 1500, 10) === []);
check('Tracker: pasangan IP+proses lain tetap dilaporkan', count($tracker->fresh([$conn('93.184.216.34', 'edge')], 1500, 10)) === 1);
check('Tracker: dilaporkan lagi setelah jendela lewat', count($tracker->fresh([$conn('93.184.216.34')], 3000, 10)) === 1);
check('Tracker: batas jumlah', count($tracker->fresh(array_map(fn ($i) => $conn("8.8.{$i}.1", 'x'), range(1, 50)), 0, 5)) === 5);

check('parseConnectionsJson: objek tunggal dan nilai tak valid', WindowsPlatform::parseConnectionsJson('{"connections":{"ip":"93.184.216.34","port":443,"process":"chrome","host":"example.com"}}') === [['ip' => '93.184.216.34', 'port' => 443, 'process' => 'chrome', 'host' => 'example.com']]
    && WindowsPlatform::parseConnectionsJson('{"connections":[{"ip":"x"},{"ip":"1.1.1.1"}]}')[0]['ip'] === '1.1.1.1'
    && WindowsPlatform::parseConnectionsJson('{"connections":[]}') === []);
$ss = "0 0 192.168.1.5:44322 93.184.216.34:443 users:((\"chrome\",pid=10,fd=5))\n0 0 [2001:db8::1]:5000 [2606:2800::1]:443\n";
$parsed = LinuxPlatform::parseSs($ss);
check('parseSs: IPv4 dengan proses dan IPv6', count($parsed) === 2 && $parsed[0]['process'] === 'chrome' && $parsed[0]['ip'] === '93.184.216.34' && $parsed[1]['ip'] === '2606:2800::1');

if (HistoryReader::isAvailable()) {
    $dir = sys_get_temp_dir() . '/nwtest' . getmypid();
    @mkdir($dir);
    $history = "{$dir}/History";
    $db = new PDO('sqlite:' . $history);
    $db->exec('CREATE TABLE urls (id INTEGER PRIMARY KEY, url TEXT); CREATE TABLE visits (id INTEGER PRIMARY KEY, url INTEGER, visit_time INTEGER, transition INTEGER)');
    $now = time();
    $t = fn (int $ago) => Sanitizer::unixToChrome($now - $ago);
    $db->exec("INSERT INTO urls VALUES (1,'https://example.com/a?q=rahasia'),(2,'chrome://settings'),(3,'https://iklan.test/frame'),(4,'https://lama.test/')");
    $db->exec("INSERT INTO visits (url, visit_time, transition) VALUES (1, {$t(60)}, 805306368), (2, {$t(50)}, 1), (3, {$t(40)}, 3), (4, {$t(86400)}, 1)");
    unset($db);

    $fakeApi = new class implements \NetWatch\Agent\Http\ApiTransport {
        /** @var list<array<string, mixed>> */
        public array $sent = [];
        public bool $fail = false;
        public function post(string $path, array $body = []): array
        {
            if ($this->fail) { throw new \RuntimeException('putus'); }
            $this->sent[] = $body;
            return [];
        }
    };

    $fake = new FakePlatform();
    $fake->historyFiles = [['browser' => 'chrome', 'path' => $history]];
    $fake->connections = [['ip' => '93.184.216.34', 'port' => 443, 'process' => 'chrome', 'host' => 'example.com']];
    $state = "{$dir}/browsing-state.json";
    $reporter = new BrowsingReporter($fake, $fakeApi, new Logger(null), $state, new ConnectionTracker('203.0.113.9'));

    $count = $reporter->report($now);
    $sent = $fakeApi->sent[0]['visits'] ?? [];
    $urls = array_values(array_filter($sent, fn ($v) => $v['kind'] === 'url'));
    check('Reporter: hanya kunjungan baru yang valid dikirim (tanpa query, tanpa chrome://, tanpa iframe, tanpa riwayat lama)',
        $count === 2 && count($urls) === 1 && $urls[0]['url'] === 'https://example.com/a' && $urls[0]['browser'] === 'chrome');
    check('Reporter: koneksi ikut terkirim', count($sent) === 2 && $sent[1]['kind'] === 'connection' && $sent[1]['host'] === 'example.com');
    check('Reporter: kursor tersimpan, panggilan berikutnya kosong', $reporter->report($now + 120) === 0 && count($fakeApi->sent) === 1);
    check('Reporter: berkas state dibuat', BrowsingState::load($state)->cursors !== []);

    $db = new PDO('sqlite:' . $history);
    $db->exec("INSERT INTO visits (url, visit_time, transition) VALUES (1, " . Sanitizer::unixToChrome($now + 30) . ", 805306368)");
    unset($db);
    $fakeApi->fail = true;
    $failed = false;
    try { $reporter->report($now + 240); } catch (\RuntimeException) { $failed = true; }
    $fakeApi->fail = false;
    check('Reporter: gagal kirim melempar dan kursor tidak maju', $failed && $reporter->report($now + 360) >= 1);

    @unlink($history); @unlink($state); @rmdir($dir);
} else {
    echo "SKIP  pdo_sqlite tidak ada di PHP ini; tes pembaca riwayat dilewati\n";
}

// ---------- port & layanan, Windows Event Log ----------

$ss = "LISTEN 0 4096 0.0.0.0:22 0.0.0.0:* users:((\"sshd\",pid=1,fd=3))\nLISTEN 0 4096 127.0.0.53%lo:53 0.0.0.0:*\nLISTEN 0 511 [::]:443 [::]:* users:((\"nginx\",pid=9,fd=6))\nLISTEN 0 1 *:8080 *:*\n";
$listening = LinuxPlatform::parseSsListening($ss, 'tcp');
check('ss: listening port, alamat bind, dan proses terbaca', count($listening) === 4 && $listening[0] === ['protocol' => 'tcp', 'address' => '0.0.0.0', 'port' => 22, 'process' => 'sshd']
    && $listening[1]['address'] === '127.0.0.53' && $listening[2]['address'] === '::' && $listening[3]['address'] === '0.0.0.0');
check('ss: baris sampah diabaikan', LinuxPlatform::parseSsListening("ESTAB 0 0 10.0.0.1:5000 10.0.0.2:443\nxx\n", 'tcp') !== null
    && count(LinuxPlatform::parseSsListening("garbage line\n", 'tcp')) === 0);
$svc = LinuxPlatform::parseSystemctl("ssh.service loaded active running OpenBSD Secure Shell\nfoo.service not-found inactive dead foo.service\ncron.service loaded failed failed Cron\n", "ssh.service enabled enabled\ncron.service disabled enabled\n");
check('systemctl: layanan, status, dan mode start', count($svc) === 2 && $svc[0]['state'] === 'Running' && $svc[0]['start'] === 'Auto' && $svc[1]['state'] === 'Failed' && $svc[1]['start'] === 'Disabled');

$inv = WindowsPlatform::parseInventoryJson('{"ports":[{"protocol":"tcp","address":"0.0.0.0","port":445,"process":"System"},{"protocol":"xx","address":"1.1.1.1","port":1},{"protocol":"tcp","address":"bukan-ip","port":2}],"services":{"name":"Spooler","display":"Print Spooler","state":"Running","start":"Auto"}}');
check('Windows inventory: port valid dikirim, protokol/alamat tidak valid dibuang', count($inv['ports']) === 1 && $inv['ports'][0]['port'] === 445);
check('Windows inventory: satu layanan (skalar PowerShell) tetap terbaca', count($inv['services']) === 1 && $inv['services'][0]['name'] === 'Spooler');

$apiLog = new class implements \NetWatch\Agent\Http\ApiTransport {
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $sent = [];
    public bool $fail = false;
    public function post(string $path, array $body = []): array
    {
        if ($this->fail) { throw new \RuntimeException('putus'); }
        $this->sent[] = [$path, $body];
        return [];
    }
};

$fakeInv = new FakePlatform();
check('InventoryReporter: pembacaan kosong tidak dikirim', (new \NetWatch\Agent\Inventory\InventoryReporter($fakeInv, $apiLog))->report() === ['ports' => 0, 'services' => 0] && $apiLog->sent === []);
$fakeInv->inventory = ['ports' => [
    ['protocol' => 'tcp', 'address' => '0.0.0.0', 'port' => 22, 'process' => 'sshd'],
    ['protocol' => 'tcp', 'address' => '0.0.0.0', 'port' => 22, 'process' => 'sshd'],
], 'services' => [['name' => 'ssh.service', 'display' => null, 'state' => 'Running', 'start' => 'Auto']]];
$sentCount = (new \NetWatch\Agent\Inventory\InventoryReporter($fakeInv, $apiLog))->report();
check('InventoryReporter: duplikat dibuang, dikirim ke /inventory', $sentCount === ['ports' => 1, 'services' => 1] && $apiLog->sent[0][0] === 'inventory');

$fakeAssets = new FakePlatform();
$assetReporter = new \NetWatch\Agent\Inventory\AssetReporter($fakeAssets, $apiLog);
$before = count($apiLog->sent);
check('AssetReporter: pembacaan kosong tidak dikirim', $assetReporter->report() === false && count($apiLog->sent) === $before);
$fakeAssets->assets = ['hardware' => null, 'software' => [['name' => 'Chrome', 'version' => '1', 'publisher' => null, 'installed' => null], ['name' => '7-Zip', 'version' => '2', 'publisher' => null, 'installed' => null]]];
check('AssetReporter: pertama kali dikirim ke /assets', $assetReporter->report() === true && end($apiLog->sent)[0] === 'assets');
check('AssetReporter: tidak berubah -> tidak dikirim ulang', $assetReporter->report() === false);
$fakeAssets->assets['software'] = [['name' => 'Chrome', 'version' => '1', 'publisher' => null, 'installed' => null]];
check('AssetReporter: software di-uninstall -> dikirim ulang tanpa software itu', $assetReporter->report() === true && count(end($apiLog->sent)[1]['software']) === 1);

$sw = \NetWatch\Agent\Inventory\AssetNormalizer::software([
    ['name' => "  Foo\x00Bar ", 'version' => '1.0', 'installed' => '20260115'], ['name' => 'Foo Bar', 'version' => '1.0'], ['name' => ''], ['name' => 'Baz', 'installed' => '20261340'],
]);
check('AssetNormalizer: duplikat, nama kosong, tanggal tidak valid', count($sw) === 2 && $sw[0]['name'] === 'Baz' && $sw[0]['installed'] === null && $sw[1]['installed'] === '2026-01-15');
check('AssetNormalizer: semua kosong -> null (bukan daftar kosong)', \NetWatch\Agent\Inventory\AssetNormalizer::software([['name' => '']]) === null);
$hw = \NetWatch\Agent\Inventory\AssetNormalizer::hardware(['manufacturer' => 'Dell', 'serial' => 'To Be Filled By O.E.M.', 'memory_mb' => '16384', 'disks' => [['model' => 'SSD', 'size_gb' => 512], ['model' => 'x', 'size_gb' => 0]]]);
check('AssetNormalizer: serial generik dibuang, disk tidak valid dibuang', $hw['serial'] === null && $hw['memory_mb'] === 16384 && count($hw['disks']) === 1);
check('AssetNormalizer: hardware tanpa info -> null', \NetWatch\Agent\Inventory\AssetNormalizer::hardware([]) === null);
$parsed = WindowsPlatform::parseAssetsJson('{"hardware":{"manufacturer":"HP","model":"EliteDesk","memory_mb":8192,"disks":{"model":"NVMe","size_gb":256}},"software":{"name":"Office","version":"16","publisher":"MS","installed":"20250101"}}');
check('Windows: parseAssetsJson menerima objek tunggal PowerShell', $parsed['hardware']['model'] === 'EliteDesk' && count($parsed['hardware']['disks']) === 1 && $parsed['software'][0]['name'] === 'Office');
check('Linux: parsePackages', LinuxPlatform::parsePackages("bash\t5.2\n\nvim\t9.0\n") === [['name' => 'bash', 'version' => '5.2', 'publisher' => null, 'installed' => null], ['name' => 'vim', 'version' => '9.0', 'publisher' => null, 'installed' => null]]);

$winEvents = WindowsPlatform::parseEventLogJson('{"events":[{"log":"System","provider":"Microsoft-Windows-DNS-Client","id":1014,"level":3,"time":1000,"record":10,"message":"DNS gagal"},{"log":"Security","provider":"x","id":1,"level":2,"time":1001,"record":11,"message":"harus dibuang"},{"log":"System","provider":"Tcpip","id":4199,"level":2,"time":1002,"record":12,"message":"a\u0000b"}]}');
check('Event Log: hanya log System/Application yang diterima', count($winEvents) === 2 && $winEvents[0]['provider'] === 'Microsoft-Windows-DNS-Client');

$journal = LinuxPlatform::parseJournalJson("{\"__REALTIME_TIMESTAMP\":\"1700000000123456\",\"PRIORITY\":\"2\",\"SYSLOG_IDENTIFIER\":\"kernel\",\"MESSAGE\":\"NIC link down\"}\nbukan json\n{\"__REALTIME_TIMESTAMP\":\"1700000001000000\",\"PRIORITY\":\"3\",\"_COMM\":\"sshd\",\"MESSAGE\":[1,2]}\n");
check('journald: prioritas dipetakan, baris rusak dibuang, record = mikrodetik', count($journal) === 2 && $journal[0]['level'] === 1 && $journal[0]['time'] === 1700000000 && $journal[0]['record'] === 1700000000123456 && $journal[1]['provider'] === 'sshd' && $journal[1]['level'] === 2);

$mkEv = fn (string $log, string $prov, int $id, int $time, int $rec): array => ['log' => $log, 'provider' => $prov, 'id' => $id, 'level' => 2, 'time' => $time, 'record' => $rec, 'message' => "msg\nbaris"];
$batch = [$mkEv('System', 'Tcpip', 4199, 100, 1), $mkEv('System', 'Tcpip', 4199, 105, 2), $mkEv('System', 'Tcpip', 4199, 110, 3), $mkEv('Application', 'App', 1, 120, 4)];
$collapsed = WinlogReporter::collapse($batch);
check('Winlog: entri identik digabung dengan hitungan, pesan satu baris', count($collapsed) === 2 && $collapsed[0]['count'] === 3 && $collapsed[0]['time'] === 110 && !str_contains($collapsed[0]['message'], "\n"));
check('Winlog: hanya entri lebih baru dari kursor log-nya', count(WinlogReporter::newerThanCursors($batch, ['System' => ['time' => 105, 'record' => 2], 'Application' => ['time' => 200, 'record' => 0]])) === 1);

$logState = sys_get_temp_dir() . '/nw-winlog-' . uniqid() . '.json';
$apiLog->sent = [];
$fakeLog = new FakePlatform();
$fakeLog->eventLog = $batch;
$wl = new WinlogReporter($fakeLog, $apiLog, $logState);
check('Winlog: kunjungan pertama membaca jendela 1 jam dan mengirim hasil gabungan', $wl->report(3000) === 2 && $apiLog->sent[0][0] === 'winlog');
check('Winlog: panggilan berikutnya tidak mengirim ulang (kursor)', $wl->report(3100) === 0 && count($apiLog->sent) === 1);
$fakeLog->eventLog[] = $mkEv('System', 'Tcpip', 4199, 3050, 5);
$apiLog->fail = true;
$failedSend = false;
try { $wl->report(3200); } catch (\RuntimeException) { $failedSend = true; }
$apiLog->fail = false;
check('Winlog: gagal kirim melempar, kursor tidak maju, lalu terkirim ulang', $failedSend && $wl->report(3300) === 1);
check('Winlog: state bertahan di berkas', WinlogState::load($logState)->cursors['System']['record'] === 5);
@unlink($logState);

exit($failures === 0 ? 0 : 1);
