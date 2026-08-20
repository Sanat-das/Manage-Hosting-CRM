# ============================================================
#  managehosting - IIS deployment script (run as Administrator)
#  Installs IIS + URL Rewrite, registers PHP FastCGI, creates
#  the site, sets ACLs, and schedules Laravel background jobs.
# ============================================================
$ErrorActionPreference = 'Continue'

$PHP_DIR    = 'C:\Users\sanat\php84'
$APP_DIR    = 'C:\Users\sanat\Local Sites\managehosting\app'
$PUBLIC_DIR = "$APP_DIR\public"
$HOSTNAME   = 'managehosting.local'
$PORT       = 8080
$POOL       = 'MH-Pool'
$SITE       = 'managehosting'
$LOG        = 'C:\Users\sanat\php84\logs\deploy-iis.log'
$REWRITE_MSI = 'https://download.microsoft.com/download/1/2/8/128E2E22-C1B9-44A4-BE2A-5859ED1D4592/rewrite_amd64_en-US.msi'
$APPCMD      = "$env:windir\System32\inetsrv\appcmd.exe"

function Log([string]$msg) {
    $line = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')  $msg"
    Add-Content -Path $LOG -Value $line -Encoding UTF8
    Write-Host $line
}
function Assert-Admin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    $p  = New-Object Security.Principal.WindowsPrincipal($id)
    return $p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

if (-not (Assert-Admin)) { Log "FATAL: not elevated - aborting"; exit 1 }
Log "=== deploy-iis.ps1 started ==="

# ------------------------------------------------------------
# 0. Port check (must match APP_URL in .env)
# ------------------------------------------------------------
$busy = Get-NetTCPConnection -LocalPort $PORT -State Listen -ErrorAction SilentlyContinue
if ($busy) { Log "FATAL: port $PORT already in use - free it or change APP_URL/.env"; exit 1 }
Log "Port $PORT is free"

# ------------------------------------------------------------
# 1. Enable IIS Windows features (incl. FastCGI via IIS-CGI)
# ------------------------------------------------------------
$features = @('IIS-WebServer','IIS-WebServerManagementTools','IIS-ManagementConsole','IIS-CGI')
foreach ($f in $features) {
    try {
        $state = (Get-WindowsOptionalFeature -Online -FeatureName $f -ErrorAction Stop).State
        if ($state -ne 'Enabled') {
            Log "Enabling Windows feature: $f ..."
            Enable-WindowsOptionalFeature -Online -FeatureName $f -All -NoRestart -ErrorAction Stop | Out-Null
            Log "Enabled $f"
        } else { Log "$f already enabled" }
    } catch { Log "WARN: could not enable $f : $($_.Exception.Message)" }
}

