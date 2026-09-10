<?php

// Vazio por enquanto: os endpoints /api/v1/* vivem em routes/web.php, dentro
// do middleware "web" + "auth" (sessão), porque o scaffold do Breeze não
// habilitou routes/api.php (bootstrap/app.php não tem a chave "api") e o
// Sanctum não foi instalado com "php artisan install:api". Quando o app
// mobile (Fase 3) precisar de autenticação por token, faz esse setup e move
// as rotas de volta pra cá com o guard "auth:sanctum".
