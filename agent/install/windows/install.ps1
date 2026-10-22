<#
.SYNOPSIS
    Memasang NetWatch Agent di Windows. Jalankan PowerShell sebagai Administrator.
.DESCRIPTION
    Agent berjalan sebagai tugas terjadwal SYSTEM saat boot (otomatis restart bila gagal),
    sehingga tidak butuh layanan pihak ketiga. PHP diambil dari folder "runtime\php" di sebelah
    paket ini (PHP portabel) atau dari PATH. Token dibaca dari parameter atau dimasukkan interaktif.
.EXAMPLE
    .\install.ps1 -Server https://netwatch.perusahaan.local -CaFile C:\temp\ca.pem
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][ValidatePattern('^https?://')][string]$Server,
    [SecureString]$Token,
    [string]$CaFile,
    [switch]$AllowInsecureHttp,
    [switch]$Reenroll
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$TaskName   = 'NetWatchAgent'
$InstallDir = Join-Path $env:ProgramFiles 'NetWatch'
$DataDir    = Join-Path $env:ProgramData 'NetWatch'
$ConfigFile = Join-Path $DataDir 'agent.json'
$SourceDir  = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

$principal = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Jalankan PowerShell sebagai Administrator.'
}

function Resolve-PhpExecutable {
    $bundled = Join-Path $SourceDir 'runtime\php\php.exe'
    if (Test-Path $bundled) { return $bundled }
    $cmd = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($null -ne $cmd) { return $cmd.Source }
    throw 'PHP tidak ditemukan. Letakkan PHP portabel (>= 8.2, ekstensi curl & mbstring aktif) di agent\runtime\php atau pasang PHP di PATH.'
}

$sourcePhp = Resolve-PhpExecutable

# Salin kode agent dan runtime PHP ke Program Files (hanya bisa diubah Administrator).
New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
foreach ($item in 'src', 'bin') {
    $target = Join-Path $InstallDir $item
    if (Test-Path $target) { Remove-Item $target -Recurse -Force }
    Copy-Item (Join-Path $SourceDir $item) $target -Recurse
}
# Skrip pemulihan disimpan di folder terproteksi agar sisa blokir bisa dibersihkan walau agent rusak.
New-Item -ItemType Directory -Force -Path (Join-Path $InstallDir 'install') | Out-Null
Copy-Item (Join-Path $PSScriptRoot 'recover-policy.ps1') (Join-Path $InstallDir 'install\recover-policy.ps1') -Force
$phpExe = $sourcePhp
if ($sourcePhp -like (Join-Path $SourceDir 'runtime\*')) {
    $runtimeTarget = Join-Path $InstallDir 'runtime'
    if (Test-Path $runtimeTarget) { Remove-Item $runtimeTarget -Recurse -Force }
    Copy-Item (Join-Path $SourceDir 'runtime') $runtimeTarget -Recurse
    $phpExe = Join-Path $InstallDir 'runtime\php\php.exe'
}

& $phpExe -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);'
if ($LASTEXITCODE -ne 0) { throw 'PHP minimal 8.2.' }
foreach ($ext in 'curl', 'mbstring') {
    if (-not (& $phpExe -m | Select-String -SimpleMatch -Quiet $ext)) { throw "Ekstensi PHP '$ext' belum aktif." }
}

# Folder data hanya boleh diakses SYSTEM dan Administrators (berisi token API perangkat).
New-Item -ItemType Directory -Force -Path $DataDir | Out-Null
& icacls.exe $DataDir /inheritance:r /grant:r 'SYSTEM:(OI)(CI)F' 'Administrators:(OI)(CI)F' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Gagal mengatur izin folder data.' }

$agentScript = Join-Path $InstallDir 'bin\netwatch-agent'

if (-not (Test-Path $ConfigFile) -or $Reenroll) {
    if ($null -eq $Token) { $Token = Read-Host -AsSecureString 'Token pendaftaran' }
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Token)
    try {
        # Token lewat environment proses ini saja, bukan argumen, agar tidak terlihat di daftar proses.
        $env:NETWATCH_ENROLL_TOKEN = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
        $enrollArgs = @($agentScript, 'enroll', "--server=$Server", "--config=$ConfigFile")
        if ($CaFile) {
            if (-not (Test-Path $CaFile)) { throw "Berkas CA tidak ditemukan: $CaFile" }
            Copy-Item $CaFile (Join-Path $DataDir 'ca.pem') -Force
            $enrollArgs += "--ca-file=$(Join-Path $DataDir 'ca.pem')"
        }
        if ($AllowInsecureHttp) { $enrollArgs += '--allow-insecure-http' }

        & $phpExe @enrollArgs
        if ($LASTEXITCODE -ne 0) { throw 'Pendaftaran agent gagal.' }
    }
    finally {
        Remove-Item Env:\NETWATCH_ENROLL_TOKEN -ErrorAction SilentlyContinue
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    }
}
else {
    Write-Host 'Agent sudah terdaftar; lewati pendaftaran (gunakan -Reenroll untuk mendaftar ulang).'
}

# Shortcut Desktop (semua pengguna) ke form tiket bantuan IT; nama PC ikut agar teknisi tahu asal tiket.
$ticketUrl = "$($Server.TrimEnd('/'))/tickets/new?pc=$([uri]::EscapeDataString($env:COMPUTERNAME))"
Set-Content -Path (Join-Path ([Environment]::GetFolderPath('CommonDesktopDirectory')) 'Buat Tiket IT.url') -Value "[InternetShortcut]`r`nURL=$ticketUrl" -Encoding ASCII

& (Join-Path $PSScriptRoot 'register-task.ps1') -PhpExe $phpExe -AgentScript $agentScript -ConfigFile $ConfigFile

Write-Host "NetWatch Agent terpasang dan berjalan. Log: $(Join-Path $DataDir 'agent.log')"
