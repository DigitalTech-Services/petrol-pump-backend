<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Models\StaffAdvance;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    use ApiResponse;

    // Manager-only route (see role:sub_user middleware) — each manager's staff list is their own.
    private function rootUserId(Request $request): int
    {
        return $request->user()->id;
    }

    // GET /staff
    public function index(Request $request): JsonResponse
    {
        try {
            $staff = Staff::where('user_id', $this->rootUserId($request))
                ->withSum('advances', 'amount')
                ->orderBy('id')
                ->get()
                ->map(fn($s) => $this->formatStaff($s));

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
                'name'         => 'required|string|max:255',
                'role'         => 'required|string|max:100',
                'phone'        => 'nullable|string|max:20',
                'join_date'    => 'nullable|date',
                'rate_per_day' => 'required|numeric|min:0',
                'shift_hours'  => 'nullable|integer|min:1|max:24',
                'days_worked'  => 'nullable|integer|min:0|max:31',
                'notes'        => 'nullable|string',
            ]);

            $staff = Staff::create([
                'user_id'      => $this->rootUserId($request),
                'name'         => $data['name'],
                'role'         => $data['role'],
                'phone'        => $data['phone'] ?? null,
                'join_date'    => $data['join_date'] ?? null,
                'rate_per_day' => $data['rate_per_day'],
                'shift_hours'  => $data['shift_hours'] ?? 8,
                'days_worked'  => $data['days_worked'] ?? 30,
                'notes'        => $data['notes'] ?? null,
            ]);

            $staff->loadSum('advances', 'amount');

            return $this->success('Staff created!', ['staff' => $this->formatStaff($staff)], 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /staff/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $staff = Staff::where('user_id', $this->rootUserId($request))
                ->withSum('advances', 'amount')
                ->findOrFail($id);

            return $this->success('Staff fetched!', ['staff' => $this->formatStaff($staff)]);
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
                'name'         => 'sometimes|string|max:255',
                'role'         => 'sometimes|string|max:100',
                'phone'        => 'sometimes|nullable|string|max:20',
                'join_date'    => 'sometimes|nullable|date',
                'rate_per_day' => 'sometimes|numeric|min:0',
                'shift_hours'  => 'sometimes|integer|min:1|max:24',
                'days_worked'  => 'sometimes|integer|min:0|max:31',
                'notes'        => 'sometimes|nullable|string',
            ]);

            $staff->update($data);
            $staff->loadSum('advances', 'amount');

            return $this->success('Staff updated!', ['staff' => $this->formatStaff($staff)]);
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
            $userId = $this->rootUserId($request);

            $query = StaffAdvance::where('user_id', $userId)
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
                'staff_id' => $data['staff_id'],
                'user_id'  => $userId,
                'date'     => $data['date'],
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

    private function formatStaff(Staff $staff): array
    {
        $totalAdvance  = (float) ($staff->advances_sum_amount ?? 0);
        $workingSalary = (float) ($staff->rate_per_day * $staff->days_worked);

        return [
            'id'             => $staff->id,
            'user_id'        => $staff->user_id,
            'name'           => $staff->name,
            'role'           => $staff->role,
            'phone'          => $staff->phone,
            'join_date'      => $staff->join_date?->toDateString(),
            'rate_per_day'   => (float) $staff->rate_per_day,
            'shift_hours'    => $staff->shift_hours,
            'days_worked'    => $staff->days_worked,
            'notes'          => $staff->notes,
            'total_advance'  => $totalAdvance,
            'working_salary' => $workingSalary,
            'final_payout'   => $workingSalary - $totalAdvance,
        ];
    }
}
