<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get(
            "database.connections.{$connection}.database",
        );

        if (! str_ends_with($database, '_testing')) {
            throw new LogicException(
                "Tests refuse to use non-testing database [{$database}].",
            );
        }

        return $app;
    }
}
