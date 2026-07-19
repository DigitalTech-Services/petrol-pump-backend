<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\MeterReadingController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaffAttendanceController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StationController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

use Illuminate\Support\Facades\DB;

Route::get('/ping', function () {
    try {
        DB::connection()->getPdo();

        return response()->json([
            'status' => 'Connected',
            'host' => config('database.connections.mysql.host'),
            'database' => config('database.connections.mysql.database'),
            'username' => config('database.connections.mysql.username'),
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'host' => config('database.connections.mysql.host'),
            'database' => config('database.connections.mysql.database'),
        ], 500);
    }
});

use App\Models\User;

Route::get('/ping2', function () {
    try {
        return [
            "count" => User::count(),
            "first" => User::first()
        ];
    } catch (\Throwable $e) {
        return [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
            "trace" => $e->getTraceAsString()
        ];
    }
});

// -------------------------------------------------
// Admin routes
// -------------------------------------------------
Route::post('/admin/login', [AdminController::class, 'login']);

Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::post('/logout',        [AdminController::class, 'logout']);
    Route::post('/users',          [AdminController::class, 'indexUsers']);
    Route::post('/add-user',         [AdminController::class, 'storeUser']);
    Route::post('/get-user-details',     [AdminController::class, 'showUser']);
    Route::post('/update-user',     [AdminController::class, 'updateUser']);
    Route::post('/delete-user',  [AdminController::class, 'destroyUser']);
});

// -------------------------------------------------
// User routes
// -------------------------------------------------
Route::post('/user/login', [UserController::class, 'login']);

Route::middleware('auth:sanctum')->prefix('user')->group(function () {
    Route::post('/logout',              [UserController::class, 'logout']);
    Route::post('/profile',              [UserController::class, 'profile']);
    Route::put('/profile',               [UserController::class, 'updateProfile']);

    // Sub-user (manager) management — owner only
    Route::middleware('role:user')->group(function () {
        Route::post('/sub-users',         [UserController::class, 'indexSubUsers']);
        Route::post('/add-sub-user',      [UserController::class, 'storeSubUser']);
        Route::post('/sub-users-details', [UserController::class, 'showSubUser']);
        Route::post('/update-sub-user',   [UserController::class, 'updateSubUser']);
        Route::post('/delete-sub-user',   [UserController::class, 'destroySubUser']);
    });
});

// -------------------------------------------------
// Station routes (owner only — a manager cannot manage stations)
// -------------------------------------------------
Route::middleware(['auth:sanctum', 'role:user'])->prefix('stations')->group(function () {
    Route::get('/',        [StationController::class, 'index']);
    Route::post('/',       [StationController::class, 'store']);
    Route::put('/{id}',    [StationController::class, 'update']);
    Route::delete('/{id}', [StationController::class, 'destroy']);
});

// -------------------------------------------------
// Settings routes
// Reads (station/fuel-rates/nozzles) are owner + manager — owner views the
// selected station's config read-only. Writes and personal notification
// preferences stay manager-only.
// -------------------------------------------------
Route::middleware(['auth:sanctum', 'role:user,sub_user'])->prefix('settings')->group(function () {
    Route::get('/',            [SettingsController::class, 'getStation']);
    Route::get('/fuel-rates',  [SettingsController::class, 'getFuelRates']);
    Route::get('/nozzles',     [SettingsController::class, 'getNozzles']);
});

Route::middleware(['auth:sanctum', 'role:sub_user'])->prefix('settings')->group(function () {
    // Station details
    Route::put('/',                    [SettingsController::class, 'updateStation']);

    // Fuel rates
    Route::put('/fuel-rates',          [SettingsController::class, 'updateFuelRates']);

    // Nozzles
    Route::post('/nozzles',            [SettingsController::class, 'storeNozzle']);
    Route::put('/nozzles/{id}',        [SettingsController::class, 'updateNozzle']);
    Route::delete('/nozzles/{id}',     [SettingsController::class, 'destroyNozzle']);

    // Notification preferences (manager-personal, not station data)
    Route::get('/notifications',       [SettingsController::class, 'getNotifications']);
    Route::put('/notifications',       [SettingsController::class, 'updateNotifications']);
});

