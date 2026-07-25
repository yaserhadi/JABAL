# Docs path integrity guard (fail-closed when docs_status: deleted)
#
# Usage (repo root):
#   powershell -NoProfile -File scripts/check-docs-policy.ps1
#   pwsh -File scripts/check-docs-policy.ps1   # when available
# EXIT 0 = pass; EXIT 1 = integrity failure
#
# Authority: Owner GO 2026-07-25; INTEGRITY_RULES → Docs path integrity

$ErrorActionPreference = 'Continue'
$failed = $false

function Write-Fail([string]$Message) {
    Write-Host "FAIL: $Message" -ForegroundColor Red
    $script:failed = $true
}

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $repoRoot

$statePath = Join-Path $repoRoot '.cursor\memory\STATE.yaml'
if (-not (Test-Path $statePath)) {
    Write-Fail "STATE.yaml missing at .cursor/memory/STATE.yaml"
    exit 1
}

$stateRaw = Get-Content -Path $statePath -Raw
$statusMatch = [regex]::Match($stateRaw, '(?m)^docs_status:\s*(\S+)\s*$')
if (-not $statusMatch.Success) {
    Write-Fail "docs_status key not found in STATE.yaml"
    exit 1
}

$docsStatus = $statusMatch.Groups[1].Value
Write-Host "docs_status = $docsStatus"

if ($docsStatus -ne 'deleted') {
    Write-Host "PASS (skipped tracked-docs/README link checks): docs_status is '$docsStatus' (not deleted)."
    Write-Host "EXIT = 0"
    exit 0
}

$tracked = @(git ls-files -- 'docs' 'docs/**' 2>$null)
if ($LASTEXITCODE -ne 0) {
    Write-Fail "git ls-files docs failed (EXIT $LASTEXITCODE)"
} elseif ($tracked.Count -gt 0) {
    Write-Fail ("Git-tracked path(s) under docs/** while docs_status: deleted:`n  - " + ($tracked -join "`n  - "))
} else {
    Write-Host "OK: no Git-tracked files under docs/"
}

$readmePath = Join-Path $repoRoot 'README.md'
if (-not (Test-Path $readmePath)) {
    Write-Fail "README.md missing"
} else {
    $readme = Get-Content -Path $readmePath -Raw
    # Active Markdown links only — prose mentioning historical docs/ is allowed.
    $linkMatches = [regex]::Matches($readme, '\]\((?:\./)?docs(?:/[^)\s]*)?\)')
    if ($linkMatches.Count -gt 0) {
        $examples = ($linkMatches | ForEach-Object { $_.Value }) -join ', '
        Write-Fail "README.md contains Markdown link(s) targeting docs/**: $examples"
    } else {
        Write-Host "OK: README.md has no Markdown links to docs/**"
    }
}

if ($failed) {
    Write-Host "EXIT = 1"
    exit 1
}

Write-Host "PASS: docs path integrity (docs_status: deleted)"
Write-Host "EXIT = 0"
exit 0
