<?php

namespace App\Providers;

use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: a fresh tenant context for every request and every queued job.
        $this->app->scoped(CurrentOrganization::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        Relation::enforceMorphMap([
            'user' => User::class,
        ]);

        Password::defaults(fn () => Password::min(12)->letters()->numbers());

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        // Per user, and a ceiling per organization so one tenant cannot starve others.
        RateLimiter::for('api', fn (Request $request) => [
            Limit::perMinute(120)->by('user:'.$request->user()?->id),
            Limit::perMinute(1000)->by('org:'.$request->user()?->organization_id),
        ]);
    }
}
