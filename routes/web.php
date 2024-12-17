<?php

use App\Http\Controllers\IncomingMail;
use Illuminate\Support\Facades\Route;


Route::post('/', IncomingMail::class);
Route::post('/incoming_mail', IncomingMail::class);
Route::get('/incoming_mail', function() {
    return view('welcome');
});
Route::get('/', function () {
    return view('welcome');
});
