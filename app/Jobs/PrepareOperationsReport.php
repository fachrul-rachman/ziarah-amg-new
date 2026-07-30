<?php

namespace App\Jobs;

use App\Enums\OperationsReportPeriod;
use App\Models\Booking;
use App\Models\ReportDispatch;
use App\Models\Setting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PrepareOperationsReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public OperationsReportPeriod $period) {}

    public function handle(): void
    {
        $reportDate = $this->period->targetDate(now());
        $hasBookings = Booking::query()
            ->confirmed()
            ->whereDate('visit_date', $reportDate)
            ->whereBetween('visit_time', [
                $this->period->startTime(),
                $this->period->endTime(),
            ])
            ->exists();

        if (! $hasBookings) {
            return;
        }

        $this->dispatchChannel($reportDate, 'email');

        if (Setting::query()->find(1)?->discord_webhook !== null) {
            $this->dispatchChannel($reportDate, 'discord');
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Operations report preparation failed.', [
            'period' => $this->period->value,
            'error_class' => $exception === null ? null : $exception::class,
        ]);
    }

    private function dispatchChannel(string $reportDate, string $channel): void
    {
        DB::transaction(function () use ($reportDate, $channel): void {
            $now = now();
            $inserted = ReportDispatch::query()->insertOrIgnore([
                'report_date' => $reportDate,
                'period' => $this->period->value,
                'channel' => $channel,
                'status' => 'pending',
                'attempt_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted !== 1) {
                return;
            }

            $dispatchId = (int) ReportDispatch::query()
                ->where('report_date', $reportDate)
                ->where('period', $this->period->value)
                ->where('channel', $channel)
                ->valueOrFail('id');

            if ($channel === 'email') {
                SendOperationsReportEmail::dispatch($dispatchId);

                return;
            }

            SendOperationsReportDiscord::dispatch($dispatchId);
        });
    }
}
