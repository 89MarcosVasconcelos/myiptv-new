<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
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
}
