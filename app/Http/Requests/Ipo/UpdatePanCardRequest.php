<?php

namespace App\Http\Requests\Ipo;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePanCardRequest extends FormRequest
{
    /**
     * @return array<string, string|string[]>
     */
    public function rules(): array
    {
        return [
            'pan_number' => ['sometimes', 'string', 'regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/', 'unique:pan_cards,pan_number,'.$this->route('panCard')],
            'holder_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'date_of_birth' => ['sometimes', 'nullable', 'date'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
