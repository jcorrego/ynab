<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Providers\BudgetServiceProvider;

class YNAB extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ynab:budgets';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Displays a list of budgets from YNAB';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $app = app();
        $budgetService = new BudgetServiceProvider($app);
        $budgets = $budgetService->getBudgets();

        if (count($budgets) === 0) {
            $this->error('No budgets found');
            return;
        }

        $this->info(count($budgets) . ' budgets found:');

        foreach ($budgets as $budget) {
            $this->info($budget['id'] . " " . $budget['name']);
        }
    }
}
