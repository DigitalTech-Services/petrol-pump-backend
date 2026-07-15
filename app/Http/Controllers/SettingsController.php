<?php

namespace App\Http\Controllers;

use App\Models\FuelRate;
use App\Models\Nozzle;
use App\Models\NotificationPreference;
use App\Models\Station;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    use ApiResponse;

    // Settings/fuel-rates/nozzles belong to a single station, shared by whoever runs it.
    // A manager always resolves their own assigned station. An owner (read-only, see
    // role:user,sub_user on the GET routes) must pick a specific station via
    // ?station_id= — there's no "all stations" aggregate for single-station config.
    private function station(Request $request): ?Station
    {
        $user = $request->user();

        if ($user->type === 'user') {
            $stationId = $request->query('station_id');
            if ($stationId === null || $stationId === '') {
                return null;
            }

            return Station::where('id', (int) $stationId)
                ->where('user_id', $user->id)
                ->first();
        }

        return $user->station;
    }

    private const NO_STATION_MESSAGE = 'You are not assigned to a station yet — contact your owner.';

    // ──────────────────────────────────────────────────────────────────
    // STATION DETAILS
    // ──────────────────────────────────────────────────────────────────

    public function getStation(Request $request): JsonResponse
    {
        try {
            $station = $this->station($request);

            if (!$station) {
                return $this->success('Station settings fetched.', ['station' => null]);
            }

            return $this->success('Station settings fetched.', [
                'station' => $station->only(['id', 'name', 'dealer_code', 'address', 'city', 'state', 'gst', 'pan', 'phone']),
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function updateStation(Request $request): JsonResponse
    {
        try {
            $station = $this->station($request);
            if (!$station) {
                return $this->error(self::NO_STATION_MESSAGE, 422);
            }

            $data = $request->validate([
                'name'        => 'sometimes|string|max:255',
                'dealer_code' => 'sometimes|string|max:255',
                'address'     => 'sometimes|string',
                'city'        => 'sometimes|string|max:100',
                'state'       => 'sometimes|string|max:100',
                'gst'         => 'sometimes|string|max:20',
                'pan'         => 'sometimes|string|max:20',
                'phone'       => 'sometimes|string|max:20',
            ]);

            $station->update($data);

            return $this->success('Station settings updated.', [
                'station' => $station->fresh()->only(['id', 'name', 'dealer_code', 'address', 'city', 'state', 'gst', 'pan', 'phone']),
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // FUEL RATES
    // ──────────────────────────────────────────────────────────────────

    private array $defaultFuelRates = [
        ['fuel_key' => 'ms',    'name' => 'MS Petrol',  'abbr' => 'MS',  'type' => 'Motor Spirit',      'rate' => 104.77, 'actual_rate' => 98.50,  'effective_date' => '2026-04-01', 'color' => '#f59e0b'],
        ['fuel_key' => 'hsd',   'name' => 'HSD Diesel', 'abbr' => 'HSD', 'type' => 'High Speed Diesel', 'rate' => 91.28,  'actual_rate' => 86.00,  'effective_date' => '2026-04-01', 'color' => '#10b981'],
        ['fuel_key' => 'speed', 'name' => 'Speed',      'abbr' => 'SP',  'type' => 'Premium Petrol',    'rate' => 113.85, 'actual_rate' => 106.50, 'effective_date' => '2026-04-01', 'color' => '#3b82f6'],
    ];

    public function getFuelRates(Request $request): JsonResponse
    {
        try {
            $station = $this->station($request);
            if (!$station) {
                return $this->error(self::NO_STATION_MESSAGE, 422);
            }
            $rates   = FuelRate::where('station_id', $station->id)->get();

            return $this->success('Fuel rates fetched.', [
                'fuel_rates' => $rates->isEmpty() ? $this->defaultFuelRates : $rates,
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function updateFuelRates(Request $request): JsonResponse
    {
        try {
            $station = $this->station($request);
            if (!$station) {
                return $this->error(self::NO_STATION_MESSAGE, 422);
            }

            $data = $request->validate([
                'rates'                  => 'required|array|min:1',
                'rates.*.fuel_key'       => 'required|string|max:20',
                'rates.*.name'           => 'sometimes|string|max:100',
                'rates.*.abbr'           => 'sometimes|string|max:10',
                'rates.*.type'           => 'sometimes|string|max:100',
                'rates.*.rate'           => 'required|numeric|min:0',
                'rates.*.actual_rate'    => 'sometimes|numeric|min:0',
                'rates.*.effective_date' => 'required|date',
                'rates.*.color'          => 'sometimes|string|max:20',
            ]);

            foreach ($data['rates'] as $r) {
                FuelRate::updateOrCreate(
                    ['station_id' => $station->id, 'fuel_key' => $r['fuel_key']],
                    array_filter([
                        'name'           => $r['name']           ?? null,
                        'abbr'           => $r['abbr']           ?? null,
                        'type'           => $r['type']           ?? null,
                        'rate'           => $r['rate'],
                        'actual_rate'    => $r['actual_rate']    ?? 0,
                        'effective_date' => $r['effective_date'],
                        'color'          => $r['color']          ?? null,
                    ], fn($v) => $v !== null)
                );
            }

            $rates = FuelRate::where('station_id', $station->id)->get();
            return $this->success('Fuel rates updated.', ['fuel_rates' => $rates]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // NOZZLES
    // ──────────────────────────────────────────────────────────────────

    public function getNozzles(Request $request): JsonResponse
    {
        try {
            $station = $this->station($request);
            if (!$station) {
                return $this->error(self::NO_STATION_MESSAGE, 422);
            }
            $nozzles = Nozzle::where('station_id', $station->id)->get();

            return $this->success('Nozzles fetched.', ['nozzles' => $nozzles]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function storeNozzle(Request $request): JsonResponse
    {
        try {
            $station = $this->station($request);
            if (!$station) {
                return $this->error(self::NO_STATION_MESSAGE, 422);
            }

            $data = $request->validate([
                'nozzle_id'    => 'required|string|max:20',
                'pump'         => 'required|string|max:50',
                'fuel'         => 'required|string|in:MS,HSD,Speed',
                'active'       => 'sometimes|boolean',
                'last_reading' => 'sometimes|string|max:30',
            ]);

            $nozzle = Nozzle::create(array_merge($data, ['station_id' => $station->id]));

            return $this->success('Nozzle added.', ['nozzle' => $nozzle], 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function updateNozzle(Request $request, int $id): JsonResponse
    {
        try {
            $station = $this->station($request);
            if (!$station) {
                return $this->error(self::NO_STATION_MESSAGE, 422);
            }
            $nozzle  = Nozzle::where('station_id', $station->id)->findOrFail($id);

            $data = $request->validate([
                'active'       => 'sometimes|boolean',
                'last_reading' => 'sometimes|string|max:30',
                'pump'         => 'sometimes|string|max:50',
                'fuel'         => 'sometimes|string|in:MS,HSD,Speed',
            ]);

            $nozzle->update($data);

            return $this->success('Nozzle updated.', ['nozzle' => $nozzle->fresh()]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroyNozzle(Request $request, int $id): JsonResponse
    {
        try {
            $station = $this->station($request);
            if (!$station) {
                return $this->error(self::NO_STATION_MESSAGE, 422);
            }
            $nozzle  = Nozzle::where('station_id', $station->id)->findOrFail($id);
            $nozzle->delete();

            return $this->success('Nozzle removed.');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // NOTIFICATION PREFERENCES (personal — per manager login, not per station)
    // ──────────────────────────────────────────────────────────────────

    private array $defaultNotifications = [
        ['notif_key' => 'daily',   'icon' => '📊', 'label' => 'Daily Sales Summary',    'sub' => 'Get end-of-day summary via WhatsApp',      'enabled' => true],
        ['notif_key' => 'stock',   'icon' => '🛢',  'label' => 'Low Stock Alert',         'sub' => 'Alert when fuel drops below threshold',     'enabled' => true],
        ['notif_key' => 'salary',  'icon' => '💰', 'label' => 'Monthly Salary Reminder', 'sub' => 'Remind on 28th to process payroll',         'enabled' => false],
        ['notif_key' => 'expense', 'icon' => '🧾', 'label' => 'High Expense Alert',      'sub' => 'Alert when daily expense exceeds ₹10,000',  'enabled' => true],
        ['notif_key' => 'meter',   'icon' => '📈', 'label' => 'Meter Variation Alert',   'sub' => 'Alert on large meter discrepancies',        'enabled' => false],
    ];

    public function getNotifications(Request $request): JsonResponse
    {
        try {
            $owner  = $request->user();
            $notifs = NotificationPreference::where('user_id', $owner->id)->get();

            return $this->success('Notification preferences fetched.', [
                'notifications' => $notifs->isEmpty() ? $this->defaultNotifications : $notifs,
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function updateNotifications(Request $request): JsonResponse
    {
        try {
            $owner = $request->user();

            $data = $request->validate([
                'notifications'              => 'required|array|min:1',
                'notifications.*.notif_key'  => 'required|string|max:30',
                'notifications.*.enabled'    => 'required|boolean',
            ]);

            foreach ($data['notifications'] as $n) {
                // Find the matching default to carry label/icon/sub forward
                $default = collect($this->defaultNotifications)
                    ->firstWhere('notif_key', $n['notif_key']) ?? [];

                NotificationPreference::updateOrCreate(
                    ['user_id' => $owner->id, 'notif_key' => $n['notif_key']],
                    [
                        'enabled' => $n['enabled'],
                        'icon'    => $default['icon']  ?? null,
                        'label'   => $default['label'] ?? $n['notif_key'],
                        'sub'     => $default['sub']   ?? '',
                    ]
                );
            }

            $notifs = NotificationPreference::where('user_id', $owner->id)->get();
            return $this->success('Notification preferences updated.', ['notifications' => $notifs]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
