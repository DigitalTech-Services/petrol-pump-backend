<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The Daily Sales Summary is now delivered by email to the owner (was WhatsApp).
// Saved preferences store their own copy of the description, so refresh it.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notification_preferences')
            ->where('notif_key', 'daily')
            ->update(['sub' => "Email the owner a summary on each day's sales entry"]);
    }

    public function down(): void
    {
        DB::table('notification_preferences')
            ->where('notif_key', 'daily')
            ->update(['sub' => 'Get end-of-day summary via WhatsApp']);
    }
};
