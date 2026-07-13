<?php

namespace App\Http\Controllers;

use App\Models\Station;
use App\Models\Transaction;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    use ApiResponse;

    // Write routes (see role:sub_user middleware) — each manager's transactions are their own.
    private function owner(Request $request): User
    {
        return $request->user();
    }

    // Read routes (role:user,sub_user) — same pattern as DashboardController::saleScope().
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

    // GET /transactions?month=YYYY-MM&type=&bank=&search=
    public function index(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            $query = Transaction::whereIn($col, $ids)
                ->orderBy('date', 'asc')
                ->orderBy('id', 'asc');

            if ($month = $request->query('month')) {
                $query->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$month]);
            }

            if ($type = $request->query('type')) {
                $query->where('type', $type);
            }

            if ($bank = $request->query('bank')) {
                $query->where('bank', $bank);
            }

            if ($search = $request->query('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('ref_number', 'like', '%' . $search . '%')
                      ->orWhere('remarks',   'like', '%' . $search . '%')
                      ->orWhere('bank',      'like', '%' . $search . '%');
                });
            }

            return $this->success('Transactions fetched.', ['transactions' => $query->get()]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // POST /transactions
    public function store(Request $request): JsonResponse
    {
        try {
            $owner = $this->owner($request);

            $data = $request->validate([
                'date'       => 'required|date',
                'type'       => 'required|string|in:PhonePe,Card,NEFT,RTGS',
                'bank'       => 'required|string|max:50',
                'amount'     => 'required|numeric|min:0',
                'ref_number' => 'sometimes|nullable|string|max:100',
                'remarks'    => 'sometimes|nullable|string|max:255',
            ]);

            $tx = Transaction::create(array_merge($data, ['user_id' => $owner->id, 'station_id' => $owner->station_id]));

            return $this->success('Transaction added.', ['transaction' => $tx], 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /transactions/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);
            $tx = Transaction::whereIn($col, $ids)->findOrFail($id);

            return $this->success('Transaction fetched.', ['transaction' => $tx]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // PUT /transactions/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $owner = $this->owner($request);
            $tx    = Transaction::where('user_id', $owner->id)->findOrFail($id);

            $data = $request->validate([
                'date'       => 'sometimes|date',
                'type'       => 'sometimes|string|in:PhonePe,Card,NEFT,RTGS',
                'bank'       => 'sometimes|string|max:50',
                'amount'     => 'sometimes|numeric|min:0',
                'ref_number' => 'sometimes|nullable|string|max:100',
                'remarks'    => 'sometimes|nullable|string|max:255',
            ]);

            $tx->update($data);

            return $this->success('Transaction updated.', ['transaction' => $tx->fresh()]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // DELETE /transactions/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $owner = $this->owner($request);
            $tx    = Transaction::where('user_id', $owner->id)->findOrFail($id);
            $tx->delete();

            return $this->success('Transaction deleted.');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /transactions/summary?month=YYYY-MM
    public function summary(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            $query = Transaction::whereIn($col, $ids);

            if ($month = $request->query('month')) {
                $query->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$month]);
            }

            $transactions = $query->get();

            if ($transactions->isEmpty()) {
                return $this->success('Summary fetched.', [
                    'summary' => [
                        'total'       => 0,
                        'count'       => 0,
                        'avg_per_day' => 0,
                        'highest'     => null,
                        'by_type'     => [],
                        'by_bank'     => [],
                    ],
                ]);
            }

            $total = round($transactions->sum('amount'), 2);
            $count = $transactions->count();

            // Highest single day total across all transaction types
            $byDay = $transactions
                ->groupBy(fn($t) => $t->date->format('Y-m-d'))
                ->map(fn($group, $date) => ['date' => $date, 'amount' => round($group->sum('amount'), 2)]);

            $avgPerDay = $byDay->count() > 0 ? round($total / $byDay->count(), 2) : 0;
            $highest   = $byDay->sortByDesc('amount')->first();

            // Breakdown by payment type
            $byType = $transactions
                ->groupBy('type')
                ->map(fn($group, $type) => [
                    'type'  => $type,
                    'total' => round($group->sum('amount'), 2),
                    'count' => $group->count(),
                ])
                ->sortByDesc('total')
                ->values();

            // Breakdown by bank
            $byBank = $transactions
                ->groupBy('bank')
                ->map(fn($group, $bank) => [
                    'bank'  => $bank,
                    'total' => round($group->sum('amount'), 2),
                    'count' => $group->count(),
                ])
                ->sortByDesc('total')
                ->values();

            return $this->success('Summary fetched.', [
                'summary' => [
                    'total'       => $total,
                    'count'       => $count,
                    'avg_per_day' => $avgPerDay,
                    'highest'     => $highest,
                    'by_type'     => $byType,
                    'by_bank'     => $byBank,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
