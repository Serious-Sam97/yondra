<?php

use App\Http\Controllers\VortexOSController;
use App\Http\Controllers\YutopiaController;
use App\Http\Controllers\YutopiaGamesController;
use App\Http\Controllers\YutopiaInternalController;
use App\Http\Controllers\YutopiaPhoneController;
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

// VortexOS on Yutopia's computers: sign in with Yondra (a one-time code), redeemed by VortexOS's backend (signed)
Route::post('/vortexos/handoff', [VortexOSController::class, 'handoff'])->middleware(['auth:sanctum', 'throttle:30,1']);
Route::post('/internal/vortexos/handoff', [VortexOSController::class, 'redeem'])->middleware('throttle:60,1');

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

// the phone (yutopia/docs/plan/21-cellphone.md): settings, contacts, threads,
// texts, photos, Vortex; photo files are signed URLs (an <img src> has no token)
Route::middleware('auth:sanctum')->prefix('yutopia/phone')->group(function () {
    Route::get('/', [YutopiaPhoneController::class, 'show']);
    Route::put('/', [YutopiaPhoneController::class, 'update'])->middleware('throttle:60,1');
    Route::get('/contacts', [YutopiaPhoneController::class, 'contacts']);
    Route::get('/threads', [YutopiaPhoneController::class, 'threads']);
    Route::post('/threads', [YutopiaPhoneController::class, 'open'])->middleware('throttle:60,1');
    Route::patch('/threads/{threadId}', [YutopiaPhoneController::class, 'editThread'])->whereNumber('threadId');
    Route::delete('/threads/{threadId}/members/me', [YutopiaPhoneController::class, 'leave'])->whereNumber('threadId');
    Route::get('/threads/{threadId}/messages', [YutopiaPhoneController::class, 'messages'])->whereNumber('threadId');
    Route::post('/threads/{threadId}/messages', [YutopiaPhoneController::class, 'send'])->whereNumber('threadId')->middleware('throttle:40,1');
    Route::post('/threads/{threadId}/read', [YutopiaPhoneController::class, 'read'])->whereNumber('threadId');
    Route::post('/threads/{threadId}/typing', [YutopiaPhoneController::class, 'typing'])->whereNumber('threadId')->middleware('throttle:40,1');
    Route::post('/messages/{messageId}/react', [YutopiaPhoneController::class, 'react'])->whereNumber('messageId')->middleware('throttle:60,1');
    Route::get('/calls', [YutopiaPhoneController::class, 'calls']);
    Route::get('/photos', [YutopiaPhoneController::class, 'photos']);
    Route::post('/photos', [YutopiaPhoneController::class, 'upload'])->middleware('throttle:30,1');
    Route::patch('/photos/{photoId}', [YutopiaPhoneController::class, 'editPhoto'])->whereNumber('photoId');
    Route::delete('/photos/{photoId}', [YutopiaPhoneController::class, 'deletePhoto'])->whereNumber('photoId');
    Route::post('/vortex', [YutopiaPhoneController::class, 'vortexText'])->middleware('throttle:20,1');
    Route::post('/vortex/lines', [YutopiaPhoneController::class, 'vortexLine'])->middleware('throttle:30,1');
    Route::post('/vortex/call', [YutopiaPhoneController::class, 'vortexCall'])->middleware('throttle:30,1');
});
Route::get('/yutopia/photos/{photo}', [YutopiaPhoneController::class, 'photoFile'])
    ->whereNumber('photo')->name('yutopia.photo')->middleware('signed:relative');
Route::post('/internal/yutopia/phone/calls', [YutopiaPhoneController::class, 'callLog'])->middleware('throttle:600,1');

// pets belong to a person, not a space (world-server only, signed)
Route::prefix('internal/yutopia/pets/{userId}')->whereNumber('userId')->group(function () {
    Route::get('/', [YutopiaInternalController::class, 'pets']);
    Route::post('/', [YutopiaInternalController::class, 'createPet']);
    Route::put('/{petId}', [YutopiaInternalController::class, 'savePet'])->whereNumber('petId');
    Route::delete('/{petId}', [YutopiaInternalController::class, 'deletePet'])->whereNumber('petId');
});

Route::prefix('internal/yutopia/spaces/{spaceId}')->group(function () {
    Route::get('/', [YutopiaInternalController::class, 'space']);
    Route::post('/seed', [YutopiaInternalController::class, 'seed']);
    Route::put('/objects', [YutopiaInternalController::class, 'upsertObject']);
    Route::delete('/objects/{uid}', [YutopiaInternalController::class, 'deleteObject']);
    Route::post('/desk', [YutopiaInternalController::class, 'desk']);
    Route::get('/spawn/{userId}', [YutopiaInternalController::class, 'spawn'])->whereNumber('userId');
    Route::put('/spawn/{userId}', [YutopiaInternalController::class, 'putSpawn'])->whereNumber('userId');
    Route::post('/presence', [YutopiaInternalController::class, 'presence']);
    Route::post('/vortex', [YutopiaInternalController::class, 'vortex']);
    Route::put('/layout', [YutopiaInternalController::class, 'layout']);
    Route::put('/playlists', [YutopiaInternalController::class, 'playlists']);
    Route::post('/fragment', [YutopiaInternalController::class, 'fragment']);
    Route::get('/games/{gameId}', [YutopiaInternalController::class, 'game'])->whereNumber('gameId');
});
