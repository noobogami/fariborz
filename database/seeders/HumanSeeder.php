<?php

namespace Database\Seeders;

use App\Domain\Research\Enums\HumanStatus;
use App\Models\Human;
use Illuminate\Database\Seeder;

/**
 * There is exactly ONE human — "Father" — who oversees the research. This seeder
 * enforces that: it removes any other humans and (re)creates the single one.
 */
class HumanSeeder extends Seeder
{
    public function run(): void
    {
        $name = config('research.human.name', 'Father');

        // Enforce singleton: drop everyone else.
        Human::query()->where('name', '!=', $name)->delete();

        Human::updateOrCreate(
            ['name' => $name],
            [
                'status' => HumanStatus::Available,
                'expertise' => null,   // the one human answers everything
                'permissions' => ['*'],
                'last_seen_at' => now(),
            ],
        );
    }
}
