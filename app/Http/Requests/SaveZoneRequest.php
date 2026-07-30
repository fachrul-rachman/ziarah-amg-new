<?php

namespace App\Http\Requests;

use App\Models\Zone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class SaveZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-admin-configuration') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $name = $this->input('name');

                if (! is_string($name) || $name === '') {
                    return;
                }

                $query = Zone::query()->whereRaw('LOWER(name) = ?', [
                    Str::lower($name),
                ]);
                $zone = $this->route('zone');

                if ($zone instanceof Zone) {
                    $query->whereKeyNot($zone->getKey());
                }

                if ($query->exists()) {
                    $validator->errors()->add(
                        'name',
                        'Nama zona sudah digunakan.',
                    );
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
