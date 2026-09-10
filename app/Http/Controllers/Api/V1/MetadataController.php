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

    /**
     * GET /api/v1/metadata/{type}
     * Suporta busca (?q=, por nome e pelo codigo/slug) e ordenacao (?sort=,
     * ?dir=) alem da paginacao — antes o front so lia data.data e ignorava a
     * paginacao, entao qualquer taxonomia com mais de 50 registros escondia
     * o resto silenciosamente.
     */
    public function index(Request $request, string $type)
    {
        $model = $this->model($type);
        $codeColumn = $this->usesCode($model) ? 'code' : 'slug';

        $query = $model::query();

        if ($request->filled('q')) {
            $term = '%' . $request->q . '%';
            $query->where(function ($sub) use ($term, $codeColumn) {
                $sub->where('name', 'like', $term)
                    ->orWhere($codeColumn, 'like', $term);
            });
        }

        $sortable = ['name', $codeColumn];
        $sort = in_array($request->input('sort'), $sortable, true) ? $request->input('sort') : 'name';
        $direction = $request->input('dir') === 'desc' ? 'desc' : 'asc';
        $perPage = min(max((int) $request->input('per_page', 50), 10), 200);

        return response()->json($query->orderBy($sort, $direction)->paginate($perPage)->withQueryString());
    }

    public function store(Request $request, string $type)
    {
        $model = $this->model($type);
        $usesCode = $this->usesCode($model);

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

    private function usesCode(string $model): bool
    {
        return in_array($model, [Country::class, Language::class, Subtitle::class], true);
    }
}
