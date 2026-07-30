<?php

use App\Enums\BookingStatus;
use App\Enums\OperationsReportPeriod;
use App\Jobs\PrepareOperationsReport;
use App\Jobs\SendOperationsReportDiscord;
use App\Jobs\SendOperationsReportEmail;
use App\Mail\OperationsReportMail;
use App\Models\Booking;
use App\Models\ReportDispatch;
use App\Models\Setting;
use App\Models\Zone;
use App\Services\BookingExcelExport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse(
        '2026-07-30 15:00:00',
        'Asia/Jakarta',
    ));

    Setting::query()->create([
        'id' => 1,
        'daily_booking_limit' => 20,
        'operations_email' => 'operations@example.com',
        'embed_allowed_origins' => [],
    ]);
    Zone::query()->create(['name' => 'Mawar', 'is_active' => true]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('report periods resolve exact Jakarta dates and non overlapping ranges', function () {
    $now = CarbonImmutable::now('Asia/Jakarta');

    expect(OperationsReportPeriod::Morning->targetDate($now))->toBe('2026-07-31')
        ->and(OperationsReportPeriod::Morning->startTime())->toBe('07:00:00')
        ->and(OperationsReportPeriod::Morning->endTime())->toBe('11:00:00')
        ->and(OperationsReportPeriod::Afternoon->targetDate($now))->toBe('2026-07-30')
        ->and(OperationsReportPeriod::Afternoon->startTime())->toBe('12:00:00')
        ->and(OperationsReportPeriod::Afternoon->endTime())->toBe('17:00:00');
});

test('morning preparation dispatches each configured channel once', function () {
    Queue::fake();
    Setting::query()->findOrFail(1)->update([
        'discord_webhook' => 'https://discord.com/api/webhooks/123456/test-token',
    ]);

    phaseNineBooking(['visit_date' => '2026-07-31', 'visit_time' => '07:00:00']);
    phaseNineBooking(['visit_date' => '2026-07-31', 'visit_time' => '11:00:00']);
    phaseNineBooking(['visit_date' => '2026-07-31', 'visit_time' => '12:00:00']);
    phaseNineBooking([
        'visit_date' => '2026-07-31',
        'visit_time' => '10:00:00',
        'status' => BookingStatus::Cancelled,
        'cancelled_at' => now(),
    ]);

    $job = new PrepareOperationsReport(OperationsReportPeriod::Morning);
    $job->handle();
    $job->handle();

    expect(ReportDispatch::query()->count())->toBe(2)
        ->and(ReportDispatch::query()->where('channel', 'email')->count())->toBe(1)
        ->and(ReportDispatch::query()->where('channel', 'discord')->count())->toBe(1);

    Queue::assertPushed(SendOperationsReportEmail::class, 1);
    Queue::assertPushed(SendOperationsReportDiscord::class, 1);
});

test('afternoon preparation uses the current date and excludes morning bookings', function () {
    Queue::fake();

    phaseNineBooking(['visit_date' => '2026-07-30', 'visit_time' => '11:00:00']);
    phaseNineBooking(['visit_date' => '2026-07-30', 'visit_time' => '12:00:00']);
    phaseNineBooking(['visit_date' => '2026-07-31', 'visit_time' => '12:00:00']);

    (new PrepareOperationsReport(OperationsReportPeriod::Afternoon))->handle();

    expect(ReportDispatch::query()->sole()->report_date->toDateString())
        ->toBe('2026-07-30')
        ->and(ReportDispatch::query()->sole()->period)
        ->toBe(OperationsReportPeriod::Afternoon);

    Queue::assertPushed(SendOperationsReportEmail::class, 1);
    Queue::assertNotPushed(SendOperationsReportDiscord::class);
});

test('empty report range sends nothing and creates no dispatch record', function () {
    Queue::fake();

    phaseNineBooking(['visit_date' => '2026-07-31', 'visit_time' => '12:00:00']);
    phaseNineBooking([
        'visit_date' => '2026-07-31',
        'visit_time' => '10:00:00',
        'status' => BookingStatus::Cancelled,
        'cancelled_at' => now(),
    ]);

    (new PrepareOperationsReport(OperationsReportPeriod::Morning))->handle();

    expect(ReportDispatch::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('email delivery uses the configured single recipient and marks success', function () {
    Mail::fake();
    phaseNineBooking(['visit_date' => '2026-07-31', 'visit_time' => '09:00:00']);
    $dispatch = phaseNineDispatch('email', OperationsReportPeriod::Morning, '2026-07-31');

    $job = new SendOperationsReportEmail($dispatch->id);
    $job->handle(app(BookingExcelExport::class));
    $job->handle(app(BookingExcelExport::class));

    Mail::assertSent(OperationsReportMail::class, 1);
    Mail::assertSent(
        OperationsReportMail::class,
        fn (OperationsReportMail $mail): bool => $mail->hasTo('operations@example.com')
            && $mail->bookingCount === 1
            && $mail->period === OperationsReportPeriod::Morning
            && $mail->hasAttachment(
                Attachment::fromPath($mail->reportPath)
                    ->as('laporan-ziarah-2026-07-31-morning_0700_1100.xlsx')
                    ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            )
            && ! file_exists($mail->reportPath),
    );

    $dispatch->refresh();
    expect($dispatch->status)->toBe('sent')
        ->and($dispatch->attempt_count)->toBe(1)
        ->and($dispatch->sent_at)->not->toBeNull()
        ->and($dispatch->last_error_summary)->toBeNull();
});

test('failed email delivery is recorded and remains retryable', function () {
    Mail::shouldReceive('to')
        ->once()
        ->with('operations@example.com')
        ->andThrow(new RuntimeException('simulated smtp failure'));
    phaseNineBooking(['visit_date' => '2026-07-31', 'visit_time' => '09:00:00']);
    $dispatch = phaseNineDispatch('email', OperationsReportPeriod::Morning, '2026-07-31');
    $job = new SendOperationsReportEmail($dispatch->id);

    expect(fn () => $job->handle(app(BookingExcelExport::class)))
        ->toThrow(RuntimeException::class);

    $dispatch->refresh();
    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([60, 300])
        ->and($dispatch->status)->toBe('failed')
        ->and($dispatch->attempt_count)->toBe(1)
        ->and($dispatch->last_error_summary)->toBe(RuntimeException::class);
});

test('missing operations email records a permanent failure without sending', function () {
    Mail::fake();
    Setting::query()->findOrFail(1)->update(['operations_email' => null]);
    phaseNineBooking(['visit_date' => '2026-07-31', 'visit_time' => '09:00:00']);
    $dispatch = phaseNineDispatch('email', OperationsReportPeriod::Morning, '2026-07-31');

    (new SendOperationsReportEmail($dispatch->id))->handle(
        app(BookingExcelExport::class),
    );

    Mail::assertNothingSent();
    expect($dispatch->fresh()->status)->toBe('failed')
        ->and($dispatch->fresh()->last_error_summary)
        ->toBe('operations_email_missing_or_invalid');
});

test('discord delivery loads the encrypted webhook at runtime and marks success', function () {
    Http::fake(['*' => Http::response(status: 204)]);
    $webhook = 'https://discord.com/api/webhooks/123456/test-token';
    Setting::query()->findOrFail(1)->update(['discord_webhook' => $webhook]);
    phaseNineBooking(['visit_date' => '2026-07-30', 'visit_time' => '12:00:00']);
    $dispatch = phaseNineDispatch('discord', OperationsReportPeriod::Afternoon, '2026-07-30');

    (new SendOperationsReportDiscord($dispatch->id))->handle(
        app(BookingExcelExport::class),
    );

    Http::assertSent(fn ($request): bool => $request->url() === $webhook
        && str_contains($request->body(), 'Laporan Persiapan Ziarah Siang')
        && str_contains($request->body(), '1 booking'));

    expect($dispatch->fresh()->status)->toBe('sent')
        ->and($dispatch->fresh()->attempt_count)->toBe(1);
});

test('failed discord delivery is recorded safely and remains retryable', function () {
    Http::fake(['*' => Http::response(status: 500)]);
    Setting::query()->findOrFail(1)->update([
        'discord_webhook' => 'https://discord.com/api/webhooks/123456/private-token',
    ]);
    phaseNineBooking(['visit_date' => '2026-07-30', 'visit_time' => '12:00:00']);
    $dispatch = phaseNineDispatch('discord', OperationsReportPeriod::Afternoon, '2026-07-30');
    $job = new SendOperationsReportDiscord($dispatch->id);

    expect(fn () => $job->handle(app(BookingExcelExport::class)))
        ->toThrow(RequestException::class);

    $dispatch->refresh();
    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([60, 300])
        ->and($dispatch->status)->toBe('failed')
        ->and($dispatch->attempt_count)->toBe(1)
        ->and($dispatch->last_error_summary)->toContain(RequestException::class)
        ->not->toContain('private-token');
});

test('scheduler registers both report runs in Asia Jakarta', function () {
    $events = collect(Schedule::events())->keyBy('description');
    $morning = $events->get('operations-report:morning');
    $afternoon = $events->get('operations-report:afternoon');

    expect($morning)->not->toBeNull()
        ->and($morning->expression)->toBe('0 15 * * *')
        ->and($morning->timezone)->toBe('Asia/Jakarta')
        ->and($afternoon)->not->toBeNull()
        ->and($afternoon->expression)->toBe('0 7 * * *')
        ->and($afternoon->timezone)->toBe('Asia/Jakarta');
});

/** @param array<string, mixed> $overrides */
function phaseNineBooking(array $overrides = []): Booking
{
    $zone = Zone::query()->sole();

    return Booking::query()->create(array_replace([
        'public_reference' => (string) Str::uuid(),
        'status' => BookingStatus::Confirmed,
        'visit_date' => '2026-07-31',
        'visit_time' => '09:00:00',
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

function phaseNineDispatch(
    string $channel,
    OperationsReportPeriod $period,
    string $date,
): ReportDispatch {
    return ReportDispatch::query()->create([
        'report_date' => $date,
        'period' => $period,
        'channel' => $channel,
        'status' => 'pending',
    ]);
}
