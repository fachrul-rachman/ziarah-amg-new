<?php

namespace App\Http\Requests;

use App\Models\Setting;
use App\Models\TimeSlot;
use App\Services\OperationsReportPlanner;
use DomainException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-admin-configuration') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'booking_window_days' => [
                'required',
                'integer',
                'between:1,100',
            ],
            'booking_limit_mode' => [
                'required',
                Rule::in([Setting::LIMIT_DAILY, Setting::LIMIT_HOURLY]),
            ],
            'daily_booking_limit' => [
                'required',
                'integer',
                'min:1',
                'max:4294967295',
            ],
            'hourly_booking_limit' => [
                'nullable',
                'required_if:booking_limit_mode,'.Setting::LIMIT_HOURLY,
                'integer',
                'min:1',
                'max:4294967295',
            ],
            'operations_email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
            ],
            'discord_webhook' => ['nullable', 'string', 'max:2048'],
            'clear_discord_webhook' => ['required', 'boolean'],
            'embed_allowed_origins' => ['present', 'array', 'max:20'],
            'embed_allowed_origins.*' => [
                'required',
                'string',
                'max:255',
                'distinct:ignore_case',
            ],
            'minimum_lead_hours' => ['required', 'integer', 'between:1,65535'],
            'report_schedules' => ['required', 'array', 'min:1', 'max:3'],
            'report_schedules.*.day_offset' => ['required', 'integer', Rule::in([-1, 0])],
            'report_schedules.*.time' => ['required', 'date_format:H:i'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $webhook = $this->input('discord_webhook');

                if (is_string($webhook) && ! $this->isDiscordWebhook($webhook)) {
                    $validator->errors()->add(
                        'discord_webhook',
                        'Webhook harus berupa URL webhook Discord yang valid.',
                    );
                }

                $origins = $this->input('embed_allowed_origins', []);

                if (! is_array($origins)) {
                    return;
                }

                foreach ($origins as $index => $origin) {
                    if (! is_string($origin) || ! $this->isOrigin($origin)) {
                        $validator->errors()->add(
                            "embed_allowed_origins.{$index}",
                            'Origin harus berupa origin HTTP atau HTTPS tanpa path.',
                        );
                    }
                }

                if (! $validator->errors()->has('booking_window_days')
                    && ! $validator->errors()->has('minimum_lead_hours')
                    && (int) $this->input('minimum_lead_hours')
                        > (int) $this->input('booking_window_days') * 24) {
                    $validator->errors()->add(
                        'minimum_lead_hours',
                        'Minimal waktu booking tidak boleh melebihi rentang hari booking.',
                    );
                }

                if (collect($validator->errors()->keys())->contains(
                    fn (string $key): bool => $key === 'minimum_lead_hours'
                        || Str::startsWith($key, 'report_schedules'),
                )) {
                    return;
                }

                $times = TimeSlot::query()
                    ->orderBy('start_time')
                    ->pluck('start_time')
                    ->map(fn (mixed $time): string => (string) $time)
                    ->values()
                    ->all();

                if ($times === []) {
                    $validator->errors()->add(
                        'report_schedules',
                        'Tambahkan minimal satu jam kunjungan sebelum mengatur laporan.',
                    );

                    return;
                }

                try {
                    app(OperationsReportPlanner::class)->plan(
                        '2030-01-02',
                        (int) $this->input('minimum_lead_hours'),
                        $this->input('report_schedules'),
                        $times,
                    );
                } catch (DomainException $exception) {
                    $validator->errors()->add('report_schedules', $exception->getMessage());
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('operations_email');
        $webhook = $this->input('discord_webhook');
        $origins = $this->input('embed_allowed_origins');
        $schedules = $this->input('report_schedules');

        $this->merge([
            'operations_email' => is_string($email)
                ? Str::lower(trim($email))
                : $email,
            'discord_webhook' => is_string($webhook) && trim($webhook) !== ''
                ? trim($webhook)
                : null,
            'embed_allowed_origins' => is_array($origins)
                ? array_map(
                    fn (mixed $origin): mixed => is_string($origin)
                        ? rtrim(trim($origin), '/')
                        : $origin,
                    $origins,
                )
                : $origins,
            'report_schedules' => is_array($schedules)
                ? collect($schedules)
                    ->map(fn (mixed $schedule): mixed => is_array($schedule) ? [
                        'day_offset' => (int) ($schedule['day_offset'] ?? 0),
                        'time' => is_string($schedule['time'] ?? null)
                            ? substr(trim($schedule['time']), 0, 5)
                            : ($schedule['time'] ?? null),
                    ] : $schedule)
                    ->sortBy(fn (mixed $schedule): string => is_array($schedule)
                        ? sprintf('%+03d %s', $schedule['day_offset'], $schedule['time'])
                        : '')
                    ->values()
                    ->all()
                : $schedules,
        ]);
    }

    private function isDiscordWebhook(string $webhook): bool
    {
        if (! Str::isUrl($webhook, ['https'])) {
            return false;
        }

        $parts = parse_url($webhook);
        $host = Str::lower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $isDiscordHost = $host === 'discord.com'
            || Str::endsWith($host, '.discord.com')
            || $host === 'discordapp.com'
            || Str::endsWith($host, '.discordapp.com');

        return $isDiscordHost
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && preg_match('#^/api/webhooks/\d+/[^/]+$#', $path) === 1;
    }

    private function isOrigin(string $origin): bool
    {
        if (! Str::isUrl($origin, ['http', 'https'])) {
            return false;
        }

        $parts = parse_url($origin);

        return isset($parts['scheme'], $parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && ($parts['path'] ?? '') === '';
    }
}
