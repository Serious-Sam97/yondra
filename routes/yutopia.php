<?php

use App\Http\Controllers\YutopiaController;
use App\Http\Controllers\YutopiaGamesController;
use App\Http\Controllers\YutopiaInternalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Yutopia — the isometric world synced with Yondra
|--------------------------------------------------------------------------
|
| Mounted under /api (see routes/api.php). User routes need a Sanctum token;
| /internal/yutopia/* is called only by the world-server and is HMAC-signed.
|
*/

Route::post('/yutopia/handoff/exchange', [YutopiaController::class, 'exchange'])->middleware('throttle:auth');

Route::middleware('auth:sanctum')->prefix('yutopia')->group(function () {
    Route::get('/spaces', [YutopiaController::class, 'spaces']);
    Route::post('/session', [YutopiaController::class, 'session'])->middleware('throttle:60,1');
    Route::get('/avatar', [YutopiaController::class, 'showAvatar']);
    Route::put('/avatar', [YutopiaController::class, 'updateAvatar']);
    Route::get('/setups', [YutopiaController::class, 'setups']);
    Route::post('/setups', [YutopiaController::class, 'saveSetup'])->middleware('throttle:30,1');
    Route::delete('/setups/{setupId}', [YutopiaController::class, 'deleteSetup']);
    Route::get('/spaces/{spaceId}/boards', [YutopiaController::class, 'boards']);
    Route::get('/spaces/{spaceId}/status', [YutopiaController::class, 'status']);
    Route::get('/spaces/{spaceId}/members', [YutopiaController::class, 'members']);
    Route::get('/projects/{projectId}/presence', [YutopiaController::class, 'projectPresence']);
    Route::get('/boards/{boardId}/presence', [YutopiaController::class, 'boardPresence']);
    Route::post('/handoff', [YutopiaController::class, 'handoff'])->middleware('throttle:30,1');
    // the game room: the team's cartridges and the saves of what's played
    Route::get('/spaces/{spaceId}/games', [YutopiaGamesController::class, 'index']);
    Route::post('/spaces/{spaceId}/games', [YutopiaGamesController::class, 'store'])->middleware('throttle:20,1');
    Route::delete('/spaces/{spaceId}/games/{gameId}', [YutopiaGamesController::class, 'destroy'])->whereNumber('gameId');
    Route::get('/spaces/{spaceId}/games/{gameId}/rom', [YutopiaGamesController::class, 'rom'])->whereNumber('gameId')->middleware('throttle:60,1');
    Route::get('/spaces/{spaceId}/saves/{game}', [YutopiaGamesController::class, 'saveInfo']);
    Route::get('/spaces/{spaceId}/saves/{game}/{kind}', [YutopiaGamesController::class, 'saveFile'])->whereIn('kind', ['state', 'sram']);
    Route::post('/spaces/{spaceId}/saves/{game}', [YutopiaGamesController::class, 'putSave'])->middleware('throttle:60,1');
});

Route::prefix('internal/yutopia/spaces/{spaceId}')->group(function () {
    Route::get('/', [YutopiaInternalController::class, 'space']);
    Route::post('/seed', [YutopiaInternalController::class, 'seed']);
    Route::put('/objects', [YutopiaInternalController::class, 'upsertObject']);
    Route::delete('/objects/{uid}', [YutopiaInternalController::class, 'deleteObject']);
    Route::post('/desk', [YutopiaInternalController::class, 'desk']);
    Route::post('/presence', [YutopiaInternalController::class, 'presence']);
    Route::post('/vortex', [YutopiaInternalController::class, 'vortex']);
    Route::put('/layout', [YutopiaInternalController::class, 'layout']);
    Route::put('/playlists', [YutopiaInternalController::class, 'playlists']);
    Route::post('/fragment', [YutopiaInternalController::class, 'fragment']);
    Route::get('/games/{gameId}', [YutopiaInternalController::class, 'game'])->whereNumber('gameId');
});
