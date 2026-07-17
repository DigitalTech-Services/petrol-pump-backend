<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_attendance', function (Blueprint $table) {
            // Snapshot of the staff member's rate at the time this day was
            // logged — mirrors sales.rate_ms/rate_hsd. Once written, a later
            // change to staff.rate_per_hour must never alter this row.
            $table->decimal('rate_per_hour', 8, 2)->nullable()->after('total_hours');
        });

        // Backfill existing rows from their staff member's current rate —
        // best-effort only, since no rate was ever captured per-day before.
        DB::statement('
            UPDATE staff_attendance sa
            JOIN staff s ON s.id = sa.staff_id
            SET sa.rate_per_hour = s.rate_per_hour
            WHERE sa.rate_per_hour IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('staff_attendance', function (Blueprint $table) {
            $table->dropColumn('rate_per_hour');
        });
    }
};
