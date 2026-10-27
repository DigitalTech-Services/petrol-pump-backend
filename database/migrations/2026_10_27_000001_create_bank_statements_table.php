<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('station_id')->nullable();

            // One row per bank account per month — the summary a CA asks for.
            $table->string('bank_name', 100);
            $table->string('account_no', 30)->nullable();
            $table->string('statement_month', 7);                 // YYYY-MM
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('total_credits', 14, 2)->default(0);
            $table->decimal('total_debits', 14, 2)->default(0);
            $table->decimal('closing_balance', 14, 2)->default(0);
            $table->string('statement_url', 500)->nullable();      // link to PDF (Drive, etc.)
            $table->boolean('shared_with_ca')->default(false);
            $table->date('shared_on')->nullable();
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

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('station_id')->references('id')->on('stations')->nullOnDelete();
            $table->index(['user_id', 'statement_month']);
            $table->index(['station_id', 'statement_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statements');
    }
};
