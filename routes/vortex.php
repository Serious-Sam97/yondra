<?php

use App\Http\Controllers\Vortex\AiSettingsController;
use App\Http\Controllers\Vortex\DatabaseController;
use App\Http\Controllers\Vortex\EntityController;
use App\Http\Controllers\Vortex\OverviewController;
use App\Http\Controllers\Vortex\SystemController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Vortex — private admin API
|--------------------------------------------------------------------------
|
| Mounted under /api/vortex behind auth:sanctum + vortex.admin
| (see routes/api.php). Consumed only by the Vortex dashboard app.
|
*/

Route::get('/me', function (Request $request) {
    return $request->user();
});

Route::get('/overview', [OverviewController::class, 'index']);
Route::get('/overview/timeseries', [OverviewController::class, 'timeseries']);

Route::get('/entities', [EntityController::class, 'index']);
Route::get('/entities/{entity}', [EntityController::class, 'list']);
Route::get('/entities/{entity}/{id}', [EntityController::class, 'show']);
Route::put('/entities/{entity}/{id}', [EntityController::class, 'update']);
Route::delete('/entities/{entity}/{id}', [EntityController::class, 'destroy']);

Route::get('/db/tables', [DatabaseController::class, 'tables']);
Route::get('/db/tables/{table}/rows', [DatabaseController::class, 'rows']);
Route::post('/db/query', [DatabaseController::class, 'query']);

Route::get('/system', [SystemController::class, 'index']);
Route::get('/system/queue', [SystemController::class, 'queue']);
Route::get('/system/failed-jobs', [SystemController::class, 'failedJobs']);
Route::post('/system/failed-jobs/{uuid}/retry', [SystemController::class, 'retryFailedJob']);
Route::delete('/system/failed-jobs/{uuid}', [SystemController::class, 'forgetFailedJob']);
Route::delete('/system/failed-jobs', [SystemController::class, 'flushFailedJobs']);
Route::post('/system/cache/clear', [SystemController::class, 'clearCache']);
Route::get('/system/logs/files', [SystemController::class, 'logFiles']);
Route::get('/system/logs', [SystemController::class, 'logs']);
Route::get('/system/storage', [SystemController::class, 'storage']);

Route::get('/ai-settings', [AiSettingsController::class, 'index']);
Route::put('/ai-settings', [AiSettingsController::class, 'update']);
Route::post('/ai-settings/health/{driver}', [AiSettingsController::class, 'health']);
