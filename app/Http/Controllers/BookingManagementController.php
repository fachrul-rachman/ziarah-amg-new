<?php

namespace App\Http\Controllers;

use App\Http\Requests\PublicAvailableSlotsRequest;
use App\Http\Requests\RescheduleBookingRequest;
use App\Mail\BookingNotification;
use App\Models\Booking;
use App\Models\BookingManagementToken;
use App\Models\Setting;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use LogicException;

class BookingManagementController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $booking = $this->tokenRecord($request)->booking;
        $now = CarbonImmutable::now();

        return response()->json([
            ...$this->payload($booking),
            'actions' => [
                'reschedule' => $this->bookings->canReschedule($booking, $now),
                'cancel' => $this->bookings->canCancel($booking, $now),
            ],
        ]);
    }

    public function availableSlots(
        PublicAvailableSlotsRequest $request,
    ): JsonResponse {
        $setting = Setting::query()->find(1);

        if ($setting === null) {
            return response()->json([
                'message' => 'Booking is not configured.',
            ], 503);
        }

        $date = (string) $request->validated('date');

        return response()->json([
            'date' => $date,
            ...$this->bookings->slotAvailability(
                $date,
                $setting,
                CarbonImmutable::now(),
                $this->tokenRecord($request)->booking,
            ),
        ]);
    }

    public function reschedule(
        RescheduleBookingRequest $request,
        string $token,
    ): JsonResponse {
        $setting = Setting::query()->find(1);

        if ($setting === null) {
            return response()->json([
                'message' => 'Booking is not configured.',
            ], 503);
        }

        try {
            $result = $this->bookings->reschedule(
                $token,
                $request->safe()->only([
                    'visit_date',
                    'visit_time',
                    'zone_id',
                    'lot_number',
                    'tent_required',
                    'chair_count',
                    'additional_notes',
                ]),
                $setting,
                CarbonImmutable::now(),
            );
        } catch (DomainException $exception) {
            $this->throwDomainValidation($exception);
        }

        $booking = $result['booking'];
        Mail::to($booking->customer_email)->queue(new BookingNotification(
            $booking,
            BookingNotification::RESCHEDULED,
            $result['management_token'],
        ));

        return response()->json($this->payload($booking));
    }

    public function cancel(Request $request, string $token): JsonResponse
    {
        try {
            $booking = $this->bookings->cancel(
                $token,
                CarbonImmutable::now(),
            );
        } catch (DomainException $exception) {
            $this->throwDomainValidation($exception);
        }

        Mail::to($booking->customer_email)->queue(new BookingNotification(
            $booking,
            BookingNotification::CANCELLED,
            null,
        ));

        return response()->json([
            'booking_reference' => $booking->public_reference,
            'status' => $booking->status->value,
        ]);
    }

    private function tokenRecord(Request $request): BookingManagementToken
    {
        $record = $request->attributes->get('management_token_record');

        if (! $record instanceof BookingManagementToken) {
            throw new LogicException('Management token middleware did not run.');
        }

        return $record;
    }

    /** @return array<string, mixed> */
    private function payload(Booking $booking): array
    {
        return [
            'booking_reference' => $booking->public_reference,
            'status' => $booking->status->value,
            'visit' => [
                'date' => $booking->visit_date->toDateString(),
                'time' => substr($booking->visit_time, 0, 5),
                'zone_id' => $booking->zone_id,
                'zone' => $booking->zone_name_snapshot,
                'lot' => $booking->lot_number,
            ],
            'facilities' => [
                'tent_required' => $booking->tent_required,
                'chair_count' => $booking->chair_count,
            ],
            'customer' => [
                'name' => $booking->customer_name,
                'email' => $booking->customer_email,
                'phone' => $booking->customer_phone,
                'deceased_name' => $booking->deceased_name,
                'additional_notes' => $booking->additional_notes,
            ],
        ];
    }

    private function throwDomainValidation(DomainException $exception): never
    {
        if ($exception->getMessage() === 'Booking management link is not available.') {
            throw new HttpResponseException(response()->json([
                'message' => 'Booking management link is not available.',
            ], 404));
        }

        $field = str_contains($exception->getMessage(), 'date is full')
            ? 'visit_date'
            : (str_contains($exception->getMessage(), 'time is full')
                ? 'visit_time'
                : 'booking');

        throw ValidationException::withMessages([
            $field => $exception->getMessage(),
        ]);
    }
}
