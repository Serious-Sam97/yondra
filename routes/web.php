<?php

use App\Http\Controllers\BoardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/api/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/api/boards', [BoardController::class, 'index']);
});