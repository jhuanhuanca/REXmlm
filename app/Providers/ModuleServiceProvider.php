<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $modulesPath = app_path('Modules');

        if (! File::isDirectory($modulesPath)) {
            return;
        }

        foreach (File::directories($modulesPath) as $module) {
            $routesFile = $module.DIRECTORY_SEPARATOR.'routes.php';

            if (File::exists($routesFile)) {
                Route::middleware('api')
                    ->prefix('api/v1')
                    ->group($routesFile);
            }

            $viewsPath = $module.DIRECTORY_SEPARATOR.'Views';

            if (File::isDirectory($viewsPath)) {
                $this->loadViewsFrom($viewsPath, basename($module));
            }

            $migrationsPath = $module.DIRECTORY_SEPARATOR.'Database'.DIRECTORY_SEPARATOR.'Migrations';

            if (File::isDirectory($migrationsPath)) {
                $this->loadMigrationsFrom($migrationsPath);
            }
        }
    }
}
