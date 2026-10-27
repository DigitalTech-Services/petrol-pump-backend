<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_charges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('station_id')->nullable();

            $table->date('date');
            $table->string('bank_name', 100);
            $table->string('account_no', 30)->nullable();
            $table->string('charge_type', 50)->default('Other');
            $table->decimal('amount', 12, 2);
            $table->decimal('gst_amount', 12, 2)->default(0);     // GST levied on the charge
            $table->string('reference_no', 100)->nullable();
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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_charges');
    }
};
