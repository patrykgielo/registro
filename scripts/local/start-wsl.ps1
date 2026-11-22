<#
.SYNOPSIS
    ONE command to bring the local Registro stack up on this Windows machine.

.DESCRIPTION
    Windows entry point. Windows PowerShell 5.1 compatible, ASCII only.

    1. Starts a hidden WSL keepalive (wsl.exe -d <distro> -- sleep infinity),
       but only if one is not already running. WSL shuts the distro down when no
       Windows-side wsl.exe is attached; the keepalive is what keeps the
       containers alive while you are idle. Existing processes are only READ
       (Win32_Process command lines), never killed.
    2. Waits until the distro answers.
    3. Runs scripts/local/up.sh inside WSL (docker compose up -d, waits for the
       tenant login to return HTTP 200, final service check).
    4. Prints a final status and exits with the WSL script's exit code.

    Run it like this (the ExecutionPolicy bypass is needed because the script
    lives on a \\wsl.localhost UNC path):

      powershell.exe -NoProfile -ExecutionPolicy Bypass -File "\\wsl.localhost\Ubuntu-24.04\var\www\projects\registro\app\scripts\local\start-wsl.ps1"

.PARAMETER Status
    Report only. Starts nothing (no keepalive, no WSL, no containers).

.PARAMETER InstallAutostart
    Register a Scheduled Task (current user, at logon, hidden) that runs this
    script. Does NOT run it now. Add -WhatIf to see what would be registered.

.PARAMETER RemoveAutostart
    Remove that Scheduled Task.

.NOTES
    Exit codes: the WSL script's own (see scripts/local/up.sh: 10..16), or
    20 wsl.exe missing / 21 distro not installed / 22 keepalive failed to start /
    23 distro never answered / 24 could not run the autostart task action.
#>
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [switch]$Status,
    [switch]$InstallAutostart,
    [switch]$RemoveAutostart,
    [string]$Distro = 'Ubuntu-24.04',
    [string]$Tenant = 'firma',
    [int]$TimeoutSec = 120,
    [string]$ProjectPath = '/var/www/projects/registro/app'
)

# Native commands (wsl.exe) write to stderr in normal operation; under 'Stop'
# Windows PowerShell 5.1 would turn that into a terminating error.
$ErrorActionPreference = 'Continue'
$TaskName = 'Registro Local Start'

function Write-Step($text) { Write-Host ('==> ' + $text) -ForegroundColor Cyan }
function Write-Good($text) { Write-Host ('    ' + $text) -ForegroundColor Green }
function Write-Warn($text) { Write-Host ('    ' + $text) -ForegroundColor Yellow }
function Write-Bad($text)  { Write-Host ('    ' + $text) -ForegroundColor Red }

