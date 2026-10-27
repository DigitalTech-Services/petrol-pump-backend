<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('station_id')->nullable();

            $table->date('date');                                 // payment date
            $table->string('tax_type', 30);
            $table->string('period', 50)->nullable();              // e.g. Sep 2026, Q2 FY 2026-27
            $table->string('challan_no', 100)->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('interest', 12, 2)->default(0);
            $table->decimal('penalty', 12, 2)->default(0);
            $table->string('payment_mode', 30)->nullable();
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
            $table->foreign('station_id')->references('id')->on('stations')->nullOnDelete();
            $table->index(['user_id', 'date']);
            $table->index(['station_id', 'date']);
            $table->index(['user_id', 'tax_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_payments');
    }
};
