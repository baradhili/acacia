<?php

namespace Tests;

use IFRS\IFRSServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Config;

abstract class TestCase extends BaseTestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Use App\Models\User which doesn't have IFRS prefix
        Config::set('ifrs.user_model', \App\Models\User::class);
    }

    /**
     * Creates the application.
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function getPackageProviders($app)
    {
        return [
            IFRSServiceProvider::class,
        ];
    }
}
