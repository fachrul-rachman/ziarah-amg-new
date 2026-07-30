<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingDateLock extends Model
{
    protected $primaryKey = 'visit_date';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'visit_date',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
        ];
    }
}