# ------------------------------------------------------------
# 2. URL Rewrite module (winget first, MSI fallback)
# ------------------------------------------------------------
$rewriteDll = "$env:windir\System32\inetsrv\rewrite.dll"
if (-not (Test-Path $rewriteDll)) {
    Log "Installing IIS URL Rewrite 2.1 ..."
    $winget = Get-Command winget -ErrorAction SilentlyContinue
    if ($winget) {
        winget install --id Microsoft.IIS.URLRewrite -e --silent --accept-package-agreements --accept-source-agreements 2>&1 | Out-Null
    }
    if (-not (Test-Path $rewriteDll)) {
        Log "winget failed - downloading MSI ..."
        $msi = "$env:TEMP\rewrite_amd64_en-US.msi"
        try { Invoke-WebRequest -Uri $REWRITE_MSI -OutFile $msi -TimeoutSec 120 -UseBasicParsing; Log "Downloaded $msi" } catch { Log "FATAL: download failed: $($_.Exception.Message)"; exit 1 }
        $p = Start-Process msiexec.exe -ArgumentList "/i `"$msi`" /qn /norestart" -Wait -PassThru
        Log "msiexec exit code: $($p.ExitCode)"
    }
}
if (Test-Path $rewriteDll) { Log "URL Rewrite module OK" } else { Log "FATAL: URL Rewrite not installed"; exit 1 }

# ------------------------------------------------------------
# 3. Register PHP FastCGI application + Authorization header
# ------------------------------------------------------------
Log "Registering PHP FastCGI ..."
& $APPCmd set config -section:system.webServer/fastCgi "/+[fullPath='$PHP_DIR\php-cgi.exe']" | Out-Null
& $APPCmd set config -section:system.webServer/fastCgi "/[fullPath='$PHP_DIR\php-cgi.exe'].instanceMaxRequests:10000" | Out-Null
& $APPCmd set config -section:system.webServer/fastCgi "/[fullPath='$PHP_DIR\php-cgi.exe'].environmentVariables.[name='HTTP_AUTHORIZATION',value='']" | Out-Null
Log "FastCGI registered (incl. HTTP_AUTHORIZATION)"

# ------------------------------------------------------------
# 4. Application pool + IIS site
# ------------------------------------------------------------
Log "Creating application pool $POOL ..."
& $APPCmd delete apppool /apppool.name:$POOL 2>&1 | Out-Null
& $APPCmd add apppool /name:$POOL /managedRuntimeVersion:"" /enable32BitAppOnWin64:false | Out-Null

Log "Creating site $SITE on $HOSTNAME`:$PORT -> $PUBLIC_DIR ..."
& $APPCmd delete site /site.name:$SITE 2>&1 | Out-Null
& $APPCmd add site /name:$SITE /bindings:"http/*:${PORT}:${HOSTNAME}" /physicalPath:"$PUBLIC_DIR" | Out-Null
& $APPCmd set site /site.name:$SITE /[path='/'].applicationPool:$POOL | Out-Null
Log "Site + pool created"

# ------------------------------------------------------------
# 5. ACLs for IIS_IUSRS (covers the MH-Pool app pool identity)
# ------------------------------------------------------------
Log "Setting NTFS permissions ..."
icacls "$APP_DIR\storage"        /grant "IIS_IUSRS:(OI)(CI)M"     /T /Q | Out-Null
icacls "$APP_DIR\bootstrap\cache"/grant "IIS_IUSRS:(OI)(CI)M"     /T /Q | Out-Null
icacls "$APP_DIR"                /grant "IIS_IUSRS:(OI)(CI)RX"    /T /Q | Out-Null
icacls "$PHP_DIR"                /grant "IIS_IUSRS:(OI)(CI)RX"    /T /Q | Out-Null
Log "ACLs applied"

# ------------------------------------------------------------
# 6. Scheduled tasks (Laravel scheduler + queue worker)
# ------------------------------------------------------------
Log "Creating scheduled tasks ..."
schtasks /Create /F /TN "MH\Scheduler" `
    /SC MINUTE /MO 1 `
    /TR "`"$PHP_DIR\php.exe`" `"$APP_DIR\artisan`" schedule:run" `
    /RL LIMITED 2>&1 | Out-Null
schtasks /Create /F /TN "MH\Queue" `
    /SC MINUTE /MO 5 `
    /TR "`"$PHP_DIR\php.exe`" `"$APP_DIR\artisan`" queue:work --stop-when-empty --tries=3 --sleep=3" `
    /RL LIMITED 2>&1 | Out-Null
Log "Scheduled tasks created (MH\Scheduler, MH\Queue)"

# ------------------------------------------------------------
# 7. Start site + health check
# ------------------------------------------------------------
Log "Starting site ..."
& $APPCmd start site /site.name:$SITE | Out-Null
Start-Sleep -Seconds 3
$url = "http://$HOSTNAME`:$PORT/up"
try {
    $r = Invoke-WebRequest -Uri $url -TimeoutSec 30 -UseBasicParsing
    Log "HEALTH CHECK $url -> HTTP $($r.StatusCode)"
} catch {
    Log "HEALTH CHECK FAILED: $($_.Exception.Message)"
}
Log "=== deploy-iis.ps1 finished ==="