<?php

namespace App\Mail;

use App\Enums\OperationsReportPeriod;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OperationsReportMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $reportPath,
        public string $reportDate,
        public OperationsReportPeriod $period,
        public int $bookingCount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->period->title()} - {$this->reportDate}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.operations-report',
            text: 'mail.operations-report-text',
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->reportPath)
                ->as("laporan-ziarah-{$this->reportDate}-{$this->period->value}.xlsx")
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }
}
