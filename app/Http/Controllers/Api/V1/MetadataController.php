<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContentType;
use App\Models\Country;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Mode;
use App\Models\Subtitle;
use Illuminate\Http\Request;

/**
 * CRUD generico para as 6 tabelas de metadado (pais, modo, tipo, genero,
 * idioma, legenda). Uma controller so, parametrizada pelo tipo na rota, em vez
 * de 6 controllers quase identicas.
 */
class MetadataController extends Controller
{
    private const MAP = [
        'paises' => Country::class,
        'modos' => Mode::class,
        'tipos' => ContentType::class,
        'generos' => Genre::class,
        'idiomas' => Language::class,
        'legendas' => Subtitle::class,
    ];

    public function index(string $type)
    {
        return response()->json($this->model($type)::orderBy('name')->paginate(50));
    }

    public function store(Request $request, string $type)
    {
        $model = $this->model($type);
        $usesCode = in_array($model, [Country::class, Language::class, Subtitle::class]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            $usesCode ? 'code' : 'slug' => 'required|string|max:10|unique:' . (new $model)->getTable(),
        ]);

        return response()->json($model::create($data), 201);
    }

    public function update(Request $request, string $type, int $id)
    {
        $model = $this->model($type);
        $record = $model::findOrFail($id);
        $record->update($request->validate(['name' => 'required|string|max:255']));

        return response()->json($record);
    }

    public function destroy(string $type, int $id)
    {
        $this->model($type)::findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    private function model(string $type): string
    {
        return self::MAP[$type] ?? abort(404, "Tipo de metadado desconhecido: {$type}");
    }
}
