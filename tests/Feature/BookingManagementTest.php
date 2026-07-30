<?php

use App\Enums\BookingStatus;
use App\Mail\BookingNotification;
use App\Models\Booking;
use App\Models\BookingManagementToken;
use App\Models\Setting;
use App\Models\TimeSlot;
use App\Models\Zone;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse(
        '2026-07-29 10:00:00',
        'Asia/Jakarta',
    ));

    config()->set('booking.minimum_form_seconds', 3);
    config()->set('booking.maximum_form_seconds', 7200);
    config()->set('booking.turnstile_secret_key', 'test-secret');
    config()->set('booking.management_read_rate_limit', 30);
    config()->set('booking.reschedule_rate_limit', 5);
    config()->set('booking.cancel_rate_limit', 5);

    Setting::query()->create([
        'id' => 1,
        'daily_booking_limit' => 2,
        'operations_email' => 'ops@example.com',
        'embed_allowed_origins' => [],
    ]);

    Zone::query()->create(['name' => 'Mawar', 'is_active' => true]);
    Zone::query()->create(['name' => 'Melati', 'is_active' => true]);
    TimeSlot::query()->create(['start_time' => '10:00:00', 'is_active' => true]);
    TimeSlot::query()->create(['start_time' => '11:00:00', 'is_active' => true]);

    Http::fake(['*' => Http::response(['success' => true])]);
    Mail::fake();
});

afterEach(function () {
    CarbonImmutable::setTestNow();

    foreach ([
        'management-read:127.0.0.1',
        'management-reschedule:127.0.0.1',
        'management-cancel:127.0.0.1',
        'public-booking:127.0.0.1',
    ] as $key) {
        RateLimiter::clear($key);
    }
});

test('public booking queues an encrypted confirmation email with management link', function () {
    CarbonImmutable::setTestNow(now()->addSeconds(4));

    $this->postJson('/api/public/bookings', phaseSevenPublicPayload())
        ->assertCreated();

    Mail::assertQueued(
        BookingNotification::class,
        fn (BookingNotification $mail): bool => $mail->hasTo('customer@example.com')
            && $mail->kind === BookingNotification::CONFIRMED
            && $mail->managementToken !== null,
    );

    expect(BookingManagementToken::query()->value('expires_at'))->toBeNull();
});

test('confirmation email renders the requested booking details and secure management link', function () {
    ['booking' => $booking, 'token' => $token] = phaseSevenManagedBooking([
        'customer_name' => 'Budi Santoso',
        'deceased_name' => 'Ahmad Santoso',
        'additional_notes' => 'Datang bersama keluarga.',
    ]);
    $mail = new BookingNotification(
        $booking,
        BookingNotification::CONFIRMED,
        $token,
    );

    $html = $mail->render();

    expect($mail)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($html)
        ->toContain('Assalamualaikum Wr. Wb.')
        ->toContain('Yth Budi Santoso')
        ->toContain('Mohon pastikan info ziarah lengkap dan akurat:')
        ->toContain($booking->public_reference)
        ->toContain('Mawar')
        ->toContain('DSD810')
        ->toContain('Ahmad Santoso')
        ->toContain('Datang bersama keluarga.')
        ->toContain('0812 3000 5673')
        ->toContain('Wassalamualaikum Wr. Wb.')
        ->toContain('Al Azhar Memorial Garden')
        ->toContain('Jika data Zona/No Lot/Nama Alm tidak sesuai data di AMG')
        ->toContain(route('booking.manage', ['token' => $token]));
});

test('valid token returns safe booking detail and currently allowed actions', function () {
    ['booking' => $booking, 'token' => $token] = phaseSevenManagedBooking();

    $this->getJson("/api/manage/bookings/{$token}")
        ->assertOk()
        ->assertJsonPath('booking_reference', $booking->public_reference)
        ->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('visit.zone', 'Mawar')
        ->assertJsonPath('customer.email', 'customer@example.com')
        ->assertJsonPath('actions.reschedule', true)
        ->assertJsonPath('actions.cancel', true)
        ->assertJsonMissingPath('id')
        ->assertJsonMissingPath('management_token');

    expect(BookingManagementToken::query()->value('last_used_at'))->not->toBeNull();
});

