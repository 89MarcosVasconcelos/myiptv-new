<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use Inertia\Inertia;
use Inertia\Response;

class ListsController extends Controller
{
    /** Tela de cadastro: textarea (links por virgula) + upload de CSV ';' */
    public function create(): Response
    {
        return Inertia::render('Lists/Create');
    }

    /** Tela com todas as listas carregadas, metricas e botao de reprocessamento */
    public function index(): Response
    {
        return Inertia::render('Lists/Index');
    }

    /**
     * Tela "Fila de processamento": mostra o que esta enfileirado, o que esta
     * rodando agora e o que falhou — antes disso, a unica forma de saber por
     * que uma lista nao andava era abrir o banco ou o log na mao.
     */
    public function queue(): Response
    {
        return Inertia::render('Queue/Index');
    }

    /** Log de erros de uma lista: canais com falha/mortos e o motivo detectado */
    public function errors(Playlist $playlist): Response
    {
        return Inertia::render('Lists/Errors', [
            'playlist' => $playlist->only('id', 'name', 'url', 'failed_count'),
        ]);
    }
}
