<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminBookingIndexRequest;
use App\Http\Requests\UpdateAdminBookingRequest;
use App\Models\Booking;
use App\Models\Setting;
use App\Models\TimeSlot;
use App\Models\Zone;
use App\Services\BookingExcelExport;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookingService,
        private readonly BookingExcelExport $excelExport,
    ) {}

    public function index(AdminBookingIndexRequest $request): Response
    {
        $filters = $request->safe()->except('page');
        $bookings = Booking::query()
            ->adminFiltered($filters)
            ->orderByDesc('visit_date')
            ->orderByDesc('visit_time')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Booking $booking): array => $this->listData($booking));

        return Inertia::render('admin/dashboard', [
            'bookings' => $bookings,
            'filters' => $filters,
            'summary' => [
                'confirmed' => Booking::query()->where('status', BookingStatus::Confirmed)->count(),
                'cancelled' => Booking::query()->where('status', BookingStatus::Cancelled)->count(),
                'completed' => Booking::query()->where('status', BookingStatus::Completed)->count(),
            ],
            'zones' => Zone::query()->orderBy('name')->get(['id', 'name']),
            'time_slots' => TimeSlot::query()
                ->where('is_active', true)
                ->orderBy('start_time')
                ->pluck('start_time')
                ->map(fn (string $time): string => substr($time, 0, 5))
                ->values(),
        ]);
    }

    public function show(Booking $booking): Response
    {
        return Inertia::render('admin/booking-detail', [
            'booking' => $this->detailData($booking),
            'zones' => Zone::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'time_slots' => TimeSlot::query()
                ->where('is_active', true)
                ->orderBy('start_time')
                ->pluck('start_time')
                ->map(fn (string $time): string => substr($time, 0, 5))
                ->values(),
        ]);
    }

    public function update(
        UpdateAdminBookingRequest $request,
        Booking $booking,
    ): RedirectResponse {
        try {
            $this->bookingService->updateByAdmin(
                $booking,
                $request->validated(),
                $this->setting(),
                CarbonImmutable::now(),
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['booking' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.bookings.show', $booking)
            ->with('success', 'Booking berhasil diperbarui.');
    }

    public function cancel(Booking $booking): RedirectResponse
    {
        try {
            $this->bookingService->cancelByAdmin(
                $booking,
                CarbonImmutable::now(),
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['booking' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.bookings.show', $booking)
            ->with('success', 'Booking berhasil dibatalkan.');
    }

    public function export(AdminBookingIndexRequest $request): BinaryFileResponse
    {
        $filters = $request->safe()->except('page');
        $path = tempnam(sys_get_temp_dir(), 'booking-export-');

        if ($path === false) {
            abort(500, 'Gagal menyiapkan file export.');
        }

        $this->excelExport->write(
            Booking::query()
                ->adminFiltered($filters)
                ->orderBy('visit_date')
                ->orderBy('visit_time')
                ->orderBy('id')
                ->cursor(),
            $path,
            isset($filters['date_from']) ? (string) $filters['date_from'] : null,
            isset($filters['date_to']) ? (string) $filters['date_to'] : null,
        );

        return response()
            ->download(
                $path,
                'booking-ziarah.xlsx',
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            )
            ->deleteFileAfterSend(true);
    }

    /** @return array<string, mixed> */
    private function listData(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'public_reference' => $booking->public_reference,
            'status' => $booking->status->value,
            'visit_date' => $booking->visit_date->toDateString(),
            'visit_time' => substr($booking->visit_time, 0, 5),
            'zone' => $booking->zone_name_snapshot,
            'lot_number' => $booking->lot_number,
            'customer_name' => $booking->customer_name,
            'customer_phone' => $booking->customer_phone,
        ];
    }

    /** @return array<string, mixed> */
    private function detailData(Booking $booking): array
    {
        $canModify = $this->bookingService->canAdminModify(
            $booking,
            CarbonImmutable::now(),
        );

        return [
            ...$this->listData($booking),
            'zone_id' => $booking->zone_id,
            'tent_required' => $booking->tent_required,
            'chair_count' => $booking->chair_count,
            'customer_email' => $booking->customer_email,
            'deceased_name' => $booking->deceased_name,
            'additional_notes' => $booking->additional_notes,
            'created_at' => $booking->created_at?->setTimezone(
                (string) config('app.business_timezone'),
            )->toIso8601String(),
            'can_edit' => $canModify,
            'can_cancel' => $canModify,
        ];
    }

    private function setting(): Setting
    {
        return Setting::query()->findOrFail(1);
    }
}
