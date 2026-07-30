<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class BookingFormToken
{
    public function issue(): string
    {
        return Crypt::encryptString((string) now()->getTimestamp());
    }

    public function isValid(string $token): bool
    {
        try {
            $issuedAt = Crypt::decryptString($token);
        } catch (DecryptException) {
            return false;
        }

        if (! ctype_digit($issuedAt)) {
            return false;
        }

        $age = now()->getTimestamp() - (int) $issuedAt;

        return $age >= (int) config('booking.minimum_form_seconds')
            && $age <= (int) config('booking.maximum_form_seconds');
    }
}
