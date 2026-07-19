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
            $table->decimal('monthly_salary', 10, 2)->default(0)->after('rate_per_hour');
        });

        // Best-effort backfill using today's day count — the hourly rate was the
        // only source of truth before this, so this is an approximation.
        DB::statement('UPDATE staff SET monthly_salary = ROUND(rate_per_hour * GREATEST(shift_hours, 1) * DAY(LAST_DAY(NOW())), 2)');

        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('rate_per_hour');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->decimal('rate_per_hour', 8, 2)->default(0)->after('monthly_salary');
        });

        DB::statement('UPDATE staff SET rate_per_hour = ROUND(monthly_salary / DAY(LAST_DAY(NOW())) / GREATEST(shift_hours, 1), 2)');

        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('monthly_salary');
        });
    }
};
