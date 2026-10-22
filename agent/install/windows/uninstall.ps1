<#
.SYNOPSIS
    Menghapus NetWatch Agent dari Windows. Jalankan PowerShell sebagai Administrator.
#>
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$principal = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Jalankan PowerShell sebagai Administrator.'
}

if (Get-ScheduledTask -TaskName 'NetWatchAgent' -ErrorAction SilentlyContinue) {
    Stop-ScheduledTask -TaskName 'NetWatchAgent' -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName 'NetWatchAgent' -Confirm:$false
}

Remove-Item (Join-Path ([Environment]::GetFolderPath('CommonDesktopDirectory')) 'Buat Tiket IT.url') -Force -ErrorAction SilentlyContinue

# Cabut blokir NetWatch (hosts, firewall, kebijakan Chrome/Edge) sebelum berkas agent dihapus.
# Berkas dan data agent baru dihapus bila pembersihan terbukti berhasil; bila gagal, skrip pemulihan dipertahankan.
$installDir = Join-Path $env:ProgramFiles 'NetWatch'
$dataDir = Join-Path $env:ProgramData 'NetWatch'
$php = Join-Path $installDir 'runtime\php\php.exe'
if (-not (Test-Path $php)) {
    $found = Get-Command php.exe -ErrorAction SilentlyContinue
    $php = if ($found) { $found.Source } else { $null }
}
$agentScript = Join-Path $installDir 'bin\netwatch-agent'
$recovery = Join-Path $PSScriptRoot 'recover-policy.ps1'
if (-not (Test-Path $recovery)) { $recovery = Join-Path $installDir 'install\recover-policy.ps1' }

$cleaned = $false
if ($php -and (Test-Path $agentScript)) {
    & $php $agentScript clear-policy
    $cleaned = ($LASTEXITCODE -eq 0)
}
if (-not $cleaned -and (Test-Path $recovery)) {
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $recovery -Apply
    $cleaned = ($LASTEXITCODE -eq 0)
}

if (-not $cleaned) {
    $safe = Join-Path $env:ProgramData 'NetWatch-Recovery'
    New-Item -ItemType Directory -Path $safe -Force | Out-Null
    if (Test-Path $recovery) { Copy-Item $recovery (Join-Path $safe 'recover-policy.ps1') -Force }
    Write-Warning "Blokir NetWatch belum bersih. Data agent dipertahankan. Periksa: $safe\recover-policy.ps1 (lalu -Apply sebagai Administrator)."
    exit 1
}

Remove-Item $installDir -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item $dataDir -Recurse -Force -ErrorAction SilentlyContinue
Write-Host 'NetWatch Agent dihapus.'
