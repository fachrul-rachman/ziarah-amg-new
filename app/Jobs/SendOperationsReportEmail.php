<?php

namespace App\Jobs;

use App\Mail\OperationsReportMail;
use App\Models\ReportDispatch;
use App\Models\Setting;
use App\Services\BookingExcelExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendOperationsReportEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $dispatchId) {}

    public function handle(BookingExcelExport $excelExport): void
    {
        $dispatch = ReportDispatch::query()->findOrFail($this->dispatchId);

        if ($dispatch->status === 'sent') {
            return;
        }

        $dispatch->startAttempt();
        $email = Setting::query()->find(1)?->operations_email;

        if (! is_string($email)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $dispatch->markFailed('operations_email_missing_or_invalid');
            Log::error('Operations report email recipient is unavailable.', [
                'report_dispatch_id' => $dispatch->id,
            ]);

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
            Mail::to($email)->send(new OperationsReportMail(
                $path,
                $dispatch->report_date->toDateString(),
                $dispatch->period,
                $count,
                $dispatch->title(),
                $dispatch->startTime(),
                $dispatch->endTime(),
            ));
            $dispatch->markSent();
        } catch (Throwable $exception) {
            $dispatch->markFailed($exception::class);
            Log::error('Operations report email delivery failed.', [
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
