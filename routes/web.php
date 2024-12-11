<?php

use App\Http\Controllers\IncomingMail;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/incoming_mail', IncomingMail::class);