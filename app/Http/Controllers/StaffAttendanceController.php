<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\Station;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffAttendanceController extends Controller
{
    use ApiResponse;

    // ─── Helpers ──────────────────────────────────────────────────────────────

    // Write routes (see role:sub_user middleware) — each manager's attendance records are their own.
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

    /**
     * Compute decimal hours from HH:MM strings.
     * Returns 0 when times are missing, equal, or out-time is before in-time.
     */
    private function calcHours(?string $inTime, ?string $outTime): float
    {
        if (!$inTime || !$outTime) return 0.0;
        try {
            [$ih, $im] = array_map('intval', explode(':', $inTime));
            [$oh, $om] = array_map('intval', explode(':', $outTime));
            $mins = ($oh * 60 + $om) - ($ih * 60 + $im);
            return $mins > 0 ? round($mins / 60, 1) : 0.0;
        } catch (\Exception) {
            return 0.0;
        }
    }

    private function formatRecord(StaffAttendance $a): array
    {
        return [
            'id'          => $a->id,
            'staff_id'    => $a->staff_id,
            'staff'       => $a->staff ? [
                'id'          => $a->staff->id,
                'name'        => $a->staff->name,
                'role'        => $a->staff->role,
                'rate_per_day'=> (float) $a->staff->rate_per_day,
            ] : null,
            'date'        => $a->date?->toDateString(),
            'status'      => $a->status,
            'in_time'     => $a->in_time,
            'out_time'    => $a->out_time,
            'total_hours' => (float) $a->total_hours,
            'notes'       => $a->notes,
            'created_at'  => $a->created_at,
        ];
    }

    // ─── GET /staff/attendance ────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);

            $query = StaffAttendance::whereIn($col, $ids)
                ->with('staff:id,name,role,rate_per_day')
                ->orderBy('date')
                ->orderBy('staff_id');

            if ($request->filled('month')) {
                $query->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$request->month]);
            }
            if ($request->filled('staff_id')) {
                $query->where('staff_id', (int) $request->staff_id);
            }
            if ($request->filled('date')) {
                $query->whereDate('date', $request->date);
            }

            $records = $query->get()->map(fn($a) => $this->formatRecord($a));

            return $this->success('Attendance fetched!', ['attendance' => $records]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ─── POST /staff/attendance  (single record) ──────────────────────────────

    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'staff_id' => 'required|integer',
                'date'     => 'required|date',
                'status'   => 'required|in:present,absent',
                'in_time'  => 'nullable|date_format:H:i',
                'out_time' => 'nullable|date_format:H:i',
                'notes'    => 'nullable|string|max:255',
            ]);

            $userId = $this->rootUserId($request);
            Staff::where('user_id', $userId)->findOrFail($data['staff_id']);

            $totalHours = $this->calcHours($data['in_time'] ?? null, $data['out_time'] ?? null);

            $record = StaffAttendance::updateOrCreate(
                ['staff_id' => $data['staff_id'], 'date' => $data['date']],
                [
                    'user_id'     => $userId,
                    'station_id'  => $request->user()->station_id,
                    'status'      => $data['status'],
                    'in_time'     => $data['in_time'] ?? null,
                    'out_time'    => $data['out_time'] ?? null,
                    'total_hours' => $totalHours,
                    'notes'       => $data['notes'] ?? null,
                ]
            );

            $record->load('staff:id,name,role,rate_per_day');

            return $this->success('Attendance recorded!', ['attendance' => $this->formatRecord($record)], 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ─── POST /staff/attendance/bulk  (mark multiple staff for one date) ──────

    public function bulk(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'date'                 => 'required|date',
                'records'              => 'required|array|min:1',
                'records.*.staff_id'   => 'required|integer',
                'records.*.status'     => 'required|in:present,absent',
                'records.*.in_time'    => 'nullable|date_format:H:i',
                'records.*.out_time'   => 'nullable|date_format:H:i',
                'records.*.notes'      => 'nullable|string|max:255',
            ]);

            $userId  = $this->rootUserId($request);
            $saved   = [];
            $skipped = 0;

            foreach ($data['records'] as $rec) {
                $staff = Staff::where('user_id', $userId)->find($rec['staff_id']);
                if (!$staff) { $skipped++; continue; }

                $totalHours = $this->calcHours($rec['in_time'] ?? null, $rec['out_time'] ?? null);

                $record = StaffAttendance::updateOrCreate(
                    ['staff_id' => $rec['staff_id'], 'date' => $data['date']],
                    [
                        'user_id'     => $userId,
                        'station_id'  => $request->user()->station_id,
                        'status'      => $rec['status'],
                        'in_time'     => $rec['in_time'] ?? null,
                        'out_time'    => $rec['out_time'] ?? null,
                        'total_hours' => $totalHours,
                        'notes'       => $rec['notes'] ?? null,
                    ]
                );

                $saved[] = $record->id;
            }

            return $this->success(
                'Attendance saved for ' . count($saved) . ' staff' . ($skipped ? ", {$skipped} skipped." : '.'),
                ['saved_count' => count($saved), 'skipped' => $skipped]
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ─── GET /staff/attendance/{id} ───────────────────────────────────────────

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);
            $record = StaffAttendance::whereIn($col, $ids)
                ->with('staff:id,name,role,rate_per_day')
                ->findOrFail($id);

            return $this->success('Record fetched!', ['attendance' => $this->formatRecord($record)]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ─── PUT /staff/attendance/{id} ───────────────────────────────────────────

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $record = StaffAttendance::where('user_id', $this->rootUserId($request))->findOrFail($id);

            $data = $request->validate([
                'status'   => 'sometimes|in:present,absent',
                'in_time'  => 'sometimes|nullable|date_format:H:i',
                'out_time' => 'sometimes|nullable|date_format:H:i',
                'notes'    => 'sometimes|nullable|string|max:255',
            ]);

            if (isset($data['in_time']) || isset($data['out_time'])) {
                $inTime  = $data['in_time']  ?? $record->in_time;
                $outTime = $data['out_time'] ?? $record->out_time;
                $data['total_hours'] = $this->calcHours($inTime, $outTime);
            }

            $record->update($data);
            $record->load('staff:id,name,role,rate_per_day');

            return $this->success('Attendance updated!', ['attendance' => $this->formatRecord($record)]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ─── DELETE /staff/attendance/{id} ────────────────────────────────────────

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $record = StaffAttendance::where('user_id', $this->rootUserId($request))->findOrFail($id);
            $record->delete();

            return $this->success('Attendance record deleted!');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ─── GET /staff/timesheet  (monthly summary per staff member) ────────────

    public function timesheet(Request $request): JsonResponse
    {
        try {
            [$col, $ids] = $this->scope($request);
            $month = $request->input('month', now()->format('Y-m')); // e.g. 2026-06

            $staff = Staff::whereIn($col, $ids)
                ->withSum(['advances' => fn($q) => $q->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$month])], 'amount')
                ->with(['attendance' => function ($q) use ($month) {
                    $q->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$month]);
                }])
                ->orderBy('id')
                ->get();

            $summary = $staff->map(function ($s) {
                $presentRecords = $s->attendance->where('status', 'present');

                $daysPresent = $presentRecords->count();
                $totalHours  = $presentRecords->sum('total_hours');
                $avgHours    = $daysPresent > 0 ? round($totalHours / $daysPresent, 1) : 0.0;
                $grossSalary = (float) $s->rate_per_day * $daysPresent;
                $totalAdv    = (float) ($s->advances_sum_amount ?? 0);

                return [
                    'staff' => [
                        'id'          => $s->id,
                        'name'        => $s->name,
                        'role'        => $s->role,
                        'rate_per_day'=> (float) $s->rate_per_day,
                        'shift_hours' => $s->shift_hours,
                        'in_time'     => $s->attendance->last()?->in_time,
                        'out_time'    => $s->attendance->last()?->out_time,
                    ],
                    'days_present'    => $daysPresent,
                    'days_absent'     => $s->attendance->where('status', 'absent')->count(),
                    'total_hours'     => (float) $totalHours,
                    'avg_hours_per_day' => $avgHours,
                    'gross_salary'    => $grossSalary,
                    'total_advance'   => $totalAdv,
                    'net_payable'     => $grossSalary - $totalAdv,
                ];
            });

            return $this->success('Timesheet fetched!', [
                'month'   => $month,
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
