<?php

use App\Http\Controllers\YutopiaController;
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
    Route::get('/spaces/{spaceId}/boards', [YutopiaController::class, 'boards']);
    Route::get('/spaces/{spaceId}/status', [YutopiaController::class, 'status']);
    Route::get('/spaces/{spaceId}/members', [YutopiaController::class, 'members']);
    Route::get('/projects/{projectId}/presence', [YutopiaController::class, 'projectPresence']);
    Route::get('/boards/{boardId}/presence', [YutopiaController::class, 'boardPresence']);
    Route::post('/handoff', [YutopiaController::class, 'handoff'])->middleware('throttle:30,1');
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
    Route::post('/fragment', [YutopiaInternalController::class, 'fragment']);
});
