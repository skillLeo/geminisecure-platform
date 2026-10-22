<#
.SYNOPSIS
  Generates _design/MANIFEST.md with a SHA-256 per imported design file.

.DESCRIPTION
  The design snapshot under _design/wireframes is read-only from the moment
  this manifest is written. Re-run and diff to detect drift after any re-import.

  Verification gate (all must hold, else the import is rejected):
    - exactly 43 files imported (import-targets.json expectedFileCount)
    - zero files under any excluded prefix (uploads/)
    - every path in import-targets.json is present

  -Check compares the current hashes against the committed manifest and exits 1
  on any difference, so a CI step can refuse a build whose boards moved.

.EXAMPLE
  powershell -File tools/design-manifest.ps1
  powershell -File tools/design-manifest.ps1 -Check
#>
[CmdletBinding()]
param(
    [string]$DesignDir = '',
    [string]$TargetsFile = '',
    [switch]$Check
)

$ErrorActionPreference = 'Stop'

# Windows PowerShell 5.1 leaves $PSScriptRoot empty inside a param() default when
# the script is started with -File, so the defaults are resolved here instead.
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
if (-not $DesignDir) { $DesignDir = Join-Path $here '..\_design' }
if (-not $TargetsFile) { $TargetsFile = Join-Path $DesignDir 'import-targets.json' }

$DesignDir = (Resolve-Path $DesignDir).Path
$targets = Get-Content $TargetsFile -Raw | ConvertFrom-Json

$expected = [System.Collections.Generic.List[string]]::new()
$targets.documents | ForEach-Object { $expected.Add($_) }
$targets.sharedScript | ForEach-Object { $expected.Add($_) }
foreach ($folder in $targets.wireframes.PSObject.Properties) {
    foreach ($file in $folder.Value) { $expected.Add("$($folder.Name)/$file") }
}

$snapshotRoot = Join-Path $DesignDir 'wireframes'
if (-not (Test-Path $snapshotRoot)) {
    Write-Error "No import found at $snapshotRoot. Run the design import first."
}

$actual = Get-ChildItem -Path $snapshotRoot -Recurse -File |
    ForEach-Object { $_.FullName.Substring($snapshotRoot.Length + 1).Replace('\', '/') }

$violations = @()
foreach ($prefix in $targets.excludedPrefixes) {
    $violations += $actual | Where-Object { $_ -like "$prefix*" }
}
if ($violations.Count -gt 0) {
    Write-Error ("IMPORT REJECTED - excluded files present:`n  " + ($violations -join "`n  ") + "`n`n" + $targets.excludeReason)
}

$missing = $expected | Where-Object { $_ -notin $actual }
if ($missing.Count -gt 0) {
    Write-Error ("IMPORT REJECTED - missing files:`n  " + ($missing -join "`n  "))
}

if ($actual.Count -ne $targets.expectedFileCount) {
    $unexpected = $actual | Where-Object { $_ -notin $expected }
    Write-Error ("IMPORT REJECTED - expected $($targets.expectedFileCount) files, found $($actual.Count)." +
        $(if ($unexpected) { "`nUnexpected:`n  " + ($unexpected -join "`n  ") } else { '' }))
}

$rows = foreach ($rel in ($expected | Sort-Object)) {
    $full = Join-Path $snapshotRoot ($rel -replace '/', '\')
    $hash = (Get-FileHash -Path $full -Algorithm SHA256).Hash.ToLower()
    $size = (Get-Item $full).Length
    [pscustomobject]@{ Path = $rel; Sha256 = $hash; Bytes = $size }
}

$out = Join-Path $DesignDir 'MANIFEST.md'

if ($Check) {
    if (-not (Test-Path $out)) { Write-Error "No manifest at $out to check against." }
    $committed = Get-Content $out
    $drift = @()
    foreach ($r in $rows) {
        $line = "| ``$($r.Path)`` | ``$($r.Sha256)`` | $($r.Bytes) |"
        if ($committed -notcontains $line) { $drift += $r.Path }
    }
    if ($drift.Count -gt 0) {
        Write-Error ("DESIGN DRIFT - these files differ from _design/MANIFEST.md:`n  " + ($drift -join "`n  "))
    }
    Write-Output "OK - $($rows.Count) files match the manifest"
    exit 0
}

$sb = [System.Text.StringBuilder]::new()
[void]$sb.AppendLine('# Design Import Manifest')
[void]$sb.AppendLine()
[void]$sb.AppendLine("Source project: $($targets.projectId)")
[void]$sb.AppendLine("Snapshot source: the web project's own import of the same design project; file hashes identical")
[void]$sb.AppendLine("Files: $($rows.Count) (expected $($targets.expectedFileCount))")
[void]$sb.AppendLine("Token source of truth: ``$($targets.tokenSourceOfTruth)``")
[void]$sb.AppendLine()
[void]$sb.AppendLine('`uploads/` is excluded by policy and is NOT part of this manifest.')
[void]$sb.AppendLine($targets.excludeReason)
[void]$sb.AppendLine()
[void]$sb.AppendLine('Not in this snapshot: `GeminiSecure Mobile Spec.dc.html` (added to the project after the web import; Parts 0-1 transcribed to `_design/MOBILE_SPEC_PART1.md`).')
[void]$sb.AppendLine()
[void]$sb.AppendLine('Regenerate with `powershell -File tools/design-manifest.ps1`; verify with `-Check`.')
[void]$sb.AppendLine()
[void]$sb.AppendLine('| File | SHA-256 | Bytes |')
[void]$sb.AppendLine('| --- | --- | --- |')
foreach ($r in $rows) {
    [void]$sb.AppendLine("| ``$($r.Path)`` | ``$($r.Sha256)`` | $($r.Bytes) |")
}

Set-Content -Path $out -Value $sb.ToString() -Encoding utf8
Write-Output "OK - $($rows.Count) files verified, manifest written to $out"
