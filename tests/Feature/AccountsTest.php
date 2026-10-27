<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Run against a throwaway database, never the live one:
 *   DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test --filter=AccountsTest
 */
class AccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $managerA;
    private User $managerB;
    private Station $stationA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner    = User::create(['type' => 'user', 'name' => 'Owner', 'email' => 'o@x.in', 'contact' => '9000000000', 'password' => 'secret']);
        $this->stationA = Station::create(['user_id' => $this->owner->id, 'name' => 'Station A']);
        $stationB       = Station::create(['user_id' => $this->owner->id, 'name' => 'Station B']);

        $this->managerA = User::create(['type' => 'sub_user', 'parent_user_id' => $this->owner->id, 'station_id' => $this->stationA->id, 'name' => 'Mgr A', 'email' => 'a@x.in', 'contact' => '9000000001', 'password' => 'secret']);
        $this->managerB = User::create(['type' => 'sub_user', 'parent_user_id' => $this->owner->id, 'station_id' => $stationB->id, 'name' => 'Mgr B', 'email' => 'b@x.in', 'contact' => '9000000002', 'password' => 'secret']);
    }

    public function test_statement_crud_uniqueness_and_shared_flag(): void
    {
        Sanctum::actingAs($this->managerA);

        $payload = ['bank_name' => 'SBI', 'account_no' => '1234', 'statement_month' => '2026-09', 'opening_balance' => 1000, 'total_credits' => 5000, 'total_debits' => 2000, 'closing_balance' => 4000];
        $id = $this->postJson('/api/accounts/statements', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.statement.shared_with_ca', false)
            ->json('data.statement.id');

        $this->postJson('/api/accounts/statements', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'A statement for this bank account and month already exists.');

        $this->putJson("/api/accounts/statements/{$id}", ['shared_with_ca' => true])
            ->assertOk()
            ->assertJsonPath('data.statement.shared_on', now()->toDateString());

        // Re-saving the form keeps the original shared date
        $this->putJson("/api/accounts/statements/{$id}", ['shared_with_ca' => true, 'shared_on' => null, 'notes' => 'x']);
        $this->travel(3)->days();
        $this->putJson("/api/accounts/statements/{$id}", ['shared_with_ca' => true, 'notes' => 'y'])
            ->assertJsonPath('data.statement.shared_on', now()->subDays(3)->toDateString());

        $this->putJson("/api/accounts/statements/{$id}", ['shared_with_ca' => false])
            ->assertJsonPath('data.statement.shared_on', null);

        // Another manager can't touch it
        Sanctum::actingAs($this->managerB);
        $this->putJson("/api/accounts/statements/{$id}", ['notes' => 'x'])->assertStatus(404);
        $this->deleteJson("/api/accounts/statements/{$id}")->assertStatus(404);

        Sanctum::actingAs($this->managerA);
        $this->deleteJson("/api/accounts/statements/{$id}")->assertOk();
    }

    public function test_charges_and_taxes_validate_and_summarise_by_period(): void
    {
        Sanctum::actingAs($this->managerA);

        $this->postJson('/api/accounts/charges', ['date' => '2026-09-10', 'bank_name' => 'SBI', 'charge_type' => 'Bogus', 'amount' => 10])
            ->assertStatus(422);

        $this->postJson('/api/accounts/charges', ['date' => '2026-09-10', 'bank_name' => 'SBI', 'charge_type' => 'SMS Charges', 'amount' => 100, 'gst_amount' => 18])->assertStatus(201);
        $this->postJson('/api/accounts/charges', ['date' => '2026-03-31', 'bank_name' => 'SBI', 'charge_type' => 'SMS Charges', 'amount' => 50])->assertStatus(201);

        $this->postJson('/api/accounts/taxes', ['date' => '2026-10-20', 'tax_type' => 'GST', 'period' => 'Sep 2026', 'amount' => 1000, 'interest' => 20, 'penalty' => 5])->assertStatus(201);
        $this->postJson('/api/accounts/taxes', ['date' => '2026-06-15', 'tax_type' => 'Advance Tax', 'amount' => 5000])->assertStatus(201);

        // FY 2026-27 excludes the 31 Mar 2026 charge
        $range = 'from=2026-04-01&to=2027-03-31';
        $this->getJson("/api/accounts/summary?{$range}")
            ->assertOk()
            ->assertJsonPath('data.summary.charges.count', 1)
            ->assertJsonPath('data.summary.charges.total', 118)
            ->assertJsonPath('data.summary.taxes.total', 6025)
            ->assertJsonPath('data.summary.taxes.by_type.0.type', 'Advance Tax');

        $this->getJson("/api/accounts/taxes?{$range}&tax_type=GST")->assertJsonCount(1, 'data.taxes');
        $this->getJson('/api/accounts/options')->assertJsonPath('data.banks', ['SBI']);
    }

    public function test_owner_reads_all_or_one_station_but_cannot_write(): void
    {
        Sanctum::actingAs($this->managerA);
        $this->postJson('/api/accounts/taxes', ['date' => '2026-10-01', 'tax_type' => 'TDS', 'amount' => 100])->assertStatus(201);
        Sanctum::actingAs($this->managerB);
        $this->postJson('/api/accounts/taxes', ['date' => '2026-10-01', 'tax_type' => 'TDS', 'amount' => 200])->assertStatus(201);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/accounts/taxes')->assertJsonCount(2, 'data.taxes');
        $this->getJson("/api/accounts/taxes?station_id={$this->stationA->id}")->assertJsonCount(1, 'data.taxes');
        $this->postJson('/api/accounts/taxes', ['date' => '2026-10-01', 'tax_type' => 'TDS', 'amount' => 1])->assertStatus(403);

        // Managers see only their own
        Sanctum::actingAs($this->managerB);
        $this->getJson('/api/accounts/taxes')->assertJsonCount(1, 'data.taxes')->assertJsonPath('data.taxes.0.amount', 200);
    }
}
