<?php

namespace EduLazaro\Larameter;

use EduLazaro\Larameter\Console\Commands\MakeMeterCommand;
use EduLazaro\Larameter\Models\Deposit;
use EduLazaro\Larameter\Models\UsageRecord;
use EduLazaro\Larameter\Observers\DepositObserver;
use EduLazaro\Larameter\Observers\UsageRecordObserver;
use Illuminate\Support\ServiceProvider;

class LarameterServiceProvider extends ServiceProvider
{
    /**
     * Register the package's bindings.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/larameter.php', 'larameter');
        $this->app->scoped(UsageTracker::class);
    }

    /**
     * Bootstrap the package's observers, commands and publishable files.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MakeMeterCommand::class]);
        }
        UsageRecord::observe(UsageRecordObserver::class);
        Deposit::observe(DepositObserver::class);

        $this->publishes([
            __DIR__ . '/../config/larameter.php' => config_path('larameter.php'),
        ], 'larameter-config');

        // The tables are yours: published once, and from then on in your repository where
        // you can read them, edit them and see them in a diff.
        $this->publishes([
            __DIR__ . '/../database/migrations/create_larameter_tables.php.stub' => database_path(
                'migrations/' . date('Y_m_d_His') . '_create_larameter_tables.php',
            ),
        ], 'larameter-migrations');

        // Changes to those tables are NOT, and run themselves on the next `migrate`.
        //
        // The split is deliberate. Publishing a schema change means an upgrade has a step
        // somebody has to remember, and somebody will skip it: composer brings code that
        // reads columns their database does not have yet, and the application goes down on
        // the first request that reads a balance. There is no version of that failure that
        // is the application author's fault.
        //
        // Each one guards on what it is about to change, so running against a database
        // that already has it is a no-op rather than an error. That is what lets a fresh
        // install take the columns from create_larameter_tables and still pass through
        // here without complaining.
        $this->loadMigrationsFrom(__DIR__ . '/../database/auto');
    }
}
