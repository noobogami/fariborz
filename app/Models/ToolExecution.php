<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolExecution extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'arguments' => 'array',
        'result' => 'array',
        'attempts' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(ResearchJob::class, 'research_job_id');
    }

    /**
     * Deterministic fingerprint used for duplicate detection.
     * Same tool + same (order-insensitive) arguments => same fingerprint.
     */
    public static function fingerprint(string $tool, array $arguments): string
    {
        $normalized = self::normalize($arguments);

        return hash('sha256', $tool.'|'.json_encode($normalized));
    }

    private static function normalize(array $arguments): array
    {
        ksort($arguments);

        foreach ($arguments as $k => $v) {
            if (is_array($v)) {
                $arguments[$k] = self::normalize($v);
            } elseif (is_string($v)) {
                $arguments[$k] = trim(mb_strtolower($v));
            }
        }

        return $arguments;
    }
}
