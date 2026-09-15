<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property string $post_id
 * @property string $account_id
 * @property string $status
 * @property string|null $platform_post_url
 */
class ZernioPostAccount extends Pivot
{
    use HasUuids;

    protected $table = 'zernio_post_account';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'string',
            'platform_post_url' => 'string',
        ];
    }
}
