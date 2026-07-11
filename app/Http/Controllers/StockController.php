<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\StockEntry;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    use ApiResponse;

    private const FUEL_TYPES = ['MS', 'HSD', 'Speed'];

    private const SALE_COLUMN = [
        'MS'    => 'ms_volume',
        'HSD'   => 'hsd_volume',
        'Speed' => 'speed_volume',
    ];

    private function owner(Request $request): User
    {
        $user = $request->user();
        return $user->type === 'sub_user' ? $user->parent : $user;
    }

    // date('Y-m-d') → sale volume for the matching fuel type, keyed by day of month
    private function saleVolumesByDay(int $ownerId, string $month, string $fuelType)
    {
        $column = self::SALE_COLUMN[$fuelType];

        return Sale::where('user_id', $ownerId)
            ->whereYear('date', substr($month, 0, 4))
            ->whereMonth('date', substr($month, 5, 2))
            ->get()
            ->keyBy(fn($s) => $s->date->format('Y-m-d'))
            ->map(fn($s) => (float) $s->{$column});
    }

    private function present(StockEntry $entry, $saleVolumes = null): array
    {
        $net    = round($entry->opening + $entry->received, 2);
        $sale   = $entry->actual_sale ?? ($saleVolumes?->get($entry->date->format('Y-m-d')) ?? 0);
        $sale   = (float) $sale;
        $variation = round($sale - ($net - $entry->closing), 2);

        return [
            'id'        => $entry->id,
            'date'      => $entry->date->format('Y-m-d'),
            'fuel_type' => $entry->fuel_type,
            'open'      => $entry->opening,
            'recv'      => $entry->received,
            'net'       => $net,
            'close'     => $entry->closing,
            'sale'      => round($sale, 2),
            'variation' => $variation,
            'remarks'   => $entry->remarks,
        ];
    }

    // GET /stock?month=YYYY-MM&fuel_type=MS
    public function index(Request $request): JsonResponse
    {
        try {
            $owner    = $this->owner($request);
            $fuelType = $request->query('fuel_type', 'MS');
            $month    = $request->query('month', date('Y-m'));

            if (!in_array($fuelType, self::FUEL_TYPES, true)) {
                return $this->error('Invalid fuel_type.', 422);
            }

            $entries = StockEntry::where('user_id', $owner->id)
                ->where('fuel_type', $fuelType)
                ->whereYear('date', substr($month, 0, 4))
                ->whereMonth('date', substr($month, 5, 2))
                ->orderBy('date', 'asc')
                ->get();

            $saleVolumes = $this->saleVolumesByDay($owner->id, $month, $fuelType);
            $rows = $entries->map(fn($e) => $this->present($e, $saleVolumes))->values();

            return $this->success('Stock entries fetched.', ['entries' => $rows]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // POST /stock
    public function store(Request $request): JsonResponse
    {
        try {
            $owner = $this->owner($request);

            $data = $request->validate([
                'date'        => 'required|date',
                'fuel_type'   => 'required|string|in:' . implode(',', self::FUEL_TYPES),
                'opening'     => 'required|numeric|min:0',
                'received'    => 'sometimes|numeric|min:0',
                'closing'     => 'required|numeric|min:0',
                'actual_sale' => 'sometimes|nullable|numeric|min:0',
                'remarks'     => 'sometimes|nullable|string|max:255',
            ]);

            $exists = StockEntry::where('user_id', $owner->id)
                ->whereDate('date', $data['date'])
                ->where('fuel_type', $data['fuel_type'])
                ->exists();

            if ($exists) {
                return $this->error('A stock entry for this date and fuel type already exists.', 422);
            }

            $entry = StockEntry::create(array_merge($data, ['user_id' => $owner->id]));

            $saleVolumes = $this->saleVolumesByDay($owner->id, substr($data['date'], 0, 7), $data['fuel_type']);

            return $this->success('Stock entry added.', ['entry' => $this->present($entry, $saleVolumes)], 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /stock/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $owner = $this->owner($request);
            $entry = StockEntry::where('user_id', $owner->id)->findOrFail($id);

            $saleVolumes = $this->saleVolumesByDay($owner->id, $entry->date->format('Y-m'), $entry->fuel_type);

            return $this->success('Stock entry fetched.', ['entry' => $this->present($entry, $saleVolumes)]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // PUT /stock/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $owner = $this->owner($request);
            $entry = StockEntry::where('user_id', $owner->id)->findOrFail($id);

            $data = $request->validate([
                'date'        => 'sometimes|date',
                'fuel_type'   => 'sometimes|string|in:' . implode(',', self::FUEL_TYPES),
                'opening'     => 'sometimes|numeric|min:0',
                'received'    => 'sometimes|numeric|min:0',
                'closing'     => 'sometimes|numeric|min:0',
                'actual_sale' => 'sometimes|nullable|numeric|min:0',
                'remarks'     => 'sometimes|nullable|string|max:255',
            ]);

            $entry->update($data);
            $entry = $entry->fresh();

            $saleVolumes = $this->saleVolumesByDay($owner->id, $entry->date->format('Y-m'), $entry->fuel_type);

            return $this->success('Stock entry updated.', ['entry' => $this->present($entry, $saleVolumes)]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // DELETE /stock/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $owner = $this->owner($request);
            $entry = StockEntry::where('user_id', $owner->id)->findOrFail($id);
            $entry->delete();

            return $this->success('Stock entry deleted.');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /stock/summary?month=YYYY-MM&fuel_type=MS
    public function summary(Request $request): JsonResponse
    {
        try {
            $owner    = $this->owner($request);
            $fuelType = $request->query('fuel_type', 'MS');
            $month    = $request->query('month', date('Y-m'));

            if (!in_array($fuelType, self::FUEL_TYPES, true)) {
                return $this->error('Invalid fuel_type.', 422);
            }

            $entries = StockEntry::where('user_id', $owner->id)
                ->where('fuel_type', $fuelType)
                ->whereYear('date', substr($month, 0, 4))
                ->whereMonth('date', substr($month, 5, 2))
                ->orderBy('date', 'asc')
                ->get();

            if ($entries->isEmpty()) {
                return $this->success('Summary fetched.', [
                    'summary' => [
                        'days' => 0, 'total_received' => 0, 'total_sale' => 0,
                        'latest_closing' => 0, 'avg_variation' => 0,
                    ],
                ]);
            }

            $saleVolumes = $this->saleVolumesByDay($owner->id, $month, $fuelType);
            $rows        = $entries->map(fn($e) => $this->present($e, $saleVolumes));

            return $this->success('Summary fetched.', [
                'summary' => [
                    'days'           => $rows->count(),
                    'total_received' => round($rows->sum('recv'), 2),
                    'total_sale'     => round($rows->sum('sale'), 2),
                    'latest_closing' => (float) $entries->last()->closing,
                    'avg_variation'  => round($rows->avg('variation'), 2),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /stock/tankwise?month=YYYY-MM — per-fuel-type breakdown for KPI cards
    public function tankwise(Request $request): JsonResponse
    {
        try {
            $owner = $this->owner($request);
            $month = $request->query('month', date('Y-m'));

            $result = [];

            foreach (self::FUEL_TYPES as $fuelType) {
                $entries = StockEntry::where('user_id', $owner->id)
                    ->where('fuel_type', $fuelType)
                    ->whereYear('date', substr($month, 0, 4))
                    ->whereMonth('date', substr($month, 5, 2))
                    ->orderBy('date', 'asc')
                    ->get();

                if ($entries->isEmpty()) {
                    $result[$fuelType] = [
                        'total_received' => 0, 'latest_closing' => 0,
                        'total_sale' => 0, 'avg_variation' => 0,
                    ];
                    continue;
                }

                $saleVolumes = $this->saleVolumesByDay($owner->id, $month, $fuelType);
                $rows        = $entries->map(fn($e) => $this->present($e, $saleVolumes));

                $result[$fuelType] = [
                    'total_received' => round($rows->sum('recv'), 2),
                    'latest_closing' => (float) $entries->last()->closing,
                    'total_sale'     => round($rows->sum('sale'), 2),
                    'avg_variation'  => round($rows->avg('variation'), 2),
                ];
            }

            return $this->success('Tankwise stock fetched.', ['tankwise' => $result]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
