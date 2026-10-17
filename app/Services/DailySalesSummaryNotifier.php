<?php

namespace App\Services;

use App\Mail\DailySalesSummaryMail;
use App\Models\NotificationPreference;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the owner a summary of the day's sales when a manager records a
 * sales entry — honouring the manager's "Daily Sales Summary" preference
 * (Settings → Notifications, notif_key = 'daily').
 */
class DailySalesSummaryNotifier
{
    public function send(Sale $sale, User $manager): void
    {
        try {
            if (!$this->dailySummaryEnabled($manager)) return;

            // Sales are entered by managers; the summary goes to the owner who
            // created them. An owner entering their own sale gets it themselves.
            $owner = $manager->type === 'sub_user' ? $manager->parent : $manager;
            if (!$owner || !filter_var($owner->email, FILTER_VALIDATE_EMAIL)) {
                Log::warning('Daily sales summary skipped: owner has no valid email', ['manager_id' => $manager->id]);
                return;
            }

            $date = $sale->date->toDateString();

            // Every entry this manager made for the day (e.g. multiple shifts), combined
            $sales = Sale::where('user_id', $manager->id)
                ->whereDate('date', $date)
                ->orderBy('id')
                ->get();

            Mail::to($owner->email, $owner->name)->send(new DailySalesSummaryMail(
                date:        $sale->date,
                stationName: $manager->station?->name ?? $owner->resolveBusinessName() ?? 'Your station',
                managerName: $manager->name,
                ownerName:   $owner->name,
                sales:       $sales,
            ));
        } catch (\Throwable $e) {
            // Never let a mail problem affect the sale itself — just record it
            Log::error('Daily sales summary email failed: ' . $e->getMessage(), [
                'sale_id'    => $sale->id,
                'manager_id' => $manager->id,
            ]);
        }
    }

    // No saved preference yet means the default — 'daily' is on by default
    private function dailySummaryEnabled(User $manager): bool
    {
        $pref = NotificationPreference::where('user_id', $manager->id)
            ->where('notif_key', 'daily')
            ->first();

        return $pref ? (bool) $pref->enabled : true;
    }
}
