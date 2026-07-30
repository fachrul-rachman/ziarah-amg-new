<?php

use App\Http\Controllers\BookingManagementController;
use App\Http\Controllers\PublicAvailabilityController;
use App\Http\Controllers\PublicBookingController;
use App\Http\Middleware\EnsureManageableBookingToken;
use App\Http\Middleware\PreventSensitiveResponseCaching;
use App\Http\Middleware\RequireJsonBookingRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('public')
    ->middleware([
        PreventSensitiveResponseCaching::class,
        'throttle:public-availability',
    ])
    ->group(function (): void {
        Route::get('/booking-options', [PublicAvailabilityController::class, 'bookingOptions']);
        Route::get('/available-slots', [PublicAvailabilityController::class, 'availableSlots']);
    });

Route::post('/public/bookings', [PublicBookingController::class, 'store'])
    ->middleware([
        PreventSensitiveResponseCaching::class,
        RequireJsonBookingRequest::class,
        'throttle:public-booking-submission',
    ]);

Route::prefix('manage/bookings/{token}')
    ->where(['token' => '[A-Za-z0-9_-]+'])
    ->middleware(PreventSensitiveResponseCaching::class)
    ->group(function (): void {
        Route::get('/', [BookingManagementController::class, 'show'])
            ->middleware([
                'throttle:booking-management-read',
                EnsureManageableBookingToken::class,
            ]);
        Route::get('/available-slots', [BookingManagementController::class, 'availableSlots'])
            ->middleware([
                'throttle:booking-management-read',
                EnsureManageableBookingToken::class,
            ]);
        Route::put('/reschedule', [BookingManagementController::class, 'reschedule'])
            ->middleware([
                'throttle:booking-reschedule',
                EnsureManageableBookingToken::class,
                RequireJsonBookingRequest::class,
            ]);
        Route::post('/cancel', [BookingManagementController::class, 'cancel'])
            ->middleware([
                'throttle:booking-cancel',
                EnsureManageableBookingToken::class,
                RequireJsonBookingRequest::class,
            ]);
    });
