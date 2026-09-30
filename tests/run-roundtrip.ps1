#Requires -Version 5.1
<#
.SYNOPSIS
    Run tests/roundtrip-test.php against the local Docker database containers.

.DESCRIPTION
    Starts jisento-mariadb (port 3307), jisento-mysql8 (port 3308), and in full mode
    jisento-mariadb11 (port 3309), waits until they accept root/root connections, then
    runs the roundtrip test in a separate child process per scenario so environment
    variables cannot leak between runs.

    -Quick sets JISENTO_TEST_ROWS=10000 and runs only MariaDB -> MariaDB.

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File tests\run-roundtrip.ps1 -Quick
#>
[CmdletBinding()]
param(
    [switch]$Quick
)

$ErrorActionPreference = 'Stop'

$Root = Split-Path -Parent $PSScriptRoot
$TestScript = Join-Path $Root 'tests\roundtrip-test.php'

function Write-Line {
    param([string]$Text)
    Write-Host $Text
}

function Test-DockerEngine {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        Write-Line "Docker is not running. The docker command was not found. Start Docker Desktop, then run this script again."
        exit 1
    }

    & docker info 1>$null 2>$null
    if ($LASTEXITCODE -ne 0) {
        Write-Line "Docker is not running. Start Docker Desktop, then run this script again."
        exit 1
    }
}

function Start-DbContainer {
    param([string]$Name)

    Write-Line "Starting container $Name ..."
    & docker start $Name
    if ($LASTEXITCODE -ne 0) {
        Write-Line "Failed to start Docker container '$Name'. Docker may not be running, or the container does not exist."
        exit 1
    }
}

function Ensure-Mariadb11 {
    $exists = & docker ps -a --format '{{.Names}}' 2>$null | Where-Object { $_ -eq 'jisento-mariadb11' }
    if (-not $exists) {
        Write-Line "Creating container jisento-mariadb11 (mariadb:11.4 on port 3309) ..."
        & docker run -d --name jisento-mariadb11 -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=jisento_test -p 3309:3306 mariadb:11.4
        if ($LASTEXITCODE -ne 0) {
            Write-Line "Failed to create Docker container 'jisento-mariadb11'."
            exit 1
        }
        return
    }
    Start-DbContainer -Name 'jisento-mariadb11'
}

function Ensure-Mariadb106 {
    $exists = & docker ps -a --format '{{.Names}}' 2>$null | Where-Object { $_ -eq 'jisento-mariadb106' }
    if (-not $exists) {
        Write-Line "Creating container jisento-mariadb106 (mariadb:10.6 on port 3310) ..."
        & docker run -d --name jisento-mariadb106 -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=jisento_test -p 3310:3306 mariadb:10.6
        if ($LASTEXITCODE -ne 0) {
            Write-Line "Failed to create Docker container 'jisento-mariadb106'."
            exit 1
        }
        return
    }
    Start-DbContainer -Name 'jisento-mariadb106'
}

function Wait-DbReady {
    param(
        [int]$Port,
        [string]$Label
    )

    if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
        Write-Line "php was not found on PATH, so $Label on port $Port cannot be checked."
        exit 1
    }

    Write-Line "Waiting for $Label (127.0.0.1:${Port}, root/root) ..."
    $deadline = (Get-Date).AddSeconds(120)
    $phpCode = "mysqli_report(MYSQLI_REPORT_OFF); exit(@mysqli_connect('127.0.0.1','root','root','',$Port) ? 0 : 1);"

    while ($true) {
        & php -r $phpCode 1>$null 2>$null
        if ($LASTEXITCODE -eq 0) {
            Write-Line "$Label is accepting connections on port $Port."
            return
        }

        $remaining = ($deadline - (Get-Date)).TotalSeconds
        if ($remaining -le 0) {
            Write-Line "Timed out after 120 seconds waiting for $Label on 127.0.0.1:${Port} (root/root)."
            exit 1
        }

        $pause = 2
        if ($remaining -lt $pause) {
            $pause = $remaining
        }
        Start-Sleep -Seconds $pause
    }
}

function Show-OutputLine {
    param([string]$Line)

    # FAIL lines are always shown, including a failed segment-hash check.
    if ($Line -match '^\s*FAIL\b') {
        Write-Line $Line
        return
    }

    if ($Line -match '^\s*OK\s+segment hash matches bytes') {
        return
    }

    Write-Line $Line
}

