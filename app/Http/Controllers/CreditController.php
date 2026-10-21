<?php

namespace App\Http\Controllers;

use App\Models\CreditCustomer;
use App\Models\CreditEntry;
use App\Models\Station;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Fuel given on credit — credit customers (unique mobile per station account),
 * their credit entries, and settling them ("mark as paid").
 *
 * Writes are manager-only (role:sub_user); reads are owner + manager. A manager
 * sees their own station's credit; an owner sees every station combined, or one
 * station via ?station_id= — same pattern as DashboardController::saleScope().
 */
class CreditController extends Controller
{
    use ApiResponse;

    private const MOBILE_RULE = 'regex:/^[6-9]\d{9}$/';

    // Write routes — each manager's credit book is their own.
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

    // Customers with their running credit totals
    private function customersWithTotals()
    {
        return CreditCustomer::query()
            ->withSum('entries as total_credit', 'amount')
            ->withSum(['entries as outstanding' => fn($q) => $q->where('status', 'pending')], 'amount')
            ->withCount(['entries as pending_count' => fn($q) => $q->where('status', 'pending')])
            ->withMax('entries as last_credit_date', 'date');
    }

    private function formatCustomer(CreditCustomer $c): array
    {
        $total       = round((float) ($c->total_credit ?? 0), 2);
        $outstanding = round((float) ($c->outstanding ?? 0), 2);

        return [
            'id'               => $c->id,
            'station_id'       => $c->station_id,
            'station_name'     => $c->station?->name,
            'name'             => $c->name,
            'mobile'           => $c->mobile,
            'vehicle_number'   => $c->vehicle_number,
            'address'          => $c->address,
            'credit_limit'     => $c->credit_limit,
            'notes'            => $c->notes,
            'total_credit'     => $total,
            'total_paid'       => round($total - $outstanding, 2),
            'outstanding'      => $outstanding,
            'pending_count'    => (int) ($c->pending_count ?? 0),
            'last_credit_date' => $c->last_credit_date ? substr((string) $c->last_credit_date, 0, 10) : null,
        ];
    }

    // ─── CUSTOMERS ─────────────────────────────────────────────────────────────

