<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Models\StaffAdvance;
use App\Models\StaffAttendance;
use App\Models\Station;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    use ApiResponse;

    // Write routes (see role:sub_user middleware) — each manager's staff list is their own.
    private function rootUserId(Request $request): int
    {
        return $request->user()->id;
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

    // GET /staff?month=YYYY-MM — gross salary reflects that month's logged hours
    public function index(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);
            $month = $request->query('month', now()->format('Y-m'));

            $staff = Staff::whereIn($col, $ids)
                ->withSum(['advances' => fn($q) => $q->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$month])], 'amount')
                ->orderBy('id')
                ->get()
                ->map(fn($s) => $this->formatStaff($s, $month));

            return $this->success('Staff fetched!', ['staff' => $staff]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // POST /staff
    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'name'          => 'required|string|max:255',
                'role'          => 'required|string|max:100',
                'phone'         => 'nullable|string|max:20',
                'join_date'     => 'nullable|date',
                'rate_per_hour' => 'required|numeric|min:0',
                'shift_hours'   => 'nullable|integer|min:1|max:24',
                'notes'         => 'nullable|string',
            ]);

            $staff = Staff::create([
                'user_id'       => $this->rootUserId($request),
                'station_id'    => $request->user()->station_id,
                'name'          => $data['name'],
                'role'          => $data['role'],
                'phone'         => $data['phone'] ?? null,
                'join_date'     => $data['join_date'] ?? null,
                'rate_per_hour' => $data['rate_per_hour'],
                'shift_hours'   => $data['shift_hours'] ?? 8,
                'notes'         => $data['notes'] ?? null,
            ]);

            $staff->loadSum('advances', 'amount');

            return $this->success('Staff created!', ['staff' => $this->formatStaff($staff, now()->format('Y-m'))], 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /staff/{id}?month=YYYY-MM
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);
            $month = $request->query('month', now()->format('Y-m'));

            $staff = Staff::whereIn($col, $ids)
                ->withSum(['advances' => fn($q) => $q->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$month])], 'amount')
                ->findOrFail($id);

            return $this->success('Staff fetched!', ['staff' => $this->formatStaff($staff, $month)]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // PUT /staff/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $staff = Staff::where('user_id', $this->rootUserId($request))->findOrFail($id);

            $data = $request->validate([
                'name'          => 'sometimes|string|max:255',
                'role'          => 'sometimes|string|max:100',
                'phone'         => 'sometimes|nullable|string|max:20',
                'join_date'     => 'sometimes|nullable|date',
                'rate_per_hour' => 'sometimes|numeric|min:0',
                'shift_hours'   => 'sometimes|integer|min:1|max:24',
                'notes'         => 'sometimes|nullable|string',
            ]);

            // Changing the rate here only affects attendance logged from now on —
            // each already-logged day already carries its own rate snapshot.
            $staff->update($data);
            $staff->loadSum('advances', 'amount');

            return $this->success('Staff updated!', ['staff' => $this->formatStaff($staff, now()->format('Y-m'))]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // DELETE /staff/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $staff = Staff::where('user_id', $this->rootUserId($request))->findOrFail($id);
            $staff->delete();

            return $this->success('Staff deleted!');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /staff/advances
    public function getAdvances(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            $query = StaffAdvance::whereIn($col, $ids)
                ->with('staff:id,name')
                ->orderBy('date', 'asc');

            if ($request->filled('staff_id')) {
                $query->where('staff_id', (int) $request->staff_id);
            }

            if ($request->filled('month')) {
                // Expects format YYYY-MM e.g. 2026-04
                $query->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$request->month]);
            }

            $advances = $query->get()->map(fn($a) => [
                'id'         => $a->id,
                'staff_id'   => $a->staff_id,
                'staff'      => $a->staff ? ['id' => $a->staff->id, 'name' => $a->staff->name] : null,
                'date'       => $a->date?->toDateString(),
                'amount'     => (float) $a->amount,
                'reason'     => $a->reason,
                'created_at' => $a->created_at,
            ]);

            return $this->success('Advances fetched!', ['advances' => $advances]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // POST /staff/advances
    public function addAdvance(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'staff_id' => 'required|integer',
                'date'     => 'required|date',
                'amount'   => 'required|numeric|min:1',
                'reason'   => 'nullable|string|max:255',
            ]);

            $userId = $this->rootUserId($request);

            // Ensure the staff member belongs to this pump
            Staff::where('user_id', $userId)->findOrFail($data['staff_id']);

            $advance = StaffAdvance::create([
                'staff_id'   => $data['staff_id'],
                'user_id'    => $userId,
                'station_id' => $request->user()->station_id,
                'date'       => $data['date'],
                'amount'   => $data['amount'],
                'reason'   => $data['reason'] ?? null,
            ]);

            $advance->load('staff:id,name');

            return $this->success('Advance recorded!', [
                'advance' => [
                    'id'       => $advance->id,
                    'staff_id' => $advance->staff_id,
                    'staff'    => $advance->staff ? ['id' => $advance->staff->id, 'name' => $advance->staff->name] : null,
                    'date'     => $advance->date?->toDateString(),
                    'amount'   => (float) $advance->amount,
                    'reason'   => $advance->reason,
                ],
            ], 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // Gross pay = Σ(hours × the rate that was in effect when each day was logged) —
    // never the staff's current rate, so a later rate change can't alter it.
    private function formatStaff(Staff $staff, string $month): array
    {
        $totalAdvance = (float) ($staff->advances_sum_amount ?? 0);

        $presentRecords = StaffAttendance::where('staff_id', $staff->id)
            ->where('status', 'present')
            ->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$month])
            ->get(['total_hours', 'rate_per_hour']);

        $hoursWorked   = (float) $presentRecords->sum('total_hours');
        $workingSalary = (float) $presentRecords->sum(fn($a) => $a->total_hours * $a->rate_per_hour);

        return [
            'id'             => $staff->id,
            'user_id'        => $staff->user_id,
            'name'           => $staff->name,
            'role'           => $staff->role,
            'phone'          => $staff->phone,
            'join_date'      => $staff->join_date?->toDateString(),
            'rate_per_hour'  => (float) $staff->rate_per_hour,
            'shift_hours'    => $staff->shift_hours,
            'hours_worked'   => $hoursWorked,
            'notes'          => $staff->notes,
            'total_advance'  => $totalAdvance,
            'working_salary' => $workingSalary,
            'final_payout'   => $workingSalary - $totalAdvance,
        ];
    }
}
