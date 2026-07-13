<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Split into separate Schema::table() calls per table/direction — combining
        // drop + add of columns/indexes/foreign keys in one ALTER TABLE can let MySQL's
        // instant-DDL path commit the "add" half before validating the "drop" half,
        // leaving a partially-applied table if the statement then errors.
        Schema::table('fuel_rates', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id', 'fuel_key']);
        });
        Schema::table('fuel_rates', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });
        Schema::table('fuel_rates', function (Blueprint $table) {
            $table->unsignedBigInteger('station_id')->nullable()->after('id');
        });
        Schema::table('fuel_rates', function (Blueprint $table) {
            $table->foreign('station_id')->references('id')->on('stations')->onDelete('cascade');
            $table->unique(['station_id', 'fuel_key']);
        });

        Schema::table('nozzles', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('nozzles', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });
        Schema::table('nozzles', function (Blueprint $table) {
            $table->unsignedBigInteger('station_id')->nullable()->after('id');
        });
        Schema::table('nozzles', function (Blueprint $table) {
            $table->foreign('station_id')->references('id')->on('stations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('fuel_rates', function (Blueprint $table) {
            $table->dropForeign(['station_id']);
            $table->dropUnique(['station_id', 'fuel_key']);
        });
        Schema::table('fuel_rates', function (Blueprint $table) {
            $table->dropColumn('station_id');
        });
        Schema::table('fuel_rates', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->after('id');
        });
        Schema::table('fuel_rates', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['user_id', 'fuel_key']);
        });

        Schema::table('nozzles', function (Blueprint $table) {
            $table->dropForeign(['station_id']);
        });
        Schema::table('nozzles', function (Blueprint $table) {
            $table->dropColumn('station_id');
        });
        Schema::table('nozzles', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->after('id');
        });
        Schema::table('nozzles', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
