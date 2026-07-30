<?php

use App\Models\Booking;
use App\Models\Setting;
use App\Models\TimeSlot;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

test('concurrent bookings cannot exceed the final configured quota unit', function (
    string $mode,
) {
    $now = CarbonImmutable::parse('2026-07-29 10:00:00', 'Asia/Jakarta');
    $date = $now->addDays(2)->toDateString();
    $zone = Zone::query()->create(['name' => 'Mawar', 'is_active' => true]);
    TimeSlot::query()->create(['start_time' => '10:00:00', 'is_active' => true]);
    Setting::query()->create([
        'id' => 1,
        'booking_limit_mode' => $mode,
        'daily_booking_limit' => $mode === Setting::LIMIT_DAILY ? 1 : 100,
        'hourly_booking_limit' => $mode === Setting::LIMIT_HOURLY ? 1 : null,
        'operations_email' => 'ops@example.com',
        'embed_allowed_origins' => [],
    ]);

    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION delay_test_booking_insert()
        RETURNS trigger AS $$
        BEGIN
            PERFORM pg_sleep(1);
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;

        CREATE TRIGGER delay_test_booking_insert
        BEFORE INSERT ON bookings
        FOR EACH ROW EXECUTE FUNCTION delay_test_booking_insert();
        SQL);

    $makeProcess = function (string $email) use ($date, $now, $zone): Process {
        $attributes = var_export([
            'visit_date' => $date,
            'visit_time' => '10:00:00',
            'zone_id' => $zone->id,
            'lot_number' => 'DSD810',
            'tent_required' => false,
            'chair_count' => 2,
            'customer_name' => 'Customer',
            'customer_email' => $email,
            'customer_phone' => '08123456789',
            'deceased_name' => 'Deceased',
            'additional_notes' => null,
            'ethics_confirmed_at' => $now->toIso8601String(),
        ], true);
        $code = sprintf(
            <<<'PHP'
                require 'vendor/autoload.php';
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                app(App\Services\BookingService::class)->createConfirmed(
                    %s,
                    App\Models\Setting::query()->findOrFail(1),
                    Carbon\CarbonImmutable::parse('%s', 'Asia/Jakarta'),
                );
                PHP,
            $attributes,
            $now->format('Y-m-d H:i:s'),
        );

        return new Process(
            ['php', '-r', $code],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => DB::getDefaultConnection(),
                'DB_HOST' => (string) config('database.connections.pgsql.host'),
                'DB_PORT' => (string) config('database.connections.pgsql.port'),
                'DB_DATABASE' => DB::connection()->getDatabaseName(),
                'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
                'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
            ],
            timeout: 30,
        );
    };

    $first = $makeProcess('first@example.com');
    $second = $makeProcess('second@example.com');

    try {
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();
    } finally {
        DB::unprepared('DROP FUNCTION IF EXISTS delay_test_booking_insert() CASCADE');
    }

    $successfulProcesses = collect([$first, $second])
        ->filter(fn (Process $process): bool => $process->isSuccessful())
        ->count();

    expect($successfulProcesses)->toBe(1)
        ->and(Booking::query()->confirmed()->where('visit_date', $date)->count())->toBe(1);
})->with([
    'daily limit' => Setting::LIMIT_DAILY,
    'hourly limit' => Setting::LIMIT_HOURLY,
]);
