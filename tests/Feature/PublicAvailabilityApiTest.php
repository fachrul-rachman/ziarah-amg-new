<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Setting;
use App\Models\TimeSlot;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse(
        '2026-07-29 15:30:00',
        'Asia/Jakarta',
    ));

    Setting::query()->create([
        'id' => 1,
        'daily_booking_limit' => 1,
        'operations_email' => 'ops@example.com',
        'embed_allowed_origins' => [],
    ]);

    Zone::query()->create(['name' => 'Mawar', 'is_active' => true]);
    Zone::query()->create(['name' => 'Melati', 'is_active' => false]);
    TimeSlot::query()->create(['start_time' => '09:00:00', 'is_active' => true]);
    TimeSlot::query()->create(['start_time' => '10:00:00', 'is_active' => true]);
    TimeSlot::query()->create(['start_time' => '11:00:00', 'is_active' => false]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    RateLimiter::clear('public-availability:127.0.0.1');
});

test('booking options expose only active configuration and the Jakarta date window', function () {
    $response = $this->getJson('/api/public/booking-options');

    $response->assertOk()
        ->assertJsonPath('server_time', '2026-07-29T15:30:00+07:00')
        ->assertJsonPath('earliest_date', '2026-07-30')
        ->assertJsonPath('latest_date', '2026-11-06')
        ->assertJsonPath('daily_booking_limit', 1)
        ->assertJsonCount(100, 'dates')
        ->assertJsonCount(1, 'zones')
        ->assertJsonPath('zones.0.name', 'Mawar')
        ->assertJsonCount(2, 'time_slots')
        ->assertJsonPath('time_slots.0.start_time', '09:00');
});

test('booking options can limit the calendar range and search active zones', function () {
    $response = $this->getJson(
        '/api/public/booking-options?start_date=2026-08-01&end_date=2026-08-03&zone_search=war',
    );

    $response->assertOk()
        ->assertJsonCount(3, 'dates')
        ->assertJsonCount(1, 'zones')
        ->assertJsonPath('zones.0.name', 'Mawar');
});

test('booking options use the configured maximum booking window', function () {
    Setting::query()->whereKey(1)->update(['booking_window_days' => 7]);

    $this->getJson('/api/public/booking-options')
        ->assertOk()
        ->assertJsonPath('latest_date', '2026-08-05')
        ->assertJsonPath('booking_window_days', 7)
        ->assertJsonCount(7, 'dates');

    $this->getJson('/api/public/booking-options?end_date=2026-08-06')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end_date');
});

test('booking options mark a date full from confirmed bookings only', function () {
    $zone = Zone::query()->where('name', 'Mawar')->firstOrFail();
    createAvailabilityBooking($zone, '2026-08-01', BookingStatus::Confirmed);
    createAvailabilityBooking($zone, '2026-08-02', BookingStatus::Cancelled);

    $response = $this->getJson(
        '/api/public/booking-options?start_date=2026-08-01&end_date=2026-08-02',
    );

    $response->assertOk()
        ->assertJsonPath('dates.0.date', '2026-08-01')
        ->assertJsonPath('dates.0.is_full', true)
        ->assertJsonPath('dates.0.is_available', false)
        ->assertJsonPath('dates.1.date', '2026-08-02')
        ->assertJsonPath('dates.1.is_full', false)
        ->assertJsonPath('dates.1.is_available', true);
});

