<?php

namespace EduLazaro\Larameter\Tests;

use EduLazaro\Larameter\LarameterServiceProvider;
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

        Schema::create('accounts', function ($table) {
            $table->id();
            $table->string('plan')->nullable();
            $table->timestamps();
        });
    }
}
