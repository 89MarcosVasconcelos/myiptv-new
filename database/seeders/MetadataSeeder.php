<?php

namespace Database\Seeders;

use App\Models\ContentType;
use App\Models\Country;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Mode;
use App\Models\Subtitle;
use Illuminate\Database\Seeder;

/**
 * Semente minima pra sair do zero: poucos registros de cada taxonomia, so pra
 * nao comecar as 6 telas de cadastro totalmente vazias. Pais/idioma completos
 * (ISO 3166 / ISO 639) entram depois via import da API do iptv-org.
 */
class MetadataSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['code' => 'BR', 'name' => 'Brasil'],
            ['code' => 'US', 'name' => 'Estados Unidos'],
            ['code' => 'PT', 'name' => 'Portugal'],
        ])->each(fn ($c) => Country::firstOrCreate(['code' => $c['code']], $c));

        collect([
            ['code' => 'por', 'name' => 'Português'],
            ['code' => 'eng', 'name' => 'Inglês'],
            ['code' => 'spa', 'name' => 'Espanhol'],
        ])->each(fn ($l) => Language::firstOrCreate(['code' => $l['code']], $l));

        collect([
            ['slug' => 'ao-vivo', 'name' => 'Ao vivo'],
            ['slug' => 'gravado', 'name' => 'Gravado'],
        ])->each(fn ($m) => Mode::firstOrCreate(['slug' => $m['slug']], $m));

        collect([
            ['slug' => 'canal', 'name' => 'Canal'],
            ['slug' => 'filme', 'name' => 'Filme'],
            ['slug' => 'serie', 'name' => 'Série'],
        ])->each(fn ($t) => ContentType::firstOrCreate(['slug' => $t['slug']], $t));

        collect([
            ['slug' => 'noticias', 'name' => 'Notícias'],
            ['slug' => 'esportes', 'name' => 'Esportes'],
            ['slug' => 'ficcao', 'name' => 'Ficção'],
            ['slug' => 'acao', 'name' => 'Ação'],
            ['slug' => 'aventura', 'name' => 'Aventura'],
            ['slug' => 'infantil', 'name' => 'Infantil'],
        ])->each(fn ($g) => Genre::firstOrCreate(['slug' => $g['slug']], $g));

        collect([
            ['code' => 'non', 'name' => 'Sem legenda'],
            ['code' => 'por', 'name' => 'Português'],
            ['code' => 'eng', 'name' => 'Inglês'],
        ])->each(fn ($s) => Subtitle::firstOrCreate(['code' => $s['code']], $s));
    }
}
