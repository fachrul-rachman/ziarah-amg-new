<?php

namespace App\Mail;

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
        public string $period,
        public int $bookingCount,
        public string $periodTitle,
        public string $startTime,
        public string $endTime,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->periodTitle} - {$this->reportDate}",
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
                ->as("laporan-ziarah-{$this->reportDate}-{$this->period}.xlsx")
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }
}
