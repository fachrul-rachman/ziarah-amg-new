<?php

use App\Models\Booking;
use App\Services\BookingExcelExport;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

uses(TestCase::class);

test('booking excel excludes customer email and phone', function () {
    $booking = new Booking([
        'visit_date' => '2026-08-12',
        'visit_time' => '09:00:00',
        'zone_name_snapshot' => 'Mawar',
        'lot_number' => 'A-01',
        'customer_name' => 'Budi',
        'customer_email' => 'budi@example.com',
        'customer_phone' => '08123456789',
        'deceased_name' => 'Almarhum',
        'additional_notes' => 'Catatan',
        'tent_required' => false,
        'chair_count' => 2,
    ]);
    $path = tempnam(sys_get_temp_dir(), 'booking-export-test-');

    expect($path)->not->toBeFalse();

    (new BookingExcelExport)->write(
        new ArrayIterator([$booking]),
        $path,
        '2026-08-12',
        '2026-08-12',
    );

    $reader = new Reader;
    $reader->open($path);
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }

    $reader->close();
    unlink($path);

    expect($rows[1])->toBe([
        'Tanggal Ziarah',
        'Jam',
        'Zona',
        'Nomor lot',
        'Nama Pemesan',
        'Nama Alm/Ah',
        'Catatan',
        'Fasilitas (Tenda, jumlah kursi)',
    ])->and($rows[2])
        ->not->toContain('budi@example.com')
        ->not->toContain('08123456789');
});
