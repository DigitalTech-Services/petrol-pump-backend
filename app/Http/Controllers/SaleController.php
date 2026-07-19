<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Station;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    use ApiResponse;

    private const SHIFTS = ['Morning', 'Evening', 'Night', 'Full Day'];

    // Write routes (see role:sub_user middleware) — each manager's sales are their own.
    private function owner(Request $request): User
    {
        return $request->user();
    }

    // Read routes (role:user,sub_user) — a manager only ever sees their own sales;
    // an owner sees every manager they created combined, or narrows to one station
    // via ?station_id= — same pattern as DashboardController::saleScope().
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

    // GET /sales?month=YYYY-MM&search=&sort=revenue|ms|expenses
    public function index(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            $query = Sale::whereIn($col, $ids)->orderBy('date', 'asc')->orderBy('id', 'asc');

            if ($month = $request->query('month')) {
                $query->whereYear('date', substr($month, 0, 4))
                      ->whereMonth('date', substr($month, 5, 2));
            }

            if ($search = $request->query('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('narration', 'like', '%' . $search . '%')
                      ->orWhereRaw("DATE_FORMAT(date, '%d %b')", 'like', '%' . $search . '%');
                });
            }

            $sales = $query->get();

            if ($sort = $request->query('sort')) {
                match ($sort) {
                    'revenue'  => $sales = $sales->sortByDesc('revenue'),
                    'ms'       => $sales = $sales->sortByDesc('ms_volume'),
                    'expenses' => $sales = $sales->sortByDesc('expenses'),
                    default    => null,
                };
            }

            return $this->success('Sales fetched.', ['sales' => $sales->values()]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // POST /sales
    public function store(Request $request): JsonResponse
    {
        try {
            $owner = $this->owner($request);

            $data = $request->validate([
                'date'         => 'required|date',
                'shift'        => 'sometimes|string|in:' . implode(',', self::SHIFTS),
                'ms_volume'    => 'sometimes|numeric|min:0',
                'hsd_volume'   => 'sometimes|numeric|min:0',
                'speed_volume' => 'sometimes|numeric|min:0',
                'ms_testing_volume'    => 'sometimes|numeric|min:0',
                'hsd_testing_volume'   => 'sometimes|numeric|min:0',
                'speed_testing_volume' => 'sometimes|numeric|min:0',
                'rate_ms'      => 'sometimes|numeric|min:0',
                'rate_hsd'     => 'sometimes|numeric|min:0',
                'rate_speed'   => 'sometimes|numeric|min:0',
                'revenue'      => 'sometimes|numeric|min:0',
                'cash'         => 'sometimes|numeric|min:0',
                'card'         => 'sometimes|numeric|min:0',
                'phone_pe'     => 'sometimes|numeric|min:0',
                'credit_sale'  => 'sometimes|numeric|min:0',
                'expenses'     => 'sometimes|numeric|min:0',
                'balance'      => 'sometimes|numeric',
                'narration'    => 'sometimes|nullable|string',
            ]);

            // Testing volume (fuel dispensed for meter calibration) isn't sold — deduct it
            // from the gross entered volume so the stored volume and revenue both reflect
            // only what was actually sold. Not persisted as its own column.
            $data['ms_volume']    = max(0, ($data['ms_volume']    ?? 0) - ($data['ms_testing_volume']    ?? 0));
            $data['hsd_volume']   = max(0, ($data['hsd_volume']   ?? 0) - ($data['hsd_testing_volume']   ?? 0));
            $data['speed_volume'] = max(0, ($data['speed_volume'] ?? 0) - ($data['speed_testing_volume'] ?? 0));
            unset($data['ms_testing_volume'], $data['hsd_testing_volume'], $data['speed_testing_volume']);

            // Compute revenue and balance server-side as a safeguard
            $msRev    = $data['ms_volume']    * ($data['rate_ms']    ?? 0);
            $hsdRev   = $data['hsd_volume']   * ($data['rate_hsd']   ?? 0);
            $speedRev = $data['speed_volume'] * ($data['rate_speed'] ?? 0);

            $data['revenue'] = round($msRev + $hsdRev + $speedRev, 2);
            $data['balance'] = round(
                ($data['cash'] ?? 0) + ($data['card'] ?? 0) + ($data['phone_pe'] ?? 0) - ($data['expenses'] ?? 0),
                2
            );

            $sale = Sale::create(array_merge($data, ['user_id' => $owner->id, 'station_id' => $owner->station_id]));

            return $this->success('Sale created.', ['sale' => $sale], 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /sales/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);
            $sale = Sale::whereIn($col, $ids)->findOrFail($id);

            return $this->success('Sale fetched.', ['sale' => $sale]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // PUT /sales/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $owner = $this->owner($request);
            $sale  = Sale::where('user_id', $owner->id)->findOrFail($id);

            $data = $request->validate([
                'date'         => 'sometimes|date',
                'shift'        => 'sometimes|string|in:' . implode(',', self::SHIFTS),
                'ms_volume'    => 'sometimes|numeric|min:0',
                'hsd_volume'   => 'sometimes|numeric|min:0',
                'speed_volume' => 'sometimes|numeric|min:0',
                'ms_testing_volume'    => 'sometimes|numeric|min:0',
                'hsd_testing_volume'   => 'sometimes|numeric|min:0',
                'speed_testing_volume' => 'sometimes|numeric|min:0',
                'rate_ms'      => 'sometimes|numeric|min:0',
                'rate_hsd'     => 'sometimes|numeric|min:0',
                'rate_speed'   => 'sometimes|numeric|min:0',
                'revenue'      => 'sometimes|numeric|min:0',
                'cash'         => 'sometimes|numeric|min:0',
                'card'         => 'sometimes|numeric|min:0',
                'phone_pe'     => 'sometimes|numeric|min:0',
                'credit_sale'  => 'sometimes|numeric|min:0',
                'expenses'     => 'sometimes|numeric|min:0',
                'balance'      => 'sometimes|numeric',
                'narration'    => 'sometimes|nullable|string',
            ]);

            // Testing volume isn't sold — deduct it from the gross volume being set
            // this request before it becomes the stored/revenue-generating volume.
            if (array_key_exists('ms_volume', $data) || array_key_exists('ms_testing_volume', $data)) {
                $data['ms_volume'] = max(0, ($data['ms_volume'] ?? $sale->ms_volume) - ($data['ms_testing_volume'] ?? 0));
            }
            if (array_key_exists('hsd_volume', $data) || array_key_exists('hsd_testing_volume', $data)) {
                $data['hsd_volume'] = max(0, ($data['hsd_volume'] ?? $sale->hsd_volume) - ($data['hsd_testing_volume'] ?? 0));
            }
            if (array_key_exists('speed_volume', $data) || array_key_exists('speed_testing_volume', $data)) {
                $data['speed_volume'] = max(0, ($data['speed_volume'] ?? $sale->speed_volume) - ($data['speed_testing_volume'] ?? 0));
            }
            unset($data['ms_testing_volume'], $data['hsd_testing_volume'], $data['speed_testing_volume']);

            // Recompute revenue & balance if volume/rate fields are being updated
            $msVol    = $data['ms_volume']    ?? $sale->ms_volume;
            $hsdVol   = $data['hsd_volume']   ?? $sale->hsd_volume;
            $speedVol = $data['speed_volume']  ?? $sale->speed_volume;
            $rateMs   = $data['rate_ms']       ?? $sale->rate_ms;
            $rateHsd  = $data['rate_hsd']      ?? $sale->rate_hsd;
            $rateSpd  = $data['rate_speed']    ?? $sale->rate_speed;
            $cash     = $data['cash']          ?? $sale->cash;
            $card     = $data['card']          ?? $sale->card;
            $phonePe  = $data['phone_pe']      ?? $sale->phone_pe;
            $expenses = $data['expenses']      ?? $sale->expenses;

            $data['revenue'] = round($msVol * $rateMs + $hsdVol * $rateHsd + $speedVol * $rateSpd, 2);
            $data['balance'] = round($cash + $card + $phonePe - $expenses, 2);

            $sale->update($data);

            return $this->success('Sale updated.', ['sale' => $sale->fresh()]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // DELETE /sales/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $owner = $this->owner($request);
            $sale  = Sale::where('user_id', $owner->id)->findOrFail($id);
            $sale->delete();

            return $this->success('Sale deleted.');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /sales/summary?month=YYYY-MM
    public function summary(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            $query = Sale::whereIn($col, $ids);

            if ($month = $request->query('month')) {
                $query->whereYear('date', substr($month, 0, 4))
                      ->whereMonth('date', substr($month, 5, 2));
            }

            $sales = $query->get();

            if ($sales->isEmpty()) {
                return $this->success('Summary fetched.', ['summary' => $this->emptySummary()]);
            }

            $summary = [
                'days'         => $sales->count(),
                'total_ms'     => round($sales->sum('ms_volume'), 2),
                'total_hsd'    => round($sales->sum('hsd_volume'), 2),
                'total_speed'  => round($sales->sum('speed_volume'), 2),
                'total_revenue'=> round($sales->sum('revenue'), 2),
                'total_cash'   => round($sales->sum('cash'), 2),
                'total_card'   => round($sales->sum('card'), 2),
                'total_phone_pe'   => round($sales->sum('phone_pe'), 2),
                'total_credit_sale'=> round($sales->sum('credit_sale'), 2),
                'total_expenses'   => round($sales->sum('expenses'), 2),
                'avg_daily_revenue'=> round($sales->sum('revenue') / $sales->count(), 2),
                'best_day'     => $sales->sortByDesc('revenue')->first()?->only(['date', 'revenue']),
            ];

            return $this->success('Summary fetched.', ['summary' => $summary]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /sales/monthly?year=YYYY
    public function monthly(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);
            $year = $request->query('year', date('Y'));

            $sales = Sale::whereIn($col, $ids)
                ->whereYear('date', $year)
                ->get();

            $monthly = $sales
                ->groupBy(fn($s) => $s->date->format('Y-m'))
                ->map(fn($group, $month) => [
                    'month'        => $month,
                    'days'         => $group->count(),
                    'ms_volume'    => round($group->sum('ms_volume'), 2),
                    'hsd_volume'   => round($group->sum('hsd_volume'), 2),
                    'speed_volume' => round($group->sum('speed_volume'), 2),
                    'revenue'      => round($group->sum('revenue'), 2),
                    'cash'         => round($group->sum('cash'), 2),
                    'phone_pe'     => round($group->sum('phone_pe'), 2),
                    'expenses'     => round($group->sum('expenses'), 2),
                ])
                ->sortKeys()
                ->values();

            return $this->success('Monthly sales fetched.', ['monthly' => $monthly]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    private function emptySummary(): array
    {
        return [
            'days' => 0, 'total_ms' => 0, 'total_hsd' => 0, 'total_speed' => 0,
            'total_revenue' => 0, 'total_cash' => 0, 'total_card' => 0,
            'total_phone_pe' => 0, 'total_credit_sale' => 0, 'total_expenses' => 0,
            'avg_daily_revenue' => 0, 'best_day' => null,
        ];
    }
}
