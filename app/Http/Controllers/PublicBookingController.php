<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePublicBookingRequest;
use App\Mail\BookingNotification;
use App\Models\Setting;
use App\Services\BookingService;
use App\Services\TurnstileVerifier;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PublicBookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly TurnstileVerifier $turnstile,
    ) {}

    public function store(StorePublicBookingRequest $request): JsonResponse
    {
        $setting = Setting::query()->find(1);

        if ($setting === null) {
            return response()->json([
                'message' => 'Booking is not configured.',
            ], 503);
        }

        if (! $this->turnstile->verify(
            (string) $request->validated('turnstile_token'),
            $request->ip(),
        )) {
            throw ValidationException::withMessages([
                'turnstile_token' => 'Verification failed. Please try again.',
            ]);
        }

        $attributes = $request->safe()->only([
            'visit_date',
            'visit_time',
            'zone_id',
            'lot_number',
            'tent_required',
            'chair_count',
            'customer_name',
            'customer_email',
            'customer_phone',
            'deceased_name',
            'additional_notes',
        ]);
        $attributes['ethics_confirmed_at'] = CarbonImmutable::now();

        try {
            $result = $this->bookings->createConfirmed(
                $attributes,
                $setting->daily_booking_limit,
                CarbonImmutable::now(),
            );
        } catch (DomainException $exception) {
            $field = str_contains($exception->getMessage(), 'full')
                ? 'visit_date'
                : 'visit_time';

            throw ValidationException::withMessages([
                $field => $exception->getMessage(),
            ]);
        }

        $booking = $result['booking'];
        Mail::to($booking->customer_email)->queue(new BookingNotification(
            $booking,
            BookingNotification::CONFIRMED,
            $result['management_token'],
        ));

        return response()->json([
            'booking_reference' => $booking->public_reference,
            'status' => $booking->status->value,
            'visit' => [
                'date' => $booking->visit_date->toDateString(),
                'time' => substr($booking->visit_time, 0, 5),
                'zone' => $booking->zone_name_snapshot,
                'lot' => $booking->lot_number,
            ],
        ], 201);
    }
}
