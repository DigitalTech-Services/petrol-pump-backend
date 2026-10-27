<?php

namespace App\Http\Controllers;

use App\Models\BankCharge;
use App\Models\BankStatement;
use App\Models\Station;
use App\Models\TaxPayment;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Accounts / CA desk — monthly bank statements, bank charges and tax payments,
 * kept together so they can be handed over to the CA for a period.
 *
 * Writes are manager-only (role:sub_user); reads are owner + manager. A manager
 * sees their own station; an owner sees every station combined, or one station
 * via ?station_id= — same pattern as CreditController::scope().
 *
 * Every list endpoint accepts ?from=YYYY-MM-DD&to=YYYY-MM-DD (e.g. a financial year).
 */
class AccountsController extends Controller
{
    use ApiResponse;

    // Write routes — each manager's books are their own.
    private function owner(Request $request): User
    {
        return $request->user();
    }

    // Read routes (role:user,sub_user)
    private function scope(Request $request): array
    {
        $stationId = $request->query('station_id');

        if ($stationId !== null && $stationId !== '' && $request->user()->type === 'user') {
            $owns = Station::where('id', (int) $stationId)
                ->where('user_id', $request->user()->id)
                ->exists();

            if ($owns) {
                return ['station_id', [(int) $stationId]];
            }
        }

        return ['user_id', $request->user()->scopeUserIds()];
    }

    private function validationError(ValidationException $e): JsonResponse
    {
        return $this->error(collect($e->errors())->flatten()->first() ?? 'Invalid data.', 422, ['errors' => $e->errors()]);
    }

