<?php

namespace Tests\Feature;

use App\Mail\StockVariationAlertMail;
use App\Models\NotificationPreference;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Run against a throwaway database, never the live one:
 *   DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=petrol_pump_stock_test DB_USERNAME=root DB_PASSWORD= php artisan test --filter=StockLastClosingTest
 */
class StockLastClosingTest extends TestCase
{
    use RefreshDatabase;

    public function test_last_closing_comes_from_latest_earlier_entry_of_same_fuel(): void
    {
        $owner   = User::create(['type' => 'user', 'name' => 'Owner', 'email' => 'o@x.in', 'contact' => '9000000000', 'password' => 'secret']);
        $station = Station::create(['user_id' => $owner->id, 'name' => 'Station A']);
        $manager = User::create(['type' => 'sub_user', 'parent_user_id' => $owner->id, 'station_id' => $station->id, 'name' => 'Mgr', 'email' => 'a@x.in', 'contact' => '9000000001', 'password' => 'secret']);

        Sanctum::actingAs($manager);
        $add = fn($date, $fuel, $close) => $this->postJson('/api/stock', ['date' => $date, 'fuel_type' => $fuel, 'opening' => 0, 'closing' => $close])->assertStatus(201);
        $add('2026-09-29', 'MS', 1000);
        $add('2026-09-30', 'MS', 1500);   // last day of previous month
        $add('2026-09-30', 'HSD', 9999);  // other fuel — ignored
        $add('2026-10-05', 'MS', 2000);   // later — ignored for 1 Oct

        $this->getJson('/api/stock/last-closing?fuel_type=MS&before=2026-10-01')
            ->assertOk()
            ->assertJsonPath('data.date', '2026-09-30')
            ->assertJsonPath('data.closing', 1500);

        $this->getJson('/api/stock/last-closing?fuel_type=Speed&before=2026-10-01')
            ->assertJsonPath('data.closing', null);
    }

    public function test_variation_above_threshold_flags_entry_and_emails_owner(): void
    {
        Mail::fake();

        $owner   = User::create(['type' => 'user', 'name' => 'Owner', 'email' => 'o@x.in', 'contact' => '9000000000', 'password' => 'secret']);
        $station = Station::create(['user_id' => $owner->id, 'name' => 'Station A']);
        $manager = User::create(['type' => 'sub_user', 'parent_user_id' => $owner->id, 'station_id' => $station->id, 'name' => 'Mgr', 'email' => 'a@x.in', 'contact' => '9000000001', 'password' => 'secret']);
        Sanctum::actingAs($manager);

        // Sale 10000 L, stock moved 10005 L → variation −5 L = 0.05% → within limit
        $this->postJson('/api/stock', ['date' => '2026-10-01', 'fuel_type' => 'MS', 'opening' => 20000, 'closing' => 9995, 'actual_sale' => 10000])
            ->assertStatus(201)
            ->assertJsonPath('data.entry.variation_pct', 0.05)
            ->assertJsonPath('data.entry.variation_alert', false);
        Mail::assertNothingSent();

        // Stock moved 10010 L → −10 L = 0.1% → alert + email to owner
        $id = $this->postJson('/api/stock', ['date' => '2026-10-02', 'fuel_type' => 'MS', 'opening' => 20000, 'closing' => 9990, 'actual_sale' => 10000])
            ->assertStatus(201)
            ->assertJsonPath('data.entry.variation_alert', true)
            ->json('data.entry.id');
        Mail::assertSent(StockVariationAlertMail::class, fn($m) => $m->hasTo('o@x.in') && $m->entry['variation'] == -10);

        // Re-saving without changing the figures doesn't email again
        $this->putJson("/api/stock/{$id}", ['remarks' => 'checked'])->assertOk()->assertJsonPath('data.entry.variation_alert', true);
        Mail::assertSentCount(1);

        // No sale figure → no % check
        $this->postJson('/api/stock', ['date' => '2026-10-03', 'fuel_type' => 'MS', 'opening' => 20000, 'closing' => 100])
            ->assertJsonPath('data.entry.variation_pct', null)
            ->assertJsonPath('data.entry.variation_alert', false);

        // Manager turned the alert off → flagged but not emailed
        NotificationPreference::create(['user_id' => $manager->id, 'notif_key' => 'stock_variation', 'label' => 'x', 'sub' => '', 'enabled' => false]);
        $this->postJson('/api/stock', ['date' => '2026-10-04', 'fuel_type' => 'MS', 'opening' => 20000, 'closing' => 9000, 'actual_sale' => 10000])
            ->assertJsonPath('data.entry.variation_alert', true);
        Mail::assertSentCount(1);
    }
}
