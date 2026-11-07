# Contexto de build = RAIZ do repositório (ver docker-compose.yaml).
# Estágio 1 compila o front (Preact + Tailwind), estágio 2 é a API FastAPI que
# serve a API e o front estático em um único container.

FROM node:20-alpine AS front
WORKDIR /front
COPY frontend-react/package.json frontend-react/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend-react/ ./
RUN npm run build

FROM python:3.12-slim

WORKDIR /app

RUN useradd --create-home --shell /bin/bash appuser

COPY api/requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY api/app ./app
# migrations SQL aplicadas automaticamente na subida (app/migrate.py)
COPY mysql/migrations ./migrations
COPY --from=front /front/dist ./frontend

# data/ guarda CSVs de importação (volume no compose)
RUN mkdir -p /app/data && chown -R appuser:appuser /app/data

USER appuser

# UMA porta só: com várias EXPOSE o Traefik do Coolify fica ambíguo e devolve 504
EXPOSE 7678

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "7678", "--proxy-headers", "--forwarded-allow-ips", "*"]
