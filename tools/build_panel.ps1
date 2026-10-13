<#
.SYNOPSIS
    Сборка бинарника панели управления Poznote (linux/amd64) из tools\panel.

.DESCRIPTION
    Кросс-компилирует Go-панель (CGO выключен) в дерево пакета:
        ..\PoznoteTOS\bin\program\poznote-panel
    Бинарник статический, рассчитан на TOS (glibc) и запускается сервисом
    init.d/service рядом с контейнерами.

.PARAMETER GoRoot
    Каталог с рабочим Go (например E:\go1.27.0). Если не задан, ищется go в PATH.

.PARAMETER PkgDir
    Каталог дерева пакета (относительно tools\). По умолчанию ..\PoznoteTOS.

.EXAMPLE
    pwsh .\tools\build_panel.ps1 -GoRoot E:\go1.27.0
#>
[CmdletBinding()]
param(
    [string]$GoRoot = '',
    [string]$PkgDir = '..\PoznoteTOS'
)

$ErrorActionPreference = 'Stop'
$WorkRoot = $PSScriptRoot
Set-Location -LiteralPath $WorkRoot

function Resolve-Go {
    param([string]$Root)
    if ($Root) {
        $exe = Join-Path $Root 'bin\go.exe'
        if (-not (Test-Path -LiteralPath $exe)) { throw "go.exe not found in -GoRoot: $Root" }
        return $exe
    }
    $cmd = Get-Command go -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    if (Test-Path 'E:\go1.27.0\bin\go.exe') { return 'E:\go1.27.0\bin\go.exe' }
    throw 'Go not found. Pass -GoRoot <dir with bin\go.exe>.'
}

$go = Resolve-Go $GoRoot
Write-Host "Using go: $go"

$outDir = [System.IO.Path]::GetFullPath((Join-Path $WorkRoot (Join-Path $PkgDir 'bin\program')))
if (-not (Test-Path -LiteralPath $outDir)) { New-Item -ItemType Directory -Force -Path $outDir | Out-Null }
$out = Join-Path $outDir 'poznote-panel'

$saved = @{}
foreach ($k in @('GOOS','GOARCH','CGO_ENABLED','GOROOT','GOTOOLCHAIN')) {
    if (Test-Path "Env:$k") { $saved[$k] = (Get-Item "Env:$k").Value }
}
try {
    $env:GOOS = 'linux'
    $env:GOARCH = 'amd64'
    $env:CGO_ENABLED = '0'
    if ($GoRoot) { $env:GOROOT = $GoRoot; $env:PATH = (Join-Path $GoRoot 'bin') + ';' + $env:PATH }
    $env:GOTOOLCHAIN = 'local'

    Push-Location (Join-Path $WorkRoot 'panel')
    try {
        & $go build -trimpath -ldflags '-s -w' -o $out .
        if ($LASTEXITCODE -ne 0) { throw "go build failed (exit $LASTEXITCODE)" }
    } finally { Pop-Location }
} finally {
    foreach ($k in @('GOOS','GOARCH','CGO_ENABLED','GOTOOLCHAIN')) {
        if ($saved.ContainsKey($k)) { [Environment]::SetEnvironmentVariable($k, $saved[$k], 'Process') }
        else { Remove-Item "Env:$k" -ErrorAction SilentlyContinue }
    }
    if ($saved.ContainsKey('GOROOT')) { $env:GOROOT = $saved['GOROOT'] }
    else { Remove-Item Env:\GOROOT -ErrorAction SilentlyContinue }
}

if (-not (Test-Path -LiteralPath $out)) { throw "panel binary not produced: $out" }
Write-Host "Panel built: $out ($((Get-Item -LiteralPath $out).Length) bytes)"
