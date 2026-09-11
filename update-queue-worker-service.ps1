# ---------------------------------------------------------------------------
# Atualiza o servico IptvQueueWorker para processar as tres filas na ordem
# certa de prioridade e reinicia o worker.
#
# Ordem: imports > validation > enrichment
#
#   imports    - importar uma lista nova. E acao interativa (voce acabou de
#                clicar e esta olhando a tela), entao tem que furar a fila.
#                Antes disso ficava atras de centenas de jobs de verificacao,
#                e a lista parecia que "nao importava".
#   validation - verificacao dos canais (ffprobe). Trabalho de fundo, longo.
#   enrichment - preencher tipo/genero/descricao via APIs externas. Sempre por
#                ultimo: um catalogo grande aqui nunca mais pode segurar o
#                resto (foi o que causou a trava de horas).
#
# O Laravel respeita essa ordem: so pega job da fila seguinte quando a
# anterior esta vazia.
#
# Rode este arquivo no PowerShell COMO ADMINISTRADOR, dentro da pasta do
# projeto:
#     .\update-queue-worker-service.ps1
# ---------------------------------------------------------------------------

$ErrorActionPreference = 'Continue'

$ServiceName = 'IptvQueueWorker'
$ProjectDir = $PSScriptRoot
$NssmExe = Join-Path $ProjectDir '.nssm\nssm.exe'

Write-Host ''
Write-Host '== Atualizando o servico do worker ==' -ForegroundColor Cyan
Write-Host ''

if (-not (Test-Path $NssmExe)) {
    Write-Host "ERRO: nao achei o nssm.exe em $NssmExe" -ForegroundColor Red
    Write-Host 'Rode antes o setup-queue-worker-service.ps1 (ele baixa o nssm e instala o servico).' -ForegroundColor Yellow
    exit 1
}

$service = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if (-not $service) {
    Write-Host "ERRO: o servico '$ServiceName' nao existe." -ForegroundColor Red
    Write-Host 'Rode antes o setup-queue-worker-service.ps1 para instala-lo.' -ForegroundColor Yellow
    exit 1
}

$phpCmd = Get-Command php -ErrorAction SilentlyContinue
if (-not $phpCmd) {
    Write-Host 'ERRO: nao achei o php.exe no PATH.' -ForegroundColor Red
    exit 1
}
$PhpExe = $phpCmd.Source

$Params = 'artisan queue:work --queue=imports,validation,enrichment --tries=3 --max-time=3600'

Write-Host 'Parando o servico...' -ForegroundColor Gray
& $NssmExe stop $ServiceName | Out-Null
Start-Sleep -Seconds 2

Write-Host 'Aplicando a nova ordem de filas...' -ForegroundColor Gray
& $NssmExe set $ServiceName Application $PhpExe | Out-Null
& $NssmExe set $ServiceName AppParameters $Params | Out-Null
& $NssmExe set $ServiceName AppDirectory $ProjectDir | Out-Null

Write-Host 'Subindo o servico de novo...' -ForegroundColor Gray
& $NssmExe start $ServiceName | Out-Null
Start-Sleep -Seconds 3

$service = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue

Write-Host ''
if ($service -and $service.Status -eq 'Running') {
    Write-Host 'Pronto. Servico rodando com as filas na ordem:' -ForegroundColor Green
    Write-Host '  imports > validation > enrichment' -ForegroundColor Green
    Write-Host ''
    Write-Host 'Acompanhe em: http://127.0.0.1:8000/fila' -ForegroundColor Cyan
} else {
    Write-Host 'O servico nao subiu. Veja o log em storage\logs\queue-worker.log' -ForegroundColor Red
}
Write-Host ''
