<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');

            $table->date('date');
            $table->enum('type', ['PhonePe', 'Card', 'NEFT', 'RTGS'])->default('PhonePe');
            $table->string('bank', 50)->default('BOM');
            $table->decimal('amount', 12, 2);
            $table->string('ref_number', 100)->nullable();
            $table->string('remarks', 255)->nullable();

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
        Schema::dropIfExists('transactions');
    }
};
