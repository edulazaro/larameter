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

        $this->publishes([
            __DIR__ . '/../database/migrations/create_larameter_tables.php.stub' => database_path(
                'migrations/' . date('Y_m_d_His') . '_create_larameter_tables.php',
            ),
            __DIR__ . '/../database/migrations/add_credit_expiry_to_larameter_deposits.php.stub' => database_path(
                'migrations/' . date('Y_m_d_His', time() + 1) . '_add_credit_expiry_to_larameter_deposits.php',
            ),
        ], 'larameter-migrations');
    }
}
