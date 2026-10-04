<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if ($app['config']->get('database.default') !== 'pgsql' || ! str_ends_with($app['config']->get('database.connections.pgsql.database'), '_test')) {
            throw new \RuntimeException('Tests require a separate PostgreSQL database with a name ending in _test.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }
}
