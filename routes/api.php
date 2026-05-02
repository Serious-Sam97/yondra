<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BoardActivityController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\BoardMessageController;
use App\Http\Controllers\BoardShareController;
use App\Http\Controllers\CardChecklistController;
use App\Http\Controllers\CardCommentController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\CardTemplateController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\TagController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::post('/broadcasting/auth', function (Request $request) {
    return Broadcast::auth($request);
})->middleware('auth:sanctum');

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::put('/user', [AuthController::class, 'updateProfile']);
    Route::put('/user/password', [AuthController::class, 'updatePassword']);

    Route::get('/boards', [BoardController::class, 'index']);
    Route::post('/boards', [BoardController::class, 'store']);
    Route::get('/boards/{boardId}', [BoardController::class, 'show']);
    Route::delete('/boards/{boardId}', [BoardController::class, 'destroy']);
    Route::post('/boards/{boardId}/sections', [SectionController::class, 'store']);
    Route::put('/boards/{boardId}/sections/{sectionId}', [SectionController::class, 'update']);
    Route::delete('/boards/{boardId}/sections/{sectionId}', [SectionController::class, 'destroy']);
    Route::post('/boards/{boardId}/cards', [CardController::class, 'store']);
    Route::get('/boards/{boardId}/cards/archived', [CardController::class, 'archived']);
    Route::put('/boards/{boardId}/cards/reorder', [CardController::class, 'reorder']);
    Route::put('/boards/{boardId}/cards/{cardId}', [CardController::class, 'update']);
    Route::put('/boards/{boardId}/cards/{cardId}/restore', [CardController::class, 'restore']);
    Route::delete('/boards/{boardId}/cards/{cardId}', [CardController::class, 'destroy']);

    Route::post('/boards/{boardId}/cards/{cardId}/checklist', [CardChecklistController::class, 'store']);
    Route::put('/boards/{boardId}/cards/{cardId}/checklist/{itemId}', [CardChecklistController::class, 'update']);
    Route::delete('/boards/{boardId}/cards/{cardId}/checklist/{itemId}', [CardChecklistController::class, 'destroy']);

    Route::get('/boards/{boardId}/cards/{cardId}/comments', [CardCommentController::class, 'index']);
    Route::post('/boards/{boardId}/cards/{cardId}/comments', [CardCommentController::class, 'store']);
    Route::delete('/boards/{boardId}/cards/{cardId}/comments/{commentId}', [CardCommentController::class, 'destroy']);

    Route::get('/boards/{boardId}/activity', [BoardActivityController::class, 'index']);

    Route::get('/boards/{boardId}/messages', [BoardMessageController::class, 'index']);
    Route::post('/boards/{boardId}/messages', [BoardMessageController::class, 'store']);
    Route::delete('/boards/{boardId}/messages/{messageId}', [BoardMessageController::class, 'destroy']);

    Route::post('/boards/{boardId}/tags', [TagController::class, 'store']);
    Route::delete('/boards/{boardId}/tags/{tagId}', [TagController::class, 'destroy']);

    Route::post('/boards/{boardId}/share', [BoardShareController::class, 'store']);
    Route::put('/boards/{boardId}/share/{userId}', [BoardShareController::class, 'update']);
    Route::delete('/boards/{boardId}/share/{userId}', [BoardShareController::class, 'destroy']);

    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{projectId}', [ProjectController::class, 'show']);
    Route::put('/projects/{projectId}', [ProjectController::class, 'update']);
    Route::delete('/projects/{projectId}', [ProjectController::class, 'destroy']);

    Route::post('/projects/{projectId}/members', [ProjectMemberController::class, 'store']);
    Route::put('/projects/{projectId}/members/{userId}', [ProjectMemberController::class, 'update']);
    Route::delete('/projects/{projectId}/members/{userId}', [ProjectMemberController::class, 'destroy']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllRead']);

    Route::get('/boards/{boardId}/templates', [CardTemplateController::class, 'index']);
    Route::post('/boards/{boardId}/templates', [CardTemplateController::class, 'store']);
    Route::delete('/boards/{boardId}/templates/{templateId}', [CardTemplateController::class, 'destroy']);

    Route::get('/boards/{boardId}/cards/{cardId}/subtasks', [CardController::class, 'subtasks']);
    Route::post('/boards/{boardId}/cards/{cardId}/subtasks', [CardController::class, 'storeSubtask']);
    Route::put('/boards/{boardId}/cards/{cardId}/subtasks/{subtaskId}', [CardController::class, 'updateSubtask']);
});
