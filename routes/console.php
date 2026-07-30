<?php

use App\Enums\OperationsReportPeriod;
use App\Jobs\PrepareOperationsReport;
use App\Models\User;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('admin:create {email} {--name=Administrator}', function (): int {
    $email = $this->argument('email');
    $name = $this->option('name');

    if (! is_string($email) || ! is_string($name)) {
        $this->error('Name and email must be strings.');

        return 1;
    }

    $data = Validator::make([
        'name' => $name,
        'email' => Str::lower(trim($email)),
        'password' => $this->secret('Password'),
    ], [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
        'password' => [
            'required',
            Password::min(12)->mixedCase()->numbers()->symbols(),
        ],
    ])->validate();

    User::query()->create($data);
    $this->info('Admin created.');

    return 0;
})->purpose('Create an admin user');

Artisan::command('bookings:complete', function (BookingService $bookings): int {
    $completed = $bookings->completeDue(CarbonImmutable::now());
    $this->info($completed.' '.Str::plural('booking', $completed).' completed.');

    return 0;
})->purpose('Complete bookings one hour after their visit starts');

Schedule::command('bookings:complete')
    ->name('bookings:complete')
    ->everyFiveMinutes()
    ->timezone((string) config('app.business_timezone'))
    ->withoutOverlapping();

Schedule::job(new PrepareOperationsReport(OperationsReportPeriod::Morning))
    ->name('operations-report:morning')
    ->dailyAt('15:00')
    ->timezone((string) config('app.business_timezone'))
    ->withoutOverlapping();

Schedule::job(new PrepareOperationsReport(OperationsReportPeriod::Afternoon))
    ->name('operations-report:afternoon')
    ->dailyAt('07:00')
    ->timezone((string) config('app.business_timezone'))
    ->withoutOverlapping();
