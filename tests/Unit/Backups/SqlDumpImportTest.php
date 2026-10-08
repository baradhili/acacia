<?php

namespace Tests\Unit\Backups;

use App\Services\Backups\SqlDumpImport;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The statement importer shared by the restore tester and the live
 * restorer. The lock-skipping rule is pinned here because it is
 * load-bearing twice over: sqlite has no LOCK TABLES syntax (a raw
 * mysqldump import would die on it), and MariaDB reports a missing
 * db-level LOCK TABLES privilege as 1044 "access denied to
 * database" — the exact failure the MySQL scratch restore hit.
 */
class SqlDumpImportTest extends TestCase
{
    public function test_mysqldump_lock_pairs_are_skipped_and_the_rest_imports(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        SqlDumpImport::import($pdo, implode("\n", [
            'LOCK TABLES `clients` WRITE;',
            'CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT);',
            'INSERT INTO clients (id, name) VALUES (1, \'acacia\');',
            'UNLOCK TABLES;',
        ]));

        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        $this->assertSame('acacia', $pdo->query('SELECT name FROM clients')->fetchColumn());
    }

    public function test_multi_line_statements_accumulate_until_the_closing_semicolon(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        SqlDumpImport::import($pdo, implode("\n", [
            'CREATE TABLE bills (',
            '    id INTEGER PRIMARY KEY,',
            '    amount INTEGER',
            ');',
            'INSERT INTO bills (id, amount) VALUES (1, 100);',
        ]));

        $this->assertSame(100, (int) $pdo->query('SELECT amount FROM bills')->fetchColumn());
    }
}
