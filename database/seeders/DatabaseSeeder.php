<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! Admin::where('username', 'admin')->exists()) {
            Admin::factory()->create();
        }
    }
}
