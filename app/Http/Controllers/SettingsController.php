<?php

namespace App\Http\Controllers;

use App\Models\FuelRate;
use App\Models\Nozzle;
use App\Models\NotificationPreference;
use App\Models\StationSetting;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    use ApiResponse;

    // Manager-only route (see role:sub_user middleware) — each manager's settings are their own.
    private function owner(Request $request): User
    {
        return $request->user();
    }

    // ──────────────────────────────────────────────────────────────────
    // STATION DETAILS
    // ──────────────────────────────────────────────────────────────────

    public function getStation(Request $request): JsonResponse
    {
        try {
            $owner   = $this->owner($request);
            $setting = StationSetting::where('user_id', $owner->id)->first();

            if (!$setting) {
                // Return sensible defaults derived from the owner account
                $setting = [
                    'station_name' => '',
                    'dealer_code'  => '',
                    'owner_name'   => $owner->name,
                    'phone'        => $owner->contact ?? '',
                    'address'      => '',
                    'city'         => '',
                    'state'        => '',
                    'gst'          => '',
                    'pan'          => '',
                ];
            }

            return $this->success('Station settings fetched.', ['station' => $setting]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function updateStation(Request $request): JsonResponse
    {
        try {
            $owner = $this->owner($request);

            $data = $request->validate([
                'station_name' => 'sometimes|string|max:255',
                'dealer_code'  => 'sometimes|string|max:255',
                'owner_name'   => 'sometimes|string|max:255',
                'phone'        => 'sometimes|string|max:20',
                'address'      => 'sometimes|string',
                'city'         => 'sometimes|string|max:100',
                'state'        => 'sometimes|string|max:100',
                'gst'          => 'sometimes|string|max:20',
                'pan'          => 'sometimes|string|max:20',
            ]);

            $setting = StationSetting::updateOrCreate(
                ['user_id' => $owner->id],
                $data
            );

            return $this->success('Station settings updated.', ['station' => $setting]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // FUEL RATES
    // ──────────────────────────────────────────────────────────────────

    private array $defaultFuelRates = [
        ['fuel_key' => 'ms',    'name' => 'MS Petrol',  'abbr' => 'MS',  'type' => 'Motor Spirit',      'rate' => 104.77, 'effective_date' => '2026-04-01', 'color' => '#f59e0b'],
        ['fuel_key' => 'hsd',   'name' => 'HSD Diesel', 'abbr' => 'HSD', 'type' => 'High Speed Diesel', 'rate' => 91.28,  'effective_date' => '2026-04-01', 'color' => '#10b981'],
        ['fuel_key' => 'speed', 'name' => 'Speed',      'abbr' => 'SP',  'type' => 'Premium Petrol',    'rate' => 113.85, 'effective_date' => '2026-04-01', 'color' => '#3b82f6'],
    ];

    public function getFuelRates(Request $request): JsonResponse
    {
        try {
            $owner = $this->owner($request);
            $rates = FuelRate::where('user_id', $owner->id)->get();

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
            $owner = $this->owner($request);

            $data = $request->validate([
                'rates'                  => 'required|array|min:1',
                'rates.*.fuel_key'       => 'required|string|max:20',
                'rates.*.name'           => 'sometimes|string|max:100',
                'rates.*.abbr'           => 'sometimes|string|max:10',
                'rates.*.type'           => 'sometimes|string|max:100',
                'rates.*.rate'           => 'required|numeric|min:0',
                'rates.*.effective_date' => 'required|date',
                'rates.*.color'          => 'sometimes|string|max:20',
            ]);

            foreach ($data['rates'] as $r) {
                FuelRate::updateOrCreate(
                    ['user_id' => $owner->id, 'fuel_key' => $r['fuel_key']],
                    array_filter([
                        'name'           => $r['name']           ?? null,
                        'abbr'           => $r['abbr']           ?? null,
                        'type'           => $r['type']           ?? null,
                        'rate'           => $r['rate'],
                        'effective_date' => $r['effective_date'],
                        'color'          => $r['color']          ?? null,
                    ], fn($v) => $v !== null)
                );
            }

            $rates = FuelRate::where('user_id', $owner->id)->get();
            return $this->success('Fuel rates updated.', ['fuel_rates' => $rates]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // NOZZLES
    // ──────────────────────────────────────────────────────────────────

    private array $defaultNozzles = [
        ['nozzle_id' => 'MS-01',  'pump' => 'Pump 1', 'fuel' => 'MS',    'active' => true,  'last_reading' => '265,422.57'],
        ['nozzle_id' => 'MS-02',  'pump' => 'Pump 1', 'fuel' => 'MS',    'active' => true,  'last_reading' => '265,422.57'],
        ['nozzle_id' => 'MS-03',  'pump' => 'Pump 2', 'fuel' => 'MS',    'active' => true,  'last_reading' => '354,926.72'],
        ['nozzle_id' => 'MS-04',  'pump' => 'Pump 2', 'fuel' => 'MS',    'active' => true,  'last_reading' => '354,926.72'],
        ['nozzle_id' => 'MS-05',  'pump' => 'Pump 3', 'fuel' => 'MS',    'active' => true,  'last_reading' => '101,181.38'],
        ['nozzle_id' => 'MS-06',  'pump' => 'Pump 3', 'fuel' => 'MS',    'active' => false, 'last_reading' => '101,181.38'],
        ['nozzle_id' => 'HSD-01', 'pump' => 'Pump 4', 'fuel' => 'HSD',   'active' => true,  'last_reading' => '48,235.60'],
        ['nozzle_id' => 'HSD-02', 'pump' => 'Pump 4', 'fuel' => 'HSD',   'active' => true,  'last_reading' => '48,235.60'],
        ['nozzle_id' => 'SP-01',  'pump' => 'Pump 5', 'fuel' => 'Speed', 'active' => true,  'last_reading' => '12,450.22'],
        ['nozzle_id' => 'SP-02',  'pump' => 'Pump 5', 'fuel' => 'Speed', 'active' => true,  'last_reading' => '12,450.22'],
    ];

    public function getNozzles(Request $request): JsonResponse
    {
        try {
            $owner   = $this->owner($request);
            $nozzles = Nozzle::where('user_id', $owner->id)->get();

            return $this->success('Nozzles fetched.', [
                'nozzles' => $nozzles->isEmpty() ? $this->defaultNozzles : $nozzles,
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function storeNozzle(Request $request): JsonResponse
    {
        try {
            $owner = $this->owner($request);

            $data = $request->validate([
                'nozzle_id'    => 'required|string|max:20',
                'pump'         => 'required|string|max:50',
                'fuel'         => 'required|string|in:MS,HSD,Speed',
                'active'       => 'sometimes|boolean',
                'last_reading' => 'sometimes|string|max:30',
            ]);

            $nozzle = Nozzle::create(array_merge($data, ['user_id' => $owner->id]));

            return $this->success('Nozzle added.', ['nozzle' => $nozzle], 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function updateNozzle(Request $request, int $id): JsonResponse
    {
        try {
            $owner  = $this->owner($request);
            $nozzle = Nozzle::where('user_id', $owner->id)->findOrFail($id);

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
            $owner  = $this->owner($request);
            $nozzle = Nozzle::where('user_id', $owner->id)->findOrFail($id);
            $nozzle->delete();

            return $this->success('Nozzle removed.');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // NOTIFICATION PREFERENCES
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
            $owner  = $this->owner($request);
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
            $owner = $this->owner($request);

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
