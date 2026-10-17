<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Run against a throwaway database, never the live one:
 *   DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=petrol_pump_credit_test DB_USERNAME=root DB_PASSWORD= php artisan test --filter=CreditTest
 */
class CreditTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $managerA;
    private User $managerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['type' => 'user', 'name' => 'Owner', 'email' => 'o@x.in', 'contact' => '9000000000', 'password' => 'secret']);
        $stationA = Station::create(['user_id' => $this->owner->id, 'name' => 'Station A']);
        $stationB = Station::create(['user_id' => $this->owner->id, 'name' => 'Station B']);

        $this->managerA = User::create(['type' => 'sub_user', 'parent_user_id' => $this->owner->id, 'station_id' => $stationA->id, 'name' => 'Mgr A', 'email' => 'a@x.in', 'contact' => '9000000001', 'password' => 'secret']);
        $this->managerB = User::create(['type' => 'sub_user', 'parent_user_id' => $this->owner->id, 'station_id' => $stationB->id, 'name' => 'Mgr B', 'email' => 'b@x.in', 'contact' => '9000000002', 'password' => 'secret']);
    }

    private function addCustomer(User $manager, string $mobile = '9876543210', string $name = 'Sharma Transport'): int
    {
        Sanctum::actingAs($manager);
        return $this->postJson('/api/credit/customers', ['name' => $name, 'mobile' => $mobile])
            ->assertStatus(201)->json('data.customer.id');
    }

    private function addEntry(User $manager, int $customerId, float $litres, float $rate): int
    {
        Sanctum::actingAs($manager);
        return $this->postJson('/api/credit/entries', [
            'credit_customer_id' => $customerId, 'date' => now()->toDateString(),
            'fuel_type' => 'hsd', 'litres' => $litres, 'rate' => $rate,
        ])->assertStatus(201)->json('data.entry.id');
    }

    public function test_mobile_number_must_be_unique_and_valid(): void
    {
        $this->addCustomer($this->managerA, '9876543210');

        $this->postJson('/api/credit/customers', ['name' => 'Dup', 'mobile' => '98765 43210'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A credit customer with this mobile number already exists.');

        $this->postJson('/api/credit/customers', ['name' => 'Bad', 'mobile' => '12345'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Enter a valid 10-digit mobile number.');
    }

    public function test_entry_amount_is_computed_and_history_tracks_balance(): void
    {
        $customer = $this->addCustomer($this->managerA);
        $first    = $this->addEntry($this->managerA, $customer, 50, 90.5);   // 4525
        $this->addEntry($this->managerA, $customer, 10, 100);                // 1000

        $this->getJson("/api/credit/customers/{$customer}")
            ->assertOk()
            ->assertJsonPath('data.customer.outstanding', 5525)
            ->assertJsonCount(2, 'data.entries');

        $this->postJson("/api/credit/entries/{$first}/pay", ['payment_mode' => 'Cash'])->assertOk();

        $this->getJson("/api/credit/customers/{$customer}")
            ->assertJsonPath('data.customer.outstanding', 1000)
            ->assertJsonPath('data.customer.total_paid', 4525);

        // Paid entries are locked; unpaid customers can't be deleted
        $this->deleteJson("/api/credit/entries/{$first}")->assertStatus(422);
        $this->deleteJson("/api/credit/customers/{$customer}")->assertStatus(422);

        $this->postJson("/api/credit/customers/{$customer}/pay-all", ['payment_mode' => 'PhonePe'])
            ->assertOk()->assertJsonPath('data.paid_count', 1);

        $this->getJson("/api/credit/customers/{$customer}")->assertJsonPath('data.customer.outstanding', 0);
        $this->deleteJson("/api/credit/customers/{$customer}")->assertOk();
    }

    public function test_owner_sees_all_stations_and_manager_only_their_own(): void
    {
        $this->addEntry($this->managerA, $this->addCustomer($this->managerA), 10, 100);   // 1000 at A
        $this->addEntry($this->managerB, $this->addCustomer($this->managerB), 20, 100);   // 2000 at B

        Sanctum::actingAs($this->managerA);
        $this->getJson('/api/credit/summary')->assertOk()
            ->assertJsonPath('data.summary.outstanding', 1000)
            ->assertJsonCount(0, 'data.summary.by_station');

        Sanctum::actingAs($this->owner);
        $res = $this->getJson('/api/credit/summary')->assertOk()
            ->assertJsonPath('data.summary.outstanding', 3000)
            ->assertJsonPath('data.summary.customers_with_dues', 2)
            ->assertJsonCount(2, 'data.summary.by_station');
        $this->assertSame('Station B', $res->json('data.summary.by_station.0.station_name'));

        // Owner is read-only
        $this->postJson('/api/credit/customers', ['name' => 'X', 'mobile' => '9123456789'])->assertStatus(403);
    }

    public function test_manager_cannot_touch_another_stations_customer(): void
    {
        $customer = $this->addCustomer($this->managerA);

        Sanctum::actingAs($this->managerB);
        $this->getJson("/api/credit/customers/{$customer}")->assertStatus(404);
        $this->postJson('/api/credit/entries', [
            'credit_customer_id' => $customer, 'date' => now()->toDateString(),
            'fuel_type' => 'ms', 'litres' => 5, 'rate' => 100,
        ])->assertStatus(422);
    }
}
