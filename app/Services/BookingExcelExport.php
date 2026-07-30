<?php

namespace App\Services;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Traversable;

class BookingExcelExport
{
    /** @param Traversable<int, Booking> $bookings */
    public function writeTemporary(Traversable $bookings, string $reportDate): string
    {
        $path = tempnam(sys_get_temp_dir(), 'booking-report-');

        if ($path === false) {
            throw new \RuntimeException('Unable to create report file.');
        }

        $this->write($bookings, $path, $reportDate, $reportDate);

        return $path;
    }

    /** @param Traversable<int, Booking> $bookings */
    public function write(
        Traversable $bookings,
        string $path,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): void {
        $options = new Options;
        $options->mergeCells(0, 1, 9, 1);
        $options->setColumnWidth(17, 1);
        $options->setColumnWidth(10, 2);
        $options->setColumnWidth(18, 3);
        $options->setColumnWidth(16, 4);
        $options->setColumnWidth(24, 5);
        $options->setColumnWidth(30, 6);
        $options->setColumnWidth(19, 7);
        $options->setColumnWidth(24, 8);
        $options->setColumnWidth(36, 9);
        $options->setColumnWidth(31, 10);

        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()
            ->setName('Info Ziarah')
            ->setSheetView(
                (new SheetView)
                    ->setShowGridLines(false)
                    ->setFreezeRow(3),
            );
        $writer->getCurrentSheet()->setPrintTitleRows('1:2');

        $title = Row::fromValues(
            [
                'INFO ZIARAH Tanggal '.$this->titleDate($dateFrom, $dateTo),
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
            ],
            $this->titleStyle(),
        )->setHeight(30);
        $writer->addRow($title);

        $header = Row::fromValues([
            'Tanggal Ziarah',
            'Jam',
            'Zona',
            'Nomor lot',
            'Nama Pemesan',
            'Email',
            'Nomor Telfon',
            'Nama Alm/Ah',
            'Catatan',
            'Fasilitas (Tenda, jumlah kursi)',
        ], $this->headerStyle())->setHeight(34);
        $writer->addRow($header);

        $index = 0;
        foreach ($bookings as $booking) {
            $rowStyle = $this->bodyStyle($index % 2 === 1);
            $dateStyle = (new Style)->setFormat('dd/mm/yyyy');
            $phoneStyle = (clone $rowStyle)->setFormat('@');
            $row = Row::fromValuesWithStyles([
                $booking->visit_date,
                substr($booking->visit_time, 0, 5),
                $this->safeText($booking->zone_name_snapshot),
                $this->safeText($booking->lot_number),
                $this->safeText($booking->customer_name),
                $this->safeText($booking->customer_email),
                $this->safeText($booking->customer_phone),
                $this->safeText($booking->deceased_name),
                $this->safeText($booking->additional_notes),
                sprintf(
                    'Tenda: %s | Jumlah kursi: %d',
                    $booking->tent_required ? 'Ya' : 'Tidak',
                    $booking->chair_count,
                ),
            ], $rowStyle, [0 => $dateStyle, 6 => $phoneStyle]);
            $writer->addRow($row);
            $index++;
        }

        $writer->close();
    }

    private function titleDate(?string $dateFrom, ?string $dateTo): string
    {
        if ($dateFrom === null && $dateTo === null) {
            return 'Semua Tanggal';
        }

        $format = static function (string $date): string {
            $value = CarbonImmutable::parse($date);
            $value->locale('id');

            return $value->translatedFormat('j F Y');
        };

        if ($dateFrom !== null && $dateTo !== null) {
            return $dateFrom === $dateTo
                ? $format($dateFrom)
                : $format($dateFrom).' sampai '.$format($dateTo);
        }

        return ($dateFrom !== null ? 'Mulai ' : 'Sampai ')
            .$format($dateFrom ?? $dateTo);
    }

    private function titleStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setFontSize(16)
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor('172746')
            ->setCellAlignment(CellAlignment::CENTER)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER);
    }

    private function headerStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor('1796C7')
            ->setBorder($this->border('0F7399'))
            ->setCellAlignment(CellAlignment::CENTER)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->setShouldWrapText();
    }

    private function bodyStyle(bool $alternate): Style
    {
        $style = (new Style)
            ->setBorder($this->border('D7E0E8'))
            ->setCellVerticalAlignment(CellVerticalAlignment::TOP)
            ->setShouldWrapText();

        if ($alternate) {
            $style->setBackgroundColor('F1F8FB');
        }

        return $style;
    }

    private function border(string $color): Border
    {
        return new Border(
            new BorderPart(Border::TOP, $color, Border::WIDTH_THIN),
            new BorderPart(Border::RIGHT, $color, Border::WIDTH_THIN),
            new BorderPart(Border::BOTTOM, $color, Border::WIDTH_THIN),
            new BorderPart(Border::LEFT, $color, Border::WIDTH_THIN),
        );
    }

    private function safeText(?string $value): string
    {
        $value ??= '';

        return Str::startsWith($value, ['=', '+', '-', '@'])
            ? "'".$value
            : $value;
    }
}
