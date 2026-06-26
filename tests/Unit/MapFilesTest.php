<?php

namespace Tests\Unit;

use App\Console\Commands\BuildGraph;
use ReflectionMethod;
use Tests\TestCase;

class MapFilesTest extends TestCase
{
    /**
     * mapFiles() prefers Brewall per zone and only fills in zones Brewall lacks from Goodurden,
     * deduping by the zone short (filename minus its `_N` layer suffix).
     */
    public function test_goodurden_only_fills_zones_brewall_does_not_cover(): void
    {
        $brewall = $this->makeDir('brewall', ['nro.txt', 'nro_1.txt', 'sro.txt']);
        $goodurden = $this->makeDir('goodurden', ['nro.txt', 'gfaydark.txt', 'gfaydark_2.txt']);

        config(['eqrouter.brewall_path' => $brewall, 'eqrouter.goodurden_path' => $goodurden]);

        $method = new ReflectionMethod(BuildGraph::class, 'mapFiles');
        $files = $method->invoke($this->app->make(BuildGraph::class));
        $names = array_map('basename', $files);

        // All three Brewall files are kept.
        $this->assertContains('nro.txt', $names);
        $this->assertContains('nro_1.txt', $names);
        $this->assertContains('sro.txt', $names);

        // Goodurden fills gfaydark (both layers) but NOT nro — Brewall already covers it.
        $this->assertContains('gfaydark.txt', $names);
        $this->assertContains('gfaydark_2.txt', $names);
        $this->assertNotContains($goodurden.'/nro.txt', $files);
        $this->assertCount(5, $files);
    }

    public function test_missing_directories_yield_no_files(): void
    {
        config([
            'eqrouter.brewall_path' => '/nonexistent/brewall',
            'eqrouter.goodurden_path' => null,
        ]);

        $method = new ReflectionMethod(BuildGraph::class, 'mapFiles');
        $this->assertSame([], $method->invoke($this->app->make(BuildGraph::class)));
    }

    /**
     * @param  list<string>  $files
     */
    private function makeDir(string $name, array $files): string
    {
        $dir = sys_get_temp_dir().'/nta-maps-'.$name.'-'.uniqid();
        mkdir($dir, 0777, true);
        foreach ($files as $file) {
            file_put_contents($dir.'/'.$file, "P 0,0,0,0,0,0,3,to_Test\n");
        }

        return $dir;
    }
}
