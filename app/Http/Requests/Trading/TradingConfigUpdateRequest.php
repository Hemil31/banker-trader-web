<?php

namespace App\Http\Requests\Trading;

use App\Models\TradingConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TradingConfigUpdateRequest extends FormRequest
{
    /**
     * @return array<string, string|string[]>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:120', 'exists:trading_configs,key'],
            'value' => ['required'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('key')) {
                return;
            }

            $row = TradingConfig::where('key', $this->get('key'))->first();

            if (! $row || ! $row->is_editable) {
                $validator->errors()->add('key', 'This setting is not editable.');

                return;
            }

            $value = $this->input('value');

            if (in_array($row->type, ['float', 'integer'], true) && ! is_numeric($value)) {
                $validator->errors()->add('value', 'Expected a number.');
            }

            if ($row->type === 'boolean' && ! in_array((string) $value, ['0', '1', 'true', 'false'], true)) {
                $validator->errors()->add('value', 'Expected true or false.');
            }
        });
    }

    /**
     * The value cast to the type registered for the config key.
     */
    public function typedValue(): mixed
    {
        $row = TradingConfig::where('key', $this->get('key'))->first();
        $value = $this->input('value');

        if ($row instanceof TradingConfig) {
            return match ($row->type) {
                'float' => (float) $value,
                'integer' => (int) $value,
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'array', 'json' => is_array($value) ? $value : json_decode((string) $value, true),
                default => $value,
            };
        }

        return $value;
    }
}
