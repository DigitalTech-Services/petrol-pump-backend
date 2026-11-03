<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Bank entries are now single transactions (one credit or one debit) instead of
    // one monthly summary per account. statement_month is kept and derived from the
    // date so period filters keep working; older summary rows have null date/type.
    public function up(): void
    {
        Schema::table('bank_statements', function (Blueprint $table) {
            $table->date('date')->nullable()->after('account_no');
            $table->string('type', 10)->nullable()->after('date');   // credit | debit
        });
    }

    public function down(): void
    {
        Schema::table('bank_statements', function (Blueprint $table) {
            $table->dropColumn(['date', 'type']);
        });
    }
};
