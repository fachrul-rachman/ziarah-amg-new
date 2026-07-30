<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\BookingService;
use Carbon\CarbonImmutable;

function bookingAt(string $date, string $time): Booking
{
    return new Booking([
        'status' => BookingStatus::Confirmed,
        'visit_date' => $date,
        'visit_time' => $time,
    ]);
}

test('visit dates range from H plus 1 through H plus 100 in Jakarta', function () {
    $now = CarbonImmutable::parse('2026-07-29 10:00:00', 'Asia/Jakarta');
    $service = app(BookingService::class);

    expect($service->isWithinDateWindow('2026-07-29', $now))->toBeFalse()
        ->and($service->isWithinDateWindow('2026-07-30', $now))->toBeTrue()
        ->and($service->isWithinDateWindow($now->addDays(100)->toDateString(), $now))->toBeTrue()
        ->and($service->isWithinDateWindow($now->addDays(101)->toDateString(), $now))->toBeFalse();
});

test('the exact 18 hour lead time is allowed without rounding', function () {
    $service = app(BookingService::class);
    $now = CarbonImmutable::parse('2026-07-29 15:00:00', 'Asia/Jakarta');

    expect($service->meetsLeadTime('2026-07-30', '08:59:59', $now))->toBeFalse()
        ->and($service->meetsLeadTime('2026-07-30', '09:00:00', $now))->toBeTrue();
});

test('slots respect the exact lead time from 15:30', function () {
    $service = app(BookingService::class);
    $now = CarbonImmutable::parse('2026-07-29 15:30:00', 'Asia/Jakarta');

    expect($service->meetsLeadTime('2026-07-30', '09:00:00', $now))->toBeFalse()
        ->and($service->meetsLeadTime('2026-07-30', '10:00:00', $now))->toBeTrue();
});

test('morning reschedule closes at 15:00 on H minus 1', function () {
    $service = app(BookingService::class);
    $booking = bookingAt('2026-07-31', '11:00:00');

    expect($service->canReschedule(
        $booking,
        CarbonImmutable::parse('2026-07-30 14:59:59', 'Asia/Jakarta'),
    ))->toBeTrue()
        ->and($service->canReschedule(
            $booking,
            CarbonImmutable::parse('2026-07-30 15:00:00', 'Asia/Jakarta'),
        ))->toBeFalse();
});

test('afternoon reschedule closes at 07:00 on the visit date', function () {
    $service = app(BookingService::class);
    $booking = bookingAt('2026-07-31', '12:00:00');

    expect($service->canReschedule(
        $booking,
        CarbonImmutable::parse('2026-07-31 06:59:59', 'Asia/Jakarta'),
    ))->toBeTrue()
        ->and($service->canReschedule(
            $booking,
            CarbonImmutable::parse('2026-07-31 07:00:00', 'Asia/Jakarta'),
        ))->toBeFalse();
});

test('cancellation closes exactly one hour before the visit', function () {
    $service = app(BookingService::class);
    $booking = bookingAt('2026-07-31', '10:00:00');

    expect($service->canCancel(
        $booking,
        CarbonImmutable::parse('2026-07-31 08:59:59', 'Asia/Jakarta'),
    ))->toBeTrue()
        ->and($service->canCancel(
            $booking,
            CarbonImmutable::parse('2026-07-31 09:00:00', 'Asia/Jakarta'),
        ))->toBeFalse();
});

test('confirmed bookings complete exactly one hour after visit start', function () {
    $service = app(BookingService::class);
    $booking = bookingAt('2026-07-31', '10:00:00');

    expect($service->shouldComplete(
        $booking,
        CarbonImmutable::parse('2026-07-31 10:59:59', 'Asia/Jakarta'),
    ))->toBeFalse()
        ->and($service->shouldComplete(
            $booking,
            CarbonImmutable::parse('2026-07-31 11:00:00', 'Asia/Jakarta'),
        ))->toBeTrue();
});
