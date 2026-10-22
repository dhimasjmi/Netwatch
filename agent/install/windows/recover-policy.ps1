<#
.SYNOPSIS
    Memeriksa dan (opsional) membersihkan sisa blokir NetWatch di komputer ini. Tidak membutuhkan PHP maupun agent.
    Jalankan PowerShell sebagai Administrator.

.DESCRIPTION
    Tanpa -Apply skrip hanya MELAPORKAN apa yang akan dihapus (aman dijalankan kapan saja).
    Hanya aturan yang terbukti milik NetWatch yang disentuh:
      - File hosts : hanya blok "# BEGIN NETWATCH ... # END NETWATCH".
      - Firewall   : hanya aturan ber-Name "NetWatch-*".
      - Chrome/Edge: hanya entri URLBlocklist yang dicatat NetWatch di HKLM:\SOFTWARE\NetWatch\Policy (nomor dan nilainya
                     masih sama). Entri milik Group Policy/MDM/admin lain tidak dihapus.
    Bila catatan kepemilikan registry sudah hilang, entri URLBlocklist TIDAK dihapus otomatis karena tidak bisa dipastikan
    pemiliknya. Hanya jika Anda sendiri menyebut domainnya lewat -AssumeDomains, entri bernilai domain itu dihapus.

.EXAMPLE
    .\recover-policy.ps1                      # periksa saja
    .\recover-policy.ps1 -Apply               # bersihkan
    .\recover-policy.ps1 -Apply -AssumeDomains facebook.com,instagram.com
#>
[CmdletBinding()]
param(
    [switch]$Apply,
    [string[]]$AssumeDomains = @()
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$principal = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Jalankan PowerShell sebagai Administrator.'
}

$hostsPath = Join-Path ($(if ($env:SystemRoot) { $env:SystemRoot } else { 'C:\Windows' })) 'System32\drivers\etc\hosts'
$own = 'HKLM:\SOFTWARE\NetWatch\Policy'
$vendors = [ordered]@{
    Chrome = 'HKLM:\SOFTWARE\Policies\Google\Chrome\URLBlocklist'
    Edge   = 'HKLM:\SOFTWARE\Policies\Microsoft\Edge\URLBlocklist'
}
$mode = if ($Apply) { 'BERSIHKAN' } else { 'PERIKSA SAJA (tambahkan -Apply untuk membersihkan)' }
Write-Host "Mode: $mode"
$problems = 0

function Get-OwnedValue([string]$name) {
    if (-not (Test-Path $own)) { return @() }
    return @((Get-ItemProperty -Path $own -Name $name -ErrorAction SilentlyContinue).$name | Where-Object { $_ })
}

# 1. hosts
$blockPattern = '(?ms)(?:\r?\n)*^# BEGIN NETWATCH[^\r\n]*\r?\n.*?^# END NETWATCH[ \t]*(?:\r?\n|\z)'
$brokenPattern = '(?ms)(?:\r?\n)*^# BEGIN NETWATCH.*\z'
if (Test-Path $hostsPath) {
    $content = [IO.File]::ReadAllText($hostsPath)
    if ($content -match '(?m)^# BEGIN NETWATCH') {
        Write-Host '[hosts] Blok NetWatch ditemukan.'
        if ($Apply) {
            $clean = [regex]::Replace($content, $blockPattern, '')
            $clean = [regex]::Replace($clean, $brokenPattern, '')
            [IO.File]::WriteAllText($hostsPath, $clean, (New-Object Text.UTF8Encoding($false)))
            if ([IO.File]::ReadAllText($hostsPath) -match '(?m)^# BEGIN NETWATCH') { Write-Warning '[hosts] Masih ada blok NetWatch.'; $problems++ }
            else { Write-Host '[hosts] Dibersihkan.'; ipconfig.exe /flushdns | Out-Null }
        }
    } else { Write-Host '[hosts] Bersih.' }
}

# 2. Firewall
$rules = @(Get-NetFirewallRule -Name 'NetWatch-*' -ErrorAction SilentlyContinue)
if ($rules.Count -gt 0) {
    Write-Host ("[firewall] Aturan NetWatch: " + (($rules | ForEach-Object { $_.Name }) -join ', '))
    if ($Apply) {
        $rules | Remove-NetFirewallRule
        if (@(Get-NetFirewallRule -Name 'NetWatch-*' -ErrorAction SilentlyContinue).Count -gt 0) { Write-Warning '[firewall] Masih ada aturan NetWatch.'; $problems++ }
        else { Write-Host '[firewall] Dibersihkan.' }
    }
} else { Write-Host '[firewall] Bersih.' }

# 3. Chrome/Edge URLBlocklist
$haveOwnership = Test-Path $own
foreach ($vendor in $vendors.Keys) {
    $key = $vendors[$vendor]
    if (-not (Test-Path $key)) { Write-Host "[$vendor] Tidak ada URLBlocklist."; continue }

    $toRemove = @()
    foreach ($entry in (Get-OwnedValue "Added$vendor")) {
        $sep = $entry.IndexOf('=')
        if ($sep -lt 1) { continue }
        $name = $entry.Substring(0, $sep); $value = $entry.Substring($sep + 1)
        $current = (Get-ItemProperty -Path $key -Name $name -ErrorAction SilentlyContinue).$name
        if ($current -eq $value) { $toRemove += $name }
    }
    if (-not $haveOwnership -and $AssumeDomains.Count -gt 0) {
        foreach ($name in (Get-Item -Path $key).Property) {
            $value = [string](Get-ItemProperty -Path $key -Name $name).$name
            if ($AssumeDomains -contains $value -and $toRemove -notcontains $name) { $toRemove += $name }
        }
    }

    $all = @((Get-Item -Path $key).Property)
    $foreign = @($all | Where-Object { $toRemove -notcontains $_ })
    Write-Host "[$vendor] Entri milik NetWatch: $($toRemove.Count); entri lain (tidak disentuh): $($foreign.Count)"
    if ($foreign.Count -gt 0 -and -not $haveOwnership -and $AssumeDomains.Count -eq 0) {
        Write-Warning "[$vendor] Catatan kepemilikan NetWatch tidak ada, sehingga entri di atas tidak dihapus. Bila Anda yakin itu sisa blokir NetWatch, jalankan lagi dengan -AssumeDomains domain1,domain2."
    }
    if ($Apply -and $toRemove.Count -gt 0) {
        foreach ($name in $toRemove) { Remove-ItemProperty -Path $key -Name $name -Force }
        $item = Get-Item -Path $key
        if ($item.ValueCount -eq 0 -and $item.SubKeyCount -eq 0) { Remove-Item -Path $key -Force }
        Write-Host "[$vendor] Dibersihkan."
    }
}

# 4. Catatan kepemilikan: dihapus hanya bila semua yang tercatat sudah bersih
if ($Apply -and $problems -eq 0 -and (Test-Path $own)) {
    Remove-Item -Path $own -Recurse -Force
    Write-Host '[registry] Catatan kepemilikan NetWatch dihapus.'
}

if ($problems -gt 0) { Write-Error "Pembersihan belum tuntas ($problems masalah). Periksa peringatan di atas."; exit 1 }
Write-Host 'Selesai.'