// -------------------------------------------------
// Dashboard routes (owner + manager access)
// Manager sees only their own stats; owner sees all managers combined.
// -------------------------------------------------
Route::middleware('auth:sanctum')->prefix('dashboard')->group(function () {
    Route::get('/summary',       [DashboardController::class, 'summary']);      // consolidated — one DB query
    Route::get('/kpis',          [DashboardController::class, 'kpis']);
    Route::get('/daily-trend',   [DashboardController::class, 'dailyTrend']);
    Route::get('/fuel-mix',      [DashboardController::class, 'fuelMix']);
    Route::get('/payment-split', [DashboardController::class, 'paymentSplit']);
    Route::get('/stock-levels',  [DashboardController::class, 'stockLevels']);
});

// -------------------------------------------------
// Reports routes (owner + manager access)
// Manager sees only their own figures; owner sees all managers combined.
// -------------------------------------------------
Route::middleware('auth:sanctum')->prefix('reports')->group(function () {
    Route::get('/monthly', [ReportsController::class, 'monthly']);
    Route::get('/fuel',    [ReportsController::class, 'fuel']);
    Route::get('/pnl',     [ReportsController::class, 'pnl']);
    Route::get('/staff',   [ReportsController::class, 'staff']);
});

// -------------------------------------------------
// Expense routes
// Reads: owner + manager (owner views the selected station's expenses).
// Writes: manager only. Fixed paths registered before {id}.
// -------------------------------------------------
Route::middleware(['auth:sanctum', 'role:user,sub_user'])->prefix('expenses')->group(function () {
    Route::get('/categories',      [ExpenseController::class, 'categories']);
    Route::get('/summary',         [ExpenseController::class, 'summary']);
    Route::get('/total-for-date',  [ExpenseController::class, 'totalForDate']);
    Route::get('/',                [ExpenseController::class, 'index']);
    Route::get('/{id}',            [ExpenseController::class, 'show']);
});
Route::middleware(['auth:sanctum', 'role:sub_user'])->prefix('expenses')->group(function () {
    Route::post('/',          [ExpenseController::class, 'store']);
    Route::put('/{id}',       [ExpenseController::class, 'update']);
    Route::delete('/{id}',    [ExpenseController::class, 'destroy']);
});

// -------------------------------------------------
// Sale routes
// Reads: owner + manager. Writes: manager only.
// summary/monthly registered before {id} to avoid wildcard match
// -------------------------------------------------
Route::middleware(['auth:sanctum', 'role:user,sub_user'])->prefix('sales')->group(function () {
    Route::get('/summary', [SaleController::class, 'summary']);
    Route::get('/monthly', [SaleController::class, 'monthly']);
    Route::get('/',        [SaleController::class, 'index']);
    Route::get('/{id}',    [SaleController::class, 'show']);
});
Route::middleware(['auth:sanctum', 'role:sub_user'])->prefix('sales')->group(function () {
    Route::post('/',       [SaleController::class, 'store']);
    Route::put('/{id}',    [SaleController::class, 'update']);
    Route::delete('/{id}', [SaleController::class, 'destroy']);
});

// -------------------------------------------------
// Stock routes
// Reads: owner + manager. Writes: manager only.
// summary/tankwise registered before {id} to avoid wildcard match
// -------------------------------------------------
Route::middleware(['auth:sanctum', 'role:user,sub_user'])->prefix('stock')->group(function () {
    Route::get('/summary',  [StockController::class, 'summary']);
    Route::get('/tankwise', [StockController::class, 'tankwise']);
    Route::get('/',         [StockController::class, 'index']);
    Route::get('/{id}',     [StockController::class, 'show']);
});
Route::middleware(['auth:sanctum', 'role:sub_user'])->prefix('stock')->group(function () {
    Route::post('/',        [StockController::class, 'store']);
    Route::put('/{id}',     [StockController::class, 'update']);
    Route::delete('/{id}',  [StockController::class, 'destroy']);
});

