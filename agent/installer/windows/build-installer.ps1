<#
.SYNOPSIS
    Membangun NetWatchAgent-Setup.exe di komputer pengembang Windows (bukan di PC user).
.DESCRIPTION
    Menyiapkan PHP portabel minimal (curl, mbstring, openssl, sqlite), menguji agent dengan PHP itu,
    lalu mengompilasi installer dengan Inno Setup 6.3+ (jrsoftware.org/isinfo.php).
.EXAMPLE
    .\build-installer.ps1 -PhpDir C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$PhpDir,
    [string]$CaBundle,
    [string]$InnoCompiler
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$agentRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$staging   = Join-Path $agentRoot 'build\staging'
$phpTarget = Join-Path $staging 'php'

if (-not (Test-Path (Join-Path $PhpDir 'php.exe'))) { throw "php.exe tidak ditemukan di: $PhpDir" }
if (-not (Test-Path (Join-Path $PhpDir 'ext'))) { throw "Folder ext tidak ditemukan di: $PhpDir (pakai PHP versi Thread Safe/NTS resmi, 64-bit)." }

$version = ([regex]::Match((Get-Content (Join-Path $agentRoot 'src\Version.php') -Raw), "CURRENT = '([^']+)'")).Groups[1].Value
if (-not $version) { throw 'Versi agent tidak terbaca dari src\Version.php.' }

if (-not $InnoCompiler) {
    $candidates = @(
        (Get-Command ISCC.exe -ErrorAction SilentlyContinue | ForEach-Object Source),
        "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe",
        "$env:ProgramFiles\Inno Setup 6\ISCC.exe"
    ) | Where-Object { $_ -and (Test-Path $_) }
    $InnoCompiler = $candidates | Select-Object -First 1
}
if (-not $InnoCompiler) { throw 'Inno Setup 6 belum terpasang. Unduh dari jrsoftware.org lalu jalankan ulang.' }

Write-Host "==> Menyiapkan PHP portabel di $phpTarget"
if (Test-Path $staging) { Remove-Item $staging -Recurse -Force }
New-Item -ItemType Directory -Force -Path (Join-Path $phpTarget 'ext') | Out-Null

$excluded = 'php-cgi.exe', 'php-win.exe', 'phpdbg.exe'
Get-ChildItem $PhpDir -File | Where-Object {
    ($_.Extension -in '.exe', '.dll') -and ($_.Name -notin $excluded) -and ($_.Name -notmatch 'embed|phpdbg|apache')
} | Copy-Item -Destination $phpTarget

foreach ($ext in 'php_curl.dll', 'php_mbstring.dll', 'php_openssl.dll', 'php_pdo_sqlite.dll', 'php_sqlite3.dll') {
    $source = Join-Path $PhpDir "ext\$ext"
    if (-not (Test-Path $source)) { throw "Ekstensi $ext tidak ada di PHP ini." }
    Copy-Item $source (Join-Path $phpTarget 'ext')
}

@'
extension_dir = "ext"
extension=curl
extension=mbstring
extension=openssl
extension=sqlite3
extension=pdo_sqlite
date.timezone = UTC
memory_limit = 128M
display_errors = 0
log_errors = 0
'@ | Set-Content -Path (Join-Path $phpTarget 'php.ini') -Encoding ascii

if ($CaBundle) { Copy-Item $CaBundle (Join-Path $staging 'cacert.pem') }

Write-Host '==> Menguji agent dengan PHP yang akan dibundel'
$php = Join-Path $phpTarget 'php.exe'
& $php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);'
if ($LASTEXITCODE -ne 0) { throw 'PHP minimal 8.2.' }
$modules = & $php -m
foreach ($ext in 'curl', 'mbstring', 'openssl', 'json', 'pdo_sqlite') {
    if ($modules -notcontains $ext) { throw "Ekstensi '$ext' tidak termuat dari php.ini hasil bundel." }
}
$reported = & $php (Join-Path $agentRoot 'bin\netwatch-agent') version
if ($reported.Trim() -ne $version) { throw "Agent tidak berjalan dengan benar (keluaran: $reported)." }

Write-Host '==> Mengompilasi installer'
& $InnoCompiler "/DAppVersion=$version" (Join-Path $PSScriptRoot 'NetWatchAgent.iss')
if ($LASTEXITCODE -ne 0) { throw 'Kompilasi Inno Setup gagal.' }

$output = Join-Path $agentRoot 'dist\NetWatchAgent-Setup.exe'
$hash = (Get-FileHash $output -Algorithm SHA256).Hash
Write-Host "Selesai: $output"
Write-Host "SHA256 : $hash"
