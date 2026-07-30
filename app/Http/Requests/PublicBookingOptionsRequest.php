<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class PublicBookingOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'zone_search' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $timezone = (string) config('app.business_timezone');
                $earliest = CarbonImmutable::now($timezone)->startOfDay()->addDay();
                $latest = $earliest->addDays(99);
                $start = $this->validatedDate('start_date', $validator);
                $end = $this->validatedDate('end_date', $validator);

                if ($start !== null && ! $start->betweenIncluded($earliest, $latest)) {
                    $validator->errors()->add(
                        'start_date',
                        'The start date must be within the selectable booking window.',
                    );
                }

                if ($end !== null && ! $end->betweenIncluded($earliest, $latest)) {
                    $validator->errors()->add(
                        'end_date',
                        'The end date must be within the selectable booking window.',
                    );
                }

                if ($start !== null && $end !== null && $end->lessThan($start)) {
                    $validator->errors()->add(
                        'end_date',
                        'The end date must be on or after the start date.',
                    );
                }
            },
        ];
    }

    private function validatedDate(
        string $field,
        Validator $validator,
    ): ?CarbonImmutable {
        if ($validator->errors()->has($field) || ! $this->filled($field)) {
            return null;
        }

        return CarbonImmutable::createFromFormat(
            '!Y-m-d',
            (string) $this->input($field),
            (string) config('app.business_timezone'),
        );
    }
}