// -------------------------------------------------
// Meter reading routes
// Reads: owner + manager. Writes: manager only.
// summary/nozzles registered before {id} to avoid wildcard match
// -------------------------------------------------
Route::middleware(['auth:sanctum', 'role:user,sub_user'])->prefix('meters')->group(function () {
    Route::get('/summary',       [MeterReadingController::class, 'summary']);
    Route::get('/nozzles',       [MeterReadingController::class, 'nozzles']);
    Route::get('/last-readings', [MeterReadingController::class, 'lastReadings']);
    Route::get('/',              [MeterReadingController::class, 'index']);
    Route::get('/{id}',          [MeterReadingController::class, 'show']);
});
Route::middleware(['auth:sanctum', 'role:sub_user'])->prefix('meters')->group(function () {
    Route::post('/',       [MeterReadingController::class, 'store']);
    Route::put('/{id}',    [MeterReadingController::class, 'update']);
    Route::delete('/{id}', [MeterReadingController::class, 'destroy']);
});

// -------------------------------------------------
// Transaction routes
// Reads: owner + manager. Writes: manager only.
// summary registered before {id} to avoid wildcard match
// -------------------------------------------------
Route::middleware(['auth:sanctum', 'role:user,sub_user'])->prefix('transactions')->group(function () {
    Route::get('/summary', [TransactionController::class, 'summary']);
    Route::get('/',        [TransactionController::class, 'index']);
    Route::get('/{id}',    [TransactionController::class, 'show']);
});
Route::middleware(['auth:sanctum', 'role:sub_user'])->prefix('transactions')->group(function () {
    Route::post('/',       [TransactionController::class, 'store']);
    Route::put('/{id}',    [TransactionController::class, 'update']);
    Route::delete('/{id}', [TransactionController::class, 'destroy']);
});

// -------------------------------------------------
// Staff routes
// Reads (staff list, advances list, attendance, timesheet): owner + manager.
// Writes (CRUD, advances, attendance marking): manager only.
// ALL fixed paths must be registered BEFORE {id} routes
// to avoid Laravel matching 'advances', 'attendance',
// 'timesheet' as the {id} wildcard.
// -------------------------------------------------
Route::middleware(['auth:sanctum', 'role:user,sub_user'])->group(function () {
    Route::get('/staff/advances',  [StaffController::class, 'getAdvances']);
    Route::get('/staff/attendance',      [StaffAttendanceController::class, 'index']);
    Route::get('/staff/attendance/{id}', [StaffAttendanceController::class, 'show']);
    Route::get('/staff/timesheet',       [StaffAttendanceController::class, 'timesheet']);
    Route::get('/staff',      [StaffController::class, 'index']);
    Route::get('/staff/{id}', [StaffController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'role:sub_user'])->group(function () {

    // ── Advances ──────────────────────────────────
    Route::post('/staff/advances', [StaffController::class, 'addAdvance']);

    // ── Attendance (fixed paths first) ────────────
    Route::post('/staff/attendance/bulk',  [StaffAttendanceController::class, 'bulk']);
    Route::post('/staff/attendance',       [StaffAttendanceController::class, 'store']);
    Route::put('/staff/attendance/{id}',   [StaffAttendanceController::class, 'update']);
    Route::delete('/staff/attendance/{id}',[StaffAttendanceController::class, 'destroy']);

    // ── Staff CRUD (parameterised — must be last) ─
    Route::post('/staff',          [StaffController::class, 'store']);
    Route::put('/staff/{id}',      [StaffController::class, 'update']);
    Route::delete('/staff/{id}',   [StaffController::class, 'destroy']);
});

Route::get('/debug', function () {
    try {
        DB::connection()->getPdo();

        return response()->json([
            'status' => 'Connected',
            'host' => config('database.connections.mysql.host'),
            'database' => config('database.connections.mysql.database'),
            'user' => config('database.connections.mysql.username'),
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'type' => get_class($e),
        ], 500);
    }
});