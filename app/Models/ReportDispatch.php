<?php

namespace App\Models;

use App\Enums\OperationsReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonImmutable $report_date
 * @property string $period
 * @property list<string>|null $visit_times
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
        'visit_times',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'immutable_date',
            'attempt_count' => 'integer',
            'sent_at' => 'immutable_datetime',
            'visit_times' => 'array',
        ];
    }

    /** @return Builder<Booking> */
    public function bookings(): Builder
    {
        return Booking::query()
            ->confirmed()
            ->whereDate('visit_date', $this->report_date->toDateString())
            ->when(
                $this->visit_times !== null && $this->visit_times !== [],
                fn (Builder $query): Builder => $query->whereIn('visit_time', $this->visit_times),
                fn (Builder $query): Builder => $query->whereBetween('visit_time', [
                    $this->legacyPeriod()->startTime(),
                    $this->legacyPeriod()->endTime(),
                ]),
            )
            ->orderBy('visit_time')
            ->orderBy('id');
    }

    public function title(): string
    {
        return $this->visit_times === null || $this->visit_times === []
            ? $this->legacyPeriod()->title()
            : 'Laporan Persiapan Ziarah';
    }

    public function startTime(): string
    {
        return $this->visit_times === null || $this->visit_times === []
            ? $this->legacyPeriod()->startTime()
            : $this->visit_times[0];
    }

    public function endTime(): string
    {
        return $this->visit_times === null || $this->visit_times === []
            ? $this->legacyPeriod()->endTime()
            : $this->visit_times[count($this->visit_times) - 1];
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

    private function legacyPeriod(): OperationsReportPeriod
    {
        return OperationsReportPeriod::from($this->period);
    }
}
