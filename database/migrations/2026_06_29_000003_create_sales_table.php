<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');

            $table->date('date');
            $table->string('shift', 20)->default('Full Day');

            // Fuel volumes
            $table->decimal('ms_volume',    10, 2)->default(0);
            $table->decimal('hsd_volume',   10, 2)->default(0);
            $table->decimal('speed_volume', 10, 2)->default(0);

            // Rates
            $table->decimal('rate_ms',    8, 2)->default(0);
            $table->decimal('rate_hsd',   8, 2)->default(0);
            $table->decimal('rate_speed', 8, 2)->default(0);

            // Financials
            $table->decimal('revenue',     12, 2)->default(0);
            $table->decimal('cash',        12, 2)->default(0);
            $table->decimal('card',        12, 2)->default(0);
            $table->decimal('phone_pe',    12, 2)->default(0);
            $table->decimal('credit_sale', 12, 2)->default(0);
            $table->decimal('expenses',    12, 2)->default(0);
            $table->decimal('balance',     12, 2)->default(0);

            $table->text('narration')->nullable();

            // Audit columns
            $table->string('created_at', 45)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_name')->nullable();
            $table->string('created_host_name')->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->string('updated_at', 45)->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_name')->nullable();
            $table->string('updated_host_name')->nullable();
            $table->string('updated_ip', 45)->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