    // Applies ?from/&to to a dated table
    private function dateRange(Builder $query, Request $request, string $column = 'date'): Builder
    {
        if ($from = $request->query('from')) {
            $query->whereDate($column, '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate($column, '<=', $to);
        }

        return $query;
    }

    // Statements are keyed by month (YYYY-MM), which compares correctly as a string
    private function monthRange(Builder $query, Request $request): Builder
    {
        if ($from = $request->query('from')) {
            $query->where('statement_month', '>=', substr($from, 0, 7));
        }
        if ($to = $request->query('to')) {
            $query->where('statement_month', '<=', substr($to, 0, 7));
        }

        return $query;
    }

    // GET /accounts/options — dropdown values for the forms
    public function options(Request $request): JsonResponse
    {
        [$col, $ids] = $this->scope($request);

        // Bank names already used, so the forms can suggest them
        $banks = BankStatement::whereIn($col, $ids)->pluck('bank_name')
            ->merge(BankCharge::whereIn($col, $ids)->pluck('bank_name'))
            ->unique()->sort()->values();

        return $this->success('Options fetched.', [
            'charge_types'  => BankCharge::CHARGE_TYPES,
            'tax_types'     => TaxPayment::TAX_TYPES,
            'payment_modes' => TaxPayment::PAYMENT_MODES,
            'banks'         => $banks,
        ]);
    }

    // GET /accounts/summary?from=&to=
    public function summary(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            $statements = $this->monthRange(BankStatement::whereIn($col, $ids), $request)->get();
            $charges    = $this->dateRange(BankCharge::whereIn($col, $ids), $request)->get();
            $taxes      = $this->dateRange(TaxPayment::whereIn($col, $ids), $request)->get();

            $chargesByType = $charges->groupBy('charge_type')
                ->map(fn($g, $type) => [
                    'type'   => $type,
                    'count'  => $g->count(),
                    'amount' => round($g->sum('amount'), 2),
                    'gst'    => round($g->sum('gst_amount'), 2),
                ])->sortByDesc('amount')->values();

            $taxByType = $taxes->groupBy('tax_type')
                ->map(fn($g, $type) => [
                    'type'     => $type,
                    'count'    => $g->count(),
                    'amount'   => round($g->sum('amount'), 2),
                    'interest' => round($g->sum('interest'), 2),
                    'penalty'  => round($g->sum('penalty'), 2),
                    'total'    => round($g->sum(fn($t) => $t->amount + $t->interest + $t->penalty), 2),
                ])->sortByDesc('total')->values();

            return $this->success('Summary fetched.', [
                'summary' => [
                    'statements' => [
                        'count'         => $statements->count(),
                        'shared'        => $statements->where('shared_with_ca', true)->count(),
                        'pending'       => $statements->where('shared_with_ca', false)->count(),
                        'total_credits' => round($statements->sum('total_credits'), 2),
                        'total_debits'  => round($statements->sum('total_debits'), 2),
                    ],
                    'charges' => [
                        'count'   => $charges->count(),
                        'amount'  => round($charges->sum('amount'), 2),
                        'gst'     => round($charges->sum('gst_amount'), 2),
                        'total'   => round($charges->sum('amount') + $charges->sum('gst_amount'), 2),
                        'by_type' => $chargesByType,
                    ],
                    'taxes' => [
                        'count'    => $taxes->count(),
                        'amount'   => round($taxes->sum('amount'), 2),
                        'interest' => round($taxes->sum('interest'), 2),
                        'penalty'  => round($taxes->sum('penalty'), 2),
                        'total'    => round($taxes->sum(fn($t) => $t->amount + $t->interest + $t->penalty), 2),
                        'by_type'  => $taxByType,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return $this->exceptionError($e);
        }
    }

    // =================================================================
    // Bank statements
    // =================================================================

    // GET /accounts/statements?from=&to=&bank=
    public function statements(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            $query = $this->monthRange(BankStatement::with('station:id,name')->whereIn($col, $ids), $request)
                ->orderBy('statement_month', 'desc')->orderBy('bank_name');

            if ($bank = $request->query('bank')) {
                $query->where('bank_name', $bank);
            }

            return $this->success('Statements fetched.', ['statements' => $query->get()]);
        } catch (\Exception $e) {
            return $this->exceptionError($e);
        }
    }

    private function statementRules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'bank_name'       => "$req|string|max:100",
            'account_no'      => 'sometimes|nullable|string|max:30',
            'statement_month' => "$req|date_format:Y-m",
            'opening_balance' => 'sometimes|numeric',
            'total_credits'   => 'sometimes|numeric|min:0',
            'total_debits'    => 'sometimes|numeric|min:0',
            'closing_balance' => 'sometimes|numeric',
            'statement_url'   => 'sometimes|nullable|url|max:500',
            'shared_with_ca'  => 'sometimes|boolean',
            'shared_on'       => 'sometimes|nullable|date',
            'notes'           => 'sometimes|nullable|string',
        ];
    }

    // One statement per bank account per month
    private function assertUniqueStatement(User $owner, array $data, ?int $ignoreId = null): void
    {
        $exists = BankStatement::where('user_id', $owner->id)
            ->where('bank_name', $data['bank_name'])
            ->where('account_no', $data['account_no'] ?? null)
            ->where('statement_month', $data['statement_month'])
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'statement_month' => 'A statement for this bank account and month already exists.',
            ]);
        }
    }

    // Marking as shared stamps today's date unless one was given; re-saving an
    // already-shared statement keeps its original date.
    private function withSharedDate(array $data, ?BankStatement $existing = null): array
    {
        if (array_key_exists('shared_with_ca', $data)) {
            $data['shared_on'] = $data['shared_with_ca']
                ? ($data['shared_on'] ?? $existing?->shared_on?->toDateString() ?? now()->toDateString())
                : null;
        }

        return $data;
    }

    // POST /accounts/statements
    public function storeStatement(Request $request): JsonResponse
    {
        try {
            $owner = $this->owner($request);
            $data  = $request->validate($this->statementRules(false));
            $this->assertUniqueStatement($owner, $data);

            $statement = BankStatement::create(array_merge(
                $this->withSharedDate($data),
                ['user_id' => $owner->id, 'station_id' => $owner->station_id]
            ));

            return $this->success('Statement added.', ['statement' => $statement->fresh()], 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (\Exception $e) {
            return $this->exceptionError($e);
        }
    }

    // PUT /accounts/statements/{id}
    public function updateStatement(Request $request, int $id): JsonResponse
    {
        try {
            $owner     = $this->owner($request);
            $statement = BankStatement::where('user_id', $owner->id)->findOrFail($id);
            $data      = $request->validate($this->statementRules(true));

            $this->assertUniqueStatement($owner, array_merge($statement->only(['bank_name', 'account_no', 'statement_month']), $data), $id);

            $statement->update($this->withSharedDate($data, $statement));

            return $this->success('Statement updated.', ['statement' => $statement->fresh()]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('Statement not found.', 404);
        } catch (\Exception $e) {
            return $this->exceptionError($e);
        }
    }

    // DELETE /accounts/statements/{id}
    public function destroyStatement(Request $request, int $id): JsonResponse
    {
        try {
            BankStatement::where('user_id', $this->owner($request)->id)->findOrFail($id)->delete();

            return $this->success('Statement deleted.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('Statement not found.', 404);
        } catch (\Exception $e) {
            return $this->exceptionError($e);
        }
    }

    // =================================================================
    // Bank charges
    // =================================================================

    // GET /accounts/charges?from=&to=&charge_type=&bank=
    public function charges(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            $query = $this->dateRange(BankCharge::with('station:id,name')->whereIn($col, $ids), $request)
                ->orderBy('date', 'desc')->orderBy('id', 'desc');

            if ($type = $request->query('charge_type')) {
                $query->where('charge_type', $type);
            }
            if ($bank = $request->query('bank')) {
                $query->where('bank_name', $bank);
            }

            return $this->success('Charges fetched.', ['charges' => $query->get()]);
        } catch (\Exception $e) {
            return $this->exceptionError($e);
        }
    }

    private function chargeRules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'date'         => "$req|date",
            'bank_name'    => "$req|string|max:100",
            'account_no'   => 'sometimes|nullable|string|max:30',
            'charge_type'  => ['sometimes', 'string', Rule::in(BankCharge::CHARGE_TYPES)],
            'amount'       => "$req|numeric|min:0",
            'gst_amount'   => 'sometimes|numeric|min:0',
            'reference_no' => 'sometimes|nullable|string|max:100',
            'narration'    => 'sometimes|nullable|string',
        ];
    }

    // POST /accounts/charges
    public function storeCharge(Request $request): JsonResponse
    {
        try {
            $owner  = $this->owner($request);
            $data   = $request->validate($this->chargeRules(false));
            $charge = BankCharge::create(array_merge($data, ['user_id' => $owner->id, 'station_id' => $owner->station_id]));

            return $this->success('Bank charge added.', ['charge' => $charge->fresh()], 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (\Exception $e) {
            return $this->exceptionError($e);
        }
    }

    // PUT /accounts/charges/{id}
    public function updateCharge(Request $request, int $id): JsonResponse
    {
        try {
            $charge = BankCharge::where('user_id', $this->owner($request)->id)->findOrFail($id);
            $charge->update($request->validate($this->chargeRules(true)));

            return $this->success('Bank charge updated.', ['charge' => $charge->fresh()]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('Bank charge not found.', 404);
        } catch (\Exception $e) {
            return $this->exceptionError($e);
        }
    }

    // DELETE /accounts/charges/{id}
    public function destroyCharge(Request $request, int $id): JsonResponse
    {
        try {
            BankCharge::where('user_id', $this->owner($request)->id)->findOrFail($id)->delete();

            return $this->success('Bank charge deleted.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('Bank charge not found.', 404);
        } catch (\Exception $e) {
            return $this->exceptionError($e);
        }
    }

    // =================================================================
    // Tax payments
    // =================================================================

    // GET /accounts/taxes?from=&to=&tax_type=
    public function taxes(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            $query = $this->dateRange(TaxPayment::with('station:id,name')->whereIn($col, $ids), $request)
                ->orderBy('date', 'desc')->orderBy('id', 'desc');

            if ($type = $request->query('tax_type')) {
                $query->where('tax_type', $type);
            }

            return $this->success('Tax payments fetched.', ['taxes' => $query->get()]);
        } catch (\Exception $e) {
            return $this->exceptionError($e);
        }
    }

    private function taxRules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'date'         => "$req|date",
            'tax_type'     => [$req, 'string', Rule::in(TaxPayment::TAX_TYPES)],
            'period'       => 'sometimes|nullable|string|max:50',
            'challan_no'   => 'sometimes|nullable|string|max:100',
            'amount'       => "$req|numeric|min:0",
            'interest'     => 'sometimes|numeric|min:0',
            'penalty'      => 'sometimes|numeric|min:0',
            'payment_mode' => ['sometimes', 'nullable', 'string', Rule::in(TaxPayment::PAYMENT_MODES)],
            'narration'    => 'sometimes|nullable|string',
        ];
    }

    // POST /accounts/taxes
    public function storeTax(Request $request): JsonResponse
    {
        try {
            $owner = $this->owner($request);
            $data  = $request->validate($this->taxRules(false));
            $tax   = TaxPayment::create(array_merge($data, ['user_id' => $owner->id, 'station_id' => $owner->station_id]));

            return $this->success('Tax payment added.', ['tax' => $tax->fresh()], 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (\Exception $e) {
            return $this->exceptionError($e);
        }
    }

    // PUT /accounts/taxes/{id}
    public function updateTax(Request $request, int $id): JsonResponse
    {
        try {
            $tax = TaxPayment::where('user_id', $this->owner($request)->id)->findOrFail($id);
            $tax->update($request->validate($this->taxRules(true)));

            return $this->success('Tax payment updated.', ['tax' => $tax->fresh()]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('Tax payment not found.', 404);
        } catch (\Exception $e) {
            return $this->exceptionError($e);
        }
    }

    // DELETE /accounts/taxes/{id}
    public function destroyTax(Request $request, int $id): JsonResponse
    {
        try {
            TaxPayment::where('user_id', $this->owner($request)->id)->findOrFail($id)->delete();

            return $this->success('Tax payment deleted.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('Tax payment not found.', 404);
        } catch (\Exception $e) {
            return $this->exceptionError($e);
        }
    }
}
