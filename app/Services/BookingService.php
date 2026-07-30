<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingDateLock;
use App\Models\BookingManagementToken;
use App\Models\Setting;
use App\Models\TimeSlot;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class BookingService
{
    public function isWithinDateWindow(
        string $visitDate,
        CarbonInterface $now,
        int $bookingWindowDays = 100,
    ): bool {
        if ($bookingWindowDays < 1 || $bookingWindowDays > 100) {
            throw new InvalidArgumentException('Booking window must be between 1 and 100 days.');
        }

        $today = $this->businessNow($now)->startOfDay();
        $date = CarbonImmutable::parse($visitDate, $this->timezone())->startOfDay();

        return $date->betweenIncluded(
            $today->addDay(),
            $today->addDays($bookingWindowDays),
        );
    }

    public function meetsLeadTime(
        string $visitDate,
        string $visitTime,
        CarbonInterface $now,
    ): bool {
        return $this->visitStartsAt($visitDate, $visitTime)
            ->greaterThanOrEqualTo($this->businessNow($now)->addHours(18));
    }

    public function canReschedule(Booking $booking, CarbonInterface $now): bool
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            return false;
        }

        $visit = $this->visitStartsAt(
            $booking->visit_date->toDateString(),
            $booking->visit_time,
        );
        $deadline = $visit->hour <= 11
            ? $visit->subDay()->setTime(15, 0)
            : $visit->startOfDay()->setTime(7, 0);

        return $this->businessNow($now)->lessThan($deadline);
    }

    public function canCancel(Booking $booking, CarbonInterface $now): bool
    {
        return $booking->status === BookingStatus::Confirmed
            && $this->businessNow($now)->lessThan(
                $this->visitStartsAt(
                    $booking->visit_date->toDateString(),
                    $booking->visit_time,
                )->subHour(),
            );
    }

    public function shouldComplete(Booking $booking, CarbonInterface $now): bool
    {
        return $booking->status === BookingStatus::Confirmed
            && $this->visitStartsAt(
                $booking->visit_date->toDateString(),
                $booking->visit_time,
            )->lessThanOrEqualTo(
                $this->completionCutoff($now),
            );
    }

    public function completeDue(CarbonInterface $now): int
    {
        $cutoff = $this->completionCutoff($now);
        $completedAt = $this->businessNow($now)->utc();

        return DB::transaction(function () use ($cutoff, $completedAt): int {
            $bookingIds = Booking::query()
                ->confirmed()
                ->where(function (Builder $query) use ($cutoff): void {
                    $query
                        ->whereDate('visit_date', '<', $cutoff->toDateString())
                        ->orWhere(function (Builder $query) use ($cutoff): void {
                            $query
                                ->whereDate('visit_date', $cutoff->toDateString())
                                ->where('visit_time', '<=', $cutoff->format('H:i:s'));
                        });
                })
                ->lockForUpdate()
                ->pluck('id');

            if ($bookingIds->isEmpty()) {
                return 0;
            }

            $completed = Booking::query()
                ->whereKey($bookingIds)
                ->where('status', BookingStatus::Confirmed->value)
                ->update([
                    'status' => BookingStatus::Completed,
                    'completed_at' => $completedAt,
                ]);

            BookingManagementToken::query()
                ->whereIn('booking_id', $bookingIds)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $completedAt]);

            return $completed;
        });
    }

    public function canAdminModify(Booking $booking, CarbonInterface $now): bool
    {
        return $booking->status === BookingStatus::Confirmed
            && $this->businessNow($now)->lessThan(
                $this->visitStartsAt(
                    $booking->visit_date->toDateString(),
                    $booking->visit_time,
                ),
            );
    }

    public function remainingCapacity(
        string $visitDate,
        string $visitTime,
        Setting $setting,
        ?Booking $currentBooking = null,
    ): int {
        $query = Booking::query()
            ->confirmed()
            ->where('visit_date', $visitDate);

        if ($setting->booking_limit_mode === Setting::LIMIT_HOURLY) {
            $query->where('visit_time', $this->normaliseTime($visitTime));
        }

        if ($currentBooking !== null) {
            $query->where('id', '<>', $currentBooking->id);
        }

        return max(0, $this->capacityLimit($setting) - $query->count());
    }

    /**
     * @return list<array{date: string, is_full: bool, is_available: bool}>
     */
    public function dateAvailability(
        string $startDate,
        string $endDate,
        Setting $setting,
    ): array {
        $limit = $this->capacityLimit($setting);
        $hourly = $setting->booking_limit_mode === Setting::LIMIT_HOURLY;
        $countQuery = Booking::query()
            ->confirmed()
            ->whereBetween('visit_date', [$startDate, $endDate])
            ->selectRaw(
                $hourly
                    ? 'visit_date, visit_time, COUNT(*) AS aggregate'
                    : 'visit_date, COUNT(*) AS aggregate',
            )
            ->groupBy('visit_date');
        $activeTimes = collect();

        if ($hourly) {
            $countQuery->groupBy('visit_time');
            $activeTimes = TimeSlot::query()
                ->where('is_active', true)
                ->orderBy('start_time')
                ->pluck('start_time');
        }

        $counts = $countQuery->get()->mapWithKeys(
            fn (Booking $row): array => [
                $hourly
                    ? $row->visit_date->toDateString().'|'.$row->visit_time
                    : $row->visit_date->toDateString() => (int) $row->getAttribute('aggregate'),
            ],
        );

        $dates = [];
        $date = CarbonImmutable::parse($startDate, $this->timezone())->startOfDay();
        $end = CarbonImmutable::parse($endDate, $this->timezone())->startOfDay();

        while ($date->lessThanOrEqualTo($end)) {
            $dateString = $date->toDateString();
            $isFull = $hourly
                ? $activeTimes->isEmpty() || $activeTimes->every(
                    fn (string $time): bool => (int) ($counts[$dateString.'|'.$time] ?? 0) >= $limit,
                )
                : (int) ($counts[$dateString] ?? 0) >= $limit;
            $dates[] = [
                'date' => $dateString,
                'is_full' => $isFull,
                'is_available' => ! $isFull,
            ];
            $date = $date->addDay();
        }

        return $dates;
    }

    /**
     * @return array{is_full: bool, slots: array<int, array{id: int, start_time: string, is_available: bool, disabled_reason: string|null}>}
     */
    public function slotAvailability(
        string $visitDate,
        Setting $setting,
        CarbonInterface $now,
        ?Booking $currentBooking = null,
    ): array {
        $slots = TimeSlot::query()
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get();
        $hourly = $setting->booking_limit_mode === Setting::LIMIT_HOURLY;
        $limit = $this->capacityLimit($setting);
        $usedQuery = Booking::query()
            ->confirmed()
            ->where('visit_date', $visitDate);

        if ($currentBooking !== null) {
            $usedQuery->where('id', '<>', $currentBooking->id);
        }

        $usedByTime = $hourly
            ? $usedQuery
                ->selectRaw('visit_time, COUNT(*) AS aggregate')
                ->groupBy('visit_time')
                ->pluck('aggregate', 'visit_time')
            : collect();
        $dateFull = ! $hourly && $usedQuery->count() >= $limit;
        $isFull = $dateFull || ($hourly && $slots->isNotEmpty() && $slots->every(
            fn (TimeSlot $slot): bool => (int) ($usedByTime[(string) $slot->start_time] ?? 0) >= $limit,
        ));

        $availability = $slots
            ->map(function (TimeSlot $slot) use (
                $visitDate,
                $dateFull,
                $hourly,
                $limit,
                $now,
                $usedByTime,
            ): array {
                $meetsLeadTime = $this->meetsLeadTime(
                    $visitDate,
                    (string) $slot->start_time,
                    $now,
                );
                $slotFull = $hourly
                    && (int) ($usedByTime[(string) $slot->start_time] ?? 0) >= $limit;
                $capacityFull = $dateFull || $slotFull;

                return [
                    'id' => $slot->id,
                    'start_time' => substr((string) $slot->start_time, 0, 5),
                    'is_available' => ! $capacityFull && $meetsLeadTime,
                    'disabled_reason' => $capacityFull
                        ? ($slotFull ? 'slot_full' : 'date_full')
                        : ($meetsLeadTime ? null : 'minimum_lead_time'),
                ];
            })
            ->values()
            ->all();

        return ['is_full' => $isFull, 'slots' => $availability];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{booking: Booking, management_token: string}
     */
    public function createConfirmed(
        array $attributes,
        Setting $setting,
        CarbonInterface $now,
    ): array {
        $visitDate = (string) $attributes['visit_date'];
        $visitTime = $this->normaliseTime((string) $attributes['visit_time']);

        if (! $this->isWithinDateWindow($visitDate, $now, $setting->booking_window_days)
            || ! $this->meetsLeadTime($visitDate, $visitTime, $now)) {
            throw new DomainException('The selected visit time is not available.');
        }

        $zone = Zone::query()
            ->whereKey($attributes['zone_id'])
            ->where('is_active', true)
            ->first();

        $slotExists = TimeSlot::query()
            ->where('start_time', $visitTime)
            ->where('is_active', true)
            ->exists();

        if ($zone === null || ! $slotExists) {
            throw new DomainException('The selected visit option is not available.');
        }

        return DB::transaction(function () use (
            $attributes,
            $setting,
            $now,
            $visitDate,
            $visitTime,
            $zone,
        ): array {
            $this->lockBookingDate($visitDate);

            if ($this->remainingCapacity($visitDate, $visitTime, $setting) < 1) {
                throw $this->capacityException($setting);
            }

            $booking = Booking::query()->create([
                'public_reference' => Str::uuid()->toString(),
                'status' => BookingStatus::Confirmed,
                'visit_date' => $visitDate,
                'visit_time' => $visitTime,
                'zone_id' => $zone->id,
                'zone_name_snapshot' => $zone->name,
                'lot_number' => $attributes['lot_number'],
                'tent_required' => $attributes['tent_required'],
                'chair_count' => $attributes['chair_count'],
                'customer_name' => $attributes['customer_name'],
                'customer_email' => $attributes['customer_email'],
                'customer_phone' => $attributes['customer_phone'],
                'deceased_name' => $attributes['deceased_name'],
                'additional_notes' => $attributes['additional_notes'] ?? null,
                'ethics_confirmed_at' => $attributes['ethics_confirmed_at']
                    ?? $this->businessNow($now)->utc(),
            ]);

            $token = $this->issueManagementToken($booking);

            return [
                'booking' => $booking,
                'management_token' => $token,
            ];
        });
    }

    public function findManageableToken(
        string $token,
        CarbonInterface $now,
        bool $touch = true,
    ): ?BookingManagementToken {
        if ($token === '' || strlen($token) > 512) {
            return null;
        }

        $record = BookingManagementToken::query()
            ->with('booking')
            ->where('token_hash', $this->hashToken($token))
            ->whereNull('revoked_at')
            ->where(function (Builder $query) use ($now): void {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now->utc());
            })
            ->whereHas(
                'booking',
                fn (Builder $query): Builder => $query->where(
                    'status',
                    BookingStatus::Confirmed->value,
                ),
            )
            ->first();

        if ($record !== null && $touch) {
            $record->forceFill(['last_used_at' => $now->utc()])->save();
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{booking: Booking, management_token: string}
     */
    public function reschedule(
        string $rawToken,
        array $attributes,
        Setting $setting,
        CarbonInterface $now,
    ): array {
        $visitDate = (string) $attributes['visit_date'];
        $visitTime = $this->normaliseTime((string) $attributes['visit_time']);

        if (! $this->isWithinDateWindow($visitDate, $now, $setting->booking_window_days)
            || ! $this->meetsLeadTime($visitDate, $visitTime, $now)) {
            throw new DomainException('The selected visit time is not available.');
        }

        return DB::transaction(function () use (
            $rawToken,
            $attributes,
            $setting,
            $now,
            $visitDate,
            $visitTime,
        ): array {
            [, $booking] = $this->lockManagementAccess($rawToken, $now);

            if (! $this->canReschedule($booking, $now)) {
                throw new DomainException('This booking can no longer be rescheduled.');
            }

            $zone = Zone::query()
                ->whereKey($attributes['zone_id'])
                ->where('is_active', true)
                ->first();
            $slotExists = TimeSlot::query()
                ->where('start_time', $visitTime)
                ->where('is_active', true)
                ->exists();

            if ($zone === null || ! $slotExists) {
                throw new DomainException('The selected visit option is not available.');
            }

            $this->lockBookingDate($visitDate);

            if ($this->remainingCapacity(
                $visitDate,
                $visitTime,
                $setting,
                $booking,
            ) < 1) {
                throw $this->capacityException($setting);
            }

            $booking->update([
                'visit_date' => $visitDate,
                'visit_time' => $visitTime,
                'zone_id' => $zone->id,
                'zone_name_snapshot' => $zone->name,
                'lot_number' => $attributes['lot_number'],
                'tent_required' => $attributes['tent_required'],
                'chair_count' => $attributes['chair_count'],
                'additional_notes' => $attributes['additional_notes'] ?? null,
            ]);

            $revokedAt = $this->businessNow($now)->utc();
            BookingManagementToken::query()
                ->where('booking_id', $booking->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $revokedAt]);
            $newToken = $this->issueManagementToken($booking);

            return [
                'booking' => $booking->refresh(),
                'management_token' => $newToken,
            ];
        });
    }

    public function cancel(
        string $rawToken,
        CarbonInterface $now,
    ): Booking {
        return DB::transaction(function () use ($rawToken, $now): Booking {
            [, $booking] = $this->lockManagementAccess($rawToken, $now);

            if (! $this->canCancel($booking, $now)) {
                throw new DomainException('This booking can no longer be cancelled.');
            }

            $cancelledAt = $this->businessNow($now)->utc();
            $booking->update([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => $cancelledAt,
            ]);
            BookingManagementToken::query()
                ->where('booking_id', $booking->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $cancelledAt]);

            return $booking->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateByAdmin(
        Booking $booking,
        array $attributes,
        Setting $setting,
        CarbonInterface $now,
    ): Booking {
        $visitDate = (string) $attributes['visit_date'];
        $visitTime = $this->normaliseTime((string) $attributes['visit_time']);

        return DB::transaction(function () use (
            $booking,
            $attributes,
            $setting,
            $now,
            $visitDate,
            $visitTime,
        ): Booking {
            $lockedBooking = Booking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->canAdminModify($lockedBooking, $now)) {
                throw new DomainException('Booking yang sudah melewati waktu kunjungan tidak dapat diubah.');
            }

            if (! $this->visitStartsAt($visitDate, $visitTime)
                ->greaterThan($this->businessNow($now))) {
                throw new DomainException('Waktu kunjungan baru harus berada di masa depan.');
            }

            $zone = Zone::query()
                ->whereKey($attributes['zone_id'])
                ->where('is_active', true)
                ->first();
            $slotExists = TimeSlot::query()
                ->where('start_time', $visitTime)
                ->where('is_active', true)
                ->exists();

            if ($zone === null || ! $slotExists) {
                throw new DomainException('Zona atau waktu kunjungan tidak tersedia.');
            }

            $this->lockBookingDate($visitDate);

            if ($this->remainingCapacity(
                $visitDate,
                $visitTime,
                $setting,
                $lockedBooking,
            ) < 1) {
                throw new DomainException(
                    $setting->booking_limit_mode === Setting::LIMIT_HOURLY
                        ? 'Jam kunjungan tujuan sudah penuh.'
                        : 'Tanggal kunjungan tujuan sudah penuh.',
                );
            }

            $lockedBooking->update([
                'visit_date' => $visitDate,
                'visit_time' => $visitTime,
                'zone_id' => $zone->id,
                'zone_name_snapshot' => $zone->name,
                'lot_number' => $attributes['lot_number'],
                'tent_required' => $attributes['tent_required'],
                'chair_count' => $attributes['chair_count'],
                'customer_name' => $attributes['customer_name'],
                'customer_email' => $attributes['customer_email'],
                'customer_phone' => $attributes['customer_phone'],
                'deceased_name' => $attributes['deceased_name'],
                'additional_notes' => $attributes['additional_notes'] ?? null,
            ]);

            return $lockedBooking->refresh();
        });
    }

    public function cancelByAdmin(
        Booking $booking,
        CarbonInterface $now,
    ): Booking {
        return DB::transaction(function () use ($booking, $now): Booking {
            $lockedBooking = Booking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->canAdminModify($lockedBooking, $now)) {
                throw new DomainException('Booking yang sudah melewati waktu kunjungan tidak dapat dibatalkan.');
            }

            $cancelledAt = $this->businessNow($now)->utc();
            $lockedBooking->update([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => $cancelledAt,
            ]);
            BookingManagementToken::query()
                ->where('booking_id', $lockedBooking->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $cancelledAt]);

            return $lockedBooking->refresh();
        });
    }

    private function issueManagementToken(Booking $booking): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        BookingManagementToken::query()->create([
            'booking_id' => $booking->id,
            'token_hash' => hash('sha256', $token),
        ]);

        return $token;
    }

    private function capacityLimit(Setting $setting): int
    {
        $limit = $setting->booking_limit_mode === Setting::LIMIT_HOURLY
            ? (int) $setting->hourly_booking_limit
            : $setting->daily_booking_limit;

        if ($limit < 1) {
            throw new InvalidArgumentException('Booking limit must be positive.');
        }

        return $limit;
    }

    private function capacityException(Setting $setting): DomainException
    {
        return new DomainException(
            $setting->booking_limit_mode === Setting::LIMIT_HOURLY
                ? 'The selected visit time is full.'
                : 'The selected visit date is full.',
        );
    }

    /**
     * @return array{BookingManagementToken, Booking}
     */
    private function lockManagementAccess(
        string $rawToken,
        CarbonInterface $now,
    ): array {
        $token = BookingManagementToken::query()
            ->where('token_hash', $this->hashToken($rawToken))
            ->lockForUpdate()
            ->first();

        if ($token === null
            || $token->revoked_at !== null
            || ($token->expires_at !== null
                && $token->expires_at->lessThanOrEqualTo($now->utc()))) {
            throw new DomainException('Booking management link is not available.');
        }

        $booking = Booking::query()
            ->whereKey($token->booking_id)
            ->lockForUpdate()
            ->first();

        if ($booking === null || $booking->status !== BookingStatus::Confirmed) {
            throw new DomainException('Booking management link is not available.');
        }

        return [$token, $booking];
    }

    private function lockBookingDate(string $visitDate): void
    {
        DB::table('booking_date_locks')->insertOrIgnore([
            'visit_date' => $visitDate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        BookingDateLock::query()
            ->whereKey($visitDate)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function visitStartsAt(string $visitDate, string $visitTime): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $visitDate.' '.$this->normaliseTime($visitTime),
            $this->timezone(),
        );
    }

    private function normaliseTime(string $time): string
    {
        $time = strlen($time) === 5 ? $time.':00' : $time;
        $parsed = DateTimeImmutable::createFromFormat('!H:i:s', $time);

        if ($parsed === false || $parsed->format('H:i:s') !== $time) {
            throw new InvalidArgumentException('Invalid visit time.');
        }

        return $time;
    }

    private function businessNow(CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now)->setTimezone($this->timezone());
    }

    private function completionCutoff(CarbonInterface $now): CarbonImmutable
    {
        return $this->businessNow($now)->subHour();
    }

    private function timezone(): string
    {
        return (string) config('app.business_timezone');
    }
}
