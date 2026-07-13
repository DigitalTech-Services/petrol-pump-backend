<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Station;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    use ApiResponse;

    private const CATEGORIES = [
        'Employee Shortage',
        'Tanker Charges',
        'Tea & Snacks',
        'DG Diesel',
        'Maintenance',
        'Stationary',
        'Other',
    ];

    // Write routes (see role:sub_user middleware) — each manager's expenses are their own.
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

    // GET /expenses?month=2026-04&category=&search=
    public function index(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            $query = Expense::whereIn($col, $ids)->orderBy('date', 'asc')->orderBy('id', 'asc');

            if ($month = $request->query('month')) {
                $query->whereYear('date', substr($month, 0, 4))
                      ->whereMonth('date', substr($month, 5, 2));
            }

            if ($category = $request->query('category')) {
                $query->where('category', $category);
            }

            if ($search = $request->query('search')) {
                $query->where('narration', 'like', '%' . $search . '%');
            }

            return $this->success('Expenses fetched.', ['expenses' => $query->get()]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // POST /expenses
    public function store(Request $request): JsonResponse
    {
        try {
            $owner = $this->owner($request);

            $data = $request->validate([
                'date'      => 'required|date',
                'amount'    => 'required|numeric|min:0',
                'category'  => 'required|string|in:' . implode(',', self::CATEGORIES),
                'narration' => 'required|string',
                'paid_by'   => 'sometimes|nullable|string|in:Cash,PhonePe,Card',
            ]);

            $expense = Expense::create(array_merge($data, ['user_id' => $owner->id, 'station_id' => $owner->station_id]));

            return $this->success('Expense added.', ['expense' => $expense], 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /expenses/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);
            $expense = Expense::whereIn($col, $ids)->findOrFail($id);

            return $this->success('Expense fetched.', ['expense' => $expense]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // PUT /expenses/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $owner   = $this->owner($request);
            $expense = Expense::where('user_id', $owner->id)->findOrFail($id);

            $data = $request->validate([
                'date'      => 'sometimes|date',
                'amount'    => 'sometimes|numeric|min:0',
                'category'  => 'sometimes|string|in:' . implode(',', self::CATEGORIES),
                'narration' => 'sometimes|string',
                'paid_by'   => 'sometimes|nullable|string|in:Cash,PhonePe,Card',
            ]);

            $expense->update($data);

            return $this->success('Expense updated.', ['expense' => $expense->fresh()]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // DELETE /expenses/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $owner   = $this->owner($request);
            $expense = Expense::where('user_id', $owner->id)->findOrFail($id);
            $expense->delete();

            return $this->success('Expense deleted.');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /expenses/summary?month=2026-04
    public function summary(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            $query = Expense::whereIn($col, $ids);

            if ($month = $request->query('month')) {
                $query->whereYear('date', substr($month, 0, 4))
                      ->whereMonth('date', substr($month, 5, 2));
            }

            $expenses = $query->get();

            if ($expenses->isEmpty()) {
                return $this->success('Summary fetched.', [
                    'summary' => ['total' => 0, 'count' => 0, 'avg_per_day' => 0, 'min' => null, 'max' => null],
                ]);
            }

            $total = $expenses->sum('amount');
            $count = $expenses->count();

            // Aggregate totals per calendar day (multiple records can exist per day)
            $byDay = $expenses
                ->groupBy(fn($e) => $e->date->format('Y-m-d'))
                ->map(fn($group, $date) => ['date' => $date, 'amount' => $group->sum('amount')]);

            $avgPerDay = $byDay->count() > 0 ? round($total / $byDay->count(), 2) : 0;
            $minDay    = $byDay->sortBy('amount')->first();
            $maxDay    = $byDay->sortByDesc('amount')->first();

            return $this->success('Summary fetched.', [
                'summary' => [
                    'total'       => round($total, 2),
                    'count'       => $count,
                    'avg_per_day' => $avgPerDay,
                    'min'         => $minDay,
                    'max'         => $maxDay,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /expenses/categories
    public function categories(): JsonResponse
    {
        return $this->success('Categories fetched.', ['categories' => self::CATEGORIES]);
    }
}
