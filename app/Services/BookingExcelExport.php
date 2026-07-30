<?php

namespace App\Services;

use App\Models\Booking;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Traversable;

class BookingExcelExport
{
    /** @param Traversable<int, Booking> $bookings */
    public function writeTemporary(Traversable $bookings): string
    {
        $path = tempnam(sys_get_temp_dir(), 'booking-report-');

        if ($path === false) {
            throw new \RuntimeException('Unable to create report file.');
        }

        $this->write($bookings, $path);

        return $path;
    }

    /** @param Traversable<int, Booking> $bookings */
    public function write(Traversable $bookings, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues([
            'Kode Booking',
            'Status',
            'Tanggal',
            'Jam',
            'Zona',
            'Nomor Lot',
            'Tenda',
            'Jumlah Kursi',
            'Nama Pemesan',
            'Email',
            'Nomor Telepon',
            'Nama Alm',
            'Catatan Tambahan',
            'Dibuat Pada',
        ]));

        foreach ($bookings as $booking) {
            $writer->addRow(Row::fromValues([
                $booking->public_reference,
                $booking->status->value,
                $booking->visit_date->toDateString(),
                substr($booking->visit_time, 0, 5),
                $this->safeText($booking->zone_name_snapshot),
                $this->safeText($booking->lot_number),
                $booking->tent_required ? 'Ya' : 'Tidak',
                $booking->chair_count,
                $this->safeText($booking->customer_name),
                $this->safeText($booking->customer_email),
                $this->safeText($booking->customer_phone),
                $this->safeText($booking->deceased_name),
                $this->safeText($booking->additional_notes),
                $booking->created_at?->setTimezone(
                    (string) config('app.business_timezone'),
                )->format('Y-m-d H:i:s'),
            ]));
        }

        $writer->close();
    }

    private function safeText(?string $value): string
    {
        $value ??= '';

        return Str::startsWith($value, ['=', '+', '-', '@'])
            ? "'".$value
            : $value;
    }
}
