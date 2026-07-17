<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Sale;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\Station;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    use ApiResponse;

    private const FUEL_COLUMNS = [
        'MS'    => ['volume' => 'ms_volume',    'rate' => 'rate_ms'],
        'HSD'   => ['volume' => 'hsd_volume',   'rate' => 'rate_hsd'],
        'Speed' => ['volume' => 'speed_volume', 'rate' => 'rate_speed'],
    ];

    // A manager only ever sees their own figures; an owner sees their own account
    // plus every manager they created, combined. An owner may narrow this to a
    // single station via ?station_id= — see DashboardController::saleScope() for
    // why this filters by the station_id column rather than by manager identity.
    // Returns [column, values] to build a whereIn() query with.
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

    private function parseYearMonth(Request $request): array
    {
        $period = $request->query('period', date('Y-m'));
        $parts  = explode('-', $period);
        return [(int) $parts[0], (int) ($parts[1] ?? 1)];
    }

    private function daysInMonth(int $year, int $month): int
    {
        return (int) date('t', mktime(0, 0, 0, $month, 1, $year));
    }

    // GET /reports/monthly?period=YYYY-MM — Summary tab
    public function monthly(Request $request): JsonResponse
    {
        try {
            [$year, $month] = $this->parseYearMonth($request);
            [$col, $ids] = $this->scope($request);

            $sales    = Sale::whereIn($col, $ids)->whereYear('date', $year)->whereMonth('date', $month)->orderBy('date')->get();
            $expenses = Expense::whereIn($col, $ids)->whereYear('date', $year)->whereMonth('date', $month)->get();
            $staff    = Staff::whereIn($col, $ids)->withSum('advances', 'amount')->get();

            $totalMs      = (float) $sales->sum('ms_volume');
            $totalHsd     = (float) $sales->sum('hsd_volume');
            $totalSpeed   = (float) $sales->sum('speed_volume');
            $totalRevenue = (float) $sales->sum('revenue');
            $totalCash    = (float) $sales->sum('cash');
            $totalPhone   = (float) $sales->sum('phone_pe');
            $totalCard    = (float) $sales->sum('card');
            $totalExpenses= (float) $expenses->sum('amount');

            $staffNet = $staff->sum(fn($s) => ((float) $s->rate_per_day * (int) $s->days_worked) - (float) ($s->advances_sum_amount ?? 0));

            $latestSale = $sales->last();
            $collectionTotal = max($totalCash + $totalPhone + $totalCard, 1);
            $daysInMonth = $this->daysInMonth($year, $month);

            $indexed = $sales->keyBy(fn($s) => $s->date->format('d'));
            $daily   = [];
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $day = str_pad($d, 2, '0', STR_PAD_LEFT);
                $s   = $indexed->get($day);
                $daily[] = [
                    'day'      => $day,
                    'revenue'  => (float) ($s?->revenue  ?? 0),
                    'cash'     => (float) ($s?->cash     ?? 0),
                    'phone_pe' => (float) ($s?->phone_pe ?? 0),
                ];
            }

            return $this->success('Monthly report fetched.', [
                'period' => sprintf('%04d-%02d', $year, $month),
                'days'   => $sales->count(),
                'kpis'   => [
                    'gross_revenue'  => round($totalRevenue, 2),
                    'total_fuel'     => round($totalMs + $totalHsd + $totalSpeed, 2),
                    'total_expenses' => round($totalExpenses, 2),
                    'net_payroll'    => round($staffNet, 2),
                    'staff_count'    => $staff->count(),
                ],
                'fuel' => [
                    'ms_volume'    => round($totalMs, 2),
                    'hsd_volume'   => round($totalHsd, 2),
                    'speed_volume' => round($totalSpeed, 2),
                    'rate_ms'      => (float) ($latestSale?->rate_ms    ?? 0),
                    'rate_hsd'     => (float) ($latestSale?->rate_hsd   ?? 0),
                    'rate_speed'   => (float) ($latestSale?->rate_speed ?? 0),
                ],
                'collection' => [
                    'cash'      => round($totalCash,  2),
                    'phone_pe'  => round($totalPhone, 2),
                    'card'      => round($totalCard,  2),
                    'cash_pct'     => round($totalCash  / $collectionTotal * 100, 1),
                    'phone_pe_pct' => round($totalPhone / $collectionTotal * 100, 1),
                    'card_pct'     => round($totalCard  / $collectionTotal * 100, 1),
                ],
                'net_profit' => round($totalRevenue - $totalExpenses - $staffNet, 2),
                'daily'      => $daily,
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /reports/fuel?period=YYYY-MM — Fuel tab
    public function fuel(Request $request): JsonResponse
    {
        try {
            [$year, $month] = $this->parseYearMonth($request);
            [$col, $ids] = $this->scope($request);

            $sales = Sale::whereIn($col, $ids)
                ->whereYear('date', $year)->whereMonth('date', $month)
                ->orderBy('date')->get();

            $daysInMonth = $this->daysInMonth($year, $month);
            $indexed     = $sales->keyBy(fn($s) => $s->date->format('d'));

            $totals = [];
            foreach (self::FUEL_COLUMNS as $fuel => $cols) {
                $totals[$fuel] = (float) $sales->sum($cols['volume']);
            }
            $grandTotal = max(array_sum($totals), 1);

            $fuelStats = [];
            $dailyByFuel = [];

            foreach (self::FUEL_COLUMNS as $fuel => $cols) {
                $volCol  = $cols['volume'];
                $rateCol = $cols['rate'];

                $revenue = (float) $sales->sum(fn($s) => $s->{$volCol} * $s->{$rateCol});
                $daysWithSale = $sales->filter(fn($s) => $s->{$volCol} > 0)->count();
                $peak = $sales->sortByDesc($volCol)->first();

                $fuelStats[$fuel] = [
                    'total_volume' => round($totals[$fuel], 2),
                    'rate'         => (float) ($sales->last()?->{$rateCol} ?? 0),
                    'revenue'      => round($revenue, 2),
                    'avg_daily'    => $daysWithSale > 0 ? round($totals[$fuel] / $daysWithSale, 2) : 0,
                    'peak_volume'  => (float) ($peak?->{$volCol} ?? 0),
                    'peak_date'    => $peak?->date?->format('d M'),
                    'pct_of_total' => round($totals[$fuel] / $grandTotal * 100, 1),
                ];

                $daily = [];
                for ($d = 1; $d <= $daysInMonth; $d++) {
                    $day = str_pad($d, 2, '0', STR_PAD_LEFT);
                    $s   = $indexed->get($day);
                    $daily[] = ['day' => $day, 'volume' => (float) ($s?->{$volCol} ?? 0)];
                }
                $dailyByFuel[$fuel] = $daily;
            }

            return $this->success('Fuel report fetched.', [
                'fuel' => $fuelStats,
                'daily' => $dailyByFuel,
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /reports/pnl?period=YYYY-MM — P&L tab
    public function pnl(Request $request): JsonResponse
    {
        try {
            [$year, $month] = $this->parseYearMonth($request);
            [$col, $ids] = $this->scope($request);

            $sales    = Sale::whereIn($col, $ids)->whereYear('date', $year)->whereMonth('date', $month)->orderBy('date')->get();
            $expenses = Expense::whereIn($col, $ids)->whereYear('date', $year)->whereMonth('date', $month)->get();

            $daysInMonth  = $this->daysInMonth($year, $month);
            $salesIndexed = $sales->keyBy(fn($s) => $s->date->format('d'));
            $expByDay     = $expenses->groupBy(fn($e) => $e->date->format('d'));

            $daily = [];
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $day = str_pad($d, 2, '0', STR_PAD_LEFT);
                $s   = $salesIndexed->get($day);
                $daily[] = [
                    'day'      => $day,
                    'revenue'  => (float) ($s?->revenue  ?? 0),
                    'cash'     => (float) ($s?->cash     ?? 0),
                    'phone_pe' => (float) ($s?->phone_pe ?? 0),
                    'expenses' => round((float) ($expByDay->get($day)?->sum('amount') ?? 0), 2),
                ];
            }

            $byCategory = $expenses
                ->groupBy('category')
                ->map(fn($group, $category) => [
                    'category' => $category,
                    'total'    => round($group->sum('amount'), 2),
                    'count'    => $group->count(),
                ])
                ->sortByDesc('total')
                ->values();

            return $this->success('P&L report fetched.', [
                'daily'       => $daily,
                'by_category' => $byCategory,
                'total_expenses' => round($expenses->sum('amount'), 2),
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /reports/staff?period=YYYY-MM — Staff tab
    public function staff(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);
            $period = $request->query('period', date('Y-m'));

            $staff = Staff::whereIn($col, $ids)
                ->withSum(['advances' => fn($q) => $q->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$period])], 'amount')
                ->orderBy('id')
                ->get()
                ->map(function ($s) use ($period) {
                    // Gross = Σ(hours × the rate in effect when each day was logged) —
                    // same historical-rate-safe formula as StaffController/timesheet.
                    $presentRecords = StaffAttendance::where('staff_id', $s->id)
                        ->where('status', 'present')
                        ->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$period])
                        ->get(['total_hours', 'rate_per_hour']);

                    $hours   = (float) $presentRecords->sum('total_hours');
                    $gross   = (float) $presentRecords->sum(fn($a) => $a->total_hours * $a->rate_per_hour);
                    $advance = (float) ($s->advances_sum_amount ?? 0);

                    return [
                        'id'      => $s->id,
                        'name'    => $s->name,
                        'role'    => $s->role,
                        'hours'   => $hours,
                        'gross'   => round($gross, 2),
                        'advance' => round($advance, 2),
                        'net'     => round($gross - $advance, 2),
                    ];
                });

            return $this->success('Staff report fetched.', ['staff' => $staff]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
