<?php

use App\Http\Controllers\AdminController;
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
    Route::post('/sub-users',            [UserController::class, 'indexSubUsers']);
    Route::post('/add-sub-user',           [UserController::class, 'storeSubUser']);
    Route::post('/sub-users-details',       [UserController::class, 'showSubUser']);
    Route::post('/sub-users/{id}',       [UserController::class, 'updateSubUser']);
    Route::post('/sub-users/{id}',    [UserController::class, 'destroySubUser']);
});
