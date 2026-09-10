<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MetadataController extends Controller
{
    private const LABELS = [
        'paises' => 'Países',
        'modos' => 'Modos',
        'tipos' => 'Tipos',
        'generos' => 'Gêneros',
        'idiomas' => 'Idiomas',
        'legendas' => 'Legendas',
    ];

    /** Tela de cadastro completa, reaproveitada pelas 6 taxonomias */
    public function index(Request $request, string $type): Response
    {
        abort_unless(array_key_exists($type, self::LABELS), 404);

        return Inertia::render('Metadata/Index', [
            'type' => $type,
            'label' => self::LABELS[$type],
        ]);
    }
}
