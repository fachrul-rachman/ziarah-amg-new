<?php

namespace App\Http\Requests;

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
            'lot_number' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9]+$/'],
            'tent_required' => ['required', 'boolean'],
            'chair_count' => ['required', 'integer', 'between:2,6'],
            'additional_notes' => ['nullable', 'string', 'max:2000'],
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

                if (! $service->isWithinDateWindow($date, $now)) {
                    $validator->errors()->add(
                        'visit_date',
                        'The selected date is outside the booking window.',
                    );
                } elseif (! $service->meetsLeadTime($date, $time, $now)) {
                    $validator->errors()->add(
                        'visit_time',
                        'The selected time must be at least 18 hours from now.',
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