function Add-RunObservation {
    param(
        $State,
        [string]$Line
    )

    if ($Line -match '^\s*Source server:') {
        if (-not $State.Source) {
            $State.Source = $Line.Trim()
        }
    }

    if ($Line -match '^\s*(\d+) passed, (\d+) failed\s*$') {
        $State.Tally = $Line.Trim()
        $State.FailedCount = [int]$Matches[2]
    }

    if ($Line -match '^\s*SKIP\b') {
        [void]$State.Skips.Add($Line.Trim())
    }

    if ($Line -match '^\s*FAIL\b') {
        $State.SawFail = $true
    }
}

function Receive-ErrorText {
    param(
        $State,
        [string]$Text
    )

    if ([string]::IsNullOrEmpty($Text)) {
        return
    }

    $lines = $Text -split '\r?\n'
    if ($lines.Count -gt 0 -and $lines[$lines.Count - 1] -eq '') {
        if ($lines.Count -eq 1) {
            return
        }
        $lines = $lines[0..($lines.Count - 2)]
    }

    foreach ($line in $lines) {
        Show-OutputLine $line
        Add-RunObservation $State $line
    }
}

function Invoke-PhpTestRun {
    param(
        [string]$Title,
        [string]$Script,
        [hashtable]$Variables
    )

    $phpExe = (Get-Command php -ErrorAction SilentlyContinue).Source
    if (-not $phpExe) {
        Write-Line "php was not found on PATH."
        exit 1
    }

    $scriptPath = Join-Path $Root $Script
    if (-not (Test-Path -LiteralPath $scriptPath)) {
        Write-Line "Cannot find $Script."
        exit 1
    }

    Write-Line ""
    Write-Line "========== $Title =========="

    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $phpExe
    $psi.Arguments = $Script
    $psi.WorkingDirectory = $Root
    $psi.UseShellExecute = $false
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.CreateNoWindow = $true

    # Drop inherited JISENTO_TEST_* values, then set only this run's variables.
    $inherited = @($psi.EnvironmentVariables.Keys)
    foreach ($name in $inherited) {
        if ([string]$name -like 'JISENTO_TEST_*') {
            [void]$psi.EnvironmentVariables.Remove([string]$name)
        }
    }
    foreach ($name in $Variables.Keys) {
        $psi.EnvironmentVariables[[string]$name] = [string]$Variables[$name]
    }

    $proc = New-Object System.Diagnostics.Process
    $proc.StartInfo = $psi

    $state = @{
        Source      = $null
        Tally       = $null
        FailedCount = 0
        Skips       = New-Object System.Collections.Generic.List[string]
        SawFail     = $false
    }

    $clock = [System.Diagnostics.Stopwatch]::StartNew()
    [void]$proc.Start()
    $errTask = $proc.StandardError.ReadToEndAsync()

    while ($null -ne ($line = $proc.StandardOutput.ReadLine())) {
        Show-OutputLine $line
        Add-RunObservation $state $line
    }

    [void]$proc.WaitForExit()
    $stderr = ''
    try {
        $stderr = [string]$errTask.Result
    } catch {
        Write-Line "Could not read the test process error stream: $($_.Exception.Message)"
        $state.SawFail = $true
    }
    Receive-ErrorText $state $stderr
    $clock.Stop()

    $exitCode = $proc.ExitCode
    $proc.Dispose()

    $skipped = $state.Skips.Count -gt 0
    $failed = $skipped -or $state.SawFail -or ($null -eq $state.Tally) -or ($state.FailedCount -gt 0) -or ($exitCode -ne 0)

    return @{
        Title    = $Title
        Source   = $state.Source
        Tally    = $state.Tally
        Skips    = @($state.Skips)
        Failed   = [bool]$failed
        ExitCode = $exitCode
        Seconds  = $clock.Elapsed.TotalSeconds
    }
}

function Invoke-RoundtripRun {
    param(
        [string]$Title,
        [hashtable]$Variables
    )

    return Invoke-PhpTestRun -Title $Title -Script 'tests/roundtrip-test.php' -Variables $Variables
}

if (-not (Test-Path -LiteralPath $TestScript)) {
    Write-Line "Cannot find tests/roundtrip-test.php next to this script."
    exit 1
}

Test-DockerEngine
Start-DbContainer -Name 'jisento-mariadb'
Start-DbContainer -Name 'jisento-mysql8'
Wait-DbReady -Port 3307 -Label 'MariaDB (jisento-mariadb)'
Wait-DbReady -Port 3308 -Label 'MySQL 8 (jisento-mysql8)'

if (-not $Quick) {
    Ensure-Mariadb11
    Ensure-Mariadb106
    Wait-DbReady -Port 3309 -Label 'MariaDB 11.4 (jisento-mariadb11)'
    Wait-DbReady -Port 3310 -Label 'MariaDB 10.6 (jisento-mariadb106)'
}

