<?php

namespace App\Http\Requests\Zernio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string $content
 * @property array<int, string> $account_ids
 * @property array<int, array{url: string, type?: string}> $media
 * @property string|null $scheduled_at
 * @property string|null $timezone
 */
class CreateZernioPostRequest extends FormRequest
{
    /**
     * @return array<string, string|string[]>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:2000'],
            'account_ids' => ['required', 'array', 'min:1', 'max:50'],
            'account_ids.*' => ['required', 'uuid', 'exists:zernio_accounts,id'],
            'media' => ['sometimes', 'array', 'max:10'],
            'media.*.url' => ['required', 'url'],
            'media.*.type' => ['sometimes', 'in:image,video,gif,document'],
            'scheduled_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i'],
            'timezone' => ['sometimes', 'nullable', 'timezone'],
        ];
    }
}
