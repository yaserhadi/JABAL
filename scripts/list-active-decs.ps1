# List Active decision digests (DEC) for governance discovery helpers.
#
# Usage (repo root):
#   powershell -NoProfile -File scripts/list-active-decs.ps1
#   powershell -NoProfile -File scripts/list-active-decs.ps1 -Search "tenancy MFA"
# EXIT 0 = listed (or empty Active set); EXIT 1 = hard failure (missing dirs)
#
# HELPER only - not an applicability oracle. Does not claim all applicable DECs found.
# Authority: BK-117 Phase A (helper); Active DEC-0026.

param(
    [string]$Search = ''
)

$ErrorActionPreference = 'Continue'

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $repoRoot

$decisionsDir = Join-Path $repoRoot '.cursor\memory\decisions'
$registryPath = Join-Path $decisionsDir 'README.md'

if (-not (Test-Path $decisionsDir)) {
    Write-Host "FAIL: decisions directory missing: .cursor/memory/decisions"
    Write-Host "EXIT = 1"
    exit 1
}

Write-Host "Registry present =" (Test-Path $registryPath)
Write-Host "Decisions dir = .cursor/memory/decisions"

$files = Get-ChildItem -Path $decisionsDir -Filter 'DEC-*.md' -File |
    Where-Object { $_.Name -notmatch 'Full-Decision-Reference' }

$active = @()
$skipped = @()

foreach ($file in $files) {
    $raw = Get-Content -Path $file.FullName -Raw -Encoding utf8 -ErrorAction SilentlyContinue
    if ($null -eq $raw) {
        $skipped += [pscustomobject]@{ File = $file.Name; Reason = 'unreadable' }
        continue
    }

    $statusMatch = [regex]::Match($raw, '(?im)^\s*Status:\s*(.+?)\s*$')
    if (-not $statusMatch.Success) {
        $skipped += [pscustomobject]@{ File = $file.Name; Reason = 'no Status line' }
        continue
    }

    $statusRaw = $statusMatch.Groups[1].Value.Trim()
    $isActive = $statusRaw -match '(?i)^(?:\*\*)?Active(?:\*\*)?\b'
    $isDraft = $statusRaw -match '(?i)^(?:\*\*)?Draft(?:\*\*)?\b'
    $isSuperseded = $statusRaw -match '(?i)Superseded'

    if ($isSuperseded -or $isDraft -or -not $isActive) {
        $reason = if ($isSuperseded) { 'Superseded' } elseif ($isDraft) { 'Draft' } else { "Status=$statusRaw" }
        $skipped += [pscustomobject]@{ File = $file.Name; Reason = $reason }
        continue
    }

    $titleMatch = [regex]::Match($raw, '(?m)^#\s+(DEC-[0-9]+(?:-IH)?)\b\s+(.+)$')
    $idFromName = [regex]::Match($file.BaseName, '^(DEC-[0-9]+(?:-IH)?)')
    if ($titleMatch.Success) {
        $id = $titleMatch.Groups[1].Value
        $title = [regex]::Replace($titleMatch.Groups[2].Value.Trim(), '^[\p{Pd}\-:]+\s*', '')
    } elseif ($idFromName.Success) {
        $id = $idFromName.Groups[1].Value
        $title = $file.BaseName
    } else {
        $id = $file.BaseName
        $title = $file.BaseName
    }

    $rel = '.cursor/memory/decisions/' + $file.Name
    $active += [pscustomobject]@{
        Id = $id
        Title = $title
        Path = $rel
        Status = 'Active'
        Body = $raw
    }
}

Write-Host ""
Write-Host "Active DEC count =" $active.Count

$terms = @()
if (-not [string]::IsNullOrWhiteSpace($Search)) {
    $terms = $Search -split '\s+' | Where-Object { $_ -ne '' }
}

$candidates = @()
foreach ($item in $active) {
    if ($terms.Count -eq 0) {
        $candidates += $item
        continue
    }
    $hay = ($item.Id + ' ' + $item.Title + ' ' + $item.Body)
    $hit = $false
    foreach ($t in $terms) {
        if ($hay -match [regex]::Escape($t)) {
            $hit = $true
            break
        }
    }
    if ($hit) {
        $candidates += $item
    }
}

if ($terms.Count -gt 0) {
    Write-Host "Search terms =" ($terms -join ', ')
    Write-Host "Candidate hits =" $candidates.Count
}

Write-Host ""
Write-Host "=== Active / candidate decisions ==="
foreach ($c in $candidates) {
    Write-Host ("- {0} | {1} | {2}" -f $c.Id, $c.Title, $c.Path)
}

if ($skipped.Count -gt 0 -and $terms.Count -eq 0) {
    Write-Host ""
    Write-Host "=== Excluded (Draft / Superseded / other) count =" $skipped.Count
}

Write-Host ""
Write-Host "NOTE: Helper listing only. Agent must still complete Governance Applicability judgment."
Write-Host "EXIT = 0"
exit 0
