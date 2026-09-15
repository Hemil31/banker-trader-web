<?php

namespace App\Http\Requests\Zernio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string $filename
 * @property string $content_type
 */
class PresignZernioMediaRequest extends FormRequest
{
    /**
     * @return array<string, string|string[]>
     */
    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255', 'regex:/^[\w.\- ]+\.(png|jpg|jpeg|gif|webp|mp4|mov|avi|webm|pdf|mp3|m4a|wav|ogg)$/i'],
            'content_type' => ['required', 'string', 'max:60',
                'in:image/jpeg,image/jpg,image/png,image/webp,image/gif,video/mp4,video/mpeg,video/quicktime,video/avi,video/x-msvideo,video/webm,video/x-m4v,application/pdf,audio/mpeg,audio/mp4,audio/aac,audio/ogg,audio/wav,audio/webm,audio/x-m4a'],
            'size' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
