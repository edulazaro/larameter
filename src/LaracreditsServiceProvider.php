<?php

namespace EduLazaro\Laracredits;

use EduLazaro\Laracredits\Contracts\ProvidesPlanLimits;
use EduLazaro\Laracredits\Plans\ConfigPlanLimits;
use Illuminate\Support\ServiceProvider;

class LaracreditsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/laracredits.php', 'laracredits');

        // Plans come from config unless you say otherwise. Nobody should have to
        // implement an interface to charge for creating a form.
        $this->app->singleton(ProvidesPlanLimits::class, function ($app) {
            $class = $app['config']['laracredits.plan_limits'] ?? null;

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
            __DIR__ . '/../config/laracredits.php' => config_path('laracredits.php'),
        ], 'laracredits-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/create_laracredits_tables.php.stub' => database_path(
                'migrations/' . date('Y_m_d_His') . '_create_laracredits_tables.php',
            ),
        ], 'laracredits-migrations');
    }
}
