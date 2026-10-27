<?php

namespace App\Services;

use App\Mail\StockVariationAlertMail;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the owner when a manager's stock entry shows a variation above the
 * allowed % of the day's sale — honouring the manager's "Stock Variation Alert"
 * preference (Settings → Notifications, notif_key = 'stock_variation').
 */
class StockVariationNotifier
{
    /** @param array $row A presented stock entry (StockController::present) */
    public function send(array $row, User $manager, float $thresholdPct): void
    {
        try {
            if (!$this->alertEnabled($manager)) return;

            $owner = $manager->type === 'sub_user' ? $manager->parent : $manager;
            if (!$owner || !filter_var($owner->email, FILTER_VALIDATE_EMAIL)) {
                Log::warning('Stock variation alert skipped: owner has no valid email', ['manager_id' => $manager->id]);
                return;
            }

            Mail::to($owner->email, $owner->name)->send(new StockVariationAlertMail(
                entry:        $row,
                stationName:  $manager->station?->name ?? $owner->resolveBusinessName() ?? 'Your station',
                managerName:  $manager->name,
                ownerName:    $owner->name,
                thresholdPct: $thresholdPct,
            ));
        } catch (\Throwable $e) {
            // Never let a mail problem affect the stock entry itself — just record it
            Log::error('Stock variation alert email failed: ' . $e->getMessage(), [
                'stock_entry_id' => $row['id'] ?? null,
                'manager_id'     => $manager->id,
            ]);
        }
    }

    // No saved preference yet means the default — on
    private function alertEnabled(User $manager): bool
    {
        $pref = NotificationPreference::where('user_id', $manager->id)
            ->where('notif_key', 'stock_variation')
            ->first();

        return $pref ? (bool) $pref->enabled : true;
    }
}
