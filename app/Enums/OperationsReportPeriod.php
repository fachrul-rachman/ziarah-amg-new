<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum OperationsReportPeriod: string
{
    case Afternoon = 'afternoon_1200_1700';
    case Morning = 'morning_0700_1100';
    case MorningFinal = 'morning_final_0700_1100';

    public function targetDate(CarbonInterface $now): string
    {
        $businessNow = CarbonImmutable::instance($now)
            ->setTimezone((string) config('app.business_timezone'));
        $today = $businessNow->startOfDay();

        return match ($this) {
            self::Morning => $today->addDay()->toDateString(),
            self::MorningFinal => ($businessNow->hour < 7 ? $today : $today->addDay())
                ->toDateString(),
            self::Afternoon => $today->toDateString(),
        };
    }

    public function shouldPrepare(CarbonInterface $now): bool
    {
        $businessNow = CarbonImmutable::instance($now)
            ->setTimezone((string) config('app.business_timezone'));
        $minute = ($businessNow->hour * 60) + $businessNow->minute;

        return match ($this) {
            self::Morning => $minute >= 15 * 60 && $minute <= 17 * 60,
            self::MorningFinal => $minute >= (17 * 60) + 5 || $minute <= (6 * 60) + 55,
            self::Afternoon => $minute >= 7 * 60 && $minute <= 17 * 60,
        };
    }

    public function startTime(): string
    {
        return match ($this) {
            self::Morning, self::MorningFinal => '07:00:00',
            self::Afternoon => '12:00:00',
        };
    }

    public function endTime(): string
    {
        return match ($this) {
            self::Morning, self::MorningFinal => '11:00:00',
            self::Afternoon => '17:00:00',
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Morning => 'Laporan Persiapan Ziarah Pagi',
            self::MorningFinal => 'Laporan Final Persiapan Ziarah Pagi',
            self::Afternoon => 'Laporan Persiapan Ziarah Siang',
        };
    }
}
