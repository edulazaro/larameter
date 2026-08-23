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
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/larameter.php', 'larameter');

        // SCOPED, not singleton. The meter memoises hasCreditsMemoized(), and a queue
        // worker keeps singletons alive between jobs: an account that ran out would go on
        // spending for as long as that worker lived. Scoped bindings are flushed between
        // jobs and between Octane requests, which is exactly the lifetime the memo is
        // meant to have.
        $this->app->scoped(UsageTracker::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MakeMeterCommand::class]);
        }

        // Every row moves the balance, whoever wrote it. Keeping this out of UsageTracker
        // means a backfill or a console command cannot record consumption nobody is
        // charged for, nor hand out credits that never reach the balance.
        UsageRecord::observe(UsageRecordObserver::class);
        Deposit::observe(DepositObserver::class);

        $this->publishes([
            __DIR__ . '/../config/larameter.php' => config_path('larameter.php'),
        ], 'larameter-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/create_larameter_tables.php.stub' => database_path(
                'migrations/' . date('Y_m_d_His') . '_create_larameter_tables.php',
            ),
        ], 'larameter-migrations');
    }
}
