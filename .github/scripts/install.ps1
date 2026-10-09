# Install or update the latest stable Windows x64 release in a user-owned folder.
# Usage: irm https://raw.githubusercontent.com/runchance/rcmaker-codex-gateway/master/.github/scripts/install.ps1 | iex
# To reuse a different installation: $env:RCMAKER_INSTALL_DIR = 'D:\Apps\RCMAKER-Gateway'
param([string]$InstallDir = $env:RCMAKER_INSTALL_DIR)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
Set-StrictMode -Version Latest

function Get-ReleaseFileHash {
    param([string]$File)

    $sha = [System.Security.Cryptography.SHA256]::Create()
    $stream = [IO.File]::OpenRead($File)
    try {
        return [BitConverter]::ToString($sha.ComputeHash($stream)).Replace('-', '').ToLowerInvariant()
    } finally {
        $stream.Dispose()
        $sha.Dispose()
    }
}

function Assert-ReleaseHash {
    param([string]$File, [string]$Name, [string[]]$Lines)

    $expected = @()
    foreach ($line in $Lines) {
        $match = [regex]::Match($line, '^([a-f0-9]{64})  (.+)$')
        if ($match.Success -and $match.Groups[2].Value -ceq $Name) {
            $expected += $match.Groups[1].Value
        }
    }
    if ($expected.Count -ne 1) { throw "Missing or duplicate SHA256SUMS entry: $Name" }
    $actual = Get-ReleaseFileHash -File $File
    if ($actual -cne $expected[0]) { throw "SHA-256 verification failed: $Name" }
    return $expected[0]
}

$architecture = if ($env:PROCESSOR_ARCHITEW6432) { $env:PROCESSOR_ARCHITEW6432 } else { $env:PROCESSOR_ARCHITECTURE }
if ($architecture -ne 'AMD64') { throw "Windows x64 is the only published Windows architecture (detected: $architecture)." }

# Windows PowerShell 5.1 may otherwise negotiate obsolete TLS versions.
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12

$repository = 'runchance/rcmaker-codex-gateway'
$headers = @{ 'User-Agent' = 'rcmaker-codex-gateway-installer'; 'Accept' = 'application/vnd.github+json' }
try {
    $release = Invoke-RestMethod -Uri "https://api.github.com/repos/$repository/releases/latest" -Headers $headers
} catch {
    throw "Cannot read the latest public GitHub Release for $repository. Confirm that a stable Release is published and GitHub is reachable. $($_.Exception.Message)"
}
$tag = [string]$release.tag_name
if ($release.draft -or $release.prerelease -or $tag -cnotmatch '^v[0-9]+\.[0-9]+\.[0-9]+$') {
    throw "Unexpected latest Release version: $tag"
}

$archiveName = "rcmaker-codex-gateway-$tag-windows-x86_64.zip"
$names = @($archiveName, 'SHA256SUMS')
$urls = @{}
foreach ($name in $names) {
    $matchesForName = @($release.assets | Where-Object { $_.name -ceq $name -and $_.state -eq 'uploaded' })
    if ($matchesForName.Count -ne 1) { throw "Latest Release $tag is missing $name" }
    $url = [string]$matchesForName[0].browser_download_url
    $expectedUrl = "https://github.com/$repository/releases/download/$tag/$name"
    if ($url -cne $expectedUrl) { throw "Unrecognized GitHub asset URL for $name" }
    $urls[$name] = $url
}

if ([string]::IsNullOrWhiteSpace($InstallDir)) {
    $InstallDir = Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'rcmaker-codex-gateway'
}
$InstallDir = [IO.Path]::GetFullPath($InstallDir)
[void][IO.Directory]::CreateDirectory($InstallDir)
$target = Join-Path $InstallDir 'rcmaker-codex-gateway.exe'
$stage = Join-Path $InstallDir ('.install-' + [Guid]::NewGuid().ToString('N'))
[void][IO.Directory]::CreateDirectory($stage)

try {
    Write-Host "Downloading $tag for Windows x64..."
    foreach ($name in $names) {
        Invoke-WebRequest -Uri $urls[$name] -Headers $headers -OutFile (Join-Path $stage $name) -TimeoutSec 900
    }
    $lines = @(Get-Content -LiteralPath (Join-Path $stage 'SHA256SUMS') -Encoding ASCII)
    [void](Assert-ReleaseHash -File (Join-Path $stage $archiveName) -Name $archiveName -Lines $lines)

    $extracted = Join-Path $stage 'extracted'
    [void][IO.Directory]::CreateDirectory($extracted)
    & tar.exe -xf (Join-Path $stage $archiveName) -C $extracted
    if ($LASTEXITCODE -ne 0) { throw 'Windows built-in tar could not extract the Release ZIP.' }
    $name = 'rcmaker-codex-gateway.exe'
    $binary = Join-Path $extracted $name
    if (-not (Test-Path -LiteralPath $binary -PathType Leaf)) { throw "Release archive is missing $name" }
    $newHash = Assert-ReleaseHash -File $binary -Name "rcmaker-codex-gateway-$tag-windows-x86_64.exe" -Lines $lines

    if ((Test-Path -LiteralPath $target -PathType Leaf) -and
        (Get-ReleaseFileHash -File $target) -ceq $newHash) {
        Write-Host "Already up to date: $tag ($target)"
        return
    }

    # Sample and licensing files are separate from user-owned .env and data/.
    foreach ($extra in @('LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES', '.env.example')) {
        $source = Join-Path $extracted $extra
        if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
            if ($extra -eq '.env.example') { continue }
            throw "Release archive is missing $extra"
        }
        $destination = Join-Path $InstallDir $extra
        if (-not (Test-Path -LiteralPath $destination)) {
            Copy-Item -LiteralPath $source -Destination $destination
        }
    }

    $old = Join-Path $stage 'previous.exe'
    $hadOld = Test-Path -LiteralPath $target -PathType Leaf
    if ($hadOld) {
        try { Move-Item -LiteralPath $target -Destination $old -ErrorAction Stop }
        catch { throw "Close rcmaker-codex-gateway.exe before updating. $($_.Exception.Message)" }
    }
    try {
        Move-Item -LiteralPath $binary -Destination $target -ErrorAction Stop
    } catch {
        if ($hadOld -and (Test-Path -LiteralPath $old)) {
            Move-Item -LiteralPath $old -Destination $target -ErrorAction Stop
        }
        throw
    }
    if ($hadOld) {
        try { Copy-Item -LiteralPath $old -Destination "$target.previous" -Force }
        catch { Write-Warning "Updated successfully, but could not save the previous executable: $($_.Exception.Message)" }
    }
    Write-Host "Installed $tag at $target"
    Write-Host 'The gateway was not started. Launch it after stopping any previous instance, and wait for its Ready message before starting/restarting Codex.'
} finally {
    $strandedOld = Join-Path $stage 'previous.exe'
    if ((Test-Path -LiteralPath $strandedOld -PathType Leaf) -and
        -not (Test-Path -LiteralPath $target -PathType Leaf)) {
        Write-Warning "Previous executable retained for manual recovery: $strandedOld"
    } elseif (Test-Path -LiteralPath $stage) {
        Remove-Item -LiteralPath $stage -Recurse -Force
    }
}
