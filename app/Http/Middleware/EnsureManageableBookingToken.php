<?php

namespace App\Http\Middleware;

use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureManageableBookingToken
{
    public function __construct(
        private readonly BookingService $bookings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $record = $this->bookings->findManageableToken(
            (string) $request->route('token'),
            CarbonImmutable::now(),
        );

        if ($record === null) {
            return new JsonResponse([
                'message' => 'Booking management link is not available.',
            ], 404);
        }

        $request->attributes->set('management_token_record', $record);

        return $next($request);
    }
}
