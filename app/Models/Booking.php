<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $public_reference
 * @property BookingStatus $status
 * @property CarbonImmutable $visit_date
 * @property string $visit_time
 */
class Booking extends Model
{
    protected $fillable = [
        'public_reference',
        'status',
        'visit_date',
        'visit_time',
        'zone_id',
        'zone_name_snapshot',
        'lot_number',
        'tent_required',
        'chair_count',
        'customer_name',
        'customer_email',
        'customer_phone',
        'deceased_name',
        'additional_notes',
        'ethics_confirmed_at',
        'cancelled_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'visit_date' => 'date',
            'tent_required' => 'boolean',
            'chair_count' => 'integer',
            'ethics_confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Zone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /** @return HasMany<BookingManagementToken, $this> */
    public function managementTokens(): HasMany
    {
        return $this->hasMany(BookingManagementToken::class);
    }

    /**
     * @param  Builder<Booking>  $query
     * @return Builder<Booking>
     */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', BookingStatus::Confirmed->value);
    }

    /**
     * @param  Builder<Booking>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Booking>
     */
    public function scopeAdminFiltered(Builder $query, array $filters): Builder
    {
        $search = $filters['search'] ?? null;

        if (is_string($search) && $search !== '') {
            $term = '%'.$search.'%';
            $query->where(function (Builder $query) use ($term): void {
                $query
                    ->where('public_reference', 'ilike', $term)
                    ->orWhere('customer_name', 'ilike', $term)
                    ->orWhere('customer_email', 'ilike', $term)
                    ->orWhere('customer_phone', 'ilike', $term)
                    ->orWhere('deceased_name', 'ilike', $term)
                    ->orWhere('lot_number', 'ilike', $term)
                    ->orWhere('zone_name_snapshot', 'ilike', $term);
            });
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['date_from'])) {
            $query->whereDate('visit_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->whereDate('visit_date', '<=', $filters['date_to']);
        }

        if (isset($filters['visit_time'])) {
            $query->where('visit_time', $filters['visit_time'].':00');
        }

        if (isset($filters['zone_id'])) {
            $query->where('zone_id', $filters['zone_id']);
        }

        return $query;
    }
}
