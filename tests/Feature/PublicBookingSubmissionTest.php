<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingManagementToken;
use App\Models\Setting;
use App\Models\TimeSlot;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse(
        '2026-07-29 15:30:00',
        'Asia/Jakarta',
    ));

    config()->set('booking.minimum_form_seconds', 3);
    config()->set('booking.maximum_form_seconds', 7200);
    config()->set('booking.turnstile_secret_key', 'test-secret');
    config()->set('booking.public_submission_rate_limit', 5);

    Setting::query()->create([
        'id' => 1,
        'daily_booking_limit' => 2,
        'operations_email' => 'ops@example.com',
        'embed_allowed_origins' => [],
    ]);

    Zone::query()->create(['name' => 'Mawar', 'is_active' => true]);
    TimeSlot::query()->create(['start_time' => '09:00:00', 'is_active' => true]);
    TimeSlot::query()->create(['start_time' => '10:00:00', 'is_active' => true]);

    Http::fake(fn ($request) => Http::response([
        'success' => $request->data()['response'] !== 'invalid-token',
    ]));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    RateLimiter::clear('public-booking:127.0.0.1');
});

test('availability includes a protected form start token', function () {
    $this->getJson('/api/public/booking-options')
        ->assertOk()
        ->assertJsonStructure(['form_token']);
});

test('a valid public submission creates one confirmed booking safely', function () {
    $payload = validPublicBookingPayload();
    CarbonImmutable::setTestNow(now()->addSeconds(4));

    $response = $this->postJson('/api/public/bookings', $payload);

    $response->assertCreated()
        ->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('visit.date', '2026-07-31')
        ->assertJsonPath('visit.time', '10:00')
        ->assertJsonPath('visit.zone', 'Mawar')
        ->assertJsonPath('visit.lot', 'DSD810')
        ->assertJsonMissingPath('management_token')
        ->assertJsonStructure(['booking_reference']);

    $booking = Booking::query()->sole();

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->lot_number)->toBe('DSD810')
        ->and($booking->customer_email)->toBe('customer@example.com')
        ->and(BookingManagementToken::query()->count())->toBe(1)
        ->and(BookingManagementToken::query()->value('token_hash'))->toHaveLength(64);

    Http::assertSent(fn ($request): bool => $request->data()['response'] === 'valid-token'
        && $request->data()['remoteip'] === '127.0.0.1');
});

