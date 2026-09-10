# setup-queue-worker-service.ps1
# Configura o worker da fila 'validation' do IPTV do MP como servico do Windows,
# usando NSSM: inicia sozinho com o Windows e reinicia se cair, sem precisar de
# terminal aberto. Rode este script em um PowerShell ABERTO COMO ADMINISTRADOR.

$ErrorActionPreference = 'Continue'

$ProjectDir  = 'C:\Users\conmyv\Desktop\MP_project\IPTV_MP\myiptv-new'
$ServiceName = 'IptvQueueWorker'
$NssmDir     = "$ProjectDir\.nssm"
$NssmExe     = "$NssmDir\nssm.exe"
$LogFile     = "$ProjectDir\storage\logs\queue-worker.log"

Write-Host "== 1/5: localizando php.exe ==" -ForegroundColor Cyan
$phpCmd = Get-Command php -ErrorAction SilentlyContinue
if (-not $phpCmd) {
    Write-Error "Nao encontrei 'php' no PATH. Edite a variavel `$PhpExe abaixo com o caminho completo do php.exe e rode o script de novo."
    exit 1
}
$PhpExe = $phpCmd.Source
Write-Host "  php.exe: $PhpExe"

Write-Host "== 2/5: garantindo NSSM ==" -ForegroundColor Cyan
if (-not (Test-Path $NssmExe)) {
    New-Item -ItemType Directory -Force -Path $NssmDir | Out-Null
    $zipPath = "$NssmDir\nssm.zip"
    Write-Host "  baixando NSSM de nssm.cc..."
    try {
        Invoke-WebRequest -Uri 'https://nssm.cc/release/nssm-2.24.zip' -OutFile $zipPath -UseBasicParsing
    } catch {
        Write-Error "Nao consegui baixar o NSSM automaticamente (sem internet ou bloqueado). Baixe manualmente em https://nssm.cc/download, extraia, e copie o nssm.exe (pasta win64) para: $NssmExe -- depois rode este script de novo."
        exit 1
    }
    Expand-Archive -Path $zipPath -DestinationPath $NssmDir -Force
    $arch = if ([Environment]::Is64BitOperatingSystem) { 'win64' } else { 'win32' }
    Copy-Item "$NssmDir\nssm-2.24\$arch\nssm.exe" $NssmExe -Force
    Write-Host "  NSSM pronto em $NssmExe"
} else {
    Write-Host "  NSSM ja estava em $NssmExe"
}

Write-Host "== 3/5: removendo instalacao anterior (se existir) ==" -ForegroundColor Cyan
if (Get-Service -Name $ServiceName -ErrorAction SilentlyContinue) {
    Write-Host "  servico ja existia, parando e removendo pra recriar do zero..."
    & $NssmExe stop $ServiceName *> $null
    Start-Sleep -Seconds 1
    & $NssmExe remove $ServiceName confirm *> $null
} else {
    Write-Host "  nenhuma instalacao anterior encontrada, seguindo."
}

Write-Host "== 4/5: instalando e configurando o servico ==" -ForegroundColor Cyan
& $NssmExe install $ServiceName $PhpExe "artisan queue:work --queue=validation --tries=3 --max-time=3600"
& $NssmExe set $ServiceName AppDirectory $ProjectDir
& $NssmExe set $ServiceName Start SERVICE_AUTO_START
& $NssmExe set $ServiceName AppStdout $LogFile
& $NssmExe set $ServiceName AppStderr $LogFile
& $NssmExe set $ServiceName AppExit Default Restart

Write-Host "== 5/5: iniciando o servico ==" -ForegroundColor Cyan
& $NssmExe start $ServiceName
Start-Sleep -Seconds 2

$svc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if ($svc) {
    $svc | Format-Table Name, Status, StartType -AutoSize
    if ($svc.Status -eq 'Running') {
        Write-Host ""
        Write-Host "Pronto! Servico '$ServiceName' instalado, rodando, e vai reiniciar sozinho com o Windows." -ForegroundColor Green
        Write-Host "Log em: $LogFile"
    } else {
        Write-Host ""
        Write-Host "Servico instalado mas nao ficou 'Running' (status: $($svc.Status)). Confira o log: $LogFile" -ForegroundColor Yellow
    }
} else {
    Write-Host ""
    Write-Host "Nao encontrei o servico '$ServiceName' depois da instalacao - algo falhou acima. Confira as mensagens de erro." -ForegroundColor Red
}
