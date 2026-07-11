<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\MeterReadingController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaffAttendanceController;
use App\Http\Controllers\StaffController;
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

    // Sub-user management
    Route::post('/sub-users',         [UserController::class, 'indexSubUsers']);
    Route::post('/add-sub-user',      [UserController::class, 'storeSubUser']);
    Route::post('/sub-users-details', [UserController::class, 'showSubUser']);
    Route::post('/update-sub-user',   [UserController::class, 'updateSubUser']);
    Route::post('/delete-sub-user',   [UserController::class, 'destroySubUser']);
});

// -------------------------------------------------
// Settings routes (owner + manager access)
// -------------------------------------------------
Route::middleware('auth:sanctum')->prefix('settings')->group(function () {
    // Station details
    Route::get('/',                    [SettingsController::class, 'getStation']);
    Route::put('/',                    [SettingsController::class, 'updateStation']);

    // Fuel rates
    Route::get('/fuel-rates',          [SettingsController::class, 'getFuelRates']);
    Route::put('/fuel-rates',          [SettingsController::class, 'updateFuelRates']);

    // Nozzles
    Route::get('/nozzles',             [SettingsController::class, 'getNozzles']);
    Route::post('/nozzles',            [SettingsController::class, 'storeNozzle']);
    Route::put('/nozzles/{id}',        [SettingsController::class, 'updateNozzle']);
    Route::delete('/nozzles/{id}',     [SettingsController::class, 'destroyNozzle']);

    // Notification preferences
    Route::get('/notifications',       [SettingsController::class, 'getNotifications']);
    Route::put('/notifications',       [SettingsController::class, 'updateNotifications']);
});

// -------------------------------------------------
// Dashboard routes (aggregates from sales + meters)
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
// Expense routes (owner + manager access)
// Fixed paths (categories, summary) registered before {id}
// -------------------------------------------------
Route::middleware('auth:sanctum')->prefix('expenses')->group(function () {
    Route::get('/categories', [ExpenseController::class, 'categories']);
    Route::get('/summary',    [ExpenseController::class, 'summary']);
    Route::get('/',           [ExpenseController::class, 'index']);
    Route::post('/',          [ExpenseController::class, 'store']);
    Route::get('/{id}',       [ExpenseController::class, 'show']);
    Route::put('/{id}',       [ExpenseController::class, 'update']);
    Route::delete('/{id}',    [ExpenseController::class, 'destroy']);
});

// -------------------------------------------------
// Sale routes (owner + manager access)
// summary/monthly registered before {id} to avoid wildcard match
// -------------------------------------------------
Route::middleware('auth:sanctum')->prefix('sales')->group(function () {
    Route::get('/summary', [SaleController::class, 'summary']);
    Route::get('/monthly', [SaleController::class, 'monthly']);
    Route::get('/',        [SaleController::class, 'index']);
    Route::post('/',       [SaleController::class, 'store']);
    Route::get('/{id}',    [SaleController::class, 'show']);
    Route::put('/{id}',    [SaleController::class, 'update']);
    Route::delete('/{id}', [SaleController::class, 'destroy']);
});

// -------------------------------------------------
// Meter reading routes (owner + manager access)
// summary/nozzles registered before {id} to avoid wildcard match
// -------------------------------------------------
Route::middleware('auth:sanctum')->prefix('meters')->group(function () {
    Route::get('/summary', [MeterReadingController::class, 'summary']);
    Route::get('/nozzles', [MeterReadingController::class, 'nozzles']);
    Route::get('/',        [MeterReadingController::class, 'index']);
    Route::post('/',       [MeterReadingController::class, 'store']);
    Route::get('/{id}',    [MeterReadingController::class, 'show']);
    Route::put('/{id}',    [MeterReadingController::class, 'update']);
    Route::delete('/{id}', [MeterReadingController::class, 'destroy']);
});

// -------------------------------------------------
// Transaction routes (owner + manager access)
// summary registered before {id} to avoid wildcard match
// -------------------------------------------------
Route::middleware('auth:sanctum')->prefix('transactions')->group(function () {
    Route::get('/summary', [TransactionController::class, 'summary']);
    Route::get('/',        [TransactionController::class, 'index']);
    Route::post('/',       [TransactionController::class, 'store']);
    Route::get('/{id}',    [TransactionController::class, 'show']);
    Route::put('/{id}',    [TransactionController::class, 'update']);
    Route::delete('/{id}', [TransactionController::class, 'destroy']);
});

// -------------------------------------------------
// Staff routes (owner + manager access)
// ALL fixed paths must be registered BEFORE {id} routes
// to avoid Laravel matching 'advances', 'attendance',
// 'timesheet' as the {id} wildcard.
// -------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {

    // ── Advances ──────────────────────────────────
    Route::get('/staff/advances',  [StaffController::class, 'getAdvances']);
    Route::post('/staff/advances', [StaffController::class, 'addAdvance']);

    // ── Attendance (fixed paths first) ────────────
    Route::get('/staff/attendance',        [StaffAttendanceController::class, 'index']);
    Route::post('/staff/attendance/bulk',  [StaffAttendanceController::class, 'bulk']);
    Route::post('/staff/attendance',       [StaffAttendanceController::class, 'store']);
    Route::get('/staff/attendance/{id}',   [StaffAttendanceController::class, 'show']);
    Route::put('/staff/attendance/{id}',   [StaffAttendanceController::class, 'update']);
    Route::delete('/staff/attendance/{id}',[StaffAttendanceController::class, 'destroy']);

    // ── Time sheet monthly summary ─────────────────
    Route::get('/staff/timesheet',         [StaffAttendanceController::class, 'timesheet']);

    // ── Staff CRUD (parameterised — must be last) ─
    Route::get('/staff',           [StaffController::class, 'index']);
    Route::post('/staff',          [StaffController::class, 'store']);
    Route::get('/staff/{id}',      [StaffController::class, 'show']);
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