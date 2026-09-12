<#
.SYNOPSIS
    Exposes the Reverb websocket server through IIS.

.DESCRIPTION
    Reverb speaks the pusher protocol on /app/* (client sockets) and /apps/*
    (server publish). Behind IIS those two prefixes have to be reverse-proxied
    to the loopback port Reverb listens on, and IIS has to be willing to hand a
    connection over to the WebSocket protocol at all.

    Two things must be true on the machine:

      1. The "WebSocket Protocol" Windows feature is installed. Without it IIS
         answers the Upgrade handshake with 400 and the browser silently falls
         back to polling.
      2. Application Request Routing (ARR) is installed AND its proxy is
         enabled. Without ARR, a rewrite rule of type "Rewrite" to an
         http://... URL is not an error you can debug from the browser - IIS
         returns 500.19 for the WHOLE SITE, because the rule references a
         module that is not there.

    That second point is why the proxy rule is NOT shipped in
    public/web.config. A rule that breaks every page of the panel on machines
    without ARR is a far worse default than a chat that falls back to polling.
    This script adds it only when the prerequisites are actually present, and
    backs the file up first.

.PARAMETER WhatIf
    Report the state of every prerequisite and print the rule that would be
    added. Changes nothing. This is the default behaviour.

.PARAMETER Apply
    Write the rewrite rule into public/web.config (after a timestamped backup).

.PARAMETER Remove
    Take the rule back out again.

.PARAMETER InstallPrerequisites
    Install the WebSocket Protocol Windows feature if it is missing. ARR is a
    separate download and cannot be installed this way; the script will say so.

.PARAMETER Port
    The loopback port Reverb listens on. Must match scripts\reverb-service.ps1.
    Default 8081.

.EXAMPLE
    powershell -File scripts\iis-reverb-proxy.ps1 -WhatIf

.EXAMPLE
    powershell -File scripts\iis-reverb-proxy.ps1 -Apply
#>
[CmdletBinding()]
param(
    [switch] $WhatIf,
    [switch] $Apply,
    [switch] $Remove,
    [switch] $InstallPrerequisites,
    [int]    $Port = 8081
)

$ErrorActionPreference = 'Stop'

$appRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$webConfig = Join-Path $appRoot 'public\web.config'
$ruleName = 'Reverb WebSocket Proxy'

if (-not (Test-Path -LiteralPath $webConfig)) {
    throw "public\web.config not found at $webConfig"
}

function Test-WebSocketFeature {
    $feature = Get-WindowsOptionalFeature -Online -FeatureName 'IIS-WebSockets' -ErrorAction SilentlyContinue
    if ($feature) { return $feature.State -eq 'Enabled' }

    # Windows Server exposes it through Get-WindowsFeature instead.
    $serverFeature = Get-WindowsFeature -Name 'Web-WebSockets' -ErrorAction SilentlyContinue
    if ($serverFeature) { return [bool] $serverFeature.Installed }

    return $null
}

function Test-ArrInstalled {
    $dll = 'C:\Program Files\IIS\Application Request Routing\requestRouter.dll'
    if (Test-Path -LiteralPath $dll) { return $true }

    $key = 'HKLM:\SOFTWARE\Microsoft\IIS Extensions\Application Request Routing'
    return (Test-Path -LiteralPath $key)
}

$webSockets = Test-WebSocketFeature
$arr = Test-ArrInstalled

Write-Host 'IIS prerequisites for Reverb'
Write-Host '----------------------------'
Write-Host ("  web.config          : {0}" -f $webConfig)
Write-Host ("  Reverb port         : {0}" -f $Port)
if ($null -eq $webSockets) {
    Write-Host '  WebSocket Protocol  : UNKNOWN (not running on a Windows IIS host?)'
} else {
    Write-Host ("  WebSocket Protocol  : {0}" -f $(if ($webSockets) { 'installed' } else { 'MISSING' }))
}
Write-Host ("  ARR (reverse proxy) : {0}" -f $(if ($arr) { 'installed' } else { 'MISSING' }))
Write-Host ''

$ruleXml = @"
        <rule name="$ruleName" stopProcessing="true">
          <match url="^(app|apps)(/.*)?$" />
          <action type="Rewrite" url="http://127.0.0.1:$Port/{R:0}" />
        </rule>
"@

if ($Remove) {
    [xml] $xml = Get-Content -LiteralPath $webConfig -Raw
    $rules = $xml.configuration.'system.webServer'.rewrite.rules
    $existing = $rules.rule | Where-Object { $_.name -eq $ruleName }

    if (-not $existing) {
        Write-Host 'The proxy rule is not present; nothing to remove.'
        return
    }

    $backup = "$webConfig.$(Get-Date -Format 'yyyyMMdd-HHmmss').bak"
    Copy-Item -LiteralPath $webConfig -Destination $backup
    $rules.RemoveChild($existing) | Out-Null
    $xml.Save($webConfig)
    Write-Host ("Removed. Backup: {0}" -f $backup)
    return
}

if ($InstallPrerequisites) {
    if ($webSockets -eq $false) {
        Write-Host 'Installing the WebSocket Protocol feature...'
        if (Get-Command Install-WindowsFeature -ErrorAction SilentlyContinue) {
            Install-WindowsFeature -Name 'Web-WebSockets' | Out-Null
        } else {
            Enable-WindowsOptionalFeature -Online -FeatureName 'IIS-WebSockets' -All -NoRestart | Out-Null
        }
        Write-Host 'Done.'
    } else {
        Write-Host 'WebSocket Protocol is already present (or could not be determined).'
    }

    if (-not $arr) {
        Write-Host ''
        Write-Host 'ARR is NOT installed and cannot be installed from here.'
        Write-Host 'Download "Application Request Routing 3.0" from Microsoft, install it, then'
        Write-Host 'enable the proxy: IIS Manager > server node > Application Request Routing Cache'
        Write-Host '> Server Proxy Settings > tick "Enable proxy".'
    }
    return
}

if ($Apply) {
    if (-not $arr) {
        throw @'
Refusing to add the rewrite rule: ARR is not installed.

A Rewrite action pointing at an http:// URL without ARR makes IIS return
500.19 for the entire site, not just the chat. Install ARR first, or use the
fallback described by -WhatIf.
'@
    }

    if ($webSockets -eq $false) {
        throw 'Refusing to add the rule: the WebSocket Protocol feature is missing. Run -InstallPrerequisites first.'
    }

    [xml] $xml = Get-Content -LiteralPath $webConfig -Raw
    $rules = $xml.configuration.'system.webServer'.rewrite.rules

    if ($rules.rule | Where-Object { $_.name -eq $ruleName }) {
        Write-Host 'The proxy rule is already present; nothing to do.'
        return
    }

    $backup = "$webConfig.$(Get-Date -Format 'yyyyMMdd-HHmmss').bak"
    Copy-Item -LiteralPath $webConfig -Destination $backup

    $fragment = $xml.CreateDocumentFragment()
    $fragment.InnerXml = $ruleXml
    # Prepended: the Laravel front-controller rule below matches ^(.*)$ and
    # would otherwise swallow /app/... before the proxy ever sees it.
    $rules.PrependChild($fragment.FirstChild) | Out-Null

    $webServer = $xml.configuration.'system.webServer'
    if (-not $webServer.webSocket) {
        $webSocketNode = $xml.CreateElement('webSocket')
        $webSocketNode.SetAttribute('enabled', 'true')
        $webServer.AppendChild($webSocketNode) | Out-Null
    }

    $xml.Save($webConfig)

    Write-Host ("Applied. Backup: {0}" -f $backup)
    Write-Host ''
    Write-Host 'Now point the browser at the public host by setting, in .env:'
    Write-Host '    VITE_REVERB_HOST=your.public.hostname'
    Write-Host '    VITE_REVERB_SCHEME=https'
    Write-Host '    VITE_REVERB_PORT=443'
    Write-Host 'then rebuild the assets: npm run build'
    return
}

# Default: report only.
Write-Host 'The rule that would be added to public\web.config, as the FIRST rewrite rule'
Write-Host '(the Laravel front controller matches ^(.*)$ and would otherwise swallow /app/...):'
Write-Host ''
Write-Host $ruleXml
Write-Host ''
Write-Host 'plus <webSocket enabled="true" /> under <system.webServer>.'
Write-Host ''

if ($arr -and $webSockets -ne $false) {
    Write-Host 'Prerequisites look satisfied. Re-run with -Apply to write the change.'
} else {
    Write-Host 'FALLBACK if ARR cannot be installed:'
    Write-Host '  Do not proxy at all. Expose Reverb on its own TLS port and point the browser'
    Write-Host '  straight at it:'
    Write-Host '     - open the port on the firewall,'
    Write-Host '     - give Reverb a certificate (REVERB_SERVER_HOST=0.0.0.0 plus the tls options'
    Write-Host '       in config/reverb.php),'
    Write-Host '     - set VITE_REVERB_PORT to that port and VITE_REVERB_SCHEME=https,'
    Write-Host '     - npm run build.'
    Write-Host '  The chat then bypasses IIS entirely.'
}

Write-Host ''
Write-Host 'Verify the upgrade handshake once it is in place:'
Write-Host '  curl -i -N -H "Connection: Upgrade" -H "Upgrade: websocket" `'
Write-Host '       -H "Sec-WebSocket-Version: 13" -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" `'
Write-Host '       http://127.0.0.1/app/$env:REVERB_APP_KEY'
Write-Host '  Expect: 101 Switching Protocols   (400 = WebSocket feature off, 404 = rule missing)'