    // GET /credit/customers?search=
    public function customers(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            $query = $this->customersWithTotals()->with('station:id,name')->whereIn($col, $ids)->orderBy('name');

            if ($search = trim((string) $request->query('search'))) {
                $query->where(fn($q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
                    ->orWhere('vehicle_number', 'like', "%{$search}%"));
            }

            $customers = $query->get()->map(fn($c) => $this->formatCustomer($c));

            return $this->success('Credit customers fetched.', ['customers' => $customers]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /credit/customers/{id}?month=YYYY-MM&status=pending|paid — customer + credit history
    public function customerHistory(Request $request, int $id): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            $customer = $this->customersWithTotals()->with('station:id,name')->whereIn($col, $ids)->findOrFail($id);

            $entries = CreditEntry::where('credit_customer_id', $customer->id)
                ->orderByDesc('date')->orderByDesc('id');

            if ($month = $request->query('month')) {
                $entries->whereYear('date', substr($month, 0, 4))->whereMonth('date', substr($month, 5, 2));
            }
            if (in_array($status = $request->query('status'), ['pending', 'paid'], true)) {
                $entries->where('status', $status);
            }

            return $this->success('Credit history fetched.', [
                'customer' => $this->formatCustomer($customer),
                'entries'  => $entries->get(),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('Credit customer not found.', 404);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    private function customerRules(User $owner, ?int $ignoreId = null): array
    {
        $unique = Rule::unique('credit_customers', 'mobile')->where('user_id', $owner->id);
        if ($ignoreId) $unique->ignore($ignoreId);

        return [
            'name'           => 'required|string|max:255',
            'mobile'         => ['required', 'string', self::MOBILE_RULE, $unique],
            'vehicle_number' => 'nullable|string|max:30',
            'address'        => 'nullable|string|max:255',
            'credit_limit'   => 'nullable|numeric|min:0',
            'notes'          => 'nullable|string',
        ];
    }

    private const CUSTOMER_MESSAGES = [
        'mobile.regex'  => 'Enter a valid 10-digit mobile number.',
        'mobile.unique' => 'A credit customer with this mobile number already exists.',
    ];

    // POST /credit/customers
    public function storeCustomer(Request $request): JsonResponse
    {
        try {
            $owner = $this->owner($request);
            $request->merge(['mobile' => preg_replace('/\D/', '', (string) $request->input('mobile'))]);
            $data  = $request->validate($this->customerRules($owner), self::CUSTOMER_MESSAGES);

            $customer = CreditCustomer::create(array_merge($data, [
                'user_id'    => $owner->id,
                'station_id' => $owner->station_id,
            ]));

            $customer = $this->customersWithTotals()->with('station:id,name')->find($customer->id);

            return $this->success('Credit customer added.', ['customer' => $this->formatCustomer($customer)], 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // PUT /credit/customers/{id}
    public function updateCustomer(Request $request, int $id): JsonResponse
    {
        try {
            $owner    = $this->owner($request);
            $customer = CreditCustomer::where('user_id', $owner->id)->findOrFail($id);

            $request->merge(['mobile' => preg_replace('/\D/', '', (string) $request->input('mobile'))]);
            $data = $request->validate($this->customerRules($owner, $customer->id), self::CUSTOMER_MESSAGES);

            $customer->update($data);
            $customer = $this->customersWithTotals()->with('station:id,name')->find($customer->id);

            return $this->success('Credit customer updated.', ['customer' => $this->formatCustomer($customer)]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('Credit customer not found.', 404);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // DELETE /credit/customers/{id} — blocked while dues are outstanding
    public function destroyCustomer(Request $request, int $id): JsonResponse
    {
        try {
            $owner    = $this->owner($request);
            $customer = CreditCustomer::where('user_id', $owner->id)->findOrFail($id);

            if ($customer->entries()->where('status', 'pending')->exists()) {
                return $this->error('This customer still has unpaid credit. Mark it as paid before deleting.', 422);
            }

            $customer->delete();

            return $this->success('Credit customer deleted.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('Credit customer not found.', 404);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ─── CREDIT ENTRIES ────────────────────────────────────────────────────────

    // GET /credit/entries?month=YYYY-MM&status=&customer_id=
    public function entries(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            $query = CreditEntry::whereIn($col, $ids)
                ->with(['customer:id,name,mobile', 'station:id,name'])
                ->orderByDesc('date')->orderByDesc('id');

            if ($month = $request->query('month')) {
                $query->whereYear('date', substr($month, 0, 4))->whereMonth('date', substr($month, 5, 2));
            }
            if (in_array($status = $request->query('status'), ['pending', 'paid'], true)) {
                $query->where('status', $status);
            }
            if ($customerId = $request->query('customer_id')) {
                $query->where('credit_customer_id', (int) $customerId);
            }

            return $this->success('Credit entries fetched.', ['entries' => $query->get()]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    private function entryRules(User $owner): array
    {
        return [
            'credit_customer_id' => ['required', 'integer', Rule::exists('credit_customers', 'id')->where('user_id', $owner->id)],
            'date'               => 'required|date|before_or_equal:today',
            'fuel_type'          => 'required|in:' . implode(',', CreditEntry::FUEL_TYPES),
            'amount'             => 'required|numeric|gt:0',
            'litres'             => 'nullable|numeric|gt:0',
            'rate'               => 'nullable|numeric|gt:0',
            'vehicle_number'     => 'nullable|string|max:30',
            'notes'              => 'nullable|string',
        ];
    }

    private const ENTRY_MESSAGES = [
        'credit_customer_id.exists' => 'Select a valid credit customer.',
        'date.before_or_equal'      => 'Credit date cannot be in the future.',
    ];

    // POST /credit/entries
    public function storeEntry(Request $request): JsonResponse
    {
        try {
            $owner = $this->owner($request);
            $data  = $request->validate($this->entryRules($owner), self::ENTRY_MESSAGES);

            $entry = CreditEntry::create(array_merge($data, [
                'user_id'    => $owner->id,
                'station_id' => $owner->station_id,
                'amount'     => round($data['amount'], 2),
                'status'     => 'pending',
            ]));

            return $this->success('Credit entry added.', ['entry' => $entry->load('customer:id,name,mobile')], 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /credit/total-for-date?date=YYYY-MM-DD — all credit given on one day
    // (paid or not), used to prefill Credit Sale on the New Sale Entry form.
    public function totalForDate(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            $data = $request->validate([
                'date' => 'required|date',
            ]);

            $total = CreditEntry::whereIn($col, $ids)->whereDate('date', $data['date'])->sum('amount');

            return $this->success('Total fetched.', ['total' => round((float) $total, 2)]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // DELETE /credit/entries/{id} — only while unpaid, so settled history stays intact
    public function destroyEntry(Request $request, int $id): JsonResponse
    {
        try {
            $owner = $this->owner($request);
            $entry = CreditEntry::where('user_id', $owner->id)->findOrFail($id);

            if ($entry->status === 'paid') {
                return $this->error('A paid credit entry cannot be deleted.', 422);
            }

            $entry->delete();

            return $this->success('Credit entry deleted.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('Credit entry not found.', 404);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ─── MARK AS PAID ──────────────────────────────────────────────────────────

    private function paymentData(Request $request): array
    {
        $data = $request->validate([
            'paid_on'      => 'nullable|date|before_or_equal:today',
            'payment_mode' => 'required|in:' . implode(',', CreditEntry::PAYMENT_MODES),
            'payment_ref'  => 'nullable|string|max:100',
        ], ['paid_on.before_or_equal' => 'Payment date cannot be in the future.']);

        return [
            'status'       => 'paid',
            'paid_on'      => $data['paid_on'] ?? now()->toDateString(),
            'payment_mode' => $data['payment_mode'],
            'payment_ref'  => $data['payment_ref'] ?? null,
        ];
    }

    // POST /credit/entries/{id}/pay
    public function markPaid(Request $request, int $id): JsonResponse
    {
        try {
            $owner = $this->owner($request);
            $entry = CreditEntry::where('user_id', $owner->id)->findOrFail($id);

            if ($entry->status === 'paid') {
                return $this->error('This credit entry is already paid.', 422);
            }

            $entry->update($this->paymentData($request));

            return $this->success('Credit marked as paid.', ['entry' => $entry->fresh()]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('Credit entry not found.', 404);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // POST /credit/entries/{id}/unpay — undo a payment recorded by mistake
    public function markUnpaid(Request $request, int $id): JsonResponse
    {
        try {
            $owner = $this->owner($request);
            $entry = CreditEntry::where('user_id', $owner->id)->findOrFail($id);

            $entry->update(['status' => 'pending', 'paid_on' => null, 'payment_mode' => null, 'payment_ref' => null]);

            return $this->success('Credit marked as unpaid.', ['entry' => $entry->fresh()]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('Credit entry not found.', 404);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // POST /credit/customers/{id}/pay-all — settle every unpaid entry of a customer
    public function markAllPaid(Request $request, int $id): JsonResponse
    {
        try {
            $owner    = $this->owner($request);
            $customer = CreditCustomer::where('user_id', $owner->id)->findOrFail($id);
            $payment  = $this->paymentData($request);

            $pending = $customer->entries()->where('status', 'pending');
            $count   = (clone $pending)->count();
            $amount  = round((float) (clone $pending)->sum('amount'), 2);

            if (!$count) {
                return $this->error('This customer has no unpaid credit.', 422);
            }

            // Row-by-row so the audit fields (updated_by etc.) are recorded on each entry
            DB::transaction(fn() => $pending->get()->each->update($payment));

            return $this->success("Marked {$count} credit " . ($count === 1 ? 'entry' : 'entries') . ' as paid.', [
                'paid_count'  => $count,
                'paid_amount' => $amount,
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('Credit customer not found.', 404);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ─── DASHBOARD SUMMARY ─────────────────────────────────────────────────────

    // GET /credit/summary?period=YYYY-MM&station_id=
    // Owner: all stations combined + per-station breakdown. Manager: their station.
    public function summary(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            $period = $request->query('period', date('Y-m'));
            [$y, $m] = array_map('intval', explode('-', $period) + [1 => date('m')]);
            $from = sprintf('%04d-%02d-01', $y, $m);
            $to   = date('Y-m-t', strtotime($from));

            $base = fn() => CreditEntry::whereIn($col, $ids);

            $outstanding  = (float) $base()->where('status', 'pending')->sum('amount');
            $pendingCount = $base()->where('status', 'pending')->count();
            $withDues     = $base()->where('status', 'pending')->distinct('credit_customer_id')->count('credit_customer_id');
            $givenMonth   = (float) $base()->whereBetween('date', [$from, $to])->sum('amount');
            $collected    = (float) $base()->where('status', 'paid')->whereBetween('paid_on', [$from, $to])->sum('amount');

            // Per-station breakdown — only meaningful for an owner looking at all stations
            $byStation = [];
            if ($request->user()->type === 'user' && $col === 'user_id') {
                $rows = $base()
                    ->select('station_id')
                    ->selectRaw("SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) AS outstanding")
                    ->selectRaw("COUNT(DISTINCT CASE WHEN status = 'pending' THEN credit_customer_id END) AS customers_with_dues")
                    ->selectRaw('SUM(CASE WHEN date BETWEEN ? AND ? THEN amount ELSE 0 END) AS given_this_month', [$from, $to])
                    ->groupBy('station_id')
                    ->get();

                $names = Station::whereIn('id', $rows->pluck('station_id')->filter())->pluck('name', 'id');

                $byStation = $rows->map(fn($r) => [
                    'station_id'          => $r->station_id,
                    'station_name'        => $names[$r->station_id] ?? 'Unassigned',
                    'outstanding'         => round((float) $r->outstanding, 2),
                    'customers_with_dues' => (int) $r->customers_with_dues,
                    'given_this_month'    => round((float) $r->given_this_month, 2),
                ])->sortByDesc('outstanding')->values();
            }

            // Biggest outstanding balances
            $topDues = $base()
                ->where('status', 'pending')
                ->select('credit_customer_id')
                ->selectRaw('SUM(amount) AS outstanding, MIN(date) AS oldest_date, COUNT(*) AS entries')
                ->groupBy('credit_customer_id')
                ->orderByDesc('outstanding')
                ->limit(5)
                ->get();

            $customers = CreditCustomer::with('station:id,name')->whereIn('id', $topDues->pluck('credit_customer_id'))->get()->keyBy('id');

            $topDues = $topDues->map(function ($r) use ($customers) {
                $c = $customers->get($r->credit_customer_id);
                return [
                    'customer_id'  => $r->credit_customer_id,
                    'name'         => $c?->name ?? '—',
                    'mobile'       => $c?->mobile,
                    'station_name' => $c?->station?->name,
                    'outstanding'  => round((float) $r->outstanding, 2),
                    'oldest_date'  => substr((string) $r->oldest_date, 0, 10),
                    'entries'      => (int) $r->entries,
                ];
            });

            return $this->success('Credit summary fetched.', [
                'summary' => [
                    'outstanding'         => round($outstanding, 2),
                    'pending_entries'     => $pendingCount,
                    'customers_with_dues' => $withDues,
                    'given_this_month'    => round($givenMonth, 2),
                    'collected_this_month'=> round($collected, 2),
                    'by_station'          => $byStation,
                    'top_dues'            => $topDues,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
