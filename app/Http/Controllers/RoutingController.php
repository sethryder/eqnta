<?php

namespace App\Http\Controllers;

use App\Services\Eq\GraphDelta;
use App\Services\Eq\Router;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoutingController extends Controller
{
    public function __construct(private Router $router) {}

    /**
     * Zone list for the start/destination dropdowns, for the chosen server (custom zones included).
     */
    public function zones(Request $request): JsonResponse
    {
        $server = $this->resolveServer($request);

        // An explicit `expansions` list (comma-separated or array) wins; otherwise use the server's
        // default era. A server with no default (TLP) and no list means "everything" (null filter).
        $raw = $request->input('expansions');
        $expansions = $raw === null
            ? ($server['config']['expansions'] ?? null)
            : array_map('intval', is_array($raw) ? $raw : explode(',', $raw));

        return response()->json($this->router->zones($server['key'], $server['delta'], $expansions))
            ->header('Cache-Control', 'public, max-age=0, s-maxage=86400, must-revalidate');
    }

    /**
     * Server profiles + expansion catalog, for the server picker and expansion selector.
     */
    public function servers(): JsonResponse
    {
        return response()->json([
            'servers' => collect(config('eqrouter.servers'))
                ->map(fn (array $server, string $key) => [
                    'key' => $key,
                    'label' => $server['label'],
                    'expansions' => $server['expansions'],
                ])
                ->values(),
            'expansions' => collect(config('eqrouter.expansions'))
                ->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])
                ->values(),
            'default' => config('eqrouter.default_server'),
            'races' => $this->codeList('eqrouter.races'),
            'classes' => $this->codeList('eqrouter.classes'),
            'deities' => $this->codeList('eqrouter.deities'),
        ])->header('Cache-Control', 'public, max-age=0, s-maxage=86400, must-revalidate');
    }

    /**
     * Flatten a code → [label, ...] config map into a UI-friendly list, carrying the `port` flag.
     *
     * @return list<array{code: string, label: string, port?: bool}>
     */
    private function codeList(string $configKey): array
    {
        return collect(config($configKey))
            ->map(fn (array $entry, string $code) => array_filter([
                'code' => $code,
                'label' => $entry['label'],
                'port' => $entry['port'] ?? null,
            ], fn ($v) => $v !== null))
            ->values()
            ->all();
    }

    /**
     * Step-by-step directions between two zones for the chosen server and (optional) class/level.
     *
     * `server` selects the profile (expansions + custom data). `expansions` overrides the server's
     * default expansion set — required for profiles that don't define one (generic TLP / custom).
     */
    public function route(Request $request): JsonResponse
    {
        $server = $this->resolveServer($request);
        $shorts = array_column($this->router->zones($server['key'], $server['delta']), 'short');

        $validated = $request->validate([
            'from' => ['required', Rule::in($shorts)],
            'to' => ['required', Rule::in($shorts)],
            'expansions' => ['nullable', 'array'],
            'expansions.*' => [Rule::in(array_keys(config('eqrouter.expansions')))],
            'class' => ['nullable', Rule::in(array_keys(config('eqrouter.classes')))],
            'level' => ['nullable', 'integer', 'min:1', 'max:'.config('eqrouter.max_level')],
            'mode' => ['nullable', Rule::in(['fastest', 'safest'])],
            'race' => ['nullable', Rule::in(array_keys(config('eqrouter.races')))],
            'deity' => ['nullable', Rule::in(array_keys(config('eqrouter.deities')))],
        ]);

        $result = $this->router->find(
            $validated['from'],
            $validated['to'],
            $this->enabledExpansions($validated, $server),
            $validated['class'] ?? null,
            (int) ($validated['level'] ?? 1),
            $validated['mode'] ?? 'fastest',
            $validated['race'] ?? null,
            $validated['deity'] ?? null,
            $server['key'],
            $server['delta'],
        );

        if ($result === null) {
            return response()->json(['message' => 'No route found.'], 404);
        }

        $result['steps'] = $this->attachRunLengths($result['steps']);

        return response()->json($result);
    }

    /**
     * Annotate each walk step with `runLen`: the baked navmesh length of the run across that step's
     * `from` zone, from where you entered it to where you leave toward the next zone. Lets the UI's
     * trip ETA use real path distance instead of a straight line. Absent where nothing is baked.
     *
     * @param  list<array>  $steps
     * @return list<array>
     */
    private function attachRunLengths(array $steps): array
    {
        $paths = $this->bakedPaths();
        for ($i = 1; $i < count($steps); $i++) {
            if (($steps[$i]['type'] ?? null) !== 'walk') {
                continue;
            }
            $key = $steps[$i - 1]['from'].'|'.$steps[$i]['to'];
            $len = $paths[$steps[$i]['from']][$key]['len'] ?? null;
            if ($len !== null) {
                $steps[$i]['runLen'] = $len;
            }
        }

        return $steps;
    }

    /**
     * Raw Brewall map geometry (`L`/`P` lines) for a zone, by short name. Served as-is for the
     * client to parse into an SVG; cached at the edge (purged on deploy), browser revalidates.
     */
    public function map(string $zone): Response
    {
        if (! preg_match('/^[a-z0-9_]+$/', $zone)) {
            abort(404);
        }

        $path = $this->mapFile($zone);
        if ($path === null) {
            abort(404);
        }

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'text/plain',
            'Cache-Control' => 'public, max-age=0, s-maxage=86400, must-revalidate',
        ]);
    }

    /**
     * Resolve a zone's map .txt: Brewall first, then the Goodurden fallback. An empty file (some
     * zones ship a 0-byte Brewall map) counts as missing so the fallback gets a turn.
     */
    private function mapFile(string $zone): ?string
    {
        foreach (['brewall_path', 'goodurden_path'] as $key) {
            $path = config("eqrouter.{$key}").'/'.$zone.'.txt';
            if (is_file($path) && filesize($path) > 0) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Walk zone-line points relating a zone to each neighbour, in this zone's own coordinate space,
     * for the client to mark on this zone's map (bounds-gated client-side). Per neighbour:
     *   - `exit`:  the zone-line trigger here, leaving toward the neighbour (edge from=this zone).
     *   - `enter`: where you land here arriving from the neighbour (the reverse edge's target coord).
     * `enter` is only present once the graph carries landing coords (`tx`/`ty`); until then the
     * client falls back to `exit`, matching the prior behaviour.
     *
     * @return JsonResponse map of neighbourShort => array{exit?: array{x,y}, enter?: array{x,y}}
     */
    public function mapExits(string $zone): JsonResponse
    {
        if (! preg_match('/^[a-z0-9_]+$/', $zone)) {
            abort(404);
        }

        $points = [];
        foreach ($this->router->graph()['edges'] as $edge) {
            if (($edge['type'] ?? 'walk') !== 'walk') {
                continue;
            }
            if (($edge['from'] ?? null) === $zone && isset($edge['x'], $edge['y'])) {
                $points[$edge['to']]['exit'] = ['x' => $edge['x'], 'y' => $edge['y']];
            }
            if (($edge['to'] ?? null) === $zone && isset($edge['tx'], $edge['ty'])) {
                $points[$edge['from']]['enter'] = ['x' => $edge['tx'], 'y' => $edge['ty']];
            }
        }

        return response()->json($points, 200, ['Cache-Control' => 'public, max-age=0, s-maxage=86400, must-revalidate']);
    }

    /**
     * Baked navmesh walkable paths within a zone, keyed by "fromShort|toShort" => {len, line}. The
     * line is a polyline already in the app's screen frame. Empty when nothing is baked for the
     * zone — the client then draws a straight line between the gates.
     */
    public function mapPaths(string $zone): JsonResponse
    {
        if (! preg_match('/^[a-z0-9_]+$/', $zone)) {
            abort(404);
        }

        $all = $this->bakedPaths();

        return response()->json($all[$zone] ?? (object) [], 200, ['Cache-Control' => 'public, max-age=0, s-maxage=86400, must-revalidate']);
    }

    /**
     * Load + cache the baked paths sidecar. Absent file (paths not baked) is fine — returns [].
     *
     * @return array<string, array<string, array{len:int, line: list<array{0:int,1:int}>}>>
     */
    private function bakedPaths(): array
    {
        static $cache = [];
        $path = config('eqrouter.paths_path');
        if (! array_key_exists($path, $cache)) {
            $cache[$path] = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        }

        return $cache[$path];
    }

    /**
     * Resolve the `server` param into its key and merged delta. Defaults to config default_server.
     *
     * @return array{key: string, config: array, delta: array{zones: list<array>, add: list<array>, remove: list<array>}}
     */
    private function resolveServer(Request $request): array
    {
        $key = $request->input('server', config('eqrouter.default_server'));
        $servers = config('eqrouter.servers');

        if (! isset($servers[$key])) {
            throw ValidationException::withMessages([
                'server' => 'Unknown server "'.$key.'".',
            ]);
        }

        $deltas = array_map(
            fn (string $name) => GraphDelta::load(config('eqrouter.deltas_path').'/'.$name.'.json'),
            $servers[$key]['deltas'] ?? [],
        );

        return [
            'key' => $key,
            'config' => $servers[$key],
            'delta' => GraphDelta::merge(...$deltas),
        ];
    }

    /**
     * Enabled expansion set: an explicit `expansions` list wins, else the server's default. A
     * server with no default (TLP/custom) requires the caller to pass `expansions`.
     *
     * @param  array<string, mixed>  $validated
     * @param  array{config: array}  $server
     * @return list<int>
     */
    private function enabledExpansions(array $validated, array $server): array
    {
        if (! empty($validated['expansions'])) {
            return array_map('intval', $validated['expansions']);
        }

        $expansions = $server['config']['expansions'] ?? null;
        if ($expansions === null) {
            throw ValidationException::withMessages([
                'expansions' => 'This server requires an explicit expansions list.',
            ]);
        }

        return $expansions;
    }
}
