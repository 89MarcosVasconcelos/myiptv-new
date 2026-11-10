# MPTV

Plataforma IPTV self-hosted (live + VOD): agrega canais, valida os links
continuamente, serve a playlist M3U e um site com player (hls.js / mpegts.js).

O projeto é baseado no **DevolpsTV**, de Bruno Baumgartner
(<https://github.com/brunobaumgartner/DevolpsTV>), com layout próprio e melhorias
vindas do MPTV anterior (Laravel/Vue).

## Stack

- **api/** — FastAPI + SQLAlchemy + MySQL; serve a API e o front compilado
- **worker/** — APScheduler: valida links (OK / MORTO / INCONCLUSIVO), limpezas e jobs periódicos
- **frontend-react/** — Preact + Tailwind + Vite (hash router)
- **mysql/migrations/** — SQL aplicado automaticamente na subida da API (`schema_migrations`)

## O que veio do projeto anterior

- Proteção **SSRF** nos proxies HLS/mídia e na importação (bloqueia rede interna, revalida cada redirect)
- Importação de **lista M3U/M3U8** por URL ou texto (Admin → Cadastrar listas), preservando
  `#EXTVLCOPT` (referrer / user-agent) e descartando só os links mortos
- Cookie de sessão `Secure` configurável (`COOKIE_SECURE`)
- Migrations automáticas e banco MySQL próprio no compose

## Deploy (Coolify)

1. Recurso **Docker Compose** apontando para este repositório (branch `producao`), arquivo `docker-compose.yaml`.
2. Domínio no serviço **`app`** (porta `7678`), ex.: `https://mptv.seudominio.com:7678`.
3. As senhas `SERVICE_PASSWORD_*` são geradas pelo Coolify. A senha do admin é `SERVICE_PASSWORD_ADMIN`
   (usuário `ADMIN_USERNAME`, padrão `admin`).
4. Opcional: `TMDB_API_KEY` (capas/sinopses) e `GITHUB_TOKEN` (token sem escopos, habilita a busca de listas por código). Mantenha `COOKIE_SECURE=true` (site em HTTPS).
5. CI: `.github/workflows/deploy.yml` verifica o código e chama o webhook do Coolify se os secrets
   `COOLIFY_WEBHOOK_URL` e `COOLIFY_TOKEN` existirem.

> O compose cria um MySQL **novo** (volume `mysql_data`). Bancos de versões antigas não são reaproveitados.

## Importar fontes (só admin)

Menu **Administração → Importar fontes**: fontes prontas (iptv-org por país/categoria/idioma, Free-TV), URLs
salvas por você e descoberta no GitHub (varredura de repositório ou busca por código). Candidatas do GitHub
entram como *pendentes* e nada é importado antes de você aprovar. Só listas de canais ao vivo; adultos são
pulados por padrão e links mortos descartados. Filmes/séries seguem manuais (CSV), com a escolha do resultado
certo do TMDB na mesma página. Uso pessoal — sem cadastro público.

## Rodando local

```bash
cp .env.example .env
docker network create coolify        # uma vez (o compose usa essa rede no Coolify)
docker compose up -d --build         # http://localhost:7678
docker compose logs app | grep -A2 "Token de teste"
```

Desenvolvimento do front: `cd frontend-react && npm ci && npm run dev` (proxy para a API em `localhost:7678`).

## Teste de fumaça (site no ar)

```bash
pip install requests
MPIPTV_BASE_URL=https://seu-dominio MPIPTV_ADMIN_PASS=... python scripts/smoke_test.py
```

## Catálogo VOD via CSV

Modelo em `data/titulos.exemplo.csv` (colunas: `type,title,description,poster_url,genre,year,season_number,episode_number,episode_title,stream_url`).
Também dá para importar CSV pelo painel (Admin → Cadastrar VOD / Cadastrar listas).

Detalhes de arquitetura e decisões: [ARQUITETURA.md](./ARQUITETURA.md).
