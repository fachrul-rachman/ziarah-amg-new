<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingManagementToken;
use App\Models\Setting;
use App\Models\TimeSlot;
use App\Models\Zone;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function validBookingAttributes(Zone $zone, string $date, array $overrides = []): array
{
    return array_replace([
        'visit_date' => $date,
        'visit_time' => '10:00:00',
        'zone_id' => $zone->id,
        'lot_number' => 'DSD810',
        'tent_required' => false,
        'chair_count' => 2,
        'customer_name' => 'Customer',
        'customer_email' => 'customer@example.com',
        'customer_phone' => '08123456789',
        'deceased_name' => 'Deceased',
        'additional_notes' => null,
        'ethics_confirmed_at' => now(),
    ], $overrides);
}

function configuredBookingTarget(CarbonImmutable $now): array
{
    $zone = Zone::query()->create(['name' => 'Mawar', 'is_active' => true]);
    TimeSlot::query()->create(['start_time' => '10:00:00', 'is_active' => true]);

    return [$zone, $now->addDays(2)->toDateString()];
}

test('confirmed booking stores only the management token hash', function () {
    $now = CarbonImmutable::parse('2026-07-29 10:00:00', 'Asia/Jakarta');
    [$zone, $date] = configuredBookingTarget($now);

    $result = app(BookingService::class)->createConfirmed(
        validBookingAttributes($zone, $date),
        dailyLimit: 10,
        now: $now,
    );

    expect($result['booking']->status)->toBe(BookingStatus::Confirmed)
        ->and(Str::isUuid($result['booking']->public_reference))->toBeTrue()
        ->and(strlen($result['management_token']))->toBeGreaterThanOrEqual(43)
        ->and(BookingManagementToken::query()->value('token_hash'))
        ->toBe(hash('sha256', $result['management_token']))
        ->not->toBe($result['management_token']);
});

test('one confirmed booking consumes one quota unit regardless of facilities', function () {
    $now = CarbonImmutable::parse('2026-07-29 10:00:00', 'Asia/Jakarta');
    [$zone, $date] = configuredBookingTarget($now);
    $service = app(BookingService::class);

    $service->createConfirmed(
        validBookingAttributes($zone, $date, ['tent_required' => true, 'chair_count' => 6]),
        dailyLimit: 1,
        now: $now,
    );

    expect(fn () => $service->createConfirmed(
        validBookingAttributes($zone, $date, ['customer_email' => 'second@example.com']),
        dailyLimit: 1,
        now: $now,
    ))->toThrow(DomainException::class)
        ->and(Booking::query()->confirmed()->whereDate('visit_date', $date)->count())->toBe(1);
});

test('cancelled bookings release their quota unit', function () {
    $now = CarbonImmutable::parse('2026-07-29 10:00:00', 'Asia/Jakarta');
    [$zone, $date] = configuredBookingTarget($now);
    $service = app(BookingService::class);

    $first = $service->createConfirmed(
        validBookingAttributes($zone, $date),
        dailyLimit: 1,
        now: $now,
    )['booking'];

    $first->update([
        'status' => BookingStatus::Cancelled,
        'cancelled_at' => $now,
    ]);

    $service->createConfirmed(
        validBookingAttributes($zone, $date, ['customer_email' => 'second@example.com']),
        dailyLimit: 1,
        now: $now,
    );

    expect(Booking::query()->whereDate('visit_date', $date)->count())->toBe(2)
        ->and(Booking::query()->confirmed()->whereDate('visit_date', $date)->count())->toBe(1);
});

test('client supplied status fields cannot change confirmed booking state', function () {
    $now = CarbonImmutable::parse('2026-07-29 10:00:00', 'Asia/Jakarta');
    [$zone, $date] = configuredBookingTarget($now);

    $booking = app(BookingService::class)->createConfirmed(
        validBookingAttributes($zone, $date, [
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => $now,
            'completed_at' => $now,
        ]),
        dailyLimit: 10,
        now: $now,
    )['booking'];

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->cancelled_at)->toBeNull()
        ->and($booking->completed_at)->toBeNull();
});

test('sensitive webhook settings are encrypted at rest', function () {
    $webhook = 'https://discord.com/api/webhooks/example/secret';

    $setting = Setting::query()->create([
        'id' => 1,
        'daily_booking_limit' => 10,
        'operations_email' => 'ops@example.com',
        'discord_webhook' => $webhook,
        'embed_allowed_origins' => [],
    ]);

    expect($setting->fresh()->discord_webhook)->toBe($webhook)
        ->and(DB::table('settings')->value('discord_webhook'))->not->toBe($webhook);
});

test('database rejects invalid chair counts', function () {
    $now = CarbonImmutable::parse('2026-07-29 10:00:00', 'Asia/Jakarta');
    [$zone, $date] = configuredBookingTarget($now);
    $booking = app(BookingService::class)->createConfirmed(
        validBookingAttributes($zone, $date),
        dailyLimit: 10,
        now: $now,
    )['booking'];

    expect(fn () => DB::table('bookings')->where('id', $booking->id)->update(['chair_count' => 1]))
        ->toThrow(QueryException::class);
});

test('database rejects lot numbers outside uppercase alphanumeric format', function () {
    $now = CarbonImmutable::parse('2026-07-29 10:00:00', 'Asia/Jakarta');
    [$zone, $date] = configuredBookingTarget($now);
    $booking = app(BookingService::class)->createConfirmed(
        validBookingAttributes($zone, $date),
        dailyLimit: 10,
        now: $now,
    )['booking'];

    expect(fn () => DB::table('bookings')->where('id', $booking->id)->update(['lot_number' => '=1+1']))
        ->toThrow(QueryException::class);
});

test('time slots must start on an exact hour', function () {
    expect(fn () => TimeSlot::query()->create([
        'start_time' => '09:30:00',
        'is_active' => true,
    ]))->toThrow(QueryException::class);
});

test('zone names are unique without case sensitivity', function () {
    Zone::query()->create(['name' => 'Mawar', 'is_active' => true]);

    expect(fn () => Zone::query()->create(['name' => 'mawar', 'is_active' => true]))
        ->toThrow(QueryException::class);
});
