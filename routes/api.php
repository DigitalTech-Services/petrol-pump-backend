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
    Route::get('/users',          [AdminController::class, 'indexUsers']);
    Route::post('/users',         [AdminController::class, 'storeUser']);
    Route::get('/users/{id}',     [AdminController::class, 'showUser']);
    Route::put('/users/{id}',     [AdminController::class, 'updateUser']);
    Route::delete('/users/{id}',  [AdminController::class, 'destroyUser']);
});

// -------------------------------------------------
// User routes
// -------------------------------------------------
Route::post('/user/login', [UserController::class, 'login']);

Route::middleware('auth:sanctum')->prefix('user')->group(function () {
    Route::post('/logout',              [UserController::class, 'logout']);
    Route::get('/profile',              [UserController::class, 'profile']);

    // Sub-user management
    Route::get('/sub-users',            [UserController::class, 'indexSubUsers']);
    Route::post('/sub-users',           [UserController::class, 'storeSubUser']);
    Route::get('/sub-users/{id}',       [UserController::class, 'showSubUser']);
    Route::put('/sub-users/{id}',       [UserController::class, 'updateSubUser']);
    Route::delete('/sub-users/{id}',    [UserController::class, 'destroySubUser']);
});
