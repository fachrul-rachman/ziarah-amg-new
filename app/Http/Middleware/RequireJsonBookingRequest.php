<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireJsonBookingRequest
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isJson()) {
            return new JsonResponse([
                'message' => 'Content-Type must be application/json.',
            ], 415);
        }

        if (strlen($request->getContent()) > (int) config('booking.maximum_request_bytes')) {
            return new JsonResponse([
                'message' => 'The request body is too large.',
            ], 413);
        }

        return $next($request);
    }
}