test('management responses are never cacheable', function () {
    ['token' => $token] = phaseSevenManagedBooking();

    $this->getJson("/api/manage/bookings/{$token}")
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    $this->get("/manage/{$token}")
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('invalid revoked cancelled and completed tokens use the same generic response', function (
    string $state,
) {
    ['booking' => $booking, 'token' => $token] = phaseSevenManagedBooking();

    if ($state === 'revoked') {
        $booking->managementTokens()->update(['revoked_at' => now()]);
    } elseif ($state === 'cancelled') {
        $booking->update([
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    } elseif ($state === 'completed') {
        $booking->update([
            'status' => BookingStatus::Completed,
            'completed_at' => now(),
        ]);
    } else {
        $token = 'unknown-token';
    }

    $this->getJson("/api/manage/bookings/{$token}")
        ->assertNotFound()
        ->assertExactJson([
            'message' => 'Booking management link is not available.',
        ]);
})->with(['unknown', 'revoked', 'cancelled', 'completed']);

test('management token reads are rate limited separately', function () {
    config()->set('booking.management_read_rate_limit', 1);
    ['token' => $token] = phaseSevenManagedBooking();

    $this->getJson("/api/manage/bookings/{$token}")->assertOk();
    $this->getJson("/api/manage/bookings/{$token}")
        ->assertTooManyRequests()
        ->assertJsonPath(
            'message',
            'Too many management link requests. Please try again shortly.',
        );
});

test('reschedule atomically updates booking rotates token and queues new link', function () {
    ['booking' => $booking, 'token' => $oldToken] = phaseSevenManagedBooking();
    $newZone = Zone::query()->where('name', 'Melati')->firstOrFail();

    $this->putJson(
        "/api/manage/bookings/{$oldToken}/reschedule",
        phaseSevenReschedulePayload($newZone),
    )
        ->assertOk()
        ->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('visit.date', '2026-08-02')
        ->assertJsonPath('visit.time', '11:00')
        ->assertJsonPath('visit.zone', 'Melati')
        ->assertJsonMissingPath('management_token');

    $booking->refresh();

    expect($booking->visit_date->toDateString())->toBe('2026-08-02')
        ->and($booking->zone_name_snapshot)->toBe('Melati')
        ->and($booking->lot_number)->toBe('NEW22')
        ->and($booking->managementTokens)->toHaveCount(2)
        ->and($booking->managementTokens->whereNotNull('revoked_at'))->toHaveCount(1);

    $newToken = null;
    Mail::assertQueued(
        BookingNotification::class,
        function (BookingNotification $mail) use (&$newToken): bool {
            if ($mail->kind !== BookingNotification::RESCHEDULED) {
                return false;
            }

            $newToken = $mail->managementToken;

            return $mail->hasTo('customer@example.com')
                && $newToken !== null;
        },
    );

    $this->getJson("/api/manage/bookings/{$oldToken}")->assertNotFound();
    $this->getJson("/api/manage/bookings/{$newToken}")
        ->assertOk()
        ->assertJsonPath('visit.date', '2026-08-02');
});

test('booking can be rescheduled repeatedly with each newly issued link', function () {
    ['token' => $firstToken] = phaseSevenManagedBooking();
    $zone = Zone::query()->where('name', 'Mawar')->firstOrFail();

    $this->putJson(
        "/api/manage/bookings/{$firstToken}/reschedule",
        phaseSevenReschedulePayload($zone),
    )->assertOk();

    $secondToken = null;
    Mail::assertQueued(
        BookingNotification::class,
        function (BookingNotification $mail) use (&$secondToken): bool {
            $secondToken = $mail->managementToken;

            return $mail->kind === BookingNotification::RESCHEDULED
                && $secondToken !== null;
        },
    );

    $this->putJson(
        "/api/manage/bookings/{$secondToken}/reschedule",
        phaseSevenReschedulePayload($zone, ['visit_date' => '2026-08-03']),
    )
        ->assertOk()
        ->assertJsonPath('visit.date', '2026-08-03');

    expect(BookingManagementToken::query()->count())->toBe(3)
        ->and(BookingManagementToken::query()->whereNull('revoked_at')->count())->toBe(1);
});

test('failed reschedule keeps original booking and token active', function () {
    Setting::query()->whereKey(1)->update(['daily_booking_limit' => 1]);
    ['booking' => $booking, 'token' => $token] = phaseSevenManagedBooking();
    $zone = Zone::query()->where('name', 'Mawar')->firstOrFail();

    app(BookingService::class)->createConfirmed(
        phaseSevenBookingAttributes($zone, '2026-08-02', [
            'customer_email' => 'other@example.com',
        ]),
        dailyLimit: 1,
        now: now(),
    );

    $this->putJson(
        "/api/manage/bookings/{$token}/reschedule",
        phaseSevenReschedulePayload($zone),
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('visit_date');

    expect($booking->fresh()->visit_date->toDateString())->toBe('2026-08-01')
        ->and($booking->managementTokens()->whereNull('revoked_at')->count())->toBe(1);

    $this->getJson("/api/manage/bookings/{$token}")->assertOk();
    Mail::assertNothingQueued();
});

test('same date reschedule does not require another quota unit', function () {
    Setting::query()->whereKey(1)->update(['daily_booking_limit' => 1]);
    ['token' => $token] = phaseSevenManagedBooking();
    $zone = Zone::query()->where('name', 'Mawar')->firstOrFail();

    $this->putJson(
        "/api/manage/bookings/{$token}/reschedule",
        phaseSevenReschedulePayload($zone, [
            'visit_date' => '2026-08-01',
            'visit_time' => '11:00',
        ]),
    )
        ->assertOk()
        ->assertJsonPath('visit.time', '11:00');
});

test('management availability keeps active slots available on the current full date', function () {
    Setting::query()->whereKey(1)->update(['daily_booking_limit' => 1]);
    ['token' => $token] = phaseSevenManagedBooking();

    $this->getJson(
        "/api/manage/bookings/{$token}/available-slots?date=2026-08-01",
    )
        ->assertOk()
        ->assertJsonPath('is_full', false)
        ->assertJsonPath('slots.0.is_available', true)
        ->assertJsonPath('slots.1.is_available', true);
});

test('reschedule deadline and target rules are enforced server side', function (
    array $override,
    string $field,
) {
    ['token' => $token] = phaseSevenManagedBooking();
    $zone = Zone::query()->where('name', 'Mawar')->firstOrFail();

    $this->putJson(
        "/api/manage/bookings/{$token}/reschedule",
        phaseSevenReschedulePayload($zone, $override),
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'date outside target window' => [['visit_date' => '2026-07-29'], 'visit_date'],
    'inactive zone' => [['zone_id' => 999999], 'zone_id'],
    'inactive slot' => [['visit_time' => '12:00'], 'visit_time'],
    'invalid lot' => [['lot_number' => 'A-1'], 'lot_number'],
    'chair range' => [['chair_count' => 7], 'chair_count'],
]);

test('morning reschedule is rejected at its exact deadline', function () {
    ['booking' => $booking, 'token' => $token] = phaseSevenManagedBooking();
    CarbonImmutable::setTestNow(CarbonImmutable::parse(
        '2026-07-31 15:00:00',
        'Asia/Jakarta',
    ));
    $zone = Zone::query()->where('name', 'Mawar')->firstOrFail();

    $this->putJson(
        "/api/manage/bookings/{$token}/reschedule",
        phaseSevenReschedulePayload($zone, ['visit_date' => '2026-08-02']),
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('booking');

    expect($booking->fresh()->visit_date->toDateString())->toBe('2026-08-01');
    Mail::assertNothingQueued();
});

test('customer cancellation preserves booking revokes token and queues email', function () {
    ['booking' => $booking, 'token' => $token] = phaseSevenManagedBooking();

    $this->postJson("/api/manage/bookings/{$token}/cancel")
        ->assertOk()
        ->assertExactJson([
            'booking_reference' => $booking->public_reference,
            'status' => 'cancelled',
        ]);

    $booking->refresh();

    expect($booking->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->cancelled_at)->not->toBeNull()
        ->and($booking->managementTokens()->whereNull('revoked_at')->count())->toBe(0)
        ->and(Booking::query()->whereKey($booking->id)->exists())->toBeTrue();

    $this->getJson("/api/manage/bookings/{$token}")->assertNotFound();

    Mail::assertQueued(
        BookingNotification::class,
        fn (BookingNotification $mail): bool => $mail->hasTo('customer@example.com')
            && $mail->kind === BookingNotification::CANCELLED
            && $mail->managementToken === null,
    );
});

test('cancellation is rejected exactly one hour before visit', function () {
    ['booking' => $booking, 'token' => $token] = phaseSevenManagedBooking();
    CarbonImmutable::setTestNow(CarbonImmutable::parse(
        '2026-08-01 09:00:00',
        'Asia/Jakarta',
    ));

    $this->postJson("/api/manage/bookings/{$token}/cancel")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('booking');

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->managementTokens()->whereNull('revoked_at')->count())->toBe(1);
    Mail::assertNothingQueued();
});

test('reschedule and cancellation attempts have separate configurable limits', function (
    string $action,
    string $message,
) {
    config()->set("booking.{$action}_rate_limit", 1);
    ['token' => $token] = phaseSevenManagedBooking();

    if ($action === 'reschedule') {
        $zone = Zone::query()->where('name', 'Mawar')->firstOrFail();
        $url = "/api/manage/bookings/{$token}/reschedule";
        $payload = phaseSevenReschedulePayload($zone);
        $this->putJson($url, $payload)->assertOk();
        $this->putJson($url, $payload)
            ->assertTooManyRequests()
            ->assertJsonPath('message', $message);

        return;
    }

    $url = "/api/manage/bookings/{$token}/cancel";
    $this->postJson($url)->assertOk();
    $this->postJson($url)
        ->assertTooManyRequests()
        ->assertJsonPath('message', $message);
})->with([
    'reschedule' => [
        'reschedule',
        'Too many reschedule attempts. Please try again shortly.',
    ],
    'cancel' => [
        'cancel',
        'Too many cancellation attempts. Please try again shortly.',
    ],
]);

/** @return array{booking: Booking, token: string} */
function phaseSevenManagedBooking(array $overrides = []): array
{
    $zone = Zone::query()->where('name', 'Mawar')->firstOrFail();
    $result = app(BookingService::class)->createConfirmed(
        phaseSevenBookingAttributes($zone, '2026-08-01', $overrides),
        dailyLimit: (int) Setting::query()->findOrFail(1)->daily_booking_limit,
        now: now(),
    );

    return [
        'booking' => $result['booking'],
        'token' => $result['management_token'],
    ];
}

/** @return array<string, mixed> */
function phaseSevenBookingAttributes(
    Zone $zone,
    string $date,
    array $overrides = [],
): array {
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

/** @return array<string, mixed> */
function phaseSevenReschedulePayload(Zone $zone, array $overrides = []): array
{
    return array_replace([
        'visit_date' => '2026-08-02',
        'visit_time' => '11:00',
        'zone_id' => $zone->id,
        'lot_number' => 'new22',
        'tent_required' => true,
        'chair_count' => 4,
        'additional_notes' => 'Datang bersama keluarga.',
    ], $overrides);
}

/** @return array<string, mixed> */
function phaseSevenPublicPayload(): array
{
    $zone = Zone::query()->where('name', 'Mawar')->firstOrFail();

    return [
        'visit_date' => '2026-08-01',
        'visit_time' => '10:00',
        'zone_id' => $zone->id,
        'lot_number' => 'DSD810',
        'tent_required' => false,
        'chair_count' => 2,
        'customer_name' => 'Customer',
        'customer_email' => 'customer@example.com',
        'customer_phone' => '08123456789',
        'deceased_name' => 'Deceased',
        'additional_notes' => null,
        'ethics_confirmed' => true,
        'turnstile_token' => 'valid-token',
        'website' => '',
        'form_token' => Crypt::encryptString((string) now()->subSeconds(4)->timestamp),
    ];
}
