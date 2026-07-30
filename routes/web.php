<?php

use App\Http\Controllers\Admin\AuthenticatedSessionController;
use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\TimeSlotController;
use App\Http\Controllers\Admin\ZoneController;
use App\Http\Middleware\AllowConfiguredEmbedding;
use App\Http\Middleware\PreventSensitiveResponseCaching;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('booking', [
    'turnstileSiteKey' => config('booking.turnstile_site_key'),
]))
    ->middleware(AllowConfiguredEmbedding::class)
    ->name('home');

Route::get('/embed/booking', fn () => Inertia::render('booking', [
    'turnstileSiteKey' => config('booking.turnstile_site_key'),
]))
    ->middleware(AllowConfiguredEmbedding::class)
    ->name('booking.embed');

Route::get(
    '/manage/{token}',
    fn (string $token) => Inertia::render('manage-booking', ['token' => $token]),
)
    ->where('token', '[A-Za-z0-9_-]+')
    ->middleware(PreventSensitiveResponseCaching::class)
    ->name('booking.manage');

Route::middleware(['guest', PreventSensitiveResponseCaching::class])->group(function () {
    Route::get('/admin/login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');
    Route::post('/admin/login', [AuthenticatedSessionController::class, 'store'])
        ->name('admin.login.store');
});

Route::prefix('admin')->middleware([
    'auth',
    PreventSensitiveResponseCaching::class,
])->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('admin.logout');

    Route::middleware('can:manage-bookings')->group(function () {
        Route::get('/', [BookingController::class, 'index'])
            ->name('admin.dashboard');
        Route::get('/bookings/export', [BookingController::class, 'export'])
            ->name('admin.bookings.export');
        Route::get('/bookings/{booking}', [BookingController::class, 'show'])
            ->name('admin.bookings.show');
        Route::put('/bookings/{booking}', [BookingController::class, 'update'])
            ->name('admin.bookings.update');
        Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])
            ->name('admin.bookings.cancel');
    });

    Route::middleware('can:manage-admin-configuration')->group(function () {
        Route::resource('zones', ZoneController::class)
            ->only(['index', 'store', 'update'])
            ->names('admin.zones');
        Route::resource('time-slots', TimeSlotController::class)
            ->only(['index', 'store', 'update'])
            ->names('admin.time-slots');
        Route::get('/settings', [SettingController::class, 'edit'])
            ->name('admin.settings.edit');
        Route::put('/settings', [SettingController::class, 'update'])
            ->name('admin.settings.update');
    });
});
