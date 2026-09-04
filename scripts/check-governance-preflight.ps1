# Deterministic Governance Applicability preflight (DEC-0026 / BK-117).
#
# Usage (repo root):
#   powershell -NoProfile -File scripts/check-governance-preflight.ps1 -PlanPath .cursor/plans/foo.plan.md -Mode enforce
#   powershell -NoProfile -File scripts/check-governance-preflight.ps1 -PlanPath .cursor/plans/foo.plan.md -Mode lint
#
# EXIT 0 = pass; EXIT 1 = enforce failure or missing inputs
#
# Verifies ONLY: GAB presence when sensitive; cited DEC paths exist and Active;
# declared BLOCK when conflict class present. Not semantic architecture correctness.
# Local only -- no CI/CD.

param(
    [Parameter(Mandatory = $true)]
    [string]$PlanPath,
    [ValidateSet('enforce', 'lint')]
    [string]$Mode = 'enforce'
)

$ErrorActionPreference = 'Continue'
$failed = $false
$warnings = 0

function Write-Fail([string]$Message) {
    Write-Host "FAIL: $Message" -ForegroundColor Red
    $script:failed = $true
}

function Write-Warn([string]$Message) {
    Write-Host "WARN: $Message" -ForegroundColor Yellow
    $script:warnings++
}

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $repoRoot

if (-not [System.IO.Path]::IsPathRooted($PlanPath)) {
    $PlanPath = Join-Path $repoRoot $PlanPath
}

if (-not (Test-Path $PlanPath)) {
    Write-Fail "Plan not found: $PlanPath"
    Write-Host "EXIT = 1"
    exit 1
}

Write-Host "Plan =" $PlanPath
Write-Host "Mode =" $Mode

$plan = Get-Content -Path $PlanPath -Raw -Encoding utf8

$sensitiveHints = @(
    'tenancy', 'tenant', 'multi-tenant', 'database', 'migration', 'schema', 'connection',
    'security', 'auth', 'permission', 'rbac', 'token', 'isolation', 'BelongsToTenant',
    'module-boundary', 'MODULE-BOUNDARY', 'Modules/', 'Platform', 'governance',
    'DEC-0026', 'Governance Applicability', 'sensitive'
)

$metaSensitive = $false
$explicitNonSensitive = $false
if ($plan -match '(?im)governance_sensitive\s*:\s*true\b') { $metaSensitive = $true }
if ($plan -match '(?im)governance_sensitive\s*:\s*false\b') { $explicitNonSensitive = $true }

$keywordHit = $false
foreach ($h in $sensitiveHints) {
    if ($plan -match [regex]::Escape($h)) {
        $keywordHit = $true
        break
    }
}

$isSensitive = $false
if ($metaSensitive) {
    $isSensitive = $true
} elseif ($explicitNonSensitive) {
    $isSensitive = $false
} elseif ($keywordHit) {
    $isSensitive = $true
}

Write-Host "Sensitive detected =" $isSensitive

$hasGab = $plan -match '(?m)^##\s+Governance Applicability\b'
Write-Host "GAB section present =" $hasGab

if (-not $isSensitive) {
    Write-Host "PASS: plan classified non-sensitive; GAB not required by script"
    Write-Host "EXIT = 0"
    exit 0
}

if (-not $hasGab) {
    if ($Mode -eq 'enforce') {
        Write-Fail "Sensitive plan missing ## Governance Applicability"
    } else {
        Write-Warn "Sensitive plan missing ## Governance Applicability"
    }
} else {
    $gabMatch = [regex]::Match($plan, '(?ms)^##\s+Governance Applicability\b.*?(?=^##\s|\z)')
    $gabBody = if ($gabMatch.Success) { $gabMatch.Value } else { '' }

    $decPaths = [regex]::Matches($gabBody, '(?i)\.cursor/memory/decisions/(DEC-[0-9A-Za-z\-]+\.md)')
    foreach ($m in $decPaths) {
        $rel = $m.Groups[1].Value
        $full = Join-Path $repoRoot ('.cursor\memory\decisions\' + $rel)
        if (-not (Test-Path $full)) {
            if ($Mode -eq 'enforce') {
                Write-Fail "Cited DEC path missing: $rel"
            } else {
                Write-Warn "Cited DEC path missing: $rel"
            }
            continue
        }
        $raw = Get-Content -Path $full -Raw -Encoding utf8
        $st = [regex]::Match($raw, '(?im)^\s*Status:\s*(.+?)\s*$')
        $statusVal = if ($st.Success) { $st.Groups[1].Value.Trim() } else { '' }
        $isActive = $statusVal -match '(?i)^(?:\*\*)?Active(?:\*\*)?\b'
        if (-not $isActive) {
            if ($Mode -eq 'enforce') {
                Write-Fail "Cited DEC not Active: $rel (Status=$statusVal)"
            } else {
                Write-Warn "Cited DEC not Active: $rel (Status=$statusVal)"
            }
        } else {
            Write-Host "OK Active cite: $rel"
        }
    }

    if ($gabBody -match '(?i)ARCHITECTURE CONFLICT|BEHAVIOR VS ARCHITECTURE|AUTHORITY CONFLICT') {
        if ($gabBody -notmatch '(?i)\bBLOCK\b') {
            if ($Mode -eq 'enforce') {
                Write-Fail "Conflict class declared without BLOCK flag (DEC-0026 / Rule 7)"
            } else {
                Write-Warn "Conflict class declared without BLOCK flag"
            }
        }
    }
}

if ($failed) {
    Write-Host "EXIT = 1"
    exit 1
}

Write-Host "PASS: governance preflight ($Mode); warnings=$warnings"
Write-Host "NOTE: Deterministic checks only - not semantic architecture correctness."
Write-Host "EXIT = 0"
exit 0
