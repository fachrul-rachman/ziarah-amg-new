<?php

use App\Models\OperationsReportConfiguration;
use App\Models\Setting;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->withSession(['_token' => 'test-token']);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('configuration pages are protected from guests', function (string $path) {
    $this->get($path)->assertRedirect('/admin/login');
})->with([
    '/admin/zones',
    '/admin/time-slots',
    '/admin/settings',
]);

test('admin can open each configuration page', function (
    string $path,
    string $component,
) {
    $this->actingAs(User::factory()->create())
        ->get($path)
        ->assertOk()
        ->assertSee(str_replace('/', '\/', $component), false);
})->with([
    ['/admin/zones', 'admin/zones'],
    ['/admin/time-slots', 'admin/time-slots'],
    ['/admin/settings', 'admin/settings'],
]);

test('admin can create and update a zone safely', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)->post('/admin/zones', [
        '_token' => 'test-token',
        'name' => '  Garden A  ',
        'is_active' => true,
    ])->assertRedirect('/admin/zones');

    $zone = Zone::query()->sole();

    expect($zone->name)->toBe('Garden A')
        ->and($zone->is_active)->toBeTrue();

    $this->actingAs($admin)->put("/admin/zones/{$zone->id}", [
        '_token' => 'test-token',
        'name' => 'Garden A',
        'is_active' => false,
    ])->assertRedirect('/admin/zones');

    expect($zone->fresh()->is_active)->toBeFalse();
});

test('zone names are required and unique without case sensitivity', function () {
    $admin = User::factory()->create();
    Zone::query()->create(['name' => 'Garden A', 'is_active' => true]);

    $this->actingAs($admin)->post('/admin/zones', [
        '_token' => 'test-token',
        'name' => ' garden a ',
        'is_active' => true,
    ])->assertSessionHasErrors('name');

    $this->actingAs($admin)->post('/admin/zones', [
        '_token' => 'test-token',
        'name' => '   ',
        'is_active' => true,
    ])->assertSessionHasErrors('name');

    expect(Zone::query()->count())->toBe(1);
});

test('admin can create and disable an hourly time slot', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)->post('/admin/time-slots', [
        '_token' => 'test-token',
        'start_time' => '07:00',
        'is_active' => true,
    ])->assertRedirect('/admin/time-slots');

    $timeSlot = TimeSlot::query()->sole();

    expect($timeSlot->start_time)->toBe('07:00:00')
        ->and($timeSlot->is_active)->toBeTrue();

    $this->actingAs($admin)->put("/admin/time-slots/{$timeSlot->id}", [
        '_token' => 'test-token',
        'start_time' => '07:00',
        'is_active' => false,
    ])->assertRedirect('/admin/time-slots');

    expect($timeSlot->fresh()->is_active)->toBeFalse();
});

test('time slots reject non-hourly and duplicate values', function () {
    $admin = User::factory()->create();
    TimeSlot::query()->create([
        'start_time' => '07:00',
        'is_active' => true,
    ]);

    $this->actingAs($admin)->post('/admin/time-slots', [
        '_token' => 'test-token',
        'start_time' => '07:30',
        'is_active' => true,
    ])->assertSessionHasErrors('start_time');

    $this->actingAs($admin)->post('/admin/time-slots', [
        '_token' => 'test-token',
        'start_time' => '07:00',
        'is_active' => true,
    ])->assertSessionHasErrors('start_time');

    expect(TimeSlot::query()->count())->toBe(1);
});

test('admin can store valid global settings without exposing the webhook', function () {
    $admin = User::factory()->create();
    $webhook = 'https://discord.com/api/webhooks/123/secret-token';
    configurationReportSlot();

    $this->actingAs($admin)->put('/admin/settings', [
        '_token' => 'test-token',
        'booking_window_days' => 7,
        'booking_limit_mode' => 'hourly',
        'daily_booking_limit' => 25,
        'hourly_booking_limit' => 30,
        'operations_email' => ' OPERATIONS@EXAMPLE.COM ',
        'discord_webhook' => $webhook,
        'clear_discord_webhook' => false,
        'embed_allowed_origins' => [
            'https://www.example.com',
            'http://localhost:3000',
        ],
        'minimum_lead_hours' => 19,
        'report_schedules' => [['day_offset' => -1, 'time' => '15:00']],
    ])->assertRedirect('/admin/settings');

    $setting = Setting::query()->sole();

    expect($setting->id)->toBe(1)
        ->and($setting->booking_window_days)->toBe(7)
        ->and($setting->booking_limit_mode)->toBe('hourly')
        ->and($setting->daily_booking_limit)->toBe(25)
        ->and($setting->hourly_booking_limit)->toBe(30)
        ->and($setting->operations_email)->toBe('operations@example.com')
        ->and($setting->discord_webhook)->toBe($webhook)
        ->and($setting->embed_allowed_origins)->toBe([
            'https://www.example.com',
            'http://localhost:3000',
        ])
        ->and(OperationsReportConfiguration::query()->orderByDesc('effective_from')->firstOrFail()->minimum_lead_hours)->toBe(19)
        ->and(OperationsReportConfiguration::query()->orderByDesc('effective_from')->firstOrFail()->report_schedules)->toBe([
            ['day_offset' => -1, 'time' => '15:00'],
        ])
        ->and(OperationsReportConfiguration::query()->orderByDesc('effective_from')->firstOrFail()->effective_from->toDateString())
        ->toBe(now('Asia/Jakarta')->addDays(2)->toDateString())
        ->and($setting->getRawOriginal('discord_webhook'))->not->toContain(
            'secret-token',
        );

    $this->actingAs($admin)
        ->get('/admin/settings')
        ->assertOk()
        ->assertDontSee('secret-token')
        ->assertSee('discord_webhook_configured', false);
});

