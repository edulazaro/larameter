<?php

namespace EduLazaro\Larameter\Tests;

use EduLazaro\Larameter\LarameterServiceProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LarameterServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {
        $migration = require __DIR__ . '/../database/migrations/create_larameter_tables.php.stub';
        $migration->up();

        // Whatever the host app bills. Note it carries NO plan column: the plan lives on
        // the larameter account, so installing the package does not mean a migration on
        // a table you already had.
        Schema::create('organizations', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        // What the meters count. Ordinary tables of the host app; the package only ever
        // asks a meter, and the meter knows how to count its own.
        foreach (['members', 'matters'] as $table) {
            Schema::create($table, function ($blueprint) {
                $blueprint->id();
                $blueprint->foreignId('organization_id');
                $blueprint->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
