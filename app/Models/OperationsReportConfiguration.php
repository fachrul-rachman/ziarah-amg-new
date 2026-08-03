<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $minimum_lead_hours
 * @property list<array{day_offset: int, time: string}> $report_schedules
 * @property CarbonImmutable $effective_from
 */
class OperationsReportConfiguration extends Model
{
    protected $fillable = [
        'effective_from',
        'minimum_lead_hours',
        'report_schedules',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'minimum_lead_hours' => 'integer',
            'report_schedules' => 'array',
        ];
    }

    public static function forVisitDate(string $visitDate): ?self
    {
        return self::query()
            ->whereDate('effective_from', '<=', $visitDate)
            ->orderByDesc('effective_from')
            ->first();
    }
}
