<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Run against a throwaway database, never the live one:
 *   DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=petrol_pump_dash_test DB_USERNAME=root DB_PASSWORD= php artisan test --filter=DashboardBankChargesTest
 */
class DashboardBankChargesTest extends TestCase
{
    use RefreshDatabase;

    public function test_actual_profit_subtracts_bank_charges_with_gst_for_the_month(): void
    {
        $owner   = User::create(['type' => 'user', 'name' => 'Owner', 'email' => 'o@x.in', 'contact' => '9000000000', 'password' => 'secret']);
        $station = Station::create(['user_id' => $owner->id, 'name' => 'Station A']);
        $manager = User::create(['type' => 'sub_user', 'parent_user_id' => $owner->id, 'station_id' => $station->id, 'name' => 'Mgr', 'email' => 'a@x.in', 'contact' => '9000000001', 'password' => 'secret']);

        Sanctum::actingAs($manager);
        $this->postJson('/api/accounts/charges', ['date' => '2026-10-05', 'bank_name' => 'SBI', 'charge_type' => 'POS / MDR Charges', 'amount' => 1000, 'gst_amount' => 180])->assertStatus(201);
        $this->postJson('/api/accounts/charges', ['date' => '2026-09-30', 'bank_name' => 'SBI', 'amount' => 500])->assertStatus(201); // other month

        $before = $this->getJson('/api/dashboard/summary?period=2026-10')->assertOk()->json('data.actual_profit');
        $this->assertEquals(1180, $before['bank_charges']);
        $this->assertEquals(round($before['fuel_margin'] - $before['expenses'] - $before['staff_payroll'] - 1180, 2), $before['total']);

        // Owner sees the same figure for that station
        Sanctum::actingAs($owner);
        $this->getJson("/api/dashboard/summary?period=2026-10&station_id={$station->id}")
            ->assertJsonPath('data.actual_profit.bank_charges', 1180);
    }
}
