<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSettingsRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    public function edit(): Response
    {
        $setting = Setting::query()->find(1);

        return Inertia::render('admin/settings', [
            'settings' => [
                'daily_booking_limit' => $setting?->daily_booking_limit,
                'operations_email' => $setting->operations_email ?? '',
                'discord_webhook_configured' => $setting?->discord_webhook !== null,
                'discord_webhook_masked' => $setting?->discord_webhook !== null
                    ? '••••••••'
                    : null,
                'embed_allowed_origins' => $setting->embed_allowed_origins ?? [],
            ],
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $setting = Setting::query()->find(1);
        $data = $request->safe()->only([
            'daily_booking_limit',
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

        return redirect()->route('admin.settings.edit');
    }
}
