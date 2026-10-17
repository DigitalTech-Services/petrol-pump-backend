<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');               // manager account that owns the customer
            $table->unsignedBigInteger('station_id')->nullable();

            $table->string('name');
            $table->string('mobile', 15);
            $table->string('vehicle_number', 30)->nullable();
            $table->string('address')->nullable();
            $table->decimal('credit_limit', 12, 2)->nullable();
            $table->text('notes')->nullable();

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

            // One customer per mobile number within a station's account
            $table->unique(['user_id', 'mobile']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('station_id')->references('id')->on('stations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_customers');
    }
};
