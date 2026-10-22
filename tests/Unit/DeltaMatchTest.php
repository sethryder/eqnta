<?php

namespace Tests\Unit;

use App\Services\Eq\GraphDelta;
use PHPUnit\Framework\TestCase;

/**
 * Covers the delta removal-matching semantics: a spec matches when every key it lists equals
 * the edge's value, so a partial spec (e.g. just from+to) can drop several edges at once.
 */
class DeltaMatchTest extends TestCase
{
    public function test_partial_spec_matches_on_listed_keys_only(): void
    {
        $edge = ['from' => 'a', 'to' => 'b', 'type' => 'walk', 'expansion' => 0];

        $this->assertTrue(GraphDelta::matches($edge, [['from' => 'a', 'to' => 'b']]));
        $this->assertTrue(GraphDelta::matches($edge, [['from' => 'a', 'to' => 'b', 'type' => 'walk']]));
    }

    public function test_spec_with_any_mismatched_key_does_not_match(): void
    {
        $edge = ['from' => 'a', 'to' => 'b', 'type' => 'walk'];

        $this->assertFalse(GraphDelta::matches($edge, [['from' => 'a', 'to' => 'b', 'type' => 'boat']]));
        $this->assertFalse(GraphDelta::matches($edge, [['from' => 'a', 'to' => 'c']]));
    }

    public function test_matches_when_any_spec_in_the_list_matches(): void
    {
        $edge = ['from' => 'a', 'to' => 'b', 'type' => 'walk'];

        $this->assertTrue(GraphDelta::matches($edge, [['from' => 'x', 'to' => 'y'], ['from' => 'a', 'to' => 'b']]));
        $this->assertFalse(GraphDelta::matches($edge, []));
    }

    public function test_apply_adds_zones_edges_and_removes_matching(): void
    {
        $zones = [['id' => 1, 'short' => 'a', 'long' => 'A']];
        $edges = [['from' => 'a', 'to' => 'b', 'type' => 'walk']];

        [$zones, $edges] = GraphDelta::apply($zones, $edges, [
            'zones' => [['id' => 2, 'short' => 'c', 'long' => 'C']],
            'add' => [['from' => 'a', 'to' => 'c', 'type' => 'boat']],
            'remove' => [['from' => 'a', 'to' => 'b']],
        ]);

        $this->assertSame(['a', 'c'], array_column($zones, 'short'));
        $this->assertSame([['from' => 'a', 'to' => 'c', 'type' => 'boat']], $edges);
    }
}
