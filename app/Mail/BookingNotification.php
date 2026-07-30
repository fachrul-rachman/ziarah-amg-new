<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingNotification extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public const string CANCELLED = 'cancelled';

    public const string CONFIRMED = 'confirmed';

    public const string RESCHEDULED = 'rescheduled';

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public Booking $booking,
        public string $kind,
        public ?string $managementToken,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->kind) {
            self::CONFIRMED => 'Booking ziarah telah dikonfirmasi',
            self::RESCHEDULED => 'Jadwal booking ziarah telah diperbarui',
            self::CANCELLED => 'Booking ziarah telah dibatalkan',
            default => 'Informasi booking ziarah',
        });
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.booking-notification',
            text: 'mail.booking-notification-text',
            with: [
                'managementUrl' => $this->managementToken === null
                    ? null
                    : route('booking.manage', [
                        'token' => $this->managementToken,
                    ]),
            ],
        );
    }
}
