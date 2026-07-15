<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_rates', function (Blueprint $table) {
            // Cost price paid to the supplier, alongside the existing `rate`
            // (the selling price charged to customers) — the gap between the
            // two is the per-litre profit/loss margin.
            $table->decimal('actual_rate', 8, 2)->default(0)->after('rate');
        });
    }

    public function down(): void
    {
        Schema::table('fuel_rates', function (Blueprint $table) {
            $table->dropColumn('actual_rate');
        });
    }
};
