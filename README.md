# IPTV MP

Plataforma web para cadastrar, validar e exibir listas IPTV (M3U/M3U8) e mídia
direta (mp4/mkv/etc — inclusive séries), com telas de metadado (país, modo,
tipo, gênero, idioma, legenda) e um player com busca e filtros encadeados.

Este commit inicial contém todo o **código específico do projeto** (migrations,
models, jobs, controllers, rotas, páginas Vue). Ele foi escrito diretamente
aqui porque este ambiente não tem saída de rede para o Packagist/npm — os
comandos abaixo, que baixam o framework e as dependências, precisam ser
rodados na sua máquina.

## Setup (rodar localmente, nesta pasta)

```bash
# 1. Scaffold do Laravel 12 (cria vendor/, config/, resources/views, etc. —
#    não sobrescreve os arquivos que já estão aqui: app/, database/migrations,
#    database/seeders, routes/web.php e routes/api.php)
composer create-project laravel/laravel:^12.0 tmp-laravel
rsync -a --ignore-existing tmp-laravel/ ./
rm -rf tmp-laravel

# 2. Dependências do projeto
composer require inertiajs/inertia-laravel laravel/sanctum
composer require laravel/breeze --dev
php artisan breeze:install vue
npm install
npm install hls.js

# 3. Banco (MySQL) — ajuste o .env antes:
#    DB_CONNECTION=mysql, DB_DATABASE=myiptv, DB_USERNAME/DB_PASSWORD
php artisan migrate --seed

# 4. Filas — a validação (HTTP probe + ffprobe) roda na fila "validation",
#    separada da "default", pra uma importação grande não travar o resto.
#    QUEUE_CONNECTION=database (ou redis) no .env, depois:
php artisan queue:work --queue=validation,default

# 5. Sobe o app
npm run dev        # outro terminal
php artisan serve
```

`ffprobe` precisa estar instalado e no PATH (parte do ffmpeg) — é ele quem
confirma que um stream realmente abre, não só que responde 200 OK.

## Estrutura do que já está pronto

- `database/migrations` — schema completo (sources, playlists, channels,
  channel_checks, import_runs + as 6 tabelas de metadado).
- `app/Models` — todas as entidades e relacionamentos.
- `app/Jobs` — `ImportPlaylistJob` (baixa, detecta playlist vs. mídia direta,
  faz parse M3U sem descartar headers/EPG/número de canal), `ValidateChannelsJob`
  (probe HTTP + ffprobe, em lote via `Bus::batch`), `FinalizeImportRunJob`.
- `app/Support/SafeUrl.php` — bloqueio de SSRF (recusa IP privado/local antes
  de qualquer request).
- `app/Http/Controllers/Api/V1` — API v1 única (sessão na web, token no app
  mobile via Sanctum): playlists, channels (com endpoint "faceted" pros
  selects encadeados do player), metadata (CRUD genérico das 6 taxonomias).
- `app/Http/Controllers/Web` + `resources/js/Pages` — as 5 telas pedidas:
  cadastro de listas, listas carregadas, itens com edição inline, cadastro de
  metadados, player.
- `routes/web.php` e `routes/api.php`.

## O que falta (próximos passos, depois do `composer install` local)

- Rodar o setup acima e conferir se tudo sobe sem erro (`php artisan route:list`,
  lint com Pint/PHPStan).
- Autenticação: as rotas assumem o middleware `auth` do Breeze.
- Ajustar `config/queue.php`/`.env` conforme o ambiente real (fila database
  serve pra começar; Redis quando o volume de validação crescer).
- Catálogo pré-carregado (iptv-org, Free-TV) e sincronização periódica — fica
  pra depois desta primeira base estar rodando.
