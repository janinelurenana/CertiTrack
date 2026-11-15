<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\InstructorController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\StatsController;
use Illuminate\Support\Facades\Route;

/*
 | CertiTrack REST API (JSON). Auth: Sanctum bearer token (extension / API clients)
 | or the web session cookie (Blade dashboards).
 */

// --- public ---
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/extension/pair', [AuthController::class, 'pair'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // platform registry (read-only for everyone signed in)
    Route::get('/platforms', [PlatformController::class, 'index']);
    Route::get('/platforms/config', [PlatformController::class, 'config']);

    Route::get('/stats', [StatsController::class, 'show']);

    // --- student ---
    Route::middleware('role:student')->group(function () {
        Route::post('/pairing-codes', [AuthController::class, 'createPairingCode']);
        Route::get('/extension/devices', [AuthController::class, 'devices']);
        Route::delete('/extension/devices/{id}', [AuthController::class, 'revokeDevice'])->whereNumber('id');

        Route::get('/sessions', [SessionController::class, 'index']);
        Route::get('/sessions/active', [SessionController::class, 'active']);
        Route::post('/sessions', [SessionController::class, 'store']);
        Route::middleware('throttle:extension')->group(function () {
            Route::post('/sessions/{id}/events', [SessionController::class, 'storeEvents'])->whereNumber('id');
            Route::post('/sessions/{id}/heartbeat', [SessionController::class, 'heartbeat'])->whereNumber('id');
        });
        Route::post('/sessions/{id}/end', [SessionController::class, 'end'])->whereNumber('id');
    });

    // --- shared read (student: own, instructor: assigned, admin: all) ---
    Route::get('/sessions/{id}', [SessionController::class, 'show'])->whereNumber('id');
    Route::get('/sessions/{id}/events', [SessionController::class, 'events'])->whereNumber('id');

    // --- instructor (+ admin read-only) ---
    Route::prefix('instructor')->middleware('role:instructor,admin')->group(function () {
        Route::get('/students', [InstructorController::class, 'students']);
        Route::get('/sessions', [InstructorController::class, 'sessions']);
        Route::get('/sessions/{id}/events', [InstructorController::class, 'events'])->whereNumber('id');
        Route::get('/sessions/{id}/report', [InstructorController::class, 'report'])->whereNumber('id');
        Route::middleware('role:instructor')->group(function () {
            Route::post('/sessions/{id}/review', [InstructorController::class, 'review'])->whereNumber('id');
            Route::delete('/sessions/{id}/events/{eventId}/flag', [InstructorController::class, 'unflag'])->whereNumber(['id', 'eventId']);
        });
    });

    // --- admin ---
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('/users', [AdminController::class, 'users']);
        Route::post('/users', [AdminController::class, 'storeUser']);
        Route::patch('/users/{id}', [AdminController::class, 'updateUser'])->whereNumber('id');
        Route::delete('/users/{id}', [AdminController::class, 'deleteUser'])->whereNumber('id');

        Route::get('/platforms', [AdminController::class, 'platforms']);
        Route::post('/platforms', [AdminController::class, 'storePlatform']);
        Route::patch('/platforms/{id}', [AdminController::class, 'updatePlatform'])->whereNumber('id');
        Route::delete('/platforms/{id}', [AdminController::class, 'deletePlatform'])->whereNumber('id');
        Route::post('/platforms/{id}/domains', [AdminController::class, 'storeDomain'])->whereNumber('id');
        Route::patch('/domains/{id}', [AdminController::class, 'updateDomain'])->whereNumber('id');
        Route::delete('/domains/{id}', [AdminController::class, 'deleteDomain'])->whereNumber('id');

        Route::get('/assignments', [AdminController::class, 'assignments']);
        Route::post('/assignments', [AdminController::class, 'storeAssignment']);
        Route::delete('/assignments/{id}', [AdminController::class, 'deleteAssignment'])->whereNumber('id');

        Route::get('/settings', [AdminController::class, 'settings']);
        Route::put('/settings', [AdminController::class, 'updateSettings']);
        Route::get('/audit-logs', [AdminController::class, 'auditLogs']);
        Route::post('/retention/purge', [AdminController::class, 'purge']);
    });
});
