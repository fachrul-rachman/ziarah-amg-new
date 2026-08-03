<?php

namespace App\Http\Requests;

use App\Models\Setting;
use App\Models\TimeSlot;
use App\Rules\BookingPhoneNumber;
use App\Services\BookingFormToken;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePublicBookingRequest extends FormRequest
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
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email:rfc', 'max:255'],
            'customer_phone' => [
                'required',
                'string',
                new BookingPhoneNumber,
            ],
            'deceased_name' => ['required', 'string', 'max:255'],
            'additional_notes' => ['nullable', 'string', 'max:2000'],
            'ethics_confirmed' => ['required', 'accepted'],
            'turnstile_token' => ['required', 'string', 'max:2048'],
            'website' => ['nullable', 'string', 'max:255'],
            'form_token' => ['required', 'string', 'max:2048'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'customer_email.required' => 'Email wajib diisi.',
            'customer_email.email' => 'Format email tidak valid.',
            'customer_phone.required' => 'Nomor telepon wajib diisi.',
            'chair_count.max' => 'Jumlah kursi maksimal 500.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'lot_number' => mb_strtoupper(trim((string) $this->input('lot_number'))),
            'customer_email' => mb_strtolower(trim((string) $this->input('customer_email'))),
            'customer_name' => trim((string) $this->input('customer_name')),
            'customer_phone' => trim((string) $this->input('customer_phone')),
            'deceased_name' => trim((string) $this->input('deceased_name')),
        ]);
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->filled('website')) {
                    $validator->errors()->add('website', 'The submitted data is invalid.');
                }

                if (! $validator->errors()->has('form_token')
                    && ! app(BookingFormToken::class)->isValid(
                        (string) $this->input('form_token'),
                    )) {
                    $validator->errors()->add(
                        'form_token',
                        'Please reload the booking form and try again.',
                    );
                }

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
