<?php

namespace EduLazaro\Laracredits;

use EduLazaro\Laracredits\Contracts\ProvidesPlanLimits;
use EduLazaro\Laracredits\Plans\UnlimitedPlanLimits;
use Illuminate\Support\ServiceProvider;

class LaracreditsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/laracredits.php', 'laracredits');

        // Nothing bound means everything is unlimited. A package should not start
        // refusing work because you have not wired your pricing yet.
        $this->app->singleton(ProvidesPlanLimits::class, function ($app) {
            $class = $app['config']['laracredits.plan_limits'] ?? null;

            return $class ? $app->make($class) : new UnlimitedPlanLimits();
        });

        $this->app->singleton(CreditMeter::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/laracredits.php' => config_path('laracredits.php'),
        ], 'laracredits-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/create_laracredits_tables.php.stub' => database_path(
                'migrations/' . date('Y_m_d_His') . '_create_laracredits_tables.php',
            ),
        ], 'laracredits-migrations');
    }
}
