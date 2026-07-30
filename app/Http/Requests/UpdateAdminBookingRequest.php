<?php

namespace App\Http\Requests;

use App\Rules\BookingPhoneNumber;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-bookings') ?? false;
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
        $notes = $this->input('additional_notes');

        $this->merge([
            'lot_number' => mb_strtoupper(trim((string) $this->input('lot_number'))),
            'customer_email' => mb_strtolower(trim((string) $this->input('customer_email'))),
            'customer_name' => trim((string) $this->input('customer_name')),
            'customer_phone' => trim((string) $this->input('customer_phone')),
            'deceased_name' => trim((string) $this->input('deceased_name')),
            'additional_notes' => is_string($notes) && trim($notes) !== ''
                ? trim($notes)
                : null,
        ]);
    }
}