if ($Quick) {
    Write-Line "Quick mode: JISENTO_TEST_ROWS=10000, running MariaDB -> MariaDB only."
}

$runA = @{
    Title = 'MariaDB -> MariaDB'
    Vars  = @{
        JISENTO_TEST_DB_HOST = '127.0.0.1'
        JISENTO_TEST_DB_PORT = '3307'
        JISENTO_TEST_DB_USER = 'root'
        JISENTO_TEST_DB_PASS = 'root'
    }
}
if ($Quick) {
    $runA.Vars['JISENTO_TEST_ROWS'] = '10000'
}

$runs = @($runA)
if (-not $Quick) {
    $runs += @{
        Title = 'MySQL 8 -> MariaDB'
        Vars  = @{
            JISENTO_TEST_SOURCE_DB_HOST = '127.0.0.1'
            JISENTO_TEST_SOURCE_DB_PORT = '3308'
            JISENTO_TEST_SOURCE_DB_USER = 'root'
            JISENTO_TEST_SOURCE_DB_PASS = 'root'
            JISENTO_TEST_DEST_DB_HOST   = '127.0.0.1'
            JISENTO_TEST_DEST_DB_PORT   = '3307'
            JISENTO_TEST_DEST_DB_USER   = 'root'
            JISENTO_TEST_DEST_DB_PASS   = 'root'
        }
    }
    $runs += @{
        Title = 'MariaDB 11.4 -> MariaDB 11.4 (uca1400 kept)'
        Vars  = @{
            JISENTO_TEST_DB_HOST = '127.0.0.1'
            JISENTO_TEST_DB_PORT = '3309'
            JISENTO_TEST_DB_USER = 'root'
            JISENTO_TEST_DB_PASS = 'root'
            JISENTO_TEST_ROWS    = '10000'
        }
    }
    # MariaDB 10.11+ accepts utf8mb*_uca1400_* via CONVERT/COLLATE even though SHOW COLLATION
    # only lists the short name uca1400_ai_ci. MariaDB 10.6 rejects them, so it exercises mapping.
    $runs += @{
        Title = 'MariaDB 11.4 -> MariaDB 10.6 (uca1400 mapped)'
        Vars  = @{
            JISENTO_TEST_SOURCE_DB_HOST = '127.0.0.1'
            JISENTO_TEST_SOURCE_DB_PORT = '3309'
            JISENTO_TEST_SOURCE_DB_USER = 'root'
            JISENTO_TEST_SOURCE_DB_PASS = 'root'
            JISENTO_TEST_DEST_DB_HOST   = '127.0.0.1'
            JISENTO_TEST_DEST_DB_PORT   = '3310'
            JISENTO_TEST_DEST_DB_USER   = 'root'
            JISENTO_TEST_DEST_DB_PASS   = 'root'
            JISENTO_TEST_ROWS           = '10000'
        }
    }
}

$results = @()
foreach ($run in $runs) {
    $results += Invoke-RoundtripRun -Title $run.Title -Variables $run.Vars
}

# Full mode: preserve-mode orphan-author checks need a real DB (not the default SKIP path).
if (-not $Quick) {
    $results += Invoke-PhpTestRun -Title 'Preserve mode (MariaDB)' -Script 'tests/preserve-mode-test.php' -Variables @{
        JISENTO_TEST_DB_HOST = '127.0.0.1'
        JISENTO_TEST_DB_PORT = '3307'
        JISENTO_TEST_DB_USER = 'root'
        JISENTO_TEST_DB_PASS = 'root'
    }
}

Write-Line ""
Write-Line "========== Summary =========="
$anyFailed = $false
foreach ($result in $results) {
    Write-Line $result.Title
    if ($result.Source) {
        Write-Line $result.Source
    } else {
        Write-Line "Source server: (not reported)"
    }
    if ($result.Tally) {
        Write-Line $result.Tally
    } else {
        Write-Line "(no passed/failed line)"
    }
    foreach ($skip in $result.Skips) {
        Write-Line $skip
    }
    Write-Line ("Duration: {0:N1}s" -f $result.Seconds)
    if ($result.Failed) {
        $anyFailed = $true
        if ($result.Skips.Count -gt 0) {
            Write-Line "Result: FAIL (SKIP)"
        } elseif ($result.ExitCode -ne 0) {
            Write-Line "Result: FAIL (exit $($result.ExitCode))"
        } else {
            Write-Line "Result: FAIL"
        }
    } else {
        Write-Line "Result: PASS"
    }
    Write-Line ""
}

if ($anyFailed) {
    exit 1
}
exit 0
