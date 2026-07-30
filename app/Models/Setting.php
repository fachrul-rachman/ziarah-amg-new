<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $booking_window_days
 * @property string $booking_limit_mode
 * @property int $daily_booking_limit
 * @property int|null $hourly_booking_limit
 * @property list<string>|null $embed_allowed_origins
 */
class Setting extends Model
{
    public const string LIMIT_DAILY = 'daily';

    public const string LIMIT_HOURLY = 'hourly';

    public $incrementing = false;

    protected $attributes = [
        'booking_window_days' => 100,
        'booking_limit_mode' => self::LIMIT_DAILY,
    ];

    protected $fillable = [
        'id',
        'booking_window_days',
        'booking_limit_mode',
        'daily_booking_limit',
        'hourly_booking_limit',
        'operations_email',
        'discord_webhook',
        'embed_allowed_origins',
    ];

    protected function casts(): array
    {
        return [
            'booking_window_days' => 'integer',
            'daily_booking_limit' => 'integer',
            'hourly_booking_limit' => 'integer',
            'discord_webhook' => 'encrypted',
            'embed_allowed_origins' => 'array',
        ];
    }
}
