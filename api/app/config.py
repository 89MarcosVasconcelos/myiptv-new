import os

from sqlalchemy.engine import URL


def _database_url():
    """Monta a conexão com o MySQL. Aceita DATABASE_URL pronta (uso local /
    legado) OU as partes MYSQL_* (usadas no docker-compose/Coolify). Montar
    pelas partes com URL.create escapa sozinho caracteres especiais da senha —
    coisa que concatenar string na mão (mysql://user:senha@host) não faz."""
    url = os.environ.get("DATABASE_URL")
    if url:
        return url
    return URL.create(
        "mysql+pymysql",
        username=os.environ["MYSQL_USER"],
        password=os.environ["MYSQL_PASSWORD"],
        host=os.environ.get("MYSQL_HOST", "mysql"),
        port=int(os.environ.get("MYSQL_PORT", "3306")),
        database=os.environ["MYSQL_DATABASE"],
    )


DATABASE_URL = _database_url()

# Cookie de sessão com flag Secure: ligado em produção (site atrás de HTTPS /
# Traefik do Coolify). Desligado por padrão só pra rodar local em http.
COOKIE_SECURE = os.environ.get("COOKIE_SECURE", "false").strip().lower() in ("1", "true", "yes", "sim")

# credenciais do painel de admin (cadastro de links VOD) — trocadas a cada
# subida a partir do .env, que é a fonte de verdade (ver ARQUITETURA.md secao 9)
ADMIN_USERNAME = os.environ.get("ADMIN_USERNAME", "admin")
ADMIN_PASSWORD = os.environ.get("ADMIN_PASSWORD")

# TODO: mover para o próprio API_PORT via env quando formos além do teste local
FRONTEND_DIR = os.path.join(os.path.dirname(os.path.dirname(__file__)), "frontend")

# dataset publico do IMDb (title.basics.tsv.gz + title.akas.tsv.gz), usado pra
# classificar genero por titulo real (nao por palavra-chave) — ver
# api/app/imdb_classifier.py. Baixado manualmente em datasets.imdbws.com, não
# versionado no git (são ~750MB juntos). Ausente = feature simplesmente
# aparece indisponível no dashboard, sem quebrar nada.
IMDB_DATA_DIR = os.environ.get("IMDB_DATA_DIR", "/app/data/imdb")

# chave grátis v3 do TMDB (themoviedb.org -> Configurações -> API). Usada pra
# enriquecer o catálogo VOD com pôster + sinopse + ano + gênero + fundo + nota
# numa única busca — ver api/app/poster_fetch.py. Ausente = o botão do
# dashboard devolve erro claro e nada acontece.
TMDB_API_KEY = os.environ.get("TMDB_API_KEY", "")

# token do GitHub (só leitura, sem escopos) pra busca de listas por código na
# página "Importar fontes". Sem ele, a busca de código fica desligada (a API do
# GitHub exige login pra isso); a varredura de um repositório funciona mesmo assim.
GITHUB_TOKEN = os.environ.get("GITHUB_TOKEN", "")
