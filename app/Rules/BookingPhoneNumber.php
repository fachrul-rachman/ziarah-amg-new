<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class BookingPhoneNumber implements ValidationRule
{
    public function validate(
        string $attribute,
        mixed $value,
        Closure $fail,
    ): void {
        if (! is_string($value)
            || preg_match('/^(?:08|62)[0-9]{8,13}$/', $value) !== 1) {
            $fail(
                'Nomor telepon harus terdiri dari 10 sampai 15 digit dan diawali 08 atau 62.',
            );
        }
    }
}
