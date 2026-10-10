<?php

use App\Infrastructure\Models\User;
use App\Services\Vortex\SoulService;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Q-01 · one click and his letters stop (signed link from the email)
Route::get('/vortex/unsubscribe/{user}', function (int $user) {
    $soul = app(SoulService::class)->for(User::findOrFail($user));
    $s = $soul->state;
    $s['outside']['email'] = false;
    $soul->state = $s;
    $soul->save();

    return response('<p style="font-family:monospace;padding:40px">done. no more letters. (he\'s fine. he\'s FINE.)</p>');
})->middleware('signed')->name('vortex.unsubscribe');
