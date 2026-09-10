<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@myiptv.local'],
            ['name' => 'Admin', 'password' => bcrypt('password')]
        );

        $this->call(MetadataSeeder::class);
    }
}
