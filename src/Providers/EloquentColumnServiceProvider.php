<?php

namespace EloquentColumn\Providers;

use Illuminate\Support\ServiceProvider;
use EloquentColumn\Console\Commands\MigrationAutoCreateCommand;

class EloquentColumnServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MigrationAutoCreateCommand::class,
            ]);
        }
    }
}
