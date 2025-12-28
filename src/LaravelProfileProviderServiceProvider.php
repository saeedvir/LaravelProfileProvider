<?php

namespace Saeedvir\LaravelProfileProvider;

use Illuminate\Support\ServiceProvider;
use Saeedvir\LaravelProfileProvider\Console\Commands\ProfileProviders;

class LaravelProfileProviderServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ProfileProviders::class,
            ]);
        }

        $this->mergeConfigFrom(
            __DIR__ . '/../config/profile-provider.php',
            'profile-provider'
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/profile-provider.php' => config_path('profile-provider.php'),
            ], 'profile-provider-config');
        }
    }
}
