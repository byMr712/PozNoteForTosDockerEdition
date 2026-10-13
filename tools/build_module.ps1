<#
.SYNOPSIS
    Сборка webui-модуля TOS (webui.bz2) для пакета Poznote.

.DESCRIPTION
    1) собирает утилиту modulemake (tools\modulemake, нативный Windows-бинарник);
    2) упаковывает каталог исходников модуля (по умолчанию ..\webui) в GNU-tar
       (как есть, без BOM/UTF-16 — так же, как эталонный модуль OutkastM);
    3) сжимает его xz -9e и кладёт результат в дерево пакета как webui.bz2.

    Готовый webui.bz2 попадает внутрь .tpk и отдаётся TOS на страницу приложения
    (path=/Poznote/), т.е. на иконку Poznote рабочего стола.

.PARAMETER SrcDir
    Каталог с исходниками модуля (относительно tools\). По умолчанию ..\webui.

.PARAMETER OutDir
    Каталог дерева пакета (относительно tools\). По умолчанию ..\PoznoteTOS.

.PARAMETER GoRoot
    Каталог с рабочим Go (например E:\go1.27.0). Если не задан, ищется go в PATH.

.PARAMETER XzPath
    Путь к xz.exe. По умолчанию ищется tools\xz\xz.exe, затем в Git for Windows.

.EXAMPLE
    pwsh .\tools\build_module.ps1 -GoRoot E:\go1.27.0
#>
[CmdletBinding()]
param(
    [string]$SrcDir = '..\webui',
    [string]$OutDir = '..\PoznoteTOS',
    [string]$GoRoot = '',
    [string]$XzPath = ''
)

$ErrorActionPreference = 'Stop'
$WorkRoot = $PSScriptRoot
Set-Location -LiteralPath $WorkRoot
[Environment]::CurrentDirectory = $WorkRoot

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

if (-not (Test-Path -LiteralPath $SrcDir)) { throw "Module source dir not found (relative to $WorkRoot): $SrcDir" }
if (-not (Test-Path -LiteralPath $OutDir)) { throw "Package dir not found (relative to $WorkRoot): $OutDir" }

if (-not $XzPath) {
    $candidates = @(
        '.\xz\xz.exe',
        "$env:ProgramFiles\Git\mingw64\bin\xz.exe",
        "${env:ProgramFiles(x86)}\Git\mingw64\bin\xz.exe",
        "$env:LOCALAPPDATA\Programs\Git\mingw64\bin\xz.exe"
    )
    $XzPath = $candidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
}
if (-not $XzPath) { throw 'xz.exe not found. Put xz.exe into tools\xz\ or pass -XzPath' }

$go = Resolve-Go $GoRoot
Write-Host "Using go: $go"
Write-Host "Using xz: $XzPath"

$BuildDir = '.\_build'
New-Item -ItemType Directory -Force -Path $BuildDir | Out-Null
$mkExe = Join-Path (Resolve-Path $BuildDir).Path 'modulemake.exe'
$raw   = Join-Path (Resolve-Path $BuildDir).Path 'webui.raw'
$absSrc = (Resolve-Path $SrcDir).Path
$outFile = Join-Path (Resolve-Path $OutDir).Path 'webui.bz2'

# ---- 1. Build modulemake (native) ----
$saved = @{}
foreach ($k in @('GOOS', 'GOARCH', 'CGO_ENABLED', 'GOROOT', 'GOTOOLCHAIN')) {
    if (Test-Path "Env:$k") { $saved[$k] = (Get-Item "Env:$k").Value }
}
try {
    Remove-Item Env:GOOS -ErrorAction SilentlyContinue
    Remove-Item Env:GOARCH -ErrorAction SilentlyContinue
    $env:CGO_ENABLED = '0'
    if ($GoRoot) { $env:GOROOT = $GoRoot; $env:PATH = (Join-Path $GoRoot 'bin') + ';' + $env:PATH }
    $env:GOTOOLCHAIN = 'local'

    Push-Location (Join-Path $WorkRoot 'modulemake')
    try {
        & $go build -trimpath -ldflags '-s -w' -o $mkExe .
        if ($LASTEXITCODE -ne 0) { throw "go build (modulemake) failed (exit $LASTEXITCODE)" }
    } finally { Pop-Location }
} finally {
    foreach ($k in @('GOOS', 'GOARCH', 'CGO_ENABLED', 'GOTOOLCHAIN')) {
        if ($saved.ContainsKey($k)) { [Environment]::SetEnvironmentVariable($k, $saved[$k], 'Process') }
        else { Remove-Item "Env:$k" -ErrorAction SilentlyContinue }
    }
    if ($saved.ContainsKey('GOROOT')) { $env:GOROOT = $saved['GOROOT'] }
    else { Remove-Item Env:\GOROOT -ErrorAction SilentlyContinue }
}

# ---- 2. Pack sources into the tar container ----
Write-Host "Packing module: $absSrc"
& $mkExe $absSrc $raw
if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $raw)) { throw "modulemake failed (exit $LASTEXITCODE)" }

# ---- 3. Compress to webui.bz2 ----
Write-Host "Compressing with xz -9e ..."
$psi = [System.Diagnostics.ProcessStartInfo]::new()
$psi.FileName = $XzPath
$psi.Arguments = "-9e -c `"$raw`""
$psi.RedirectStandardOutput = $true
$psi.UseShellExecute = $false
$psi.WorkingDirectory = $WorkRoot
$p = [System.Diagnostics.Process]::Start($psi)
$fs = [System.IO.File]::Create($outFile)
$p.StandardOutput.BaseStream.CopyTo($fs)
$fs.Close()
$p.WaitForExit()
if ($p.ExitCode -ne 0) { throw "xz failed (exit $($p.ExitCode))" }

Write-Host "webui.bz2 written: $outFile ($((Get-Item -LiteralPath $outFile).Length) bytes)"

Remove-Item -LiteralPath $raw -ErrorAction SilentlyContinue
Remove-Item -LiteralPath $mkExe -ErrorAction SilentlyContinue
Remove-Item -LiteralPath $BuildDir -Recurse -Force -ErrorAction SilentlyContinue
