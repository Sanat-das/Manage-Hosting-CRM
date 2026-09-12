<#
.SYNOPSIS
    Supervises the Laravel Reverb websocket server on Windows.

.DESCRIPTION
    `php artisan reverb:start` is a long-running foreground process. Under IIS
    nothing keeps it alive, so without a supervisor the Slack-like chat works
    until the first reboot, crash or app-pool recycle and then silently falls
    back to polling.

    This script resolves ABSOLUTE paths to php.exe and artisan and quotes every
    one of them. That is not decoration:

      * nothing is on PATH under the IIS application-pool identity, so a bare
        `php` resolves to nothing and the failure is silent;
      * this application's own directory contains spaces ("Local Sites"), and
        Windows argument quoting breaks on them in a way that produces no error
        message at all - the process just never starts.

    Both traps have bitten this codebase before, in the in-app updater.

    Registration prefers NSSM (a real Windows service: automatic restart,
    starts before login). If NSSM is absent it falls back to a Scheduled Task
    triggered At Startup with restart-on-failure every minute.

.PARAMETER WhatIf
    Resolve everything and print the exact command line, changing nothing.

.PARAMETER Register
    Actually install the service or scheduled task. Requires elevation.

.PARAMETER Unregister
    Remove a previously registered service or scheduled task.

.PARAMETER Run
    Run Reverb in the foreground in this window. Useful for a smoke test.

.PARAMETER Status
    Report whether the supervisor exists and whether it is running.

.PARAMETER PhpPath
    Absolute path to php.exe. Auto-detected when omitted.

.PARAMETER BindAddress
    Address Reverb listens on. Default 127.0.0.1 - the public traffic is
    expected to arrive through the IIS reverse proxy, so the socket server
    itself should not be exposed.

.PARAMETER Port
    Port Reverb listens on. Default 8081.

.PARAMETER ServiceName
    Name of the Windows service / scheduled task. Default HostVexaReverb.

.EXAMPLE
    powershell -File scripts\reverb-service.ps1 -WhatIf

.EXAMPLE
    powershell -File scripts\reverb-service.ps1 -Register

.NOTES
    With no switches the script only reports what it WOULD do. Installing a
    service is a machine-level change and is never the default.
#>
[CmdletBinding()]
param(
    [switch] $WhatIf,
    [switch] $Register,
    [switch] $Unregister,
    [switch] $Run,
    [switch] $Status,
    [string] $PhpPath,
    [string] $BindAddress = '127.0.0.1',
    [int]    $Port = 8081,
    [string] $ServiceName = 'HostVexaReverb'
)

$ErrorActionPreference = 'Stop'

function Resolve-PhpPath {
    param([string] $Explicit)

    if ($Explicit) {
        if (-not (Test-Path -LiteralPath $Explicit)) {
            throw "php.exe not found at the path given: $Explicit"
        }
        return (Resolve-Path -LiteralPath $Explicit).Path
    }

    $onPath = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($onPath) { return $onPath.Source }

    # PATH is empty under the IIS identity, so look where PHP actually lives.
    $candidates = @()
    $candidates += Get-ChildItem -Path 'C:\Program Files\PHP' -Directory -ErrorAction SilentlyContinue |
        ForEach-Object { Join-Path $_.FullName 'php.exe' }
    $candidates += Get-ChildItem -Path 'C:\PHP' -Directory -ErrorAction SilentlyContinue |
        ForEach-Object { Join-Path $_.FullName 'php.exe' }
    $candidates += 'C:\PHP\php.exe'
    $candidates += 'C:\Program Files\PHP\php.exe'
    $candidates += Get-ChildItem -Path 'C:\tools\php*' -Directory -ErrorAction SilentlyContinue |
        ForEach-Object { Join-Path $_.FullName 'php.exe' }

    foreach ($candidate in $candidates) {
        if ($candidate -and (Test-Path -LiteralPath $candidate)) {
            return (Resolve-Path -LiteralPath $candidate).Path
        }
    }

    throw 'Could not locate php.exe. Pass -PhpPath "C:\full\path\to\php.exe".'
}

$appRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$artisan = Join-Path $appRoot 'artisan'

if (-not (Test-Path -LiteralPath $artisan)) {
    throw "artisan not found at $artisan - run this script from the application's scripts\ directory."
}

$php = Resolve-PhpPath -Explicit $PhpPath

# Every path is quoted. "Local Sites" has a space in it, and an unquoted
# argument there fails with no diagnostic whatsoever.
$argumentList = '"{0}" reverb:start --host={1} --port={2}' -f $artisan, $BindAddress, $Port
$commandLine = '"{0}" {1}' -f $php, $argumentList

Write-Host 'Reverb supervisor'
Write-Host '-----------------'
Write-Host ("  php.exe      : {0}" -f $php)
Write-Host ("  artisan      : {0}" -f $artisan)
Write-Host ("  working dir  : {0}" -f $appRoot)
Write-Host ("  service name : {0}" -f $ServiceName)
Write-Host ("  command line : {0}" -f $commandLine)
Write-Host ''

