<?php

namespace App\Services\Backups;

/**
 * Executes a textual sqlite dump into a PDO connection — the one
 * importer shared by the restore tester and the live restorer.
 *
 * Statements are line-oriented as both our dumper and sqlite3's
 * .dump emit them, with one exception the parser must honour:
 * CREATE TRIGGER bodies span lines and contain their own
 * semicolons, so once a trigger starts the accumulator only
 * completes on a line that is exactly its closing END;.
 */
class SqlDumpImport
{
    public static function import(\PDO $pdo, string $sql): void
    {
        $buffer = '';
        $inTrigger = false;

        foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
            $buffer .= $line."\n";
            $trimmed = trim($line);

            if ($buffer !== '' && preg_match('/^\s*CREATE\s+TRIGGER/i', $buffer)) {
                $inTrigger = true;
            }

            $complete = $inTrigger
                ? (strtoupper(rtrim($trimmed)) === 'END;')
                : str_ends_with($trimmed, ';');

            if ($complete) {
                $statement = rtrim($buffer);
                $buffer = '';
                $inTrigger = false;

                if ($statement !== '') {
                    $pdo->exec($statement);
                }
            }
        }

        if (trim($buffer) !== '') {
            $pdo->exec(rtrim($buffer));
        }
    }
}
