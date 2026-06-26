<?php

namespace App\Services\Eq;

/**
 * Resolves which zones are kill-on-sight for a traveler, from the committed factions.json
 * (built by eq:build-factions). A character's standing with a guard faction is the faction's base
 * plus its race/class/deity modifiers; the zone is KOS when that standing falls below the threshold
 * for any of its guard factions — the same con math EQEmu uses for a freshly created character.
 */
class FactionService
{
    /** @var array{threshold:int, factions:array<string,array{base:int,mods:array<string,int>}>, zones:array<string,list<int>>}|null */
    private ?array $data = null;

    /**
     * @return array{threshold:int, factions:array<string,array{base:int,mods:array<string,int>}>, zones:array<string,list<int>>}
     */
    public function data(): array
    {
        if ($this->data === null) {
            $path = config('eqrouter.factions_path');
            $this->data = is_file($path)
                ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)
                : ['threshold' => (int) config('eqrouter.kos_threshold'), 'factions' => [], 'zones' => []];
        }

        return $this->data;
    }

    /**
     * Zones that are KOS for this race/class/deity. Empty when no traveler identity is given (the
     * modifiers are what make a city hostile, so with none there's nothing race-specific to flag).
     *
     * @return array<string, true>
     */
    public function kosZones(?string $race, ?string $class, ?string $deity): array
    {
        $keys = $this->modifierKeys($race, $class, $deity);
        if ($keys === []) {
            return [];
        }

        $data = $this->data();
        $threshold = $data['threshold'];
        $standing = [];   // faction id => computed standing (memoized across zones)
        $kos = [];

        foreach ($data['zones'] as $zone => $factionIds) {
            foreach ($factionIds as $id) {
                if (! isset($standing[$id])) {
                    $faction = $data['factions'][$id] ?? null;
                    $value = $faction['base'] ?? 0;
                    foreach ($keys as $key) {
                        $value += $faction['mods'][$key] ?? 0;
                    }
                    $standing[$id] = $value;
                }

                if ($standing[$id] < $threshold) {
                    $kos[$zone] = true;
                    break;
                }
            }
        }

        return $kos;
    }

    /**
     * The faction-modifier keys for a traveler: r<raceId>/c<classId>/d<deityId>. Agnostic (no deity
     * faction) and unknown codes are skipped.
     *
     * @return list<string>
     */
    private function modifierKeys(?string $race, ?string $class, ?string $deity): array
    {
        $keys = [];

        if ($race !== null && ($id = config("eqrouter.races.{$race}.id")) !== null) {
            $keys[] = 'r'.$id;
        }
        if ($class !== null && ($id = config("eqrouter.classes.{$class}.id")) !== null) {
            $keys[] = 'c'.$id;
        }
        if ($deity !== null && ($id = config("eqrouter.deities.{$deity}.id")) !== null && $id !== 140) {
            $keys[] = 'd'.$id;
        }

        return $keys;
    }
}