test('required booking fields and business values are validated server side', function (
    array $override,
    string $field,
) {
    CarbonImmutable::setTestNow(now()->addSeconds(4));

    $this->postJson('/api/public/bookings', array_replace(
        validPublicBookingPayload(),
        $override,
    ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(Booking::query()->count())->toBe(0);
})->with([
    'date before H plus 1' => [['visit_date' => '2026-07-29'], 'visit_date'],
    'lead time violation' => [[
        'visit_date' => '2026-07-30',
        'visit_time' => '09:00',
    ], 'visit_time'],
    'invalid lot' => [['lot_number' => 'A-1'], 'lot_number'],
    'chair below minimum' => [['chair_count' => 1], 'chair_count'],
    'chair above maximum' => [['chair_count' => 7], 'chair_count'],
    'tent missing' => [['tent_required' => null], 'tent_required'],
    'email missing' => [['customer_email' => ''], 'customer_email'],
    'invalid email' => [['customer_email' => 'invalid'], 'customer_email'],
    'phone missing' => [['customer_phone' => ''], 'customer_phone'],
    'phone with unsupported prefix' => [['customer_phone' => '07123456789'], 'customer_phone'],
    'phone shorter than ten digits' => [['customer_phone' => '081234567'], 'customer_phone'],
    'phone longer than fifteen digits' => [['customer_phone' => '0812345678901234'], 'customer_phone'],
    'phone with non digit characters' => [['customer_phone' => '08123-456789'], 'customer_phone'],
    'ethics not accepted' => [['ethics_confirmed' => false], 'ethics_confirmed'],
]);

test('phone accepts supported prefixes at the allowed length boundaries', function (
    string $phone,
) {
    CarbonImmutable::setTestNow(now()->addSeconds(4));

    $this->postJson('/api/public/bookings', array_replace(
        validPublicBookingPayload(),
        ['customer_phone' => $phone],
    ))->assertCreated();
})->with([
    '08 with ten digits' => '0812345678',
    '08 with fifteen digits' => '081234567890123',
    '62 with ten digits' => '6281234567',
    '62 with fifteen digits' => '628123456789012',
]);

test('inactive zones and slots are rejected', function (string $field) {
    if ($field === 'zone_id') {
        Zone::query()->where('is_active', true)->update(['is_active' => false]);
    } else {
        TimeSlot::query()->where('is_active', true)->update(['is_active' => false]);
    }

    CarbonImmutable::setTestNow(now()->addSeconds(4));

    $this->postJson('/api/public/bookings', validPublicBookingPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with(['zone_id', 'visit_time']);

test('a full date is rejected without creating another booking', function () {
    Setting::query()->whereKey(1)->update(['daily_booking_limit' => 1]);
    CarbonImmutable::setTestNow(now()->addSeconds(4));

    $this->postJson('/api/public/bookings', validPublicBookingPayload())
        ->assertCreated();
    $this->postJson('/api/public/bookings', array_replace(
        validPublicBookingPayload(),
        ['customer_email' => 'second@example.com'],
    ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('visit_date');

    expect(Booking::query()->count())->toBe(1);
});

test('the same email and phone may create multiple bookings', function () {
    CarbonImmutable::setTestNow(now()->addSeconds(4));

    $this->postJson('/api/public/bookings', validPublicBookingPayload())
        ->assertCreated();
    $this->postJson('/api/public/bookings', validPublicBookingPayload())
        ->assertCreated();

    expect(Booking::query()->count())->toBe(2);
});

test('Turnstile failure is rejected with a safe generic error', function () {
    CarbonImmutable::setTestNow(now()->addSeconds(4));

    $this->postJson('/api/public/bookings', array_replace(
        validPublicBookingPayload(),
        ['turnstile_token' => 'invalid-token'],
    ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('turnstile_token');

    expect(Booking::query()->count())->toBe(0);
});

test('honeypot and form timing abuse checks reject submissions', function (
    array $override,
    string $field,
) {
    $this->postJson('/api/public/bookings', array_replace(
        validPublicBookingPayload(),
        $override,
    ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'filled honeypot' => [['website' => 'https://spam.example'], 'website'],
    'invalid form token' => [['form_token' => 'forged'], 'form_token'],
]);

test('an unrealistically fast submission is rejected', function () {
    $payload = validPublicBookingPayload();
    $payload['form_token'] = Crypt::encryptString((string) now()->timestamp);

    $this->postJson('/api/public/bookings', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('form_token');
});

test('submission accepts JSON only and limits request size', function () {
    $this->post(
        '/api/public/bookings',
        validPublicBookingPayload(),
        ['Accept' => 'application/json'],
    )->assertUnsupportedMediaType();

    config()->set('booking.maximum_request_bytes', 100);

    $this->postJson('/api/public/bookings', validPublicBookingPayload())
        ->assertStatus(413);
});

test('public booking submission is rate limited separately', function () {
    config()->set('booking.public_submission_rate_limit', 1);
    CarbonImmutable::setTestNow(now()->addSeconds(4));

    $this->postJson('/api/public/bookings', validPublicBookingPayload())
        ->assertCreated();
    $this->postJson('/api/public/bookings', validPublicBookingPayload())
        ->assertTooManyRequests()
        ->assertJsonPath(
            'message',
            'Too many booking attempts. Please try again shortly.',
        );
});

/** @return array<string, mixed> */
function validPublicBookingPayload(): array
{
    $zone = Zone::query()->where('name', 'Mawar')->firstOrFail();

    return [
        'visit_date' => '2026-07-31',
        'visit_time' => '10:00',
        'zone_id' => $zone->id,
        'lot_number' => 'dsd810',
        'tent_required' => false,
        'chair_count' => 2,
        'customer_name' => 'Customer',
        'customer_email' => ' CUSTOMER@EXAMPLE.COM ',
        'customer_phone' => '08123456789',
        'deceased_name' => 'Deceased',
        'additional_notes' => null,
        'ethics_confirmed' => true,
        'turnstile_token' => 'valid-token',
        'website' => '',
        'form_token' => Crypt::encryptString((string) now()->subSeconds(4)->timestamp),
        'status' => 'cancelled',
        'daily_booking_limit' => 999,
    ];
}
