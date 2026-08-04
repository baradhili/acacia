<?php

namespace Tests;

use IFRS\IFRSServiceProvider;
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

    protected function getPackageProviders($app)
    {
        return [
            IFRSServiceProvider::class,
        ];
    }
}
