<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ContentType;
use App\Models\Country;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Mode;
use App\Models\Subtitle;
use Inertia\Inertia;
use Inertia\Response;

class ChannelsController extends Controller
{
    /** Tela com todos os itens carregados: edicao manual dos 6 campos + reprocessar */
    public function index(): Response
    {
        return Inertia::render('Channels/Index', [
            'countries' => Country::orderBy('name')->get(),
            'modes' => Mode::orderBy('name')->get(),
            'contentTypes' => ContentType::orderBy('name')->get(),
            'genres' => Genre::orderBy('name')->get(),
            'languages' => Language::orderBy('name')->get(),
            'subtitles' => Subtitle::orderBy('name')->get(),
        ]);
    }
}
