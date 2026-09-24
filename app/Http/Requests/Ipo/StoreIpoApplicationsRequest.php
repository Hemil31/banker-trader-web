<?php

namespace App\Http\Requests\Ipo;

use Illuminate\Foundation\Http\FormRequest;

class StoreIpoApplicationsRequest extends FormRequest
{
    /**
     * @return array<string, string|string[]>
     */
    public function rules(): array
    {
        return [
            'pan_card_id' => ['required', 'uuid', 'exists:pan_cards,id'],
            'demat_account_id' => ['required', 'uuid', 'exists:demat_accounts,id'],
            'trading_account_id' => ['sometimes', 'nullable', 'uuid', 'exists:trading_accounts,id'],
            'applications' => ['required', 'array', 'min:1', 'max:20'],
            'applications.*.ipo_id' => ['required', 'uuid', 'exists:ipos,id'],
            'applications.*.lots' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
