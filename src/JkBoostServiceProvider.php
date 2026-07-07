<?php

declare(strict_types=1);

namespace JkBoost;

use Illuminate\Support\ServiceProvider;
use JkBoost\Console\InstallCommand;

final class JkBoostServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }
    }
}
