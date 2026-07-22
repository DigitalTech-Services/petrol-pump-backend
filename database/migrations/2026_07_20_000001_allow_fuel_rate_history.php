<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fuel rates change daily, so a station needs one row PER DAY it was changed,
// not a single row that gets overwritten every time Settings is saved. This
// drops the old "one row per station+fuel_key" constraint in favor of "one row
// per station+fuel_key+effective_date", turning fuel_rates into an append-only
// history that a specific sale's date can look up the correct rate from.
return new class extends Migration
{
    public function up(): void
    {
        // The station_id foreign key relies on the (station_id, fuel_key) unique
        // index as its supporting index — MySQL refuses to drop that index until
        // the FK has another one to fall back on, so add a plain index first.
        Schema::table('fuel_rates', function (Blueprint $table) {
            $table->index('station_id');
        });

        Schema::table('fuel_rates', function (Blueprint $table) {
            $table->dropUnique(['station_id', 'fuel_key']);
            $table->unique(['station_id', 'fuel_key', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::table('fuel_rates', function (Blueprint $table) {
            $table->dropUnique(['station_id', 'fuel_key', 'effective_date']);
            $table->unique(['station_id', 'fuel_key']);
        });

        Schema::table('fuel_rates', function (Blueprint $table) {
            $table->dropIndex(['station_id']);
        });
    }
};
