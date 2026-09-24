<?php

namespace App\Http\Requests\Ipo;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDematAccountRequest extends FormRequest
{
    /**
     * @return array<string, string|string[]>
     */
    public function rules(): array
    {
        $provider = $this->input('provider');

        return [
            'provider' => ['required', 'in:nsdl,cdsl,cdsl_other'],
            'dp_id' => ['sometimes', 'nullable', 'string', 'max:16'],
            'client_id' => ['required', 'string', 'max:32', Rule::unique('demat_accounts', 'client_id')
                ->where(fn ($q) => $q->where('user_id', $this->user()?->id)->where('provider', $provider))],
            'account_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'upi_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
