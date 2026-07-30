<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Event::listen(
            DiagnosingHealth::class,
            fn (): array => DB::select('select 1'),
        );

        Gate::define(
            'manage-admin-configuration',
            fn (User $user): bool => true,
        );
        Gate::define(
            'manage-bookings',
            fn (User $user): bool => true,
        );

        RateLimiter::for('public-availability', function (Request $request): Limit {
            return Limit::perMinute(
                (int) config('booking.public_availability_rate_limit'),
            )
                ->by('public-availability:'.$request->ip())
                ->response(fn (): JsonResponse => response()->json([
                    'message' => 'Too many availability requests. Please try again shortly.',
                ], 429));
        });

        RateLimiter::for('public-booking-submission', function (Request $request): Limit {
            return Limit::perMinute(
                (int) config('booking.public_submission_rate_limit'),
            )
                ->by('public-booking:'.$request->ip())
                ->response(fn (): JsonResponse => response()->json([
                    'message' => 'Too many booking attempts. Please try again shortly.',
                ], 429));
        });

        RateLimiter::for('booking-management-read', function (Request $request): Limit {
            return Limit::perMinute(
                (int) config('booking.management_read_rate_limit'),
            )
                ->by('management-read:'.$request->ip())
                ->response(fn (): JsonResponse => response()->json([
                    'message' => 'Too many management link requests. Please try again shortly.',
                ], 429));
        });

        RateLimiter::for('booking-reschedule', function (Request $request): Limit {
            return Limit::perMinute(
                (int) config('booking.reschedule_rate_limit'),
            )
                ->by('management-reschedule:'.$request->ip())
                ->response(fn (): JsonResponse => response()->json([
                    'message' => 'Too many reschedule attempts. Please try again shortly.',
                ], 429));
        });

        RateLimiter::for('booking-cancel', function (Request $request): Limit {
            return Limit::perMinute(
                (int) config('booking.cancel_rate_limit'),
            )
                ->by('management-cancel:'.$request->ip())
                ->response(fn (): JsonResponse => response()->json([
                    'message' => 'Too many cancellation attempts. Please try again shortly.',
                ], 429));
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
