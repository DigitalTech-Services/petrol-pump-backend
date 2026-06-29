<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meter_reading_nozzles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('meter_reading_id');

            $table->string('nozzle_id', 20);   // e.g. MS-1, MS-2, HSD-1, SP-1
            $table->decimal('opening', 12, 2);
            $table->decimal('closing', 12, 2);
            $table->decimal('used', 10, 2)->default(0);

            $table->timestamps();

            $table->foreign('meter_reading_id')->references('id')->on('meter_readings')->onDelete('cascade');
            $table->unique(['meter_reading_id', 'nozzle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_reading_nozzles');
    }
};
