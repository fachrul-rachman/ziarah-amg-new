<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSettingsRequest;
use App\Models\OperationsReportConfiguration;
use App\Models\Setting;
use App\Models\TimeSlot;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    public function edit(): Response
    {
        $setting = Setting::query()->find(1);
        $reportConfiguration = OperationsReportConfiguration::query()
            ->orderByDesc('effective_from')
            ->first();

        return Inertia::render('admin/settings', [
            'settings' => [
                'booking_window_days' => $setting->booking_window_days ?? 100,
                'booking_limit_mode' => $setting->booking_limit_mode ?? Setting::LIMIT_DAILY,
                'daily_booking_limit' => $setting?->daily_booking_limit,
                'hourly_booking_limit' => $setting?->hourly_booking_limit,
                'operations_email' => $setting->operations_email ?? '',
                'discord_webhook_configured' => $setting?->discord_webhook !== null,
                'discord_webhook_masked' => $setting?->discord_webhook !== null
                    ? '••••••••'
                    : null,
                'embed_allowed_origins' => $setting->embed_allowed_origins ?? [],
                'minimum_lead_hours' => $reportConfiguration->minimum_lead_hours ?? Setting::DEFAULT_LEAD_HOURS,
                'report_schedules' => $reportConfiguration->report_schedules ?? Setting::DEFAULT_REPORT_SCHEDULES,
                'report_settings_effective_from' => $reportConfiguration?->effective_from->toDateString(),
            ],
            'visit_times' => TimeSlot::query()
                ->orderBy('start_time')
                ->pluck('start_time')
                ->map(fn (string $time): string => substr($time, 0, 5))
                ->all(),
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $setting = Setting::query()->find(1);
        $reportConfiguration = OperationsReportConfiguration::query()
            ->orderByDesc('effective_from')
            ->first();
        $data = $request->safe()->only([
            'booking_window_days',
            'booking_limit_mode',
            'daily_booking_limit',
            'hourly_booking_limit',
            'operations_email',
            'discord_webhook',
            'embed_allowed_origins',
        ]);

        if ($request->boolean('clear_discord_webhook')) {
            $data['discord_webhook'] = null;
        } elseif ($data['discord_webhook'] === null && $setting !== null) {
            unset($data['discord_webhook']);
        }

        Setting::query()->updateOrCreate(['id' => 1], $data);
        $leadHours = (int) $request->validated('minimum_lead_hours');
        $reportSchedules = $request->validated('report_schedules');

        if ($reportConfiguration === null
            || $reportConfiguration->minimum_lead_hours !== $leadHours
            || $reportConfiguration->report_schedules !== $reportSchedules) {
            OperationsReportConfiguration::query()->updateOrCreate(
                ['effective_from' => CarbonImmutable::now(
                    (string) config('app.business_timezone'),
                )->startOfDay()->addDays(2)->toDateString()],
                [
                    'minimum_lead_hours' => $leadHours,
                    'report_schedules' => $reportSchedules,
                ],
            );
        }

        return redirect()->route('admin.settings.edit');
    }
}
