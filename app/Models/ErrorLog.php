<?php

namespace App\Models;

use Database\Factories\ErrorLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 */
class ErrorLog extends Model
{
    /** @use HasFactory<ErrorLogFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['level', 'component', 'code', 'message', 'context', 'logged_at'];

    protected $casts = [
        'context' => 'json',
        'logged_at' => 'datetime',
    ];
}
