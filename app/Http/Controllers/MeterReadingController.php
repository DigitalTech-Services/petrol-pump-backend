<?php

namespace App\Http\Controllers;

use App\Models\MeterReading;
use App\Models\MeterReadingNozzle;
use App\Models\Nozzle;
use App\Models\Station;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeterReadingController extends Controller
{
    use ApiResponse;

    // Write routes (see role:sub_user middleware) — each manager's readings are their own.
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

    // GET /meters?month=YYYY-MM&fuel_type=MS
    public function index(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            $query = MeterReading::with('nozzleReadings')
                ->whereIn($col, $ids)
                ->orderBy('date', 'asc');

            if ($month = $request->query('month')) {
                $query->whereYear('date', substr($month, 0, 4))
                      ->whereMonth('date', substr($month, 5, 2));
            }

            $readings = $query->get();

            return $this->success('Meter readings fetched.', ['readings' => $readings]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // POST /meters
    public function store(Request $request): JsonResponse
    {
        try {
            $owner = $this->owner($request);

            $data = $request->validate([
                'date'                         => 'required|date',
                'notes'                        => 'sometimes|nullable|string|max:255',
                'nozzle_readings'              => 'required|array|min:1',
                'nozzle_readings.*.nozzle_id'  => 'required|string|max:20',
                'nozzle_readings.*.opening'    => 'required|numeric|min:0',
                'nozzle_readings.*.closing'    => 'required|numeric|min:0',
            ]);

            $alreadyExists = MeterReading::where('user_id', $owner->id)
                ->whereDate('date', $data['date'])
                ->exists();

            if ($alreadyExists) {
                return $this->error('A meter reading for this date already exists.', 422);
            }

            $reading = DB::transaction(function () use ($data, $owner) {
                $totalUsed = collect($data['nozzle_readings'])
                    ->sum(fn($nr) => max(0, $nr['closing'] - $nr['opening']));

                $reading = MeterReading::create([
                    'user_id'    => $owner->id,
                    'station_id' => $owner->station_id,
                    'date'       => $data['date'],
                    'total_used' => round($totalUsed, 2),
                    'notes'      => $data['notes'] ?? null,
                ]);

                foreach ($data['nozzle_readings'] as $nr) {
                    MeterReadingNozzle::create([
                        'meter_reading_id' => $reading->id,
                        'nozzle_id'        => strtoupper($nr['nozzle_id']),
                        'opening'          => $nr['opening'],
                        'closing'          => $nr['closing'],
                        'used'             => round(max(0, $nr['closing'] - $nr['opening']), 2),
                    ]);
                }

                return $reading->load('nozzleReadings');
            });

            return $this->success('Meter reading added.', ['reading' => $reading], 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /meters/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);
            $reading = MeterReading::with('nozzleReadings')
                ->whereIn($col, $ids)
                ->findOrFail($id);

            return $this->success('Meter reading fetched.', ['reading' => $reading]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // PUT /meters/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $owner   = $this->owner($request);
            $reading = MeterReading::where('user_id', $owner->id)->findOrFail($id);

            $data = $request->validate([
                'date'                         => 'sometimes|date',
                'notes'                        => 'sometimes|nullable|string|max:255',
                'nozzle_readings'              => 'sometimes|array|min:1',
                'nozzle_readings.*.nozzle_id'  => 'required_with:nozzle_readings|string|max:20',
                'nozzle_readings.*.opening'    => 'required_with:nozzle_readings|numeric|min:0',
                'nozzle_readings.*.closing'    => 'required_with:nozzle_readings|numeric|min:0',
            ]);

            DB::transaction(function () use ($data, $reading) {
                if (isset($data['nozzle_readings'])) {
                    $totalUsed = collect($data['nozzle_readings'])
                        ->sum(fn($nr) => max(0, $nr['closing'] - $nr['opening']));

                    $reading->nozzleReadings()->delete();

                    foreach ($data['nozzle_readings'] as $nr) {
                        MeterReadingNozzle::create([
                            'meter_reading_id' => $reading->id,
                            'nozzle_id'        => strtoupper($nr['nozzle_id']),
                            'opening'          => $nr['opening'],
                            'closing'          => $nr['closing'],
                            'used'             => round(max(0, $nr['closing'] - $nr['opening']), 2),
                        ]);
                    }

                    $data['total_used'] = round($totalUsed, 2);
                }

                unset($data['nozzle_readings']);
                $reading->update($data);
            });

            return $this->success('Meter reading updated.', ['reading' => $reading->fresh()->load('nozzleReadings')]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // DELETE /meters/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $owner   = $this->owner($request);
            $reading = MeterReading::where('user_id', $owner->id)->findOrFail($id);
            $reading->delete();

            return $this->success('Meter reading deleted.');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /meters/nozzles?fuel_type=MS
    public function nozzles(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            // Nozzles are keyed by station, not user — a specific station_id resolves directly;
            // the "all stations" aggregate resolves to every scoped manager's assigned station.
            $stationIds = $col === 'station_id'
                ? $ids
                : User::whereIn('id', $ids)->whereNotNull('station_id')->pluck('station_id')->all();

            $query = Nozzle::whereIn('station_id', $stationIds)->where('active', true);

            if ($fuel = $request->query('fuel_type')) {
                $query->where('fuel', $fuel);
            }

            return $this->success('Nozzles fetched.', ['nozzles' => $query->orderBy('nozzle_id')->get()]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /meters/last-readings?before=YYYY-MM-DD — closing reading per nozzle from
    // the most recent prior day's entry (whichever day that was, not necessarily
    // literally yesterday), so a new day's opening can be pre-filled from it.
    public function lastReadings(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);
            $before = $request->query('before', now()->format('Y-m-d'));

            $lastReading = MeterReading::with('nozzleReadings')
                ->whereIn($col, $ids)
                ->whereDate('date', '<', $before)
                ->orderBy('date', 'desc')
                ->first();

            $closing = [];
            foreach ($lastReading?->nozzleReadings ?? [] as $nr) {
                $closing[$nr->nozzle_id] = (float) $nr->closing;
            }

            return $this->success('Last readings fetched.', [
                'date'    => $lastReading?->date?->toDateString(),
                'closing' => $closing,
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /meters/summary?month=YYYY-MM
    public function summary(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            $query = MeterReading::with('nozzleReadings')->whereIn($col, $ids);

            if ($month = $request->query('month')) {
                $query->whereYear('date', substr($month, 0, 4))
                      ->whereMonth('date', substr($month, 5, 2));
            }

            $readings = $query->get();

            if ($readings->isEmpty()) {
                return $this->success('Summary fetched.', [
                    'summary' => ['total_used' => 0, 'days' => 0, 'avg_per_day' => 0, 'by_nozzle' => []],
                ]);
            }

            $totalUsed = round($readings->sum('total_used'), 2);
            $days      = $readings->count();

            $byNozzle = $readings
                ->flatMap(fn($r) => $r->nozzleReadings)
                ->groupBy('nozzle_id')
                ->map(fn($group, $nozzleId) => [
                    'nozzle_id'  => $nozzleId,
                    'total_used' => round($group->sum('used'), 2),
                ])
                ->sortBy('nozzle_id')
                ->values();

            return $this->success('Summary fetched.', [
                'summary' => [
                    'total_used'  => $totalUsed,
                    'days'        => $days,
                    'avg_per_day' => $days > 0 ? round($totalUsed / $days, 2) : 0,
                    'by_nozzle'   => $byNozzle,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
