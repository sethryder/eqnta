<?php

namespace App\Services\Eq;

/**
 * A delta layers custom data over a graph: add zones, add edges, remove edges. Used both at
 * build time (universal corrections baked into the base graph) and at request time (per-server
 * differences like Project 1999 connections or EverQuest Legends custom zones).
 *
 * Shape: { "zones": [...], "add": [edge...], "remove": [spec...] }. A bare edge list is treated
 * as additions for backward compatibility.
 */
class GraphDelta
{
    /**
     * Load and normalise a delta file. Returns an empty delta when the path is null or missing.
     *
     * @return array{zones: list<array>, add: list<array>, remove: list<array>}
     */
    public static function load(?string $path): array
    {
        if ($path === null || ! is_file($path)) {
            return ['zones' => [], 'add' => [], 'remove' => []];
        }

        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (array_is_list($data)) {
            return ['zones' => [], 'add' => $data, 'remove' => []];
        }

        return [
            'zones' => $data['zones'] ?? [],
            'add' => $data['add'] ?? [],
            'remove' => $data['remove'] ?? [],
        ];
    }

    /**
     * Combine several deltas into one (zones/add/remove concatenated), applied in order.
     *
     * @param  array{zones: list<array>, add: list<array>, remove: list<array>}  ...$deltas
     * @return array{zones: list<array>, add: list<array>, remove: list<array>}
     */
    public static function merge(array ...$deltas): array
    {
        $merged = ['zones' => [], 'add' => [], 'remove' => []];
        foreach ($deltas as $delta) {
            $merged['zones'] = array_merge($merged['zones'], $delta['zones'] ?? []);
            $merged['add'] = array_merge($merged['add'], $delta['add'] ?? []);
            $merged['remove'] = array_merge($merged['remove'], $delta['remove'] ?? []);
        }

        return $merged;
    }

    public static function isEmpty(array $delta): bool
    {
        return ($delta['zones'] ?? []) === [] && ($delta['add'] ?? []) === [] && ($delta['remove'] ?? []) === [];
    }

    /**
     * Apply a delta to a graph: drop edges matching any remove spec, append added edges, append
     * custom zones (deduped by short).
     *
     * @param  list<array>  $zones
     * @param  list<array>  $edges
     * @param  array{zones: list<array>, add: list<array>, remove: list<array>}  $delta
     * @return array{0: list<array>, 1: list<array>} [zones, edges]
     */
    public static function apply(array $zones, array $edges, array $delta): array
    {
        if (($delta['remove'] ?? []) !== []) {
            $edges = array_values(array_filter(
                $edges,
                fn (array $edge) => ! self::matches($edge, $delta['remove']),
            ));
        }

        $edges = array_merge($edges, $delta['add'] ?? []);

        $known = array_flip(array_column($zones, 'short'));
        foreach (($delta['zones'] ?? []) as $zone) {
            if (! isset($known[$zone['short']])) {
                $known[$zone['short']] = true;
                $zones[] = $zone;
            }
        }

        return [$zones, $edges];
    }

    /**
     * True when the edge matches any spec. A spec matches when every key it lists (e.g. from, to,
     * type) equals the edge's value — so {from, to} removes both a walk and a boat between them.
     *
     * @param  array<string, mixed>  $edge
     * @param  list<array<string, mixed>>  $specs
     */
    public static function matches(array $edge, array $specs): bool
    {
        foreach ($specs as $spec) {
            foreach ($spec as $key => $value) {
                if (($edge[$key] ?? null) !== $value) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }
}
