<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\FuelRate;
use App\Models\Sale;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\Station;
use App\Models\StockEntry;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

    private const STOCK_FUEL_TYPES = ['MS', 'HSD', 'Speed'];

    // A manager only ever sees their own figures; an owner sees their own account
    // plus every manager they created, combined — this is the "review all stats" view.
    // An owner may narrow this to a single station via ?station_id=, which switches
    // to filtering Sale by its own station_id column instead — this keeps a station's
    // history intact even if its manager is later reassigned, unlike filtering by
    // whichever manager currently happens to run it.
    // Returns [column, values] to build a whereIn() query with.
    private function saleScope(Request $request): array
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
        return [(int)$parts[0], (int)($parts[1] ?? 1)];
    }

    // GET /dashboard/summary?period=YYYY-MM
    // Single endpoint — one DB query, returns KPIs + daily trend + fuel mix + payment split.
    // Avoids multiple concurrent requests that stall the single-threaded PHP dev server.
    public function summary(Request $request): JsonResponse
    {
        try {
            [$year, $month] = $this->parseYearMonth($request);
            [$scopeColumn, $scopeValues] = $this->saleScope($request);

            $sales = Sale::whereIn($scopeColumn, $scopeValues)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->get();

            // ── KPIs ──────────────────────────────────────────────────
            $days       = $sales->count();
            $totalMs    = (float)$sales->sum('ms_volume');
            $totalHsd   = (float)$sales->sum('hsd_volume');
            $totalSpeed = (float)$sales->sum('speed_volume');
            $totalRev   = (float)$sales->sum('revenue');
            $totalCash  = (float)$sales->sum('cash');
            $totalCard  = (float)$sales->sum('card');
            $totalPhone = (float)$sales->sum('phone_pe');
            // The categorized Expense ledger (Expenses tab) is the real source of
            // truth for operating costs — not Sale.expenses, which is just a manual
            // per-entry field on the daily sale record and is usually left blank.
            $totalExp   = (float) Expense::whereIn($scopeColumn, $scopeValues)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->sum('amount');
            $totalFuel  = $totalMs + $totalHsd + $totalSpeed;

            $avgRev   = $days > 0 ? round($totalRev / $days, 2) : 0;
            $avgMs    = $days > 0 ? round($totalMs  / $days, 2) : 0;
            $bestSale = $sales->sortByDesc('revenue')->first();

            $staffPayroll = $this->staffPayrollTotal($scopeColumn, $scopeValues, $year, $month);
            $profitLoss   = $this->profitLossData($sales);

            $kpis = [
                'totalRevenue'    => $this->inr($totalRev),
                'msSold'          => $this->num($totalMs),
                'hsdSold'         => $this->num($totalHsd),
                'speedSold'       => $this->num($totalSpeed),
                'totalCash'       => $this->inr($totalCash),
                'totalPhonePe'    => $this->inr($totalPhone),
                'totalExpenses'   => $this->inr($totalExp),
                'staffPayroll'    => $this->inr($staffPayroll),
                'avgDailyRevenue' => $this->inr($avgRev),
                'bestDay'         => $bestSale?->date?->format('d M, Y'),
                'bestDayRevenue'  => $this->inr((float)($bestSale?->revenue ?? 0)),
                'totalFuel'       => $this->num($totalFuel) . ' L',
                'totalCard'       => $this->inr($totalCard),
                'avgMsPerDay'     => $this->num($avgMs) . ' L',
            ];

            // ── Daily trend + fuel mix (same indexed collection) ──────
            $indexed     = $sales->keyBy(fn($s) => $s->date->format('d'));
            $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
            $monthStr    = str_pad($month, 2, '0', STR_PAD_LEFT);

            $trend   = [];
            $fuelMix = [];

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $day = str_pad($d, 2, '0', STR_PAD_LEFT);
                $s   = $indexed->get($day);

                $trend[] = [
                    'day'      => $day,
                    'date'     => "$year-$monthStr-$day",
                    'revenue'  => (float)($s?->revenue      ?? 0),
                    'ms'       => (float)($s?->ms_volume    ?? 0),
                    'hsd'      => (float)($s?->hsd_volume   ?? 0),
                    'speed'    => (float)($s?->speed_volume ?? 0),
                    'cash'     => (float)($s?->cash         ?? 0),
                    'phone_pe' => (float)($s?->phone_pe     ?? 0),
                    'card'     => (float)($s?->card         ?? 0),
                    'expenses' => (float)($s?->expenses     ?? 0),
                    'balance'  => (float)($s?->balance      ?? 0),
                ];

                $fuelMix[] = [
                    'day'   => $day,
                    'ms'    => (float)($s?->ms_volume    ?? 0),
                    'hsd'   => (float)($s?->hsd_volume   ?? 0),
                    'speed' => (float)($s?->speed_volume ?? 0),
                ];
            }

            return $this->success('Dashboard summary fetched.', [
                'kpis'          => $kpis,
                'daily_trend'   => $trend,
                'fuel_mix'      => $fuelMix,
                'payment_split' => [
                    'cash'     => round($totalCash,  2),
                    'phone_pe' => round($totalPhone, 2),
                    'card'     => round($totalCard,  2),
                ],
                'stock_levels'  => $this->stockLevelsData($scopeColumn, $scopeValues, $year, $month),
                'profit_loss'   => $profitLoss,
                'actual_profit' => [
                    // Actual profit = fuel margin − expenses (Expense ledger, same source
                    // as the Total Expenses KPI) − staff payroll (gross, hours × rate;
                    // advances are just an early payout of this same earned salary, not
                    // an extra cost, so they aren't subtracted again here).
                    'total'         => round($profitLoss['total'] - $totalExp - $staffPayroll, 2),
                    'fuel_margin'   => $profitLoss['total'],
                    'expenses'      => round($totalExp, 2),
                    'staff_payroll' => round($staffPayroll, 2),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /dashboard/kpis?period=YYYY-MM
    public function kpis(Request $request): JsonResponse
    {
        try {
            [$year, $month] = $this->parseYearMonth($request);
            [$scopeColumn, $scopeValues] = $this->saleScope($request);

            $sales = Sale::whereIn($scopeColumn, $scopeValues)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->get();

            $days       = $sales->count();
            $totalMs    = (float)$sales->sum('ms_volume');
            $totalHsd   = (float)$sales->sum('hsd_volume');
            $totalSpeed = (float)$sales->sum('speed_volume');
            $totalRev   = (float)$sales->sum('revenue');
            $totalCash  = (float)$sales->sum('cash');
            $totalCard  = (float)$sales->sum('card');
            $totalPhone = (float)$sales->sum('phone_pe');
            $totalExp   = (float) Expense::whereIn($scopeColumn, $scopeValues)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->sum('amount');
            $totalFuel  = $totalMs + $totalHsd + $totalSpeed;

            $avgRev     = $days > 0 ? round($totalRev  / $days, 2) : 0;
            $avgMs      = $days > 0 ? round($totalMs   / $days, 2) : 0;
            $bestSale   = $sales->sortByDesc('revenue')->first();

            return $this->success('KPIs fetched.', [
                'totalRevenue'    => $this->inr($totalRev),
                'msSold'          => $this->num($totalMs),
                'hsdSold'         => $this->num($totalHsd),
                'speedSold'       => $this->num($totalSpeed),
                'totalCash'       => $this->inr($totalCash),
                'totalPhonePe'    => $this->inr($totalPhone),
                'totalExpenses'   => $this->inr($totalExp),
                'staffPayroll'    => null,
                'avgDailyRevenue' => $this->inr($avgRev),
                'bestDay'         => $bestSale?->date?->format('d M, Y'),
                'bestDayRevenue'  => $this->inr((float)($bestSale?->revenue ?? 0)),
                'totalFuel'       => $this->num($totalFuel) . ' L',
                'totalCard'       => $this->inr($totalCard),
                'avgMsPerDay'     => $this->num($avgMs) . ' L',
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /dashboard/daily-trend?period=YYYY-MM
    // Returns one row per calendar day (zeros for days without a sale entry)
    public function dailyTrend(Request $request): JsonResponse
    {
        try {
            [$year, $month] = $this->parseYearMonth($request);
            [$scopeColumn, $scopeValues] = $this->saleScope($request);

            $sales = Sale::whereIn($scopeColumn, $scopeValues)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->get()
                ->keyBy(fn($s) => $s->date->format('d'));

            $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
            $monthStr    = str_pad($month, 2, '0', STR_PAD_LEFT);
            $trend       = [];

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $day  = str_pad($d, 2, '0', STR_PAD_LEFT);
                $s    = $sales->get($day);
                $trend[] = [
                    'day'      => $day,
                    'date'     => "$year-$monthStr-$day",
                    'revenue'  => (float)($s?->revenue   ?? 0),
                    'ms'       => (float)($s?->ms_volume  ?? 0),
                    'hsd'      => (float)($s?->hsd_volume ?? 0),
                    'speed'    => (float)($s?->speed_volume ?? 0),
                    'cash'     => (float)($s?->cash       ?? 0),
                    'phone_pe' => (float)($s?->phone_pe   ?? 0),
                    'card'     => (float)($s?->card       ?? 0),
                    'expenses' => (float)($s?->expenses   ?? 0),
                    'balance'  => (float)($s?->balance    ?? 0),
                ];
            }

            return $this->success('Daily trend fetched.', $trend);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /dashboard/fuel-mix?period=YYYY-MM
    public function fuelMix(Request $request): JsonResponse
    {
        try {
            [$year, $month] = $this->parseYearMonth($request);
            [$scopeColumn, $scopeValues] = $this->saleScope($request);

            $sales = Sale::whereIn($scopeColumn, $scopeValues)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->get()
                ->keyBy(fn($s) => $s->date->format('d'));

            $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
            $mix         = [];

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $day  = str_pad($d, 2, '0', STR_PAD_LEFT);
                $s    = $sales->get($day);
                $mix[] = [
                    'day'   => $day,
                    'ms'    => (float)($s?->ms_volume    ?? 0),
                    'hsd'   => (float)($s?->hsd_volume   ?? 0),
                    'speed' => (float)($s?->speed_volume ?? 0),
                ];
            }

            return $this->success('Fuel mix fetched.', $mix);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /dashboard/payment-split?period=YYYY-MM
    public function paymentSplit(Request $request): JsonResponse
    {
        try {
            [$year, $month] = $this->parseYearMonth($request);
            [$scopeColumn, $scopeValues] = $this->saleScope($request);

            $sales = Sale::whereIn($scopeColumn, $scopeValues)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->get();

            return $this->success('Payment split fetched.', [
                'cash'     => (float)round($sales->sum('cash'),     2),
                'phone_pe' => (float)round($sales->sum('phone_pe'), 2),
                'card'     => (float)round($sales->sum('card'),     2),
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /dashboard/stock-levels?period=YYYY-MM — current closing stock per fuel type
    public function stockLevels(Request $request): JsonResponse
    {
        try {
            [$year, $month] = $this->parseYearMonth($request);
            [$scopeColumn, $scopeValues] = $this->saleScope($request);

            return $this->success(
                'Stock levels fetched.',
                $this->stockLevelsData($scopeColumn, $scopeValues, $year, $month)
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // Latest closing stock reading per fuel type within the given month —
    // same "current level" concept as StockController::tankwise()'s latest_closing.
    private function stockLevelsData(string $scopeColumn, array $scopeValues, int $year, int $month): array
    {
        $levels = [];

        foreach (self::STOCK_FUEL_TYPES as $fuelType) {
            $latest = StockEntry::whereIn($scopeColumn, $scopeValues)
                ->where('fuel_type', $fuelType)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->orderBy('date', 'desc')
                ->first();

            $levels[strtolower($fuelType)] = $latest ? [
                'closing' => (float) $latest->closing,
                'date'    => $latest->date->format('Y-m-d'),
            ] : null;
        }

        return $levels;
    }

    // Estimated profit/loss = (selling rate − actual/cost rate) × volume sold, per
    // fuel type. Rates are configured per station (Settings → Fuel Rates), so sales
    // are grouped by station and matched against that station's own rates — an
    // owner viewing "All Stations" combines each station's margin correctly instead
    // of applying one station's rates to everyone. Sales don't record a historical
    // cost rate, so this uses each station's *current* FuelRate row — an
    // approximation if rates changed mid-period, the same limitation Settings' own
    // per-litre margin preview has. A station that has never saved Settings → Fuel
    // Rates has zero FuelRate rows — falls back to FuelRate::defaults() (the same
    // values Settings itself displays as a preview) rather than silently treating
    // "no row" as "zero margin".
    private function profitLossData($sales): array
    {
        $byStation  = $sales->groupBy('station_id');
        $stationIds = $byStation->keys()->filter()->all();

        $ratesByStation = FuelRate::whereIn('station_id', $stationIds)
            ->get()
            ->groupBy('station_id');

        $defaultRates = collect(FuelRate::defaults())->keyBy('fuel_key');

        $fuelVolumeColumns = ['ms' => 'ms_volume', 'hsd' => 'hsd_volume', 'speed' => 'speed_volume'];
        $totals = ['ms' => 0.0, 'hsd' => 0.0, 'speed' => 0.0];

        foreach ($byStation as $stationId => $stationSales) {
            $stationRates = $ratesByStation->get($stationId) ?? collect();
            $stationRates = $stationRates->isEmpty() ? $defaultRates : $stationRates->keyBy('fuel_key');

            foreach ($fuelVolumeColumns as $key => $volumeColumn) {
                $rate = $stationRates->get($key);
                if (!$rate) continue;

                $margin = (float) $rate['rate'] - (float) $rate['actual_rate'];
                $totals[$key] += $margin * (float) $stationSales->sum($volumeColumn);
            }
        }

        return [
            'total' => round(array_sum($totals), 2),
            'ms'    => round($totals['ms'], 2),
            'hsd'   => round($totals['hsd'], 2),
            'speed' => round($totals['speed'], 2),
        ];
    }

    // Total staff labor cost for the month — Σ(hours × the rate snapshot in effect
    // when each day was logged), same formula as StaffController::formatStaff()'s
    // per-staff working_salary, summed across every staff member in scope.
    private function staffPayrollTotal(string $scopeColumn, array $scopeValues, int $year, int $month): float
    {
        $staffIds = Staff::whereIn($scopeColumn, $scopeValues)->pluck('id');

        if ($staffIds->isEmpty()) {
            return 0.0;
        }

        return (float) StaffAttendance::whereIn('staff_id', $staffIds)
            ->where('status', 'present')
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get(['total_hours', 'rate_per_hour'])
            ->sum(fn($a) => $a->total_hours * $a->rate_per_hour);
    }

    // ── Formatting helpers ────────────────────────────────────────────

    private function inr(float $val): string
    {
        if ($val >= 10_000_000) return '₹' . number_format($val / 10_000_000, 2) . 'Cr';
        if ($val >= 100_000)    return '₹' . number_format($val / 100_000,    2) . 'L';
        if ($val >= 1_000)      return '₹' . number_format((int)round($val));
        return '₹' . number_format($val, 2);
    }

    private function num(float $val): string
    {
        return number_format((int)round($val));
    }
}
