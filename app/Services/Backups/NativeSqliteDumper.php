<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\DbDumper\Databases\Sqlite;

/**
 * A sqlite dumper that stays inside PHP: spatie's own Sqlite dumper
 * pipes `.dump` through the sqlite3 CLI, which need not exist on the
 * host (it doesn't on the dev box). This one reads through the app's
 * live PDO connection — which also makes :memory: test databases
 * dumpable at all, and sees committed data still sitting in the WAL
 * that a raw file copy would miss. Produces the same textual SQL as
 * sqlite3's .dump: PRAGMA + BEGIN, CREATE TABLE, INSERTs, then the
 * indexes/triggers/views, then COMMIT. Registered over the 'sqlite'
 * driver from AppServiceProvider::boot.
 *
 * Two invariants the restore parsers depend on: one INSERT per line
 * (values containing newlines are emitted as quoted segments joined
 * with char(10), never raw line breaks), and rows streamed row by row
 * rather than accumulated in memory.
 */
class NativeSqliteDumper extends Sqlite
{
    public function dumpToFile(string $dumpFile): void
    {
        $sql = $this->dumpViaConnection($this->resolveConnection());

        if (@file_put_contents($dumpFile, $sql) === false) {
            throw new RuntimeException("Could not write the sqlite dump to {$dumpFile}.");
        }

        if (! str_contains($sql, 'CREATE TABLE')) {
            throw new RuntimeException('The sqlite dump contains no tables — refusing to ship an empty backup.');
        }
    }

    /**
     * The live default connection when it is the sqlite database we
     * were asked to dump (by path or :memory:); otherwise a fresh PDO
     * onto the named file.
     */
    protected function resolveConnection(): \PDO
    {
        $name = config('database.default');
        $config = config("database.connections.{$name}");

        if (($config['driver'] ?? null) === 'sqlite'
            && in_array($config['database'], [':memory:', $this->dbName], true)) {
            return DB::connection($name)->getPdo();
        }

        return new \PDO('sqlite:'.$this->dbName, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    protected function dumpViaConnection(\PDO $pdo): string
    {
        // Tables first with their data; indexes, triggers and views
        // come after the rows they belong to — the same order
        // sqlite3's .dump uses and restorers expect. Explicit indexes
        // carry business constraints (e.g. the backup-settings
        // singleton guard), so dropping them would restore a database
        // that no longer enforces them.
        $tables = $pdo->query(
            'SELECT name, sql FROM sqlite_master'
            ." WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL"
        )->fetchAll(\PDO::FETCH_OBJ);

        $laterObjects = $pdo->query(
            'SELECT sql FROM sqlite_master'
            ." WHERE type IN ('index', 'trigger', 'view') AND sql IS NOT NULL"
        )->fetchAll(\PDO::FETCH_COLUMN);

        $dump = "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n";

        foreach ($tables as $table) {
            $dump .= $table->sql.";\n";

            $rows = $pdo->query('SELECT * FROM "'.$table->name.'"');
            $columns = null;

            while ($row = $rows->fetch(\PDO::FETCH_ASSOC)) {
                // Column order comes from the row itself; it cannot be
                // assumed stable across ALTER TABLEs.
                if ($columns === null) {
                    $columns = array_keys($row);
                }

                $values = array_map(
                    fn ($value) => $value === null
                        ? 'NULL'
                        : $this->quoteValue($pdo, (string) $value),
                    array_values($row),
                );

                $dump .= 'INSERT INTO "'.$table->name.'" ("'
                    .implode('", "', $columns).'") VALUES ('.implode(', ', $values).");\n";
            }
        }

        foreach ($laterObjects as $ddl) {
            $dump .= $ddl.";\n";
        }

        return $dump."COMMIT;\n";
    }

    /**
     * Exact-value, single-line serialization. Binary or non-UTF-8
     * content goes in as a hex blob literal; text containing newlines
     * is emitted as quoted segments joined with char(10) so the
     * INSERT never spans lines (the restore parsers split on lines);
     * everything else is a plain quoted string.
     */
    protected function quoteValue(\PDO $pdo, string $value): string
    {
        if (str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8')) {
            return "X'".bin2hex($value)."'";
        }

        if (str_contains($value, "\n")) {
            return collect(explode("\n", $value))
                ->map(fn (string $segment) => $pdo->quote($segment))
                ->implode('||char(10)||');
        }

        return $pdo->quote($value);
    }
}
