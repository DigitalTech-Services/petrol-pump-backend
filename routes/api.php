<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\StaffAttendanceController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

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

    // ── Timesheet monthly summary ─────────────────
    Route::get('/staff/timesheet',         [StaffAttendanceController::class, 'timesheet']);

    // ── Staff CRUD (parameterised — must be last) ─
    Route::get('/staff',           [StaffController::class, 'index']);
    Route::post('/staff',          [StaffController::class, 'store']);
    Route::get('/staff/{id}',      [StaffController::class, 'show']);
    Route::put('/staff/{id}',      [StaffController::class, 'update']);
    Route::delete('/staff/{id}',   [StaffController::class, 'destroy']);
});