function Get-Supervisor {
    $service = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    if ($service) { return [pscustomobject]@{ Kind = 'Service'; State = $service.Status.ToString() } }

    $task = Get-ScheduledTask -TaskName $ServiceName -ErrorAction SilentlyContinue
    if ($task) { return [pscustomobject]@{ Kind = 'ScheduledTask'; State = $task.State.ToString() } }

    return $null
}

if ($WhatIf) {
    Write-Host 'WhatIf: nothing was changed.'
    $existing = Get-Supervisor
    if ($existing) {
        Write-Host ("A supervisor already exists: {0} ({1})." -f $existing.Kind, $existing.State)
    } else {
        Write-Host 'No supervisor is registered yet. Re-run with -Register to install one.'
    }
    return
}

if ($Status) {
    $existing = Get-Supervisor
    if ($existing) {
        Write-Host ("{0} '{1}' is {2}." -f $existing.Kind, $ServiceName, $existing.State)
    } else {
        Write-Host ("No service or scheduled task named '{0}' is registered." -f $ServiceName)
    }
    return
}

if ($Run) {
    Write-Host 'Starting Reverb in the foreground. Ctrl+C to stop.'
    & $php $artisan reverb:start --host=$BindAddress --port=$Port
    return
}

if ($Unregister) {
    $existing = Get-Supervisor
    if (-not $existing) {
        Write-Host 'Nothing to remove.'
        return
    }

    if ($existing.Kind -eq 'Service') {
        $nssm = Get-Command nssm.exe -ErrorAction SilentlyContinue
        if ($nssm) {
            & $nssm.Source stop $ServiceName confirm 2>$null | Out-Null
            & $nssm.Source remove $ServiceName confirm
        } else {
            & sc.exe stop $ServiceName | Out-Null
            & sc.exe delete $ServiceName
        }
    } else {
        Unregister-ScheduledTask -TaskName $ServiceName -Confirm:$false
    }

    Write-Host 'Removed.'
    return
}

if (-not $Register) {
    Write-Host 'No action taken. Installing a service is a machine-level change, so it is never'
    Write-Host 'the default. Re-run with one of:'
    Write-Host '    -WhatIf       show what would happen (this output, plus current state)'
    Write-Host '    -Register     install the service or scheduled task'
    Write-Host '    -Run          run Reverb in the foreground now'
    Write-Host '    -Status       report whether a supervisor exists'
    Write-Host '    -Unregister   remove it'
    return
}

$isElevated = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()
    ).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isElevated) {
    throw 'Registering a service or scheduled task requires an elevated PowerShell session.'
}

$existing = Get-Supervisor
if ($existing) {
    throw ("A supervisor already exists ({0}, {1}). Run -Unregister first." -f $existing.Kind, $existing.State)
}

$nssm = Get-Command nssm.exe -ErrorAction SilentlyContinue

if ($nssm) {
    Write-Host 'NSSM found - registering a real Windows service.'

    & $nssm.Source install $ServiceName $php $argumentList
    & $nssm.Source set $ServiceName AppDirectory $appRoot
    & $nssm.Source set $ServiceName DisplayName 'HostVexa Reverb (chat websockets)'
    & $nssm.Source set $ServiceName Description 'Laravel Reverb websocket server for the HostVexa chat.'
    & $nssm.Source set $ServiceName Start SERVICE_AUTO_START
    # Restart on exit, after a 1 second pause, forever.
    & $nssm.Source set $ServiceName AppExit Default Restart
    & $nssm.Source set $ServiceName AppRestartDelay 1000
    & $nssm.Source set $ServiceName AppStdout (Join-Path $appRoot 'storage\logs\reverb.log')
    & $nssm.Source set $ServiceName AppStderr (Join-Path $appRoot 'storage\logs\reverb.log')

    Start-Service -Name $ServiceName
    Write-Host ("Service '{0}' registered and started." -f $ServiceName)
} else {
    Write-Host 'NSSM not found - falling back to a Scheduled Task (At Startup, restart every minute on failure).'

    $action = New-ScheduledTaskAction -Execute $php -Argument $argumentList -WorkingDirectory $appRoot
    $trigger = New-ScheduledTaskTrigger -AtStartup
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet `
        -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries `
        -RestartInterval (New-TimeSpan -Minutes 1) `
        -RestartCount 999 `
        -ExecutionTimeLimit (New-TimeSpan -Seconds 0) `
        -MultipleInstances IgnoreNew

    Register-ScheduledTask -TaskName $ServiceName -Action $action -Trigger $trigger `
        -Principal $principal -Settings $settings `
        -Description 'Laravel Reverb websocket server for the HostVexa chat.' | Out-Null

    Start-ScheduledTask -TaskName $ServiceName
    Write-Host ("Scheduled task '{0}' registered and started." -f $ServiceName)
}

Write-Host ''
Write-Host 'Next: expose it through IIS with scripts\iis-reverb-proxy.ps1 -WhatIf'
