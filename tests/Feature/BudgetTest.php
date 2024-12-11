<?php

test('get a list of budgets', function () {
  $this->artisan('ynab:budgets')
    ->expectsOutputToContain('budgets found')
    ->assertSuccessful();
});
