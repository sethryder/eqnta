<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Flags walk edges that cross between continents. In classic scope (expansion ≤ 2) the landmasses
 * are separate islands linked only by boats, so a land edge between two different continents (per
 * continents.json) is wrong: it's either a boat the data mislabelled (add to config boat_links) or a
 * dangling junk zone line (remove via overrides.json). Verify against EQAtlas / the P99 wiki.
 *
 * Read-only. Needs continents.json (eq:build-continents) — note that a *new* bogus edge merging two
 * continents is caught by eq:build-continents itself (it refuses to label a merged component); this
 * command catches cross-continent edges among already-labelled zones.
 */
class AuditGraph extends Command
{
    protected $signature = 'eq:audit-graph';

    protected $description = 'Report walk edges that cross between continents';

    public function handle(): int
    {
        $graph = json_decode((string) file_get_contents(config('eqrouter.graph_path')), true);
        $continentsPath = config('eqrouter.continents_path');

        if (! is_file($continentsPath)) {
            $this->error('continents.json missing — run eq:build-continents first.');

            return self::FAILURE;
        }

        $continent = json_decode((string) file_get_contents($continentsPath), true);
        $long = array_column($graph['zones'], 'long', 'short');

        $crossings = [];
        foreach ($graph['edges'] as $edge) {
            if ($edge['type'] !== 'walk' || ($edge['expansion'] ?? 0) > 2) {
                continue;
            }
            $from = $continent[$edge['from']] ?? null;
            $to = $continent[$edge['to']] ?? null;
            if ($from === null || $to === null || $from === $to) {
                continue;
            }
            // Dedupe direction.
            $key = implode("\0", $this->sortPair($edge['from'], $edge['to']));
            $crossings[$key] = [$edge['from'], $from, $edge['to'], $to];
        }

        if ($crossings === []) {
            $this->info('No cross-continent walk edges. Graph is geographically consistent.');

            return self::SUCCESS;
        }

        $this->warn(count($crossings).' cross-continent walk edge(s) — verify, then fix via boat_links or overrides:');
        $this->table(
            ['From', 'To'],
            array_map(
                fn ($c) => [($long[$c[0]] ?? $c[0])." ({$c[1]})", ($long[$c[2]] ?? $c[2])." ({$c[3]})"],
                $crossings,
            ),
        );

        return self::SUCCESS;
    }

    /**
     * @return array{string, string}
     */
    private function sortPair(string $a, string $b): array
    {
        return $a <= $b ? [$a, $b] : [$b, $a];
    }
}
