<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingManagementToken;
use App\Models\Setting;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->withSession(['_token' => 'test-token']);
    config(['app.asset_url' => 'http://test-assets']);

    CarbonImmutable::setTestNow(CarbonImmutable::parse(
        '2026-07-30 10:00:00',
        'Asia/Jakarta',
    ));

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

    Mail::fake();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('admin booking routes are protected from guests', function (string $method, string $path) {
    $booking = phaseEightBooking();

    $response = match ($method) {
        'get' => $this->get($path === '{booking}'
            ? "/admin/bookings/{$booking->id}"
            : $path),
        'put' => $this->put("/admin/bookings/{$booking->id}", ['_token' => 'test-token']),
        'post' => $this->post("/admin/bookings/{$booking->id}/cancel", ['_token' => 'test-token']),
    };

    $response->assertRedirect('/admin/login');
})->with([
    ['get', '/admin'],
    ['get', '{booking}'],
    ['get', '/admin/bookings/export'],
    ['put', '{booking}'],
    ['post', '{booking}'],
]);

test('admin can search filter and paginate bookings with useful summaries', function () {
    $admin = User::factory()->create();
    $mawar = Zone::query()->where('name', 'Mawar')->firstOrFail();
    $melati = Zone::query()->where('name', 'Melati')->firstOrFail();

    phaseEightBooking([
        'zone_id' => $mawar->id,
        'zone_name_snapshot' => 'Mawar',
        'customer_name' => 'Budi Santoso',
        'visit_date' => '2026-08-01',
    ]);
    phaseEightBooking([
        'zone_id' => $melati->id,
        'zone_name_snapshot' => 'Melati',
        'customer_name' => 'Siti Aminah',
        'status' => BookingStatus::Cancelled,
        'visit_date' => '2026-08-02',
        'cancelled_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get('/admin?'.http_build_query([
            'search' => 'budi',
            'status' => 'confirmed',
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-01',
            'zone_id' => $mawar->id,
        ]), phaseEightInertiaHeaders())
        ->assertOk()
        ->assertJsonPath('component', 'admin/dashboard')
        ->assertJsonPath('props.filters.search', 'budi')
        ->assertJsonPath('props.filters.status', 'confirmed')
        ->assertJsonCount(1, 'props.bookings.data')
        ->assertJsonPath('props.bookings.data.0.customer_name', 'Budi Santoso')
        ->assertJsonPath('props.bookings.data.0.zone', 'Mawar')
        ->assertJsonPath('props.summary.confirmed', 1)
        ->assertJsonPath('props.summary.cancelled', 1)
        ->assertJsonCount(2, 'props.zones')
        ->assertJsonCount(2, 'props.time_slots');
});

test('admin can inspect full booking detail and editability', function () {
    $booking = phaseEightBooking([
        'customer_name' => 'Budi Santoso',
        'additional_notes' => 'Datang bersama keluarga.',
    ]);

    $this->actingAs(User::factory()->create())
        ->get("/admin/bookings/{$booking->id}", phaseEightInertiaHeaders())
        ->assertOk()
        ->assertJsonPath('component', 'admin/booking-detail')
        ->assertJsonPath('props.booking.public_reference', $booking->public_reference)
        ->assertJsonPath('props.booking.customer_name', 'Budi Santoso')
        ->assertJsonPath('props.booking.additional_notes', 'Datang bersama keluarga.')
        ->assertJsonPath('props.booking.can_edit', true)
        ->assertJsonPath('props.booking.can_cancel', true)
        ->assertJsonCount(2, 'props.zones')
        ->assertJsonCount(2, 'props.time_slots');
});

test('admin can update a future booking without emailing the customer', function () {
    $booking = phaseEightBooking();
    $melati = Zone::query()->where('name', 'Melati')->firstOrFail();

    $this->actingAs(User::factory()->create())
        ->put("/admin/bookings/{$booking->id}", phaseEightUpdatePayload([
            'zone_id' => $melati->id,
            'customer_name' => '  Budi Santoso  ',
            'customer_email' => ' BUDI@EXAMPLE.COM ',
            'lot_number' => 'new22',
            'additional_notes' => '  Datang bersama keluarga.  ',
        ]))
        ->assertRedirect("/admin/bookings/{$booking->id}")
        ->assertSessionHas('success');

    $booking->refresh();

    expect($booking->zone_name_snapshot)->toBe('Melati')
        ->and($booking->customer_name)->toBe('Budi Santoso')
        ->and($booking->customer_email)->toBe('budi@example.com')
        ->and($booking->lot_number)->toBe('NEW22')
        ->and($booking->additional_notes)->toBe('Datang bersama keluarga.');

    Mail::assertNothingQueued();
});

test('admin booking update rejects invalid customer contact data', function (
    array $override,
    string $field,
) {
    $booking = phaseEightBooking();

    $this->actingAs(User::factory()->create())
        ->from("/admin/bookings/{$booking->id}")
        ->put(
            "/admin/bookings/{$booking->id}",
            phaseEightUpdatePayload($override),
        )
        ->assertRedirect("/admin/bookings/{$booking->id}")
        ->assertSessionHasErrors($field);
})->with([
    'email missing' => [['customer_email' => ''], 'customer_email'],
    'email malformed' => [['customer_email' => 'bukan-email'], 'customer_email'],
    'phone prefix invalid' => [['customer_phone' => '07123456789'], 'customer_phone'],
    'phone too short' => [['customer_phone' => '081234567'], 'customer_phone'],
    'phone too long' => [['customer_phone' => '0812345678901234'], 'customer_phone'],
    'phone contains non digits' => [['customer_phone' => '08123-456789'], 'customer_phone'],
]);

