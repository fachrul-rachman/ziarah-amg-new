<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property list<string>|null $embed_allowed_origins
 */
class Setting extends Model
{
    public $incrementing = false;

    protected $fillable = [
        'id',
        'daily_booking_limit',
        'operations_email',
        'discord_webhook',
        'embed_allowed_origins',
    ];

    protected function casts(): array
    {
        return [
            'daily_booking_limit' => 'integer',
            'discord_webhook' => 'encrypted',
            'embed_allowed_origins' => 'array',
        ];
    }
}
