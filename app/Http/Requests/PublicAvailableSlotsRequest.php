<?php

namespace App\Http\Requests;

use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class PublicAvailableSlotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('date')) {
                    return;
                }

                if (! app(BookingService::class)->isWithinDateWindow(
                    (string) $this->input('date'),
                    CarbonImmutable::now(),
                )) {
                    $validator->errors()->add(
                        'date',
                        'The date must be within the selectable booking window.',
                    );
                }
            },
        ];
    }
}