test('admin update rejects a full target date without changing the booking', function () {
    $booking = phaseEightBooking(['visit_date' => '2026-08-01']);
    phaseEightBooking([
        'visit_date' => '2026-08-03',
        'visit_time' => '11:00:00',
    ]);
    phaseEightBooking([
        'visit_date' => '2026-08-03',
        'visit_time' => '10:00:00',
    ]);

    $this->actingAs(User::factory()->create())
        ->from("/admin/bookings/{$booking->id}")
        ->put(
            "/admin/bookings/{$booking->id}",
            phaseEightUpdatePayload(['visit_date' => '2026-08-03']),
        )
        ->assertRedirect("/admin/bookings/{$booking->id}")
        ->assertSessionHasErrors('booking');

    expect($booking->fresh()->visit_date->toDateString())->toBe('2026-08-01');
});

test('admin cannot update or cancel after the visit time has passed', function () {
    $booking = phaseEightBooking([
        'visit_date' => '2026-07-30',
        'visit_time' => '09:00:00',
    ]);
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->from("/admin/bookings/{$booking->id}")
        ->put(
            "/admin/bookings/{$booking->id}",
            phaseEightUpdatePayload(),
        )
        ->assertSessionHasErrors('booking');

    $this->actingAs($admin)
        ->from("/admin/bookings/{$booking->id}")
        ->post("/admin/bookings/{$booking->id}/cancel", ['_token' => 'test-token'])
        ->assertSessionHasErrors('booking');

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

test('admin cancellation preserves the booking revokes tokens and sends no email', function () {
    $booking = phaseEightBooking();
    $token = BookingManagementToken::query()->create([
        'booking_id' => $booking->id,
        'token_hash' => hash('sha256', 'secret-token'),
    ]);

    $this->actingAs(User::factory()->create())
        ->post("/admin/bookings/{$booking->id}/cancel", ['_token' => 'test-token'])
        ->assertRedirect("/admin/bookings/{$booking->id}")
        ->assertSessionHas('success');

    $booking->refresh();

    expect($booking->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->cancelled_at)->not->toBeNull()
        ->and($token->fresh()->revoked_at)->not->toBeNull()
        ->and(Booking::query()->whereKey($booking->id)->exists())->toBeTrue();

    Mail::assertNothingQueued();
});

test('excel export follows filters and neutralises spreadsheet formulas', function () {
    phaseEightBooking([
        'customer_name' => '=HYPERLINK("https://example.com")',
        'deceased_name' => '+SUM(1,1)',
        'additional_notes' => '@danger',
    ]);
    phaseEightBooking([
        'status' => BookingStatus::Cancelled,
        'customer_name' => 'Tidak Diekspor',
        'cancelled_at' => now(),
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get('/admin/bookings/export?'.http_build_query([
            'status' => 'confirmed',
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-01',
        ]));

    $response->assertOk()
        ->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        )
        ->assertDownload('booking-ziarah.xlsx');

    $path = tempnam(sys_get_temp_dir(), 'booking-export-');
    expect($path)->not->toBeFalse();

    file_put_contents($path, $response->streamedContent());

    $reader = new Reader;
    $reader->open($path);
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }

    $reader->close();
    unlink($path);

    expect($rows)->toHaveCount(3)
        ->and($rows[0][0])->toBe('INFO ZIARAH Tanggal 1 Agustus 2026')
        ->and($rows[1])->toBe([
            'Tanggal Ziarah',
            'Jam',
            'Zona',
            'Nomor lot',
            'Nama Pemesan',
            'Email',
            'Nomor Telfon',
            'Nama Alm/Ah',
            'Catatan',
            'Fasilitas (Tenda, jumlah kursi)',
        ])
        ->and($rows[2])
        ->toContain('\'=HYPERLINK("https://example.com")')
        ->toContain('\'+SUM(1,1)')
        ->toContain('\'@danger')
        ->toContain('Tenda: Tidak | Jumlah kursi: 2')
        ->not->toContain('Tidak Diekspor');
});

/** @param array<string, mixed> $overrides */
function phaseEightBooking(array $overrides = []): Booking
{
    $zone = Zone::query()->where('name', 'Mawar')->firstOrFail();

    return Booking::query()->create(array_replace([
        'public_reference' => (string) Str::uuid(),
        'status' => BookingStatus::Confirmed,
        'visit_date' => '2026-08-01',
        'visit_time' => '10:00:00',
        'zone_id' => $zone->id,
        'zone_name_snapshot' => $zone->name,
        'lot_number' => 'DSD810',
        'tent_required' => false,
        'chair_count' => 2,
        'customer_name' => 'Customer',
        'customer_email' => 'customer@example.com',
        'customer_phone' => '08123456789',
        'deceased_name' => 'Deceased',
        'additional_notes' => null,
        'ethics_confirmed_at' => now(),
        'cancelled_at' => null,
        'completed_at' => null,
    ], $overrides));
}

/** @param array<string, mixed> $overrides */
function phaseEightUpdatePayload(array $overrides = []): array
{
    $zone = Zone::query()->where('name', 'Mawar')->firstOrFail();

    return array_replace([
        '_token' => 'test-token',
        'visit_date' => '2026-08-02',
        'visit_time' => '11:00',
        'zone_id' => $zone->id,
        'lot_number' => 'DSD810',
        'tent_required' => true,
        'chair_count' => 4,
        'customer_name' => 'Customer Updated',
        'customer_email' => 'updated@example.com',
        'customer_phone' => '08123456780',
        'deceased_name' => 'Deceased Updated',
        'additional_notes' => null,
    ], $overrides);
}

/** @return array<string, string> */
function phaseEightInertiaHeaders(): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash('xxh128', 'http://test-assets'),
    ];
}
