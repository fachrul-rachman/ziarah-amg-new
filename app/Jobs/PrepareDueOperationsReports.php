<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\OperationsReportConfiguration;
use App\Models\ReportDispatch;
use App\Models\Setting;
use App\Models\TimeSlot;
use App\Services\OperationsReportPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PrepareDueOperationsReports implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function handle(OperationsReportPlanner $planner): void
    {
        $setting = Setting::query()->find(1);

        if ($setting === null) {
            return;
        }

        $now = CarbonImmutable::now((string) config('app.business_timezone'));
        $visitTimes = TimeSlot::query()
            ->orderBy('start_time')
            ->pluck('start_time')
            ->map(fn (string $time): string => $time)
            ->values()
            ->all();

        if ($visitTimes === []) {
            return;
        }

        foreach ([$now->toDateString(), $now->addDay()->toDateString()] as $visitDate) {
            $configuration = OperationsReportConfiguration::forVisitDate($visitDate);

            if ($configuration === null) {
                continue;
            }

            $runs = $planner->plan(
                $visitDate,
                $configuration->minimum_lead_hours,
                $configuration->report_schedules,
                $visitTimes,
            );

            foreach ($runs as $index => $run) {
                if (! $planner->isDue($visitDate, $run, $now)
                    || ! Booking::query()->confirmed()
                        ->whereDate('visit_date', $visitDate)
                        ->whereIn('visit_time', $run['visit_times'])
                        ->exists()) {
                    continue;
                }

                $this->dispatchChannels(
                    $setting,
                    $configuration->id,
                    $visitDate,
                    $index + 1,
                    $run['visit_times'],
                );
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Configurable operations report preparation failed.', [
            'error_class' => $exception === null ? null : $exception::class,
        ]);
    }

    /** @param list<string> $visitTimes */
    private function dispatchChannels(
        Setting $setting,
        int $configurationId,
        string $visitDate,
        int $scheduleIndex,
        array $visitTimes,
    ): void {
        $this->dispatchChannel($configurationId, $visitDate, $scheduleIndex, $visitTimes, 'email');

        if ($setting->discord_webhook !== null) {
            $this->dispatchChannel($configurationId, $visitDate, $scheduleIndex, $visitTimes, 'discord');
        }
    }

    /** @param list<string> $visitTimes */
    private function dispatchChannel(
        int $configurationId,
        string $visitDate,
        int $scheduleIndex,
        array $visitTimes,
        string $channel,
    ): void {
        DB::transaction(function () use ($configurationId, $visitDate, $scheduleIndex, $visitTimes, $channel): void {
            $period = "scheduled_{$configurationId}_{$scheduleIndex}";
            $now = now();
            $inserted = ReportDispatch::query()->insertOrIgnore([
                'report_date' => $visitDate,
                'period' => $period,
                'visit_times' => json_encode($visitTimes, JSON_THROW_ON_ERROR),
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
                ->where('report_date', $visitDate)
                ->where('period', $period)
                ->where('channel', $channel)
                ->valueOrFail('id');

            $channel === 'email'
                ? SendOperationsReportEmail::dispatch($dispatchId)
                : SendOperationsReportDiscord::dispatch($dispatchId);
        });
    }
}
