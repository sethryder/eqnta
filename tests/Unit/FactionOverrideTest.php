<?php

namespace Tests\Unit;

use App\Console\Commands\BuildFactions;
use PHPUnit\Framework\TestCase;

/**
 * Covers the hand-curated faction merge: overrides add guard factions to a zone (for post-RoF
 * zones PEQ's spawns don't reach) without dropping the PEQ-derived ones, keeping ids sorted.
 */
class FactionOverrideTest extends TestCase
{
    public function test_override_adds_to_a_zone_without_replacing_existing(): void
    {
        $zones = ['cabeast' => [215, 300]];

        $merged = BuildFactions::applyFactionOverrides($zones, ['cabeast' => [400]]);

        $this->assertSame([215, 300, 400], $merged['cabeast']);
    }

    public function test_override_creates_a_zone_absent_from_peq(): void
    {
        $merged = BuildFactions::applyFactionOverrides([], ['neriakd' => [215]]);

        $this->assertSame([215], $merged['neriakd']);
    }

    public function test_override_dedupes_and_sorts_string_ids(): void
    {
        // JSON numbers arrive as ints, but a curator may quote them — they must still merge cleanly.
        $merged = BuildFactions::applyFactionOverrides(['bixiewarfront' => [300]], ['bixiewarfront' => ['300', '210']]);

        $this->assertSame([210, 300], $merged['bixiewarfront']);
    }

    public function test_no_overrides_leaves_zones_untouched(): void
    {
        $zones = ['cabeast' => [215]];

        $this->assertSame($zones, BuildFactions::applyFactionOverrides($zones, []));
    }
}
