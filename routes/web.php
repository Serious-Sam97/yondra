<?php

use App\Http\Controllers\BoardController;
use App\Http\Controllers\CardController;
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
    Route::get('/api/boards/{boardId}', [BoardController::class, 'show']);
    Route::post('/api/boards/{boardId}/cards', [CardController::class, 'store']);
    Route::put('/api/boards/{boardId}/cards/{cardId}', [CardController::class, 'update']);
});