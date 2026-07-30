<?php

namespace App\Models;

use App\Enums\OperationsReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonImmutable $report_date
 * @property OperationsReportPeriod $period
 * @property string $channel
 * @property string $status
 * @property int $attempt_count
 * @property CarbonImmutable|null $sent_at
 * @property string|null $last_error_summary
 */
class ReportDispatch extends Model
{
    protected $fillable = [
        'report_date',
        'period',
        'channel',
        'status',
        'attempt_count',
        'sent_at',
        'last_error_summary',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'immutable_date',
            'period' => OperationsReportPeriod::class,
            'attempt_count' => 'integer',
            'sent_at' => 'immutable_datetime',
        ];
    }

    /** @return Builder<Booking> */
    public function bookings(): Builder
    {
        return Booking::query()
            ->confirmed()
            ->whereDate('visit_date', $this->report_date->toDateString())
            ->whereBetween('visit_time', [
                $this->period->startTime(),
                $this->period->endTime(),
            ])
            ->orderBy('visit_time')
            ->orderBy('id');
    }

    public function startAttempt(): void
    {
        $this->forceFill([
            'status' => 'processing',
            'attempt_count' => $this->attempt_count + 1,
            'last_error_summary' => null,
        ])->save();
    }

    public function markSent(): void
    {
        $this->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
            'last_error_summary' => null,
        ])->save();
    }

    public function markFailed(string $summary): void
    {
        $this->forceFill([
            'status' => 'failed',
            'last_error_summary' => $summary,
        ])->save();
    }

    public function markSkipped(string $summary): void
    {
        $this->forceFill([
            'status' => 'skipped',
            'last_error_summary' => $summary,
        ])->save();
    }
}
