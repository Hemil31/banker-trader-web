<?php

namespace App\Models;

use Database\Factories\SystemEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 */
class SystemEvent extends Model
{
    /** @use HasFactory<SystemEventFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'type', 'action', 'actor', 'subject_type', 'subject_id',
        'description', 'data', 'occurred_at',
    ];

    protected $casts = [
        'data' => 'json',
        'occurred_at' => 'datetime',
    ];

    public $timestamps = false;
}
