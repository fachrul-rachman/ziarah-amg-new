<?php

namespace App\Http\Requests;

use App\Enums\BookingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminBookingIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-bookings') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(BookingStatus::class)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'visit_time' => ['nullable', 'date_format:H:i'],
            'zone_id' => ['nullable', 'integer', 'exists:zones,id'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->normaliseNullableString('search'),
            'status' => $this->normaliseNullableString('status'),
            'date_from' => $this->normaliseNullableString('date_from'),
            'date_to' => $this->normaliseNullableString('date_to'),
            'visit_time' => $this->normaliseNullableString('visit_time'),
            'zone_id' => $this->normaliseNullableString('zone_id'),
        ]);
    }

    private function normaliseNullableString(string $key): ?string
    {
        $value = $this->input($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
