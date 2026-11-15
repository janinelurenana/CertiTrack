<?php

use App\Http\Controllers\Web\WebAuthController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => Auth::check() ? redirect(WebAuthController::home(Auth::user())) : redirect('/login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [WebAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [WebAuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/register', [WebAuthController::class, 'showRegister']);
    Route::post('/register', [WebAuthController::class, 'register'])->middleware('throttle:login');
});
Route::post('/logout', [WebAuthController::class, 'logout'])->middleware('auth');

// Neutral page used to try the extension without a real certification platform (NOT an exam system).
Route::view('/demo-target', 'demo-target');

Route::middleware('auth')->group(function () {
    // role gating is enforced again by the API; these checks keep each role on its own pages.
    Route::middleware('role:student')->group(function () {
        Route::view('/student', 'student.dashboard');
        Route::view('/student/privacy', 'student.privacy');
    });
    Route::middleware('role:instructor,admin')->group(function () {
        Route::view('/instructor', 'instructor.dashboard');
        Route::get('/reports/sessions/{id}', fn (int $id) => view('sessions.report', ['id' => $id]))->whereNumber('id');
    });
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::view('/', 'admin.dashboard');
        Route::view('/users', 'admin.users');
        Route::view('/platforms', 'admin.platforms');
        Route::view('/assignments', 'admin.assignments');
        Route::view('/settings', 'admin.settings');
        Route::view('/audit', 'admin.audit');
    });
    Route::get('/sessions/{id}', fn (int $id) => view('sessions.show', ['id' => $id]))->whereNumber('id');
});
