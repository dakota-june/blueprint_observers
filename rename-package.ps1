<#
.SYNOPSIS
  Renames dakotajune/blueclues to dakotajune/blueprint_observers throughout the package.

.DESCRIPTION
  Run from the package root (the folder holding composer.json).

    Composer name     dakotajune/blueclues                 -> dakotajune/blueprint_observers
    PHP namespace     Dakotajune\Blueclues                 -> Dakotajune\BlueprintObservers
    Classes / files   BluecluesServiceProvider(.php)       -> BlueprintObserversServiceProvider(.php)
    Config file/keys  config/blueclues.php, blueclues.*    -> config/blueprint_observers.php, blueprint_observers.*
    Publish tags      blueclues-config / blueclues-stubs   -> blueprint-observers-config / blueprint-observers-stubs
    Ownership marker  @generated blueclues                 -> @generated blueprint_observers
    Manifest file     .blueclues                           -> .blueprint_observers
    Env var           BLUECLUES_KEEP                       -> BLUEPRINT_OBSERVERS_KEEP

  Never enters vendor, .git, node_modules or .phpunit.cache (at any depth) and skips composer.lock. Files are written as UTF-8
  WITHOUT a BOM (a BOM in front of <?php would break strict_types). Safe to run twice.

.PARAMETER DryRun        Show what would change, change nothing.
.PARAMETER NoBackup      Skip the backup copy (default: copy the project, minus vendor/.git, next to it first).
.PARAMETER SkipComposer  Do not run "composer dump-autoload" afterwards.

.EXAMPLE
  .\rename-package.ps1 -DryRun
  .\rename-package.ps1
#>
[CmdletBinding()]
param(
    [string]$Path = (Get-Location).Path,
    [switch]$DryRun,
    [switch]$NoBackup,
    [switch]$SkipComposer
)

$ErrorActionPreference = 'Stop'

$root = (Resolve-Path -LiteralPath $Path).Path.TrimEnd('\')

if (-not (Test-Path -LiteralPath (Join-Path $root 'composer.json'))) {
    throw "No composer.json in $root. Run this from the package root, or pass -Path."
}

# Ordered, case-sensitive, literal replacements (most specific first).
$replacements = @(
    , @('blueclues-config', 'blueprint-observers-config')
    , @('blueclues-stubs',  'blueprint-observers-stubs')
    , @('Blueclues',        'BlueprintObservers')
    , @('BLUECLUES',        'BLUEPRINT_OBSERVERS')
    , @('blueclues',        'blueprint_observers')
)

function Convert-Text([string]$text) {
    foreach ($r in $replacements) { $text = $text.Replace($r[0], $r[1]) }
    return $text
}

$skipNames     = @('vendor', '.git', 'node_modules', '.phpunit.cache')   # never entered, at any depth
$textExt       = @('.php', '.json', '.md', '.yaml', '.yml', '.stub', '.xml', '.txt', '.dist')
$textNames     = @('.gitignore', '.gitattributes', '.editorconfig')
$self          = $MyInvocation.MyCommand.Path

function Get-ProjectItems {
    # Manual walk so excluded folders (notably .git) are pruned and never enumerated at all.
    $stack = New-Object System.Collections.Generic.Stack[string]
    $stack.Push($root)

    while ($stack.Count -gt 0) {
        $dir = $stack.Pop()

        foreach ($child in Get-ChildItem -LiteralPath $dir -Force) {
            if ($skipNames -contains $child.Name) { continue }                  # also covers a .git *file*
            if ($self -and $child.FullName -eq $self) { continue }

            if ($child.PSIsContainer) { $stack.Push($child.FullName) }

            $child
        }
    }
}

$items = @(Get-ProjectItems)
$files = @($items | Where-Object {
    -not $_.PSIsContainer -and $_.Name -ne 'composer.lock' -and
            ($textExt -contains $_.Extension.ToLower() -or $textNames -contains $_.Name)
})

Write-Host ''
Write-Host "Project : $root"
Write-Host ("Mode    : " + $(if ($DryRun) { 'DRY RUN (no changes)' } else { 'APPLY' }))
Write-Host ''

# --- Backup -------------------------------------------------------------------------------------
if (-not $DryRun -and -not $NoBackup) {
    $backup = "$root-backup-" + (Get-Date -Format 'yyyyMMdd-HHmmss')
    & robocopy $root $backup /E /XD vendor .git node_modules /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "Backup failed (robocopy exit $LASTEXITCODE). Nothing was changed." }
    $global:LASTEXITCODE = 0
    Write-Host "Backup  : $backup"
    Write-Host ''
}

# --- 1. File contents ---------------------------------------------------------------------------
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
$changed = 0

foreach ($f in $files) {
    $text = [System.IO.File]::ReadAllText($f.FullName)
    $new  = Convert-Text $text

    if ($new -ne $text) {
        $changed++
        Write-Host ("edit    : " + $f.FullName.Substring($root.Length + 1))
        if (-not $DryRun) { [System.IO.File]::WriteAllText($f.FullName, $new, $utf8NoBom) }
    }
}

# --- 2. File and folder names (deepest first so parents are renamed last) -----------------------
$renamed = 0
$toRename = @(Get-ProjectItems | Where-Object { $_.Name -match 'blueclues' } |
        Sort-Object { $_.FullName.Length } -Descending)

foreach ($item in $toRename) {
    $newName = Convert-Text $item.Name
    $renamed++
    Write-Host ("rename  : " + $item.FullName.Substring($root.Length + 1) + '  ->  ' + $newName)
    if (-not $DryRun) { Rename-Item -LiteralPath $item.FullName -NewName $newName }
}

Write-Host ''
Write-Host "$changed file(s) edited, $renamed path(s) renamed."

# --- 3. Anything left behind? -------------------------------------------------------------------
if (-not $DryRun) {
    $left = @()
    foreach ($f in @(Get-ProjectItems | Where-Object {
        -not $_.PSIsContainer -and $_.Name -ne 'composer.lock' -and
                ($textExt -contains $_.Extension.ToLower() -or $textNames -contains $_.Name)
    })) {
        if ([System.IO.File]::ReadAllText($f.FullName) -match '(?i)blueclues') { $left += $f.FullName.Substring($root.Length + 1) }
    }
    if ($left.Count -gt 0) {
        Write-Host ''
        Write-Warning 'Still mentions "blueclues" (check by hand):'
        $left | ForEach-Object { Write-Host "  $_" }
    }
    else {
        Write-Host 'Check   : no "blueclues" left in any text file.'
    }

    # --- 4. Composer autoload (the PSR-4 namespace changed) -------------------------------------
    if (-not $SkipComposer -and (Get-Command composer -ErrorAction SilentlyContinue)) {
        Write-Host ''
        Push-Location $root
        try { & composer dump-autoload } finally { Pop-Location }
    }
}

Write-Host ''
Write-Host 'Next:'
Write-Host '  * If composer warns the lock file is out of date:  composer update --lock'
Write-Host '  * Optionally rename the project folder itself (it cannot be renamed while you are inside it).'
Write-Host '  * Sanity test:  $env:BLUEPRINT_OBSERVERS_KEEP=1; vendor\bin\pest tests\Feature\SanityTest.php'
Write-Host '  * Observers generated BEFORE this rename carry the old "@generated blueclues" tag and will be'
Write-Host '    treated as yours. Replace the tag in them (or delete and regenerate) if you have any.'
if ($DryRun) { Write-Host ''; Write-Host 'Dry run only. Re-run without -DryRun to apply.' }