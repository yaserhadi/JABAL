# Deterministic Governance Applicability preflight (DEC-0026 / BK-117).
#
# Usage (repo root):
#   powershell -NoProfile -File scripts/check-governance-preflight.ps1 -PlanPath .cursor/plans/foo.plan.md -Mode enforce
#   powershell -NoProfile -File scripts/check-governance-preflight.ps1 -PlanPath scripts/fixtures/bk117/<fixture>.plan.md -Mode enforce
#
# EXIT 0 = pass; EXIT 1 = enforce failure or missing inputs
#
# Verifies ONLY deterministic evidence:
#   fail-closed sensitivity; GAB presence; required GAB field/category tokens;
#   cited DEC paths exist + Active; declared conflict implies BLOCK.
# Does NOT claim semantic DEC completeness or architecture correctness.
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

function Report-Issue([string]$Message) {
    if ($Mode -eq 'enforce') {
        Write-Fail $Message
    } else {
        Write-Warn $Message
    }
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

# --- Sensitivity (DEC-0026 fail-closed) ---
# Keywords may promote to sensitive; they must never be the only thing preventing fail-open.
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

$hasNonSensitiveReason = $false
if ($plan -match '(?im)(?:non[_\s-]?sensitive[_\s-]?reason|governance_non_sensitive_reason)\s*[:=]\s*\S+') {
    $hasNonSensitiveReason = $true
} elseif ($explicitNonSensitive -and $plan -match '(?is)governance_sensitive\s*:\s*false.{0,800}?(?:reason|because|rationale)\s*[:=]\s*\S+') {
    $hasNonSensitiveReason = $true
}

$keywordHit = $false
foreach ($h in $sensitiveHints) {
    if ($plan -match [regex]::Escape($h)) {
        $keywordHit = $true
        break
    }
}

# Default sensitive (fail-closed). Explicit false is honored ONLY with an explicit reason.
# Keywords may promote undeclared work to sensitive; they must not be required to avoid fail-open,
# and must not override a valid non-sensitive declaration (false + reason).
$isSensitive = $true
$sensitivityNote = 'default fail-closed (uncertain / undeclared)'
if ($metaSensitive) {
    $isSensitive = $true
    $sensitivityNote = 'governance_sensitive: true'
} elseif ($explicitNonSensitive -and $hasNonSensitiveReason) {
    $isSensitive = $false
    $sensitivityNote = 'governance_sensitive: false + explicit reason'
} elseif ($explicitNonSensitive -and -not $hasNonSensitiveReason) {
    $isSensitive = $true
    $sensitivityNote = 'governance_sensitive: false without explicit reason -> treated sensitive'
} elseif ($keywordHit) {
    $isSensitive = $true
    $sensitivityNote = 'sensitive keyword hit'
}

Write-Host "Sensitive detected =" $isSensitive
Write-Host "Sensitivity note =" $sensitivityNote

$hasGab = $plan -match '(?m)^##\s+Governance Applicability\b'
Write-Host "GAB section present =" $hasGab

if (-not $isSensitive) {
    Write-Host "PASS: plan classified non-sensitive with explicit reason; GAB not required by script"
    Write-Host "EXIT = 0"
    exit 0
}

if (-not $hasGab) {
    Report-Issue "Sensitive plan missing ## Governance Applicability"
} else {
    $gabMatch = [regex]::Match($plan, '(?ms)^##\s+Governance Applicability\b.*?(?=^##\s|\z)')
    $gabBody = if ($gabMatch.Success) { $gabMatch.Value } else { '' }

    # Required GAB evidence categories (token/label presence only — not semantic correctness)
    $requiredChecks = @(
        @{ Name = 'BK/task'; Pattern = '(?i)\b(BK\s*/\s*task|BK-\d+|^\s*[-*]\s*BK\b|task\s*:)' },
        @{ Name = 'domains/modules/planes'; Pattern = '(?i)\b(domain|module|plane)' },
        @{ Name = 'auth/security impact or N/A'; Pattern = '(?i)(auth|security).{0,120}(N/?A|impact|none)|N/?A.{0,80}(auth|security)' },
        @{ Name = 'persistence impact or N/A'; Pattern = '(?i)(persistence|schema).{0,120}(N/?A|impact|none)|N/?A.{0,80}(persistence|schema)' },
        @{ Name = 'MANIFEST locks'; Pattern = '(?i)\bMANIFEST\b' },
        @{ Name = 'Active DECs'; Pattern = '(?i)\bDEC-\d+|Active\s+DEC|matched\s+Active\s+DEC' },
        @{ Name = 'INTEGRITY'; Pattern = '(?i)\bINTEGRITY\b' },
        @{ Name = 'workflow rules'; Pattern = '(?i)\b(workflow\s+rules?|matched\s+rules?|\.cursor/rules|plan-preflight|governance-applicability)' },
        @{ Name = 'explicit N/A (where used)'; Pattern = '(?i)\bN/?A\b' },
        @{ Name = 'conflict classification'; Pattern = '(?i)(conflict\s+classification|NO CONFLICT|OPERATIONAL STALENESS|ARCHITECTURE CONFLICT|AUTHORITY CONFLICT|BEHAVIOR\s+VS\s+ARCHITECTURE|BEHAVIOR_VS_ARCHITECTURE)' },
        @{ Name = 'required verification'; Pattern = '(?i)\b(verification|verify|check-governance-preflight|list-active-decs)\b' }
    )

    foreach ($chk in $requiredChecks) {
        if ($gabBody -notmatch $chk.Pattern) {
            Report-Issue ("GAB missing required evidence category: {0}" -f $chk.Name)
        } else {
            Write-Host ("OK GAB category: {0}" -f $chk.Name)
        }
    }

    $decPaths = [regex]::Matches($gabBody, '(?i)\.cursor/memory/decisions/(DEC-[0-9A-Za-z\-]+\.md)')
    foreach ($m in $decPaths) {
        $rel = $m.Groups[1].Value
        $full = Join-Path $repoRoot ('.cursor\memory\decisions\' + $rel)
        if (-not (Test-Path $full)) {
            Report-Issue "Cited DEC path missing: $rel"
            continue
        }
        $raw = Get-Content -Path $full -Raw -Encoding utf8
        $st = [regex]::Match($raw, '(?im)^\s*Status:\s*(.+?)\s*$')
        $statusVal = if ($st.Success) { $st.Groups[1].Value.Trim() } else { '' }
        $isActive = $statusVal -match '(?i)^(?:\*\*)?Active(?:\*\*)?\b'
        if (-not $isActive) {
            Report-Issue "Cited DEC not Active: $rel (Status=$statusVal)"
        } else {
            Write-Host "OK Active cite: $rel"
        }
    }

    $conflictDeclared = $gabBody -match '(?i)ARCHITECTURE CONFLICT|AUTHORITY CONFLICT|BEHAVIOR\s+VS\s+ARCHITECTURE(?:\s+CONFLICT)?|BEHAVIOR_VS_ARCHITECTURE(?:_CONFLICT)?'
    if ($conflictDeclared -and $gabBody -notmatch '(?i)\bNO CONFLICT\b') {
        # Require BLOCK for architecture/behavior/authority classes (not for NO CONFLICT / OPERATIONAL STALENESS alone)
        $needsBlock = $gabBody -match '(?i)ARCHITECTURE CONFLICT|AUTHORITY CONFLICT|BEHAVIOR\s+VS\s+ARCHITECTURE|BEHAVIOR_VS_ARCHITECTURE'
        if ($needsBlock -and $gabBody -notmatch '(?i)\bBLOCK\b') {
            Report-Issue "Conflict class declared without BLOCK flag (DEC-0026 / Rule 7)"
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
