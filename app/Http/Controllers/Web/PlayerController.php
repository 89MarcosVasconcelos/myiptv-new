<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ContentType;
use App\Models\Country;
use App\Models\Genre;
use App\Models\Mode;
use Inertia\Inertia;
use Inertia\Response;

class PlayerController extends Controller
{
    /** Tela de exibicao: select de canal com busca + selects encadeados de filtro */
    public function show(): Response
    {
        return Inertia::render('Player/Show', [
            'countries' => Country::orderBy('name')->get(),
            'modes' => Mode::orderBy('name')->get(),
            'contentTypes' => ContentType::orderBy('name')->get(),
            'genres' => Genre::orderBy('name')->get(),
        ]);
    }
}
