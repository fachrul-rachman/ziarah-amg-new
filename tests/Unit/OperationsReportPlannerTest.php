<?php

use App\Services\OperationsReportPlanner;
use Carbon\CarbonImmutable;
use DomainException;
use Tests\TestCase;

uses(TestCase::class);

test('report schedules partition visit slots from their booking cutoffs', function (
    int $leadHours,
    array $expected,
) {
    $runs = app(OperationsReportPlanner::class)->plan(
        '2026-08-04',
        $leadHours,
        [
            ['day_offset' => -1, 'time' => '15:00'],
            ['day_offset' => 0, 'time' => '07:00'],
        ],
        array_map(fn (int $hour): string => sprintf('%02d:00:00', $hour), range(7, 17)),
    );

    expect(array_column($runs, 'visit_times'))->toBe($expected);
})->with([
    '18 hours' => [18, [
        ['07:00:00', '08:00:00', '09:00:00'],
        ['10:00:00', '11:00:00', '12:00:00', '13:00:00', '14:00:00', '15:00:00', '16:00:00', '17:00:00'],
    ]],
    '19 hours' => [19, [
        ['07:00:00', '08:00:00', '09:00:00', '10:00:00'],
        ['11:00:00', '12:00:00', '13:00:00', '14:00:00', '15:00:00', '16:00:00', '17:00:00'],
    ]],
]);

test('report schedules reject an empty or uncovered partition', function (array $schedules) {
    expect(fn () => app(OperationsReportPlanner::class)->plan(
        '2026-08-04',
        18,
        $schedules,
        ['07:00:00', '08:00:00'],
    ))->toThrow(DomainException::class);
})->with([
    'empty schedule' => [[
        ['day_offset' => -1, 'time' => '13:00'],
        ['day_offset' => -1, 'time' => '15:00'],
        ['day_offset' => 0, 'time' => '07:00'],
    ]],
    'uncovered slot' => [[
        ['day_offset' => -1, 'time' => '12:00'],
    ]],
]);

test('a report becomes due on the first five minute tick after its cutoff', function () {
    $planner = app(OperationsReportPlanner::class);
    $run = [
        'day_offset' => -1,
        'time' => '15:00',
        'visit_times' => ['07:00:00'],
    ];

    expect($planner->isDue('2026-08-04', $run, CarbonImmutable::parse('2026-08-03 15:04:59', 'Asia/Jakarta')))->toBeFalse()
        ->and($planner->isDue('2026-08-04', $run, CarbonImmutable::parse('2026-08-03 15:05:00', 'Asia/Jakarta')))->toBeTrue();
});
