<?php

namespace App\Providers;

use App\Contracts\FeedbackGenerator;
use App\Services\Generation\AnthropicFeedbackGenerator;
use App\Services\Generation\FakeFeedbackGenerator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FeedbackGenerator::class, function () {
            return match (config('services.feedback_generator.driver')) {
                'anthropic' => new AnthropicFeedbackGenerator,
                default => new FakeFeedbackGenerator,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
