<?php

namespace EduLazaro\Larameter;

use EduLazaro\Larameter\Contracts\ProvidesPlanLimits;
use EduLazaro\Larameter\Plans\ConfigPlanLimits;
use Illuminate\Support\ServiceProvider;

class LarameterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/larameter.php', 'larameter');

        // Plans come from config unless you say otherwise. Nobody should have to
        // implement an interface to charge for creating a form.
        $this->app->singleton(ProvidesPlanLimits::class, function ($app) {
            $class = $app['config']['larameter.plan_limits'] ?? null;

            return $class ? $app->make($class) : new ConfigPlanLimits();
        });

        // SCOPED, not singleton. The meter memoises hasCreditsMemoized(), and a queue
        // worker keeps singletons alive between jobs: an account that ran out would go
        // on spending for as long as that worker lived. Scoped bindings are flushed
        // between jobs and between Octane requests, which is exactly the lifetime the
        // memo is meant to have.
        $this->app->scoped(CreditMeter::class);
    }

    public function boot(): void
    {
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
