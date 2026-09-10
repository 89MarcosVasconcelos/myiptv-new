<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    /*
    | Caminho do binario do ffprobe usado na verificacao de canais
    | (ValidateChannelsJob). Padrao 'ffprobe' assume que esta no PATH do
    | processo que roda o "php artisan queue:work" — no Windows isso costuma
    | falhar quando o ffmpeg so foi adicionado ao PATH do Git Bash/MSYS
    | (formato /c/ffmpeg/bin), que processos nativos do Windows nao entendem.
    | Se aparecer o erro "nao e reconhecido como um comando interno ou
    | externo...", defina FFPROBE_PATH no .env com o caminho completo, ex.:
    | FFPROBE_PATH="C:\\ffmpeg\\bin\\ffprobe.exe"
    */
    'ffprobe' => [
        'path' => env('FFPROBE_PATH', 'ffprobe'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
