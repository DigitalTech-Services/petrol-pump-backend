<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->decimal('rate_per_hour', 8, 2)->default(0)->after('rate_per_day');
        });

        // One-time best-effort conversion from the old daily rate — no hourly
        // rate existed before this, so this is an approximation, not a
        // historical fact. Users can correct it afterwards in Staff & Salary.
        DB::statement('UPDATE staff SET rate_per_hour = ROUND(rate_per_day / GREATEST(shift_hours, 1), 2)');

        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn(['rate_per_day', 'days_worked']);
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->decimal('rate_per_day', 10, 2)->default(0);
            $table->smallInteger('days_worked')->default(0);
        });

        DB::statement('UPDATE staff SET rate_per_day = ROUND(rate_per_hour * GREATEST(shift_hours, 1), 2), days_worked = 30');

        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('rate_per_hour');
        });
    }
};
