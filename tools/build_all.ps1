<#
.SYNOPSIS
    Штатная сборка TPK-пакета Poznote: панель управления + .tpk одной командой.

.DESCRIPTION
    1) Собирает Go-панель управления (linux/amd64) в дерево пакета
       PoznoteTOS\bin\program\poznote-panel (tools\build_panel.ps1).
    2) Собирает сам .tpk из дерева пакета (tools\build_tpk.ps1).

    Исходники приложения (sources\) не нужны: контейнеры Poznote берутся
    из ghcr.io при запуске на NAS.

.PARAMETER GoRoot
    Каталог с рабочим Go (например E:\go1.27.0). Если не задан, ищется go в PATH.

.PARAMETER PkgDir
    Каталог дерева пакета (относительно tools\). По умолчанию ..\PoznoteTOS.

.PARAMETER ReleaseTag
    Суффикс версии в имени файла .tpk (по умолчанию beta; '' - без суффикса).

.PARAMETER SkipPanel
    Не собирать панель (использовать уже собранный bin\program\poznote-panel).

.EXAMPLE
    pwsh -NoProfile -File .\tools\build_all.ps1 -GoRoot E:\go1.27.0
#>
[CmdletBinding()]
param(
    [string]$GoRoot = '',
    [string]$PkgDir = '..\PoznoteTOS',
    [string]$ReleaseTag = 'beta',
    [switch]$SkipPanel,
    [switch]$SkipModule
)

$ErrorActionPreference = 'Stop'
$WorkRoot = $PSScriptRoot

Write-Host '=== [1/3] Building TOS webui module ==='
if (-not $SkipModule) {
    & (Join-Path $WorkRoot 'build_module.ps1') -GoRoot $GoRoot -OutDir $PkgDir
    if ($LASTEXITCODE -ne 0) { throw "build_module failed (exit $LASTEXITCODE)" }
} else {
    Write-Host 'Skipped (SkipModule)'
}

if (-not $SkipPanel) {
    Write-Host '=== [2/3] Building control panel ==='
    & (Join-Path $WorkRoot 'build_panel.ps1') -GoRoot $GoRoot -PkgDir $PkgDir
    if ($LASTEXITCODE -ne 0) { throw "build_panel failed (exit $LASTEXITCODE)" }
} else {
    Write-Host '=== [2/3] Skipping panel build (SkipPanel) ==='
}

$panel = Join-Path $WorkRoot (Join-Path $PkgDir 'bin\program\poznote-panel')
if (-not (Test-Path -LiteralPath $panel)) {
    throw "panel binary not found: $panel (run without -SkipPanel first)"
}

Write-Host '=== [3/3] Building .tpk ==='
& (Join-Path $WorkRoot 'build_tpk.ps1') -PkgDir $PkgDir -ReleaseTag $ReleaseTag
if ($LASTEXITCODE -ne 0) { throw "build_tpk failed (exit $LASTEXITCODE)" }

Write-Host '=== Done ==='
Get-ChildItem -LiteralPath (Join-Path $WorkRoot '..') -Filter '*.tpk' |
    Sort-Object LastWriteTime -Descending | Select-Object -First 3 |
    ForEach-Object { Write-Host ("{0}  ({1} bytes)" -f $_.Name, $_.Length) }
