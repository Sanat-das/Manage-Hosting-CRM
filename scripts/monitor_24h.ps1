$LOG="storage/logs/deploy-monitor.log"
New-Item -ItemType Directory -Force -Path "storage/logs" | Out-Null
$ts = Get-Date -Format "o"
"[$ts] 24h monitor started" | Tee-Object -FilePath $LOG -Append
$http = 0
try { $http = (Invoke-WebRequest -Uri "http://managehosting.local/up" -UseBasicParsing -TimeoutSec 5).StatusCode } catch { $http = 0 }
$dbcheck = php scripts/check_health.php 2>&1
$dbcheck = $dbcheck.Trim()
$status = "OK"
if ($http -ne 200) { $status = "FAIL http $http" }
if ($dbcheck -notlike "*perms=97*") { $status = "FAIL db $dbcheck" }
"[$ts] hour 1/24 http:$http db:$dbcheck $status" | Tee-Object -FilePath $LOG -Append
if ($status -ne "OK") { "ALERT: $status - rollback: bash scripts/rollback.sh storage/backups/pre_deploy_*.sql" | Tee-Object -FilePath $LOG -Append }
"--- first check complete, remaining 23h via Task Scheduler (hourly) ---" | Tee-Object -FilePath $LOG -Append