# wsl.exe -l prints UTF-16 by default, UTF-8 when WSL_UTF8=1 is set in the
# environment. Decoding must match, or the names come back with NULs between
# every character (UTF-16 read as OEM) or as garbage (UTF-8 read as UTF-16) and
# no comparison ever matches. NULs are stripped as a last guard. Names only
# (--quiet): the STATE column is localised ("Running" is not "Running" on a
# Polish Windows), so it is never compared.
function Get-WslNames([string[]]$WslArgs) {
    $previous = [Console]::OutputEncoding
    try {
        if ($env:WSL_UTF8 -eq '1') {
            [Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
        } else {
            [Console]::OutputEncoding = [System.Text.Encoding]::Unicode
        }
        $lines = & wsl.exe @WslArgs
    } finally {
        [Console]::OutputEncoding = $previous
    }
    $names = @()
    foreach ($line in @($lines)) {
        $t = (([string]$line) -replace [char]0, '').Trim()
        if ($t) { $names += $t }
    }
    return $names
}

function Test-DistroInstalled { return (@(Get-WslNames @('-l', '-q')) -contains $Distro) }
function Test-DistroRunning   { return (@(Get-WslNames @('-l', '--running', '-q')) -contains $Distro) }

# The keepalive is recognised by its exact command line, not by "any wsl.exe":
# other wsl.exe processes (a terminal, a tool run) must never match, and never
# be touched.
# wsl.exe spawns a child wsl.exe with the IDENTICAL command line, so one
# keepalive shows up as two matching processes (parent + child). Only the ROOT
# of each such pair is a keepalive: a match whose ParentProcessId is itself a
# match is the child and is not counted.
function Get-Keepalive {
    $pattern = '-d\s+' + [regex]::Escape($Distro) + '\s+--\s+sleep\s+infinity\s*$'
    $matching = @()
    foreach ($p in @(Get-CimInstance Win32_Process -Filter "Name='wsl.exe'")) {
        if ($p.CommandLine -and ($p.CommandLine -match $pattern)) { $matching += $p }
    }
    $matchingIds = @($matching | ForEach-Object { [int]$_.ProcessId })
    $roots = @()
    foreach ($p in $matching) {
        if ($matchingIds -notcontains [int]$p.ParentProcessId) { $roots += $p }
    }
    return $roots
}

function Test-HostsEntry([string]$Name) {
    $hostsFile = Join-Path $env:SystemRoot 'System32\drivers\etc\hosts'
    try { $lines = Get-Content -LiteralPath $hostsFile -ErrorAction Stop } catch { return $null }
    foreach ($line in $lines) {
        $t = $line.Trim()
        if ($t.StartsWith('#') -or -not $t) { continue }
        $tokens = $t -split '\s+'
        if ($tokens.Count -ge 2 -and ($tokens[1..($tokens.Count - 1)] -contains $Name)) { return $true }
    }
    return $false
}

function Write-HostsAdvice {
    foreach ($n in @('registro.local', ($Tenant + '.registro.local'))) {
        $r = Test-HostsEntry $n
        if ($r -eq $true) {
            Write-Good ('hosts: ' + $n + ' present')
        } elseif ($r -eq $false) {
            Write-Warn ('hosts: ' + $n + ' is MISSING from the Windows hosts file -- the browser will not find it.')
            Write-Warn ('       fix (admin PowerShell): C:\projects\registro\.local-setup\add-hosts.ps1 ' + $Tenant)
        } else {
            Write-Warn ('hosts: could not read the hosts file; cannot tell whether ' + $n + ' is present.')
        }
    }
}

function Get-AutostartTask {
    try { return (Get-ScheduledTask -TaskName $TaskName -ErrorAction Stop) } catch { return $null }
}

# ---- autostart ---------------------------------------------------------------

if ($InstallAutostart -or $RemoveAutostart) {
    if ($InstallAutostart -and $RemoveAutostart) {
        Write-Bad 'Pass either -InstallAutostart or -RemoveAutostart, not both.'
        exit 2
    }

    if ($RemoveAutostart) {
        if (-not (Get-AutostartTask)) {
            Write-Good ("Scheduled Task '" + $TaskName + "' is not registered; nothing to remove.")
            exit 0
        }
        if ($PSCmdlet.ShouldProcess($TaskName, 'Unregister scheduled task')) {
            try {
                Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction Stop
            } catch {
                Write-Bad ('Could not remove the task: ' + $_.Exception.Message)
                exit 24
            }
            if (Get-AutostartTask) { Write-Bad 'The task is still registered.'; exit 24 }
            Write-Good ("Removed Scheduled Task '" + $TaskName + "'.")
        }
        exit 0
    }

    $self = $PSCommandPath
    if (-not $self) { Write-Bad 'Cannot determine this script''s own path.'; exit 24 }
    $logFile = Join-Path $env:LOCALAPPDATA 'registro-local-start.log'
    $who = $env:USERDOMAIN + '\' + $env:USERNAME

    # The script lives on \\wsl.localhost, which exists only while the distro
    # runs -- and at logon it does not. So the task first starts the distro
    # (wsl -- true), waits for the UNC path to appear, and only then runs the
    # script. The output of the hidden run goes to a log file.
    $inner = @'
& wsl.exe -d __DISTRO__ -- true; $p = '__SELF__'; for ($i = 0; $i -lt 30 -and -not (Test-Path -LiteralPath $p); $i++) { Start-Sleep -Seconds 2 }; & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $p -Distro '__DISTRO__' -Tenant '__TENANT__' -TimeoutSec __TIMEOUT__ 2>&1 | Out-File -Encoding ascii -FilePath '__LOG__'
'@
    $inner = $inner.Replace('__DISTRO__', $Distro).Replace('__SELF__', $self).Replace('__TENANT__', $Tenant).Replace('__TIMEOUT__', [string]$TimeoutSec).Replace('__LOG__', $logFile).Trim()
    $argString = '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -Command "' + $inner + '"'

    Write-Step ("Scheduled Task '" + $TaskName + "'")
    Write-Host ('    user    : ' + $who + ' (at logon, no admin rights requested)')
    Write-Host ('    action  : powershell.exe ' + $argString)
    Write-Host ('    log     : ' + $logFile + ' (last run only)')

    try {
        $action    = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $argString
        $trigger   = New-ScheduledTaskTrigger -AtLogOn -User $who
        # The task action itself ends in about 2 minutes (start the distro, run
        # the script), so the default 72 h ExecutionTimeLimit is not what
        # matters here; zero (= unlimited) is just not adding a second way to
        # be killed.
        # THE REAL OPEN QUESTION, UNVERIFIED: the hidden keepalive is started by
        # Start-Process from inside this task's process tree. Whether it
        # SURVIVES once the task action exits / the task ends (Task Scheduler
        # may tear the tree down) has never been run. Verification steps for
        # the owner are in app/docs/guides/lokalny-start-wsl.md ("Autostart").
        $settings  = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew
        $principal = New-ScheduledTaskPrincipal -UserId $who -LogonType Interactive -RunLevel Limited
        if ($PSCmdlet.ShouldProcess($TaskName, 'Register scheduled task')) {
            Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force -ErrorAction Stop | Out-Null
            if (-not (Get-AutostartTask)) { Write-Bad 'Register-ScheduledTask returned but the task is not there.'; exit 24 }
            Write-Good 'Registered. It will run at your next logon. To undo: -RemoveAutostart'
        }
    } catch {
        Write-Bad ('Could not register the task: ' + $_.Exception.Message)
        Write-Bad 'If this says "Access is denied", re-run from an elevated (Run as administrator) PowerShell.'
        exit 24
    }
    exit 0
}

# ---- bring the stack up ------------------------------------------------------

if (-not (Get-Command wsl.exe -ErrorAction SilentlyContinue)) {
    Write-Bad 'wsl.exe not found. Is WSL installed?'
    exit 20
}
if (-not (Test-DistroInstalled)) {
    Write-Bad ("WSL distro '" + $Distro + "' is not installed (see: wsl.exe -l -v). Use -Distro <name> if it is named differently.")
    exit 21
}

$title = 'Registro local'
if ($Status) { $title = $title + ' (status only: nothing will be started)' }
Write-Step $title

# 1. keepalive
$keep = @(Get-Keepalive)
if ($keep.Count -gt 0) {
    $ids = ($keep | ForEach-Object { $_.ProcessId }) -join ', '
    Write-Good ('keepalive: already running (PID ' + $ids + '); not starting another.')
    if ($keep.Count -gt 1) {
        Write-Warn ('           ' + $keep.Count + ' keepalives exist. Harmless (one is enough); left alone, nothing is killed.')
    }
} elseif ($Status) {
    Write-Warn 'keepalive: NOT running -- WSL will stop itself when idle. Run without -Status to start it.'
} else {
    Write-Step 'starting hidden keepalive (wsl.exe -d <distro> -- sleep infinity)'
    try {
        Start-Process -FilePath 'wsl.exe' -ArgumentList @('-d', $Distro, '--', 'sleep', 'infinity') -WindowStyle Hidden -ErrorAction Stop
    } catch {
        Write-Bad ('could not start the keepalive: ' + $_.Exception.Message)
        exit 22
    }
    Start-Sleep -Seconds 2
    $keep = @(Get-Keepalive)
    if ($keep.Count -eq 0) {
        Write-Bad 'keepalive was started but its process is not visible; WSL may stop when idle.'
        exit 22
    }
    Write-Good ('keepalive: started (PID ' + (($keep | ForEach-Object { $_.ProcessId }) -join ', ') + ')')
}

# 2. distro
if ($Status) {
    if (-not (Test-DistroRunning)) {
        Write-Bad ("distro '" + $Distro + "': NOT running. Run without -Status to start it.")
        $task = Get-AutostartTask
        if ($task) { Write-Host ('    autostart: task registered (' + $task.State + ')') } else { Write-Host '    autostart: not installed' }
        Write-HostsAdvice
        exit 1
    }
} else {
    Write-Step ("waiting for distro '" + $Distro + "'")
    $up = $false
    for ($i = 1; $i -le 30; $i++) {
        & wsl.exe -d $Distro -- true 2>$null
        if ($LASTEXITCODE -eq 0 -and (Test-DistroRunning)) { $up = $true; break }
        Start-Sleep -Seconds 2
    }
    if (-not $up) {
        Write-Bad ("distro '" + $Distro + "' did not answer within 60 s.")
        exit 23
    }
    Write-Good 'distro: running'
}

# 3. the WSL-side script (streams its own output)
$upArgs = @('-d', $Distro, '--', 'bash', ($ProjectPath + '/scripts/local/up.sh'), '--tenant', $Tenant, '--timeout', [string]$TimeoutSec)
if ($Status) { $upArgs += '--status' }
Write-Step 'running scripts/local/up.sh inside WSL'
& wsl.exe @upArgs
$rc = $LASTEXITCODE

# 4. final status
Write-Host ''
$task = Get-AutostartTask
if ($task) { Write-Host ('autostart: Scheduled Task registered (' + $task.State + ')') } else { Write-Host 'autostart: not installed (optional: -InstallAutostart)' }
Write-HostsAdvice
Write-Host ''
if ($rc -eq 0) {
    Write-Host 'RESULT: OK' -ForegroundColor Green
    Write-Host ('  https://' + $Tenant + '.registro.local:8444/admin/login')
    Write-Host '  https://registro.local:8444'
} else {
    Write-Host ('RESULT: FAILED (exit ' + $rc + ') -- see the message above; scripts/local/up.sh header lists the codes.') -ForegroundColor Red
}
exit $rc
