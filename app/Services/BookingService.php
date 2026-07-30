<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingDateLock;
use App\Models\BookingManagementToken;
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
    public function isWithinDateWindow(string $visitDate, CarbonInterface $now): bool
    {
        $today = $this->businessNow($now)->startOfDay();
        $date = CarbonImmutable::parse($visitDate, $this->timezone())->startOfDay();

        return $date->betweenIncluded($today->addDay(), $today->addDays(100));
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

    public function remainingCapacity(string $visitDate, int $dailyLimit): int
    {
        if ($dailyLimit < 1) {
            throw new InvalidArgumentException('Daily booking limit must be positive.');
        }

        $used = Booking::query()
            ->confirmed()
            ->where('visit_date', $visitDate)
            ->count();

        return max(0, $dailyLimit - $used);
    }

    public function isDateAvailable(
        string $visitDate,
        int $dailyLimit,
        CarbonInterface $now,
    ): bool {
        return $this->isWithinDateWindow($visitDate, $now)
            && $this->remainingCapacity($visitDate, $dailyLimit) > 0;
    }

    /**
     * @return list<array{date: string, is_full: bool, is_available: bool}>
     */
    public function dateAvailability(
        string $startDate,
        string $endDate,
        int $dailyLimit,
    ): array {
        if ($dailyLimit < 1) {
            throw new InvalidArgumentException('Daily booking limit must be positive.');
        }

        $counts = Booking::query()
            ->confirmed()
            ->whereBetween('visit_date', [$startDate, $endDate])
            ->selectRaw('visit_date, COUNT(*) AS aggregate')
            ->groupBy('visit_date')
            ->pluck('aggregate', 'visit_date');

        $dates = [];
        $date = CarbonImmutable::parse($startDate, $this->timezone())->startOfDay();
        $end = CarbonImmutable::parse($endDate, $this->timezone())->startOfDay();

        while ($date->lessThanOrEqualTo($end)) {
            $dateString = $date->toDateString();
            $isFull = (int) ($counts[$dateString] ?? 0) >= $dailyLimit;
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
        int $dailyLimit,
        CarbonInterface $now,
        ?Booking $currentBooking = null,
    ): array {
        $isCurrentDate = $currentBooking?->visit_date->toDateString() === $visitDate;
        $isFull = ! $isCurrentDate
            && $this->remainingCapacity($visitDate, $dailyLimit) === 0;

        $slots = TimeSlot::query()
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get()
            ->map(function (TimeSlot $slot) use ($visitDate, $isFull, $now): array {
                $meetsLeadTime = $this->meetsLeadTime(
                    $visitDate,
                    (string) $slot->start_time,
                    $now,
                );

                return [
                    'id' => $slot->id,
                    'start_time' => substr((string) $slot->start_time, 0, 5),
                    'is_available' => ! $isFull && $meetsLeadTime,
                    'disabled_reason' => $isFull
                        ? 'date_full'
                        : ($meetsLeadTime ? null : 'minimum_lead_time'),
                ];
            })
            ->values()
            ->all();

        return ['is_full' => $isFull, 'slots' => $slots];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{booking: Booking, management_token: string}
     */
    public function createConfirmed(
        array $attributes,
        int $dailyLimit,
        CarbonInterface $now,
    ): array {
        $visitDate = (string) $attributes['visit_date'];
        $visitTime = $this->normaliseTime((string) $attributes['visit_time']);

        if (! $this->isWithinDateWindow($visitDate, $now)
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
            $dailyLimit,
            $now,
            $visitDate,
            $visitTime,
            $zone,
        ): array {
            $this->lockBookingDate($visitDate);

            if ($this->remainingCapacity($visitDate, $dailyLimit) < 1) {
                throw new DomainException('The selected visit date is full.');
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
        int $dailyLimit,
        CarbonInterface $now,
    ): array {
        $visitDate = (string) $attributes['visit_date'];
        $visitTime = $this->normaliseTime((string) $attributes['visit_time']);

        if (! $this->isWithinDateWindow($visitDate, $now)
            || ! $this->meetsLeadTime($visitDate, $visitTime, $now)) {
            throw new DomainException('The selected visit time is not available.');
        }

        return DB::transaction(function () use (
            $rawToken,
            $attributes,
            $dailyLimit,
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

            if ($booking->visit_date->toDateString() !== $visitDate) {
                $this->lockBookingDate($visitDate);

                if ($this->remainingCapacity($visitDate, $dailyLimit) < 1) {
                    throw new DomainException('The selected visit date is full.');
                }
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
        int $dailyLimit,
        CarbonInterface $now,
    ): Booking {
        $visitDate = (string) $attributes['visit_date'];
        $visitTime = $this->normaliseTime((string) $attributes['visit_time']);

        return DB::transaction(function () use (
            $booking,
            $attributes,
            $dailyLimit,
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

            if ($lockedBooking->visit_date->toDateString() !== $visitDate) {
                $this->lockBookingDate($visitDate);

                if ($this->remainingCapacity($visitDate, $dailyLimit) < 1) {
                    throw new DomainException('Tanggal kunjungan tujuan sudah penuh.');
                }
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
