<?php

namespace App\Http\Requests\Ipo;

use Illuminate\Foundation\Http\FormRequest;

class StorePanCardRequest extends FormRequest
{
    /**
     * @return array<string, string|string[]>
     */
    public function rules(): array
    {
        return [
            'pan_number' => ['required', 'string', 'regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/', 'unique:pan_cards,pan_number'],
            'holder_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'date_of_birth' => ['sometimes', 'nullable', 'date'],
            'is_primary' => ['sometimes', 'boolean'],
            'verification_details' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
