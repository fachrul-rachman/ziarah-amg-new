<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingManagementToken;
use App\Models\Setting;
use App\Models\TimeSlot;
use App\Models\Zone;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse(
        '2026-07-30 11:00:00',
        'Asia/Jakarta',
    ));

    Zone::query()->create(['name' => 'Mawar', 'is_active' => true]);
    TimeSlot::query()->create(['start_time' => '12:00:00', 'is_active' => true]);
    Setting::query()->create([
        'id' => 1,
        'daily_booking_limit' => 20,
        'operations_email' => 'ops@example.com',
        'embed_allowed_origins' => [],
    ]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('command completes only due confirmed bookings and revokes management access', function () {
    $due = phaseTenBooking(['visit_time' => '10:00:00']);
    $future = phaseTenBooking(['visit_time' => '10:00:01']);
    $cancelled = phaseTenBooking([
        'visit_time' => '09:00:00',
        'status' => BookingStatus::Cancelled,
        'cancelled_at' => now(),
    ]);
    $token = 'phase-ten-secret';
    $tokenRecord = BookingManagementToken::query()->create([
        'booking_id' => $due->id,
        'token_hash' => hash('sha256', $token),
    ]);

    $this->artisan('bookings:complete')
        ->expectsOutput('1 booking completed.')
        ->assertSuccessful();
    $this->artisan('bookings:complete')
        ->expectsOutput('0 bookings completed.')
        ->assertSuccessful();

    expect($due->fresh()->status)->toBe(BookingStatus::Completed)
        ->and($due->fresh()->completed_at?->equalTo(now()->utc()))->toBeTrue()
        ->and($future->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($cancelled->fresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($tokenRecord->fresh()->revoked_at?->equalTo(now()->utc()))->toBeTrue()
        ->and(app(BookingService::class)->findManageableToken(
            $token,
            CarbonImmutable::now(),
            false,
        ))->toBeNull();
});

test('completed bookings cannot be modified cancelled or rescheduled', function () {
    $booking = phaseTenBooking(['visit_time' => '10:00:00']);
    $token = 'phase-ten-secret';
    BookingManagementToken::query()->create([
        'booking_id' => $booking->id,
        'token_hash' => hash('sha256', $token),
    ]);
    $service = app(BookingService::class);

    $service->completeDue(CarbonImmutable::now());
    $booking->refresh();

    expect($service->canAdminModify($booking, CarbonImmutable::now()))->toBeFalse()
        ->and($service->canCancel($booking, CarbonImmutable::now()))->toBeFalse()
        ->and($service->canReschedule($booking, CarbonImmutable::now()))->toBeFalse()
        ->and(fn () => $service->cancel($token, CarbonImmutable::now()))
        ->toThrow(DomainException::class)
        ->and(fn () => $service->reschedule(
            $token,
            [
                'visit_date' => '2026-07-31',
                'visit_time' => '12:00:00',
                'zone_id' => Zone::query()->sole()->id,
                'lot_number' => 'A102',
                'tent_required' => false,
                'chair_count' => 2,
                'additional_notes' => null,
            ],
            Setting::query()->findOrFail(1),
            CarbonImmutable::now(),
        ))->toThrow(DomainException::class);
});

test('automatic completion is scheduled every five minutes in Jakarta', function () {
    $event = collect(Schedule::events())
        ->firstWhere('description', 'bookings:complete');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->timezone)->toBe('Asia/Jakarta');
});

/** @param array<string, mixed> $overrides */
function phaseTenBooking(array $overrides = []): Booking
{
    $zone = Zone::query()->sole();

    return Booking::query()->create(array_replace([
        'public_reference' => (string) Str::uuid(),
        'status' => BookingStatus::Confirmed,
        'visit_date' => '2026-07-30',
        'visit_time' => '09:00:00',
        'zone_id' => $zone->id,
        'zone_name_snapshot' => $zone->name,
        'lot_number' => 'A101',
        'tent_required' => false,
        'chair_count' => 2,
        'customer_name' => 'Customer',
        'customer_email' => 'customer@example.com',
        'customer_phone' => '08123456789',
        'deceased_name' => 'Deceased',
        'ethics_confirmed_at' => now(),
        'cancelled_at' => null,
        'completed_at' => null,
    ], $overrides));
}
