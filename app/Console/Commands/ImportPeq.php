<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

/**
 * Downloads the ProjectEQ content-database dump and loads just the tables the router needs into the
 * `peq` connection. The dump is a zip of mariadb-dump SQL (~32MB); we stream it, slice out the
 * configured tables, and execute their statements against `peq` — no external mysql client needed,
 * so it runs the same in dev, CI, and prod.
 *
 * Pair with eq:build-graph + eq:build-factions (or pass --build) to regenerate the committed JSON
 * the app actually serves. Prod normally deploys that JSON and never needs this; run it to refresh.
 */
class ImportPeq extends Command
{
    protected $signature = 'eq:import-peq
        {--file= : Use a local dump (.zip or create_tables_content.sql) instead of downloading}
        {--build : Also run eq:build-graph and eq:build-factions afterwards}';

    protected $description = 'Download the PEQ dump and load the tables the router needs into the peq connection';

    public function handle(): int
    {
        $sql = $this->resolveSqlPath();
        $wanted = array_flip(config('eqrouter.import_tables'));
        $db = DB::connection(config('eqrouter.connection'));

        $this->info('Loading '.count($wanted).' tables into the "'.config('eqrouter.connection').'" connection...');

        $handle = fopen($sql, 'r');
        if ($handle === false) {
            $this->error("Could not read {$sql}");

            return self::FAILURE;
        }

        $db->statement('SET FOREIGN_KEY_CHECKS=0');
        $db->statement('SET UNIQUE_CHECKS=0');

        $current = null;       // table the parser is currently inside
        $capturing = false;    // whether $current is one we want
        $buffer = '';          // accumulating one SQL statement
        $counts = [];          // table => statements executed

        while (($line = fgets($handle)) !== false) {
            // Table boundary marker: "-- Table structure for table `name`".
            if (preg_match('/^-- Table structure for table `([^`]+)`/', $line, $m)) {
                $current = $m[1];
                $capturing = isset($wanted[$current]);
                $buffer = '';

                continue;
            }

            if (! $capturing) {
                continue;
            }

            $trimmed = trim($line);
            // Skip comments, blanks, table locks, and the dump's /*!...*/ version pragmas — the
            // latter restore @OLD_* session vars we never set (header is outside any table). CREATE
            // TABLE carries its own charset, so dropping these changes nothing we need.
            if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*!')
                || str_starts_with($trimmed, 'LOCK TABLES') || str_starts_with($trimmed, 'UNLOCK TABLES')) {
                continue;
            }

            $buffer .= $line;

            // mysqldump ends every statement with ';' at end of line (INSERTs are one line each).
            if (str_ends_with($trimmed, ';')) {
                $db->unprepared($buffer);
                $counts[$current] = ($counts[$current] ?? 0) + 1;
                $buffer = '';
            }
        }

        fclose($handle);
        $db->statement('SET FOREIGN_KEY_CHECKS=1');
        $db->statement('SET UNIQUE_CHECKS=1');

        // Drop the 270MB+ extracted SQL; keep the cached zip so a re-run can reuse it via --file.
        File::deleteDirectory(storage_path('app/eq-import/peq-dump'));

        foreach (config('eqrouter.import_tables') as $table) {
            $rows = isset($counts[$table]) ? $db->table($table)->count() : 0;
            $this->line(sprintf('  %-22s %s', $table, isset($counts[$table]) ? "{$rows} rows" : '<not found in dump>'));
        }

        if ($this->option('build')) {
            $this->newLine();
            $this->call('eq:build-graph');
            $this->call('eq:build-factions');
        } else {
            $this->newLine();
            $this->info('Next: php artisan eq:build-graph && php artisan eq:build-factions');
        }

        return self::SUCCESS;
    }

    /**
     * Return a path to the dump's content SQL, downloading + unzipping as needed. Accepts a local
     * .sql directly, a local .zip, or (default) downloads the configured dump_url.
     */
    private function resolveSqlPath(): string
    {
        $work = storage_path('app/eq-import');
        @mkdir($work, 0755, true);

        $file = $this->option('file');
        if ($file !== null && str_ends_with($file, '.sql')) {
            return $file;
        }

        $zip = $file ?? $this->download($work.'/peq-dump.zip');

        $archive = new ZipArchive;
        if ($archive->open($zip) !== true) {
            throw new RuntimeException("Could not open dump zip: {$zip}");
        }

        // The content tables live in create_tables_content.sql inside the archive.
        $name = collect(range(0, $archive->numFiles - 1))
            ->map(fn (int $i) => $archive->getNameIndex($i))
            ->first(fn (string $n) => str_ends_with($n, 'create_tables_content.sql'));

        if ($name === null) {
            throw new RuntimeException('create_tables_content.sql not found in the dump.');
        }

        $archive->extractTo($work, $name);
        $archive->close();

        return $work.'/'.$name;
    }

    private function download(string $to): string
    {
        $url = config('eqrouter.dump_url');
        $this->info("Downloading PEQ dump from {$url} ...");
        Http::timeout(300)->sink($to)->throw()->get($url);
        $this->line('  '.number_format(filesize($to) / 1048576, 1).' MB');

        return $to;
    }
}
