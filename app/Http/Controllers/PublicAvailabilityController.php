<?php

namespace App\Http\Controllers;

use App\Http\Requests\PublicAvailableSlotsRequest;
use App\Http\Requests\PublicBookingOptionsRequest;
use App\Models\Setting;
use App\Models\TimeSlot;
use App\Models\Zone;
use App\Services\BookingFormToken;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class PublicAvailabilityController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly BookingFormToken $formToken,
    ) {}

    public function bookingOptions(PublicBookingOptionsRequest $request): JsonResponse
    {
        $setting = $this->setting();

        if ($setting === null) {
            return $this->notConfigured();
        }

        $now = CarbonImmutable::now()->setTimezone(
            (string) config('app.business_timezone'),
        );
        $start = (string) ($request->validated('start_date') ?? $now->addDay()->toDateString());
        $end = (string) ($request->validated('end_date') ?? $now->addDays(100)->toDateString());
        $zoneSearch = trim((string) ($request->validated('zone_search') ?? ''));

        $zones = Zone::query()
            ->where('is_active', true)
            ->when(
                $zoneSearch !== '',
                fn (Builder $query): Builder => $query->whereRaw(
                    'LOWER(name) LIKE ?',
                    ['%'.mb_strtolower($zoneSearch).'%'],
                ),
            )
            ->orderBy('name')
            ->get(['id', 'name']);

        $timeSlots = TimeSlot::query()
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get(['id', 'start_time'])
            ->map(fn (TimeSlot $slot): array => [
                'id' => $slot->id,
                'start_time' => substr((string) $slot->start_time, 0, 5),
            ]);

        return response()->json([
            'server_time' => $now->toIso8601String(),
            'earliest_date' => $now->addDay()->toDateString(),
            'latest_date' => $now->addDays(100)->toDateString(),
            'daily_booking_limit' => $setting->daily_booking_limit,
            'form_token' => $this->formToken->issue(),
            'zones' => $zones,
            'time_slots' => $timeSlots,
            'dates' => $this->bookings->dateAvailability(
                $start,
                $end,
                $setting->daily_booking_limit,
            ),
        ]);
    }

    public function availableSlots(PublicAvailableSlotsRequest $request): JsonResponse
    {
        $setting = $this->setting();

        if ($setting === null) {
            return $this->notConfigured();
        }

        $date = (string) $request->validated('date');
        $now = CarbonImmutable::now();
        $availability = $this->bookings->slotAvailability(
            $date,
            $setting->daily_booking_limit,
            $now,
        );

        return response()->json([
            'date' => $date,
            ...$availability,
        ]);
    }

    private function setting(): ?Setting
    {
        return Setting::query()->find(1);
    }

    private function notConfigured(): JsonResponse
    {
        return response()->json([
            'message' => 'Booking availability is not configured.',
        ], 503);
    }
}
