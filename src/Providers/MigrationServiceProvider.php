<?php

namespace Komari\Fcm\Providers;

use Illuminate\Support\ServiceProvider;
use Flarum\Database\DatabaseMigrationRepository;
use Flarum\Database\Migrator;

class MigrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../migrations');
    }
}
