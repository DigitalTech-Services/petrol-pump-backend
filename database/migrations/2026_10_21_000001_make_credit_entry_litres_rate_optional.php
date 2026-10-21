<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Credit is now recorded by its final amount; litres and rate are optional extras.
// Raw ALTERs because doctrine/dbal (needed for ->change()) isn't installed.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE credit_entries MODIFY litres DECIMAL(10,2) NULL');
        DB::statement('ALTER TABLE credit_entries MODIFY rate DECIMAL(10,2) NULL');
    }

    public function down(): void
    {
        DB::statement('UPDATE credit_entries SET litres = 0 WHERE litres IS NULL');
        DB::statement('UPDATE credit_entries SET rate = 0 WHERE rate IS NULL');
        DB::statement('ALTER TABLE credit_entries MODIFY litres DECIMAL(10,2) NOT NULL');
        DB::statement('ALTER TABLE credit_entries MODIFY rate DECIMAL(10,2) NOT NULL');
    }
};