test('calendar range must stay within H plus 1 through H plus 100', function (
    array $query,
    string $errorField,
) {
    $this->getJson('/api/public/booking-options?'.http_build_query($query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorField);
})->with([
    'start before H plus 1' => [['start_date' => '2026-07-29'], 'start_date'],
    'end after H plus 100' => [['end_date' => '2026-11-07'], 'end_date'],
    'end before start' => [[
        'start_date' => '2026-08-02',
        'end_date' => '2026-08-01',
    ], 'end_date'],
]);

test('available slots apply the exact 18 hour boundary', function () {
    $response = $this->getJson('/api/public/available-slots?date=2026-07-30');

    $response->assertOk()
        ->assertJsonPath('date', '2026-07-30')
        ->assertJsonPath('is_full', false)
        ->assertJsonPath('slots.0.start_time', '09:00')
        ->assertJsonPath('slots.0.is_available', false)
        ->assertJsonPath('slots.0.disabled_reason', 'minimum_lead_time')
        ->assertJsonPath('slots.1.start_time', '10:00')
        ->assertJsonPath('slots.1.is_available', true)
        ->assertJsonPath('slots.1.disabled_reason', null);
});

test('available slots are disabled when the selected date is full', function () {
    $zone = Zone::query()->where('name', 'Mawar')->firstOrFail();
    createAvailabilityBooking($zone, '2026-08-01', BookingStatus::Confirmed);

    $response = $this->getJson('/api/public/available-slots?date=2026-08-01');

    $response->assertOk()
        ->assertJsonPath('is_full', true)
        ->assertJsonPath('slots.0.is_available', false)
        ->assertJsonPath('slots.0.disabled_reason', 'date_full')
        ->assertJsonPath('slots.1.is_available', false)
        ->assertJsonPath('slots.1.disabled_reason', 'date_full');
});

test('hourly quota disables only full slots until every slot is full', function () {
    Setting::query()->whereKey(1)->update([
        'booking_limit_mode' => 'hourly',
        'daily_booking_limit' => 1,
        'hourly_booking_limit' => 1,
    ]);
    $zone = Zone::query()->where('name', 'Mawar')->firstOrFail();
    createAvailabilityBooking($zone, '2026-08-01', BookingStatus::Confirmed);

    $this->getJson(
        '/api/public/booking-options?start_date=2026-08-01&end_date=2026-08-01',
    )
        ->assertOk()
        ->assertJsonPath('dates.0.is_full', false);

    $this->getJson('/api/public/available-slots?date=2026-08-01')
        ->assertOk()
        ->assertJsonPath('is_full', false)
        ->assertJsonPath('slots.0.start_time', '09:00')
        ->assertJsonPath('slots.0.is_available', true)
        ->assertJsonPath('slots.1.start_time', '10:00')
        ->assertJsonPath('slots.1.is_available', false)
        ->assertJsonPath('slots.1.disabled_reason', 'slot_full');

    createAvailabilityBooking(
        $zone,
        '2026-08-01',
        BookingStatus::Confirmed,
        '09:00:00',
    );

    $this->getJson(
        '/api/public/booking-options?start_date=2026-08-01&end_date=2026-08-01',
    )
        ->assertOk()
        ->assertJsonPath('dates.0.is_full', true);
});

test('available slots reject missing malformed and out of range dates', function (?string $date) {
    $query = $date === null ? '' : '?date='.urlencode($date);

    $this->getJson('/api/public/available-slots'.$query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');
})->with([
    'missing' => [null],
    'malformed' => ['tomorrow'],
    'today' => ['2026-07-29'],
    'after H plus 100' => ['2026-11-07'],
]);

test('availability fails closed when global settings are missing', function () {
    Setting::query()->delete();

    $this->getJson('/api/public/booking-options')
        ->assertServiceUnavailable()
        ->assertExactJson([
            'message' => 'Booking availability is not configured.',
        ]);
});

test('public availability endpoints share a configurable rate limit', function () {
    config()->set('booking.public_availability_rate_limit', 2);

    $this->getJson('/api/public/booking-options')->assertOk();
    $this->getJson('/api/public/available-slots?date=2026-07-30')->assertOk();
    $this->getJson('/api/public/booking-options')
        ->assertTooManyRequests()
        ->assertJsonPath('message', 'Too many availability requests. Please try again shortly.');
});

function createAvailabilityBooking(
    Zone $zone,
    string $date,
    BookingStatus $status,
    string $time = '10:00:00',
): Booking {
    return Booking::query()->create([
        'public_reference' => Str::uuid()->toString(),
        'status' => $status,
        'visit_date' => $date,
        'visit_time' => $time,
        'zone_id' => $zone->id,
        'zone_name_snapshot' => $zone->name,
        'lot_number' => 'A1',
        'tent_required' => false,
        'chair_count' => 2,
        'customer_name' => 'Customer',
        'customer_email' => Str::uuid().'@example.com',
        'customer_phone' => '08123456789',
        'deceased_name' => 'Deceased',
        'ethics_confirmed_at' => now(),
        'cancelled_at' => $status === BookingStatus::Cancelled ? now() : null,
    ]);
}
