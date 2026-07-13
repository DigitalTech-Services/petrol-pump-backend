<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Station;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

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
            $totalExp   = (float)$sales->sum('expenses');
            $totalFuel  = $totalMs + $totalHsd + $totalSpeed;

            $avgRev   = $days > 0 ? round($totalRev / $days, 2) : 0;
            $avgMs    = $days > 0 ? round($totalMs  / $days, 2) : 0;
            $bestSale = $sales->sortByDesc('revenue')->first();

            $kpis = [
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
            $totalExp   = (float)$sales->sum('expenses');
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

    // GET /dashboard/stock-levels — placeholder (no stock intake table yet)
    public function stockLevels(): JsonResponse
    {
        return $this->success('Stock levels fetched.', [
            'ms' => null, 'hsd' => null, 'speed' => null,
        ]);
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
