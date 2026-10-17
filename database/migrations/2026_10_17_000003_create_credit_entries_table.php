<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('station_id')->nullable();
            $table->unsignedBigInteger('credit_customer_id');

            $table->date('date');
            $table->string('fuel_type', 10);                    // ms | hsd | speed
            $table->decimal('litres', 10, 2);
            $table->decimal('rate', 10, 2);
            $table->decimal('amount', 12, 2);
            $table->string('vehicle_number', 30)->nullable();
            $table->text('notes')->nullable();

            // Settlement
            $table->string('status', 10)->default('pending');   // pending | paid
            $table->date('paid_on')->nullable();
            $table->string('payment_mode', 20)->nullable();
            $table->string('payment_ref', 100)->nullable();

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
            $table->foreign('station_id')->references('id')->on('stations')->nullOnDelete();
            $table->foreign('credit_customer_id')->references('id')->on('credit_customers')->onDelete('cascade');
            $table->index(['user_id', 'date']);
            $table->index(['station_id', 'date']);
            $table->index(['credit_customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_entries');
    }
};
