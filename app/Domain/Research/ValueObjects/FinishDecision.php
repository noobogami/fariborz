<?php

namespace App\Domain\Research\ValueObjects;

final class FinishDecision extends Decision
{
    public function __construct(
        public readonly string $report,
        public readonly ?float $confidence = null,
        string $thought = '',
    ) {
        parent::__construct($thought);
    }

    public function toArray(): array
    {
        return [
            'action' => 'finish',
            'report' => $this->report,
            'confidence' => $this->confidence,
            'thought' => $this->thought,
        ];
    }
}
