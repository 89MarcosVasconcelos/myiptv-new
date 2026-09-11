#!/bin/sh
# Ponto de entrada unico da imagem — o "$1" decide se este container sobe
# como "app" (nginx + php-fpm) ou como "worker" (queue:work), conforme o
# 'command:' de cada serviço no docker-compose.yml.
set -e

wait_for_db() {
    echo "Aguardando o banco de dados responder..."
    until php artisan db:show > /dev/null 2>&1; do
        echo "Banco ainda nao respondeu — tentando de novo em 3s..."
        sleep 3
    done
    echo "Banco de dados OK."
}

case "$1" in
    app)
        wait_for_db

        echo "Rodando migrations..."
        php artisan migrate --force

        # So o container "app" faz cache de config/rotas/views — evita dois
        # containers (app + worker) escrevendo o cache ao mesmo tempo.
        echo "Gerando cache de config/rotas/views..."
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache

        echo "Subindo nginx + php-fpm..."
        exec supervisord -c /etc/supervisord.conf
        ;;

    worker)
        wait_for_db

        # Nao roda migrate aqui de proposito — evita dois processos
        # migrando ao mesmo tempo. Quem migra e sempre o container "app".
        echo "Subindo o worker da fila (imports > validation > enrichment)..."
        exec php artisan queue:work \
            --queue=imports,validation,enrichment \
            --tries=3 \
            --max-time=3600
        ;;

    *)
        # Permite rodar comandos avulsos dentro da imagem, ex.:
        #   docker compose run app php artisan tinker
        exec "$@"
        ;;
esac
