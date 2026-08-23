<?php

namespace EduLazaro\Larameter\Console\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

/**
 *     php artisan make:meter MemberMeter
 *     php artisan make:meter MemberMeter Organization
 *
 * The key and the counted relation are both guessed from the class name, because they
 * are the same word nine times out of ten: MemberMeter counts `members`. Wrong guess,
 * two edits.
 */
class MakeMeterCommand extends GeneratorCommand
{
    protected $signature = 'make:meter {name} {model?}';

    protected $description = 'Create a new Meter';

    protected $type = 'Meter';

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub(): string
    {
        return __DIR__ . '/stubs/meter.stub';
    }

    /**
     * App\Meters\Organization\MemberMeter, grouped by what it caps.
     *
     * A meter only means anything next to the model it counts on, and an app of any size
     * ends up with several models that have caps. Flat, App\Meters would mix the seats of
     * an organisation with the members of a case and you would be reading class names to
     * tell them apart.     *
     * @param string $rootNamespace
     * @return string

     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        $model = $this->argument('model');

        if (! $model) {
            return $rootNamespace . '\Meters';
        }

        $segments = explode('\\', trim($model, '\\'));

        return $rootNamespace . '\Meters\\' . end($segments);
    }

    /**
     * Fill the stub in from the class name and the optional model.
     *
     * @param string $stub
     * @param string $name
     * @return string
     */
    protected function replaceClass($stub, $name): string
    {
        $segments = explode('\\', $name);
        $meterClass = end($segments);
        $subject = preg_replace('/Meter$/', '', $meterClass);

        if ($this->argument('model')) {
            $modelClass = '\\' . trim($this->argument('model'), '\\');

            if (! str_contains(trim($this->argument('model'), '\\'), '\\')) {
                $modelClass = '\\App\\Models\\' . trim($this->argument('model'), '\\');
            }
        } else {
            $modelClass = '\\Illuminate\\Database\\Eloquent\\Model';
        }

        $modelSegments = explode('\\', $modelClass);
        $usedModelClass = end($modelSegments);
        $key = Str::snake(Str::pluralStudly($subject));

        return str_replace(
            ['{{model_class}}', '{{used_model_class}}', '{{model_class_variable_name}}',
             '{{meter_class}}', '{{meter_key}}', '{{meter_label}}'],
            [ltrim($modelClass, '\\'), $usedModelClass, Str::camel($usedModelClass),
             $meterClass, $key, Str::headline($key)],
            $stub,
        );
    }
}
