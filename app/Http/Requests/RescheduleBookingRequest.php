<?php

namespace App\Http\Requests;

use App\Models\Setting;
use App\Models\TimeSlot;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RescheduleBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'visit_date' => ['required', 'date_format:Y-m-d'],
            'visit_time' => ['required', 'date_format:H:i'],
            'zone_id' => [
                'required',
                'integer',
                Rule::exists('zones', 'id')->where(
                    fn (Builder $query): Builder => $query->where('is_active', true),
                ),
            ],
            'lot_number' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9]+([\/-][A-Z0-9]+)*$/'],
            'tent_required' => ['required', 'boolean'],
            'chair_count' => ['required', 'integer', 'min:0', 'max:500'],
            'additional_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'chair_count.max' => 'Jumlah kursi maksimal 500.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'lot_number' => mb_strtoupper(trim((string) $this->input('lot_number'))),
        ]);
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('visit_date')
                    || $validator->errors()->has('visit_time')) {
                    return;
                }

                $service = app(BookingService::class);
                $now = CarbonImmutable::now();
                $date = (string) $this->input('visit_date');
                $time = (string) $this->input('visit_time');
                $setting = Setting::query()->find(1) ?? new Setting;
                $leadHours = $service->minimumLeadHours($date);

                if (! $service->isWithinDateWindow(
                    $date,
                    $now,
                    $setting->booking_window_days ?? 100,
                )) {
                    $validator->errors()->add(
                        'visit_date',
                        'The selected date is outside the booking window.',
                    );
                } elseif (! $service->meetsLeadTime($date, $time, $now, $leadHours)) {
                    $validator->errors()->add(
                        'visit_time',
                        "The selected time must be at least {$leadHours} hours from now.",
                    );
                }

                if (! TimeSlot::query()
                    ->where('start_time', $time.':00')
                    ->where('is_active', true)
                    ->exists()) {
                    $validator->errors()->add(
                        'visit_time',
                        'The selected time is not available.',
                    );
                }
            },
        ];
    }
}
