<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;

class OperationsReportPlanner
{
    /**
     * @param  list<array{day_offset: int, time: string}>  $schedules
     * @param  array<int, string>  $visitTimes
     * @return list<array{day_offset: int, time: string, visit_times: list<string>}>
     */
    public function plan(
        string $visitDate,
        int $leadHours,
        array $schedules,
        array $visitTimes,
    ): array {
        $timezone = (string) config('app.business_timezone');
        $date = CarbonImmutable::parse($visitDate, $timezone)->startOfDay();
        $runs = collect($schedules)
            ->map(fn (array $schedule): array => [
                'day_offset' => (int) $schedule['day_offset'],
                'time' => substr((string) $schedule['time'], 0, 5),
                'visit_times' => [],
            ])
            ->sortBy(fn (array $run): int => ($run['day_offset'] * 1440)
                + ((int) substr($run['time'], 0, 2) * 60)
                + (int) substr($run['time'], 3, 2))
            ->values()
            ->all();

        foreach ($visitTimes as $visitTime) {
            $normalisedTime = strlen($visitTime) === 5 ? $visitTime.':00' : $visitTime;
            $visitAt = $date->setTimeFromTimeString($normalisedTime);
            $cutoff = $visitAt->subHours($leadHours);
            $assigned = false;

            foreach ($runs as &$run) {
                $reportAt = $date
                    ->addDays($run['day_offset'])
                    ->setTimeFromTimeString($run['time']);

                if ($reportAt->greaterThanOrEqualTo($cutoff)
                    && $reportAt->addMinutes(5)->lessThan($visitAt)) {
                    $run['visit_times'][] = $normalisedTime;
                    $assigned = true;
                    break;
                }
            }
            unset($run);

            if (! $assigned) {
                throw new DomainException('Jadwal laporan belum mencakup seluruh jam kunjungan.');
            }
        }

        if (collect($runs)->contains(fn (array $run): bool => $run['visit_times'] === [])) {
            throw new DomainException('Setiap jadwal laporan harus memiliki jam kunjungan.');
        }

        return array_values($runs);
    }

    /** @param array{day_offset: int, time: string, visit_times: list<string>} $run */
    public function isDue(
        string $visitDate,
        array $run,
        CarbonInterface $now,
    ): bool {
        $timezone = (string) config('app.business_timezone');
        $businessNow = CarbonImmutable::instance($now)->setTimezone($timezone);
        $date = CarbonImmutable::parse($visitDate, $timezone)->startOfDay();
        $reportAt = $date
            ->addDays($run['day_offset'])
            ->setTimeFromTimeString($run['time'])
            ->addMinutes(5);
        $firstVisit = $date->setTimeFromTimeString($run['visit_times'][0]);

        return $businessNow->greaterThanOrEqualTo($reportAt)
            && $businessNow->lessThan($firstVisit);
    }
}
