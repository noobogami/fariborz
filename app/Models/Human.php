<?php

namespace App\Models;

use App\Domain\Research\Enums\HumanStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Human extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'status' => HumanStatus::class,
        'expertise' => 'array',
        'permissions' => 'array',
        'last_seen_at' => 'datetime',
    ];

    public function assignedQuestions(): HasMany
    {
        return $this->hasMany(HumanQuestion::class, 'assigned_human_id');
    }
}
