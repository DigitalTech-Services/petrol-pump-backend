<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_attendance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->unsignedBigInteger('user_id');

            $table->date('date');
            $table->enum('status', ['present', 'absent'])->default('present');
            $table->time('in_time')->nullable();
            $table->time('out_time')->nullable();
            $table->decimal('total_hours', 4, 1)->nullable()->default(0);
            $table->string('notes')->nullable();

            // One record per staff per date
            $table->unique(['staff_id', 'date']);

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

            $table->foreign('staff_id')->references('id')->on('staff')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_attendance');
    }
};
