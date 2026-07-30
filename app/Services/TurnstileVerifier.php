<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TurnstileVerifier
{
    public function verify(string $token, ?string $ip): bool
    {
        $secret = config('booking.turnstile_secret_key');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        try {
            return Http::asForm()
                ->timeout(5)
                ->post(
                    'https://challenges.cloudflare.com/turnstile/v0/siteverify',
                    [
                        'secret' => $secret,
                        'response' => $token,
                        'remoteip' => $ip,
                    ],
                )
                ->json('success') === true;
        } catch (ConnectionException) {
            return false;
        }
    }
}
