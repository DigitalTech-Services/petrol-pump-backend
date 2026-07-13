<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('station_id')->nullable()->after('user_id');
            $table->foreign('station_id')->references('id')->on('stations')->nullOnDelete();
            $table->index(['station_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['station_id', 'date']);
            $table->dropForeign(['station_id']);
            $table->dropColumn('station_id');
        });
    }
};
