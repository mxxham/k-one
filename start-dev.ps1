param(
    [switch]$Build,
    [switch]$NoFrontend,
    [switch]$NoBrowser,
    [switch]$Stop
)

$ErrorActionPreference = 'Stop'
$root      = $PSScriptRoot
$frontend  = Join-Path $root 'frontend'
$backendUrl = 'http://localhost/k-one/index.php'
$frontendUrl = 'http://localhost:5173/'
$dockerDesktop = "C:\Users\asust\AppData\Local\Programs\DockerDesktop\Docker Desktop.exe"

function Write-Step($msg) { Write-Host "`n==> $msg" -ForegroundColor Cyan }

if ($Stop) {
    Write-Step "Stopping frontend (Vite)..."
    Get-CimInstance Win32_Process -Filter "Name = 'node.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -like '*frontend*' -and $_.CommandLine -like '*node_modules*vite*' } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    Write-Step "Stopping backend (Docker)..."
    docker compose -f (Join-Path $root 'docker-compose.yml') down
    Write-Host "Done. Everything stopped."
    exit 0
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw "Docker CLI tidak ditemukan. Pastikan Docker sudah terinstall."
}

if (-not (Get-Command npm -ErrorAction SilentlyContinue)) {
    throw "npm tidak ditemukan. Pastikan Node.js sudah terinstall."
}

# 1. Docker daemon
Write-Step "Memastikan Docker daemon berjalan..."
docker info 2>$null | Out-Null
$dockerReady = ($LASTEXITCODE -eq 0)
if (-not $dockerReady) {
    if (Test-Path -LiteralPath $dockerDesktop) {
        Write-Host "Docker Desktop belum berjalan, mencoba memulainya..."
        Start-Process -FilePath $dockerDesktop
        $ready = $false
        for ($i = 0; $i -lt 60; $i++) {
            Start-Sleep -Seconds 5
            docker info 2>$null | Out-Null
            if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        }
        if (-not $ready) { throw "Docker Desktop tidak merespons. Mulai manual lalu jalankan ulang." }
    } else {
        throw "Docker daemon tidak berjalan. Nyalakan Docker lalu jalankan ulang."
    }
    Write-Host "Docker daemon siap."
} else {
    Write-Host "Docker daemon sudah berjalan."
}

# 2. Backend
Write-Step "Menjalankan backend (docker compose up)..."
if ($Build) {
    docker compose -f (Join-Path $root 'docker-compose.yml') up -d --build
} else {
    docker compose -f (Join-Path $root 'docker-compose.yml') up -d
}
if ($LASTEXITCODE -ne 0) { throw "docker compose up gagal." }

Write-Host "Menunggu backend siap ($backendUrl)..."
$backendReady = $false
for ($i = 0; $i -lt 40; $i++) {
    try {
        $resp = Invoke-WebRequest -Uri $backendUrl -UseBasicParsing -TimeoutSec 5
        if ($resp.StatusCode -eq 200) { $backendReady = $true; break }
    } catch {}
    Start-Sleep -Seconds 3
}
if (-not $backendReady) {
    Write-Host "Peringatan: backend belum merespons dalam batas waktu, lanjut ke frontend..."
} else {
    Write-Host "Backend siap."
}

# 3. Frontend
if (-not $NoFrontend) {
    Write-Step "Menjalankan frontend (Vite dev server) di :5173..."
    $viteAlreadyUp = $false
    try {
        $r = Invoke-WebRequest -Uri $frontendUrl -UseBasicParsing -TimeoutSec 5
        if ($r.StatusCode -eq 200) { $viteAlreadyUp = $true }
    } catch {}

    if (-not $viteAlreadyUp) {
        Start-Process -FilePath "cmd.exe" -ArgumentList "/c npm run dev" -WorkingDirectory $frontend -WindowStyle Normal
        $frontendReady = $false
        for ($i = 0; $i -lt 30; $i++) {
            Start-Sleep -Seconds 2
            try {
                $r = Invoke-WebRequest -Uri $frontendUrl -UseBasicParsing -TimeoutSec 5
                if ($r.StatusCode -eq 200) { $frontendReady = $true; break }
            } catch {}
        }
        if ($frontendReady) { Write-Host "Frontend siap." }
    } else {
        Write-Host "Frontend sudah berjalan."
    }
}

# 4. Summary
Write-Step "Semua service telah dinyalakan."
Write-Host "  Backend : $backendUrl"
Write-Host "  Frontend: $frontendUrl"
Write-Host "  Login   : admin / admin123"

if (-not $NoFrontend -and -not $NoBrowser) {
    try { Start-Process $frontendUrl; Write-Host "`nBrowser dibuka ke $frontendUrl" } catch {}
}

Write-Host "`nUntuk menghentikan semua: .\start-dev.ps1 -Stop"