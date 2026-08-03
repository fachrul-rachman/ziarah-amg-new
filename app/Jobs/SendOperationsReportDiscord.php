<?php

namespace App\Jobs;

use App\Models\ReportDispatch;
use App\Models\Setting;
use App\Services\BookingExcelExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class SendOperationsReportDiscord implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $dispatchId) {}

    /** @throws JsonException */
    public function handle(BookingExcelExport $excelExport): void
    {
        $dispatch = ReportDispatch::query()->findOrFail($this->dispatchId);

        if ($dispatch->status === 'sent') {
            return;
        }

        $dispatch->startAttempt();
        $webhook = Setting::query()->find(1)?->discord_webhook;

        if (! is_string($webhook) || $webhook === '') {
            $dispatch->markSkipped('discord_webhook_missing');

            return;
        }

        $count = $dispatch->bookings()->count();

        if ($count === 0) {
            $dispatch->markSkipped('no_matching_bookings');

            return;
        }

        $path = null;

        try {
            $path = $excelExport->writeTemporary(
                $dispatch->bookings()->cursor(),
                $dispatch->report_date->toDateString(),
            );
            $filename = "laporan-ziarah-{$dispatch->report_date->toDateString()}-{$dispatch->period}.xlsx";
            $fileContents = file_get_contents($path);

            if ($fileContents === false) {
                throw new \RuntimeException('Unable to read generated report file.');
            }

            $content = implode("\n", [
                "**{$dispatch->title()}**",
                "Tanggal: {$dispatch->report_date->toDateString()}",
                'Waktu: '.substr($dispatch->startTime(), 0, 5)
                    .' sampai '.substr($dispatch->endTime(), 0, 5).' WIB',
                "Jumlah: {$count} booking",
            ]);

            Http::connectTimeout(5)
                ->timeout(15)
                ->attach(
                    'files[0]',
                    $fileContents,
                    $filename,
                    ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                )
                ->post($webhook, [
                    'payload_json' => json_encode(
                        ['content' => $content],
                        JSON_THROW_ON_ERROR,
                    ),
                ])
                ->throw();

            $dispatch->markSent();
        } catch (Throwable $exception) {
            $dispatch->markFailed($exception::class);
            Log::error('Operations report Discord delivery failed.', [
                'report_dispatch_id' => $dispatch->id,
                'error_class' => $exception::class,
            ]);

            throw $exception;
        } finally {
            if ($path !== null && is_file($path)) {
                unlink($path);
            }
        }
    }
}
