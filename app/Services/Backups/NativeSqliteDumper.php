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
 * sqlite3's .dump: PRAGMA + BEGIN, CREATE TABLE, INSERTs, COMMIT.
 * Registered over the 'sqlite' driver from AppServiceProvider::boot.
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
        $tables = $pdo->query(
            'SELECT name, sql FROM sqlite_master'
            ." WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL"
        )->fetchAll(\PDO::FETCH_OBJ);

        $dump = "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n";

        foreach ($tables as $table) {
            $dump .= $table->sql.";\n";

            $rows = $pdo->query('SELECT * FROM "'.$table->name.'"')->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $columns = array_keys($row);
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

        return $dump."COMMIT;\n";
    }

    /**
     * Binary-safe: anything that is not valid UTF-8 goes in as a hex
     * blob literal instead of a quoted string.
     */
    protected function quoteValue(\PDO $pdo, string $value): string
    {
        if (str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8')) {
            return "X'".bin2hex($value)."'";
        }

        return $pdo->quote($value);
    }
}
