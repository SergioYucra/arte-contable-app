<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Nota: no se usa WithoutModelEvents para que la creación del
     * SuperAdmin quede registrada en el log de auditoría.
     */
    public function run(): void
    {
        $this->call([
            CoreSecuritySeeder::class,
        ]);
    }
}
