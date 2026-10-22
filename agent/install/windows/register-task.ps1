<#
.SYNOPSIS
    Mendaftarkan NetWatch Agent sebagai tugas terjadwal SYSTEM (jalan saat boot, tersembunyi, restart otomatis).
    Dipakai bersama oleh install.ps1 dan installer .exe. Harus dijalankan sebagai Administrator.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$PhpExe,
    [Parameter(Mandatory)][string]$AgentScript,
    [Parameter(Mandatory)][string]$ConfigFile
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$taskName = 'NetWatchAgent'

if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) {
    Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
}

$action    = New-ScheduledTaskAction -Execute $PhpExe -Argument "`"$AgentScript`" run --config=`"$ConfigFile`"" `
    -WorkingDirectory (Split-Path -Parent $PhpExe)
$trigger   = New-ScheduledTaskTrigger -AtStartup
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings  = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -Hidden `
    -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -StartWhenAvailable

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal `
    -Settings $settings -Description 'NetWatch Agent' | Out-Null
Start-ScheduledTask -TaskName $taskName
