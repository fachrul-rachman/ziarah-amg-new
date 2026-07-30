<?php

namespace App\Http\Requests;

use App\Models\TimeSlot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTimeSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-admin-configuration') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $timeSlot = $this->route('time_slot');

        return [
            'start_time' => [
                'required',
                'date_format:H:i',
                'regex:/^\d{2}:00$/',
                Rule::unique('time_slots', 'start_time')->ignore(
                    $timeSlot instanceof TimeSlot ? $timeSlot : null,
                ),
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
