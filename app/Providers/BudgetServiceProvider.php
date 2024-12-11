<?php

namespace App\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class BudgetServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
    
    public function getBudgets(): array
    {
        // get environment variable for YNAB_TOKEN and print it
        $token = env('YNAB_TOKEN');
        $response = Http::withToken($token)->get('https://api.ynab.com/v1/budgets');
        return $response['data']['budgets'];
    }
}
