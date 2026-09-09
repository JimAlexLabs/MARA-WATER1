<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// This backend is API-only and has no login page. Laravel's auth middleware
// resolves route('login') when an unauthenticated request doesn't ask for JSON;
// define it so that path returns a clean 401 instead of a 500.
Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');
