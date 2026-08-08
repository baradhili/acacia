<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RunAllTests extends Command
{
    protected $signature = 'test {--unit : Run only PHPUnit Unit tests}';
    protected $description = 'Run all tests (PHPUnit Unit tests and Behat feature tests)';

    public function handle(): int
    {
        $this->info('Running Laravel-ERP Test Suite');
        $this->info('================================');

        // Run PHPUnit Unit Tests
        $this->info("\n📦 Running PHPUnit Unit Tests...");

        $phpunit = new Process([PHP_BINARY, 'vendor/bin/phpunit']);
        $phpunit->setTimeout(300);
        $phpunit->run();

        if (!$phpunit->isSuccessful()) {
            $this->error('PHPUnit tests failed!');
            $this->line($phpunit->getOutput());
            return self::FAILURE;
        }

        $this->info('PHPUnit Tests passed ✓');
        $this->line($phpunit->getOutput());

        $this->info("\n================================");
        $this->info('✅ All tests passed!');

        return self::SUCCESS;
    }
}
