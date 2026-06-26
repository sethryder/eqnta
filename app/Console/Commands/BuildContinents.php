<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Derives database/eq/continents.json (zone short → continent) from the committed graph. In classic
 * scope (edge expansion ≤ 2) the landmasses are separate islands linked only by boats, so each
 * connected component of the land graph is one continent — labelled by the config anchor it contains.
 *
 * Run after eq:build-graph. If a component holds anchors from two continents, a bogus land edge is
 * still merging them (the command warns and names them) — fix it via overrides/boat_links and rerun.
 */
class BuildContinents extends Command
{
    protected $signature = 'eq:build-continents';

    protected $description = 'Derive the zone→continent map from the classic land graph';

    public function handle(): int
    {
        $graph = json_decode((string) file_get_contents(config('eqrouter.graph_path')), true);

        // Undirected land graph, classic scope (edge expansion ≤ 2 means both endpoints are P99-era).
        $adj = [];
        foreach ($graph['edges'] as $edge) {
            if ($edge['type'] !== 'walk' || ($edge['expansion'] ?? 0) > 2 || $edge['from'] === $edge['to']) {
                continue;
            }
            $adj[$edge['from']][$edge['to']] = true;
            $adj[$edge['to']][$edge['from']] = true;
        }

        // Anchor zone → continent, for labelling components.
        $anchorOf = [];
        foreach (config('eqrouter.continent_anchors') as $continent => $anchors) {
            foreach ($anchors as $anchor) {
                $anchorOf[$anchor] = $continent;
            }
        }

        $continents = [];
        $merged = false;
        foreach ($this->components($adj) as $component) {
            $labels = array_values(array_unique(array_filter(
                array_map(fn ($zone) => $anchorOf[$zone] ?? null, $component),
            )));

            if (count($labels) > 1) {
                $merged = true;
                $this->error('Component merges continents '.implode(' + ', $labels).': '.implode(', ', array_slice($component, 0, 6)).'…');

                continue;
            }
            if ($labels === []) {
                continue; // boat-only island with no anchor — leave unlabelled
            }

            foreach ($component as $zone) {
                $continents[$zone] = $labels[0];
            }
        }

        ksort($continents);
        file_put_contents(config('eqrouter.continents_path'), json_encode($continents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $counts = array_count_values($continents);
        ksort($counts);
        $this->info('Wrote '.count($continents).' zones: '.json_encode($counts));

        return $merged ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Connected components of an undirected adjacency map.
     *
     * @param  array<string, array<string, true>>  $adj
     * @return list<list<string>>
     */
    private function components(array $adj): array
    {
        $seen = [];
        $components = [];
        foreach (array_keys($adj) as $start) {
            if (isset($seen[$start])) {
                continue;
            }
            $component = [];
            $stack = [$start];
            $seen[$start] = true;
            while ($stack !== []) {
                $node = array_pop($stack);
                $component[] = $node;
                foreach (array_keys($adj[$node] ?? []) as $next) {
                    if (! isset($seen[$next])) {
                        $seen[$next] = true;
                        $stack[] = $next;
                    }
                }
            }
            $components[] = $component;
        }

        return $components;
    }
}