test('blank webhook preserves it and explicit clear removes it', function () {
    $admin = User::factory()->create();
    configurationReportSlot();
    $setting = Setting::query()->create([
        'id' => 1,
        'daily_booking_limit' => 20,
        'operations_email' => 'operations@example.com',
        'discord_webhook' => 'https://discord.com/api/webhooks/123/token',
        'embed_allowed_origins' => [],
        'minimum_lead_hours' => 18,
        'report_schedules' => [['day_offset' => -1, 'time' => '15:00']],
    ]);

    $payload = [
        '_token' => 'test-token',
        'booking_window_days' => 100,
        'booking_limit_mode' => 'daily',
        'daily_booking_limit' => 30,
        'hourly_booking_limit' => null,
        'operations_email' => 'operations@example.com',
        'discord_webhook' => null,
        'clear_discord_webhook' => false,
        'embed_allowed_origins' => [],
    ];

    $this->actingAs($admin)
        ->put('/admin/settings', $payload)
        ->assertRedirect('/admin/settings');

    expect($setting->fresh()->discord_webhook)
        ->toBe('https://discord.com/api/webhooks/123/token');

    $payload['clear_discord_webhook'] = true;

    $this->actingAs($admin)
        ->put('/admin/settings', $payload)
        ->assertRedirect('/admin/settings');

    expect($setting->fresh()->discord_webhook)->toBeNull();
});

test('consecutive report setting changes preserve each effective visit date', function () {
    OperationsReportConfiguration::query()->delete();
    configurationReportSlot();
    $admin = User::factory()->create();
    $payload = [
        '_token' => 'test-token',
        'booking_window_days' => 7,
        'booking_limit_mode' => 'daily',
        'daily_booking_limit' => 30,
        'hourly_booking_limit' => null,
        'operations_email' => 'operations@example.com',
        'discord_webhook' => null,
        'clear_discord_webhook' => false,
        'embed_allowed_origins' => [],
        'minimum_lead_hours' => 19,
        'report_schedules' => [['day_offset' => -1, 'time' => '15:00']],
    ];

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-03 10:00:00', 'Asia/Jakarta'));
    $this->actingAs($admin)->put('/admin/settings', $payload)->assertRedirect();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-04 10:00:00', 'Asia/Jakarta'));
    $payload['minimum_lead_hours'] = 20;
    $this->actingAs($admin)->put('/admin/settings', $payload)->assertRedirect();

    expect(OperationsReportConfiguration::forVisitDate('2026-08-04'))->toBeNull()
        ->and(OperationsReportConfiguration::forVisitDate('2026-08-05')?->minimum_lead_hours)->toBe(19)
        ->and(OperationsReportConfiguration::forVisitDate('2026-08-06')?->minimum_lead_hours)->toBe(20);
});

test('invalid global settings are rejected server side', function (
    array $change,
    string $errorKey,
) {
    configurationReportSlot();
    $payload = [
        '_token' => 'test-token',
        'booking_window_days' => 100,
        'booking_limit_mode' => 'daily',
        'daily_booking_limit' => 20,
        'hourly_booking_limit' => null,
        'operations_email' => 'operations@example.com',
        'discord_webhook' => null,
        'clear_discord_webhook' => false,
        'embed_allowed_origins' => ['https://www.example.com'],
        'minimum_lead_hours' => 18,
        'report_schedules' => [['day_offset' => -1, 'time' => '15:00']],
        ...$change,
    ];

    $this->actingAs(User::factory()->create())
        ->put('/admin/settings', $payload)
        ->assertSessionHasErrors($errorKey);

    expect(Setting::query()->count())->toBe(0);
})->with([
    'booking window below one day' => [
        ['booking_window_days' => 0],
        'booking_window_days',
    ],
    'booking window above one hundred days' => [
        ['booking_window_days' => 101],
        'booking_window_days',
    ],
    'invalid booking limit mode' => [
        ['booking_limit_mode' => 'weekly'],
        'booking_limit_mode',
    ],
    'hourly mode without hourly limit' => [[
        'booking_limit_mode' => 'hourly',
        'hourly_booking_limit' => null,
    ], 'hourly_booking_limit'],
    'non-positive daily limit' => [
        ['daily_booking_limit' => 0],
        'daily_booking_limit',
    ],
    'invalid operations email' => [
        ['operations_email' => 'not-an-email'],
        'operations_email',
    ],
    'non-Discord webhook' => [[
        'discord_webhook' => 'https://example.com/api/webhooks/123/token',
    ], 'discord_webhook'],
    'webhook with query parameters' => [[
        'discord_webhook' => 'https://discord.com/api/webhooks/123/token?x=1',
    ], 'discord_webhook'],
    'origin with a path' => [[
        'embed_allowed_origins' => ['https://www.example.com/path'],
    ], 'embed_allowed_origins.0'],
    'origin with query parameters' => [[
        'embed_allowed_origins' => ['https://www.example.com?x=1'],
    ], 'embed_allowed_origins.0'],
    'wildcard origin' => [
        ['embed_allowed_origins' => ['*']],
        'embed_allowed_origins.0',
    ],
    'zero lead time' => [
        ['minimum_lead_hours' => 0],
        'minimum_lead_hours',
    ],
    'more than three report schedules' => [[
        'report_schedules' => [
            ['day_offset' => -1, 'time' => '12:00'],
            ['day_offset' => -1, 'time' => '13:00'],
            ['day_offset' => -1, 'time' => '14:00'],
            ['day_offset' => -1, 'time' => '15:00'],
        ],
    ], 'report_schedules'],
]);

function configurationReportSlot(): void
{
    TimeSlot::query()->create(['start_time' => '07:00', 'is_active' => true]);
}
