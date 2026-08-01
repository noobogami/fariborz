<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CustomTool extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'promoted' => 'boolean',
        'run_count' => 'integer',
    ];
}
