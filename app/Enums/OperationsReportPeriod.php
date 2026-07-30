<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum OperationsReportPeriod: string
{
    case Afternoon = 'afternoon_1200_1700';
    case Morning = 'morning_0700_1100';

    public function targetDate(CarbonInterface $now): string
    {
        $today = CarbonImmutable::instance($now)
            ->setTimezone((string) config('app.business_timezone'))
            ->startOfDay();

        return match ($this) {
            self::Morning => $today->addDay()->toDateString(),
            self::Afternoon => $today->toDateString(),
        };
    }

    public function startTime(): string
    {
        return match ($this) {
            self::Morning => '07:00:00',
            self::Afternoon => '12:00:00',
        };
    }

    public function endTime(): string
    {
        return match ($this) {
            self::Morning => '11:00:00',
            self::Afternoon => '17:00:00',
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Morning => 'Laporan Persiapan Ziarah Pagi',
            self::Afternoon => 'Laporan Persiapan Ziarah Siang',
        };
    }
}
