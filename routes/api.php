<?php

use App\Http\Controllers\AiAssistController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BoardActivityController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\BoardMessageController;
use App\Http\Controllers\BoardShareController;
use App\Http\Controllers\CardChecklistController;
use App\Http\Controllers\CardCommentController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\CardDocumentController;
use App\Http\Controllers\CardImageController;
use App\Http\Controllers\CardLinkController;
use App\Http\Controllers\CardTemplateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GifController;
use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\ImageUploadController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\PlanningController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\QaController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\SprintController;
use App\Http\Controllers\StepController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TestPlanController;
use App\Http\Controllers\WhatsappAutomationController;
use App\Http\Controllers\WhatsappController;
use App\Http\Controllers\WhatsappWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::post('/broadcasting/auth', function (Request $request) {
    return Broadcast::auth($request);
})->middleware('auth:sanctum');

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth');

// Inbound GitHub webhooks — public, authenticated per-board via HMAC signature.
Route::post('/webhooks/github/{boardId}', [GitHubWebhookController::class, 'handle']);

// Inbound WhatsApp Cloud API webhooks — public: GET verify handshake, POST HMAC-signed.
Route::get('/webhooks/whatsapp/{boardId}', [WhatsappWebhookController::class, 'verify']);
Route::post('/webhooks/whatsapp/{boardId}', [WhatsappWebhookController::class, 'handle']);

// Inbound Sentinel CI results — public, authenticated by the case's unguessable ci_token.
Route::post('/webhooks/qa-ci/{token}', [QaController::class, 'ciHook']);

// Private image streaming — signature-gated instead of Sanctum because the
// frontend consumes these as plain <img src> URLs (no Bearer header possible).
// `signed:relative` validates the path+query signature host-independently.
Route::get('/card-images/{imageId}', [CardImageController::class, 'show'])
    ->name('card-images.show')
    ->middleware('signed:relative');
Route::get('/inline-images', [ImageUploadController::class, 'show'])
    ->name('inline-images.show')
    ->middleware('signed:relative');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::put('/user', [AuthController::class, 'updateProfile']);
    Route::put('/user/password', [AuthController::class, 'updatePassword']);

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/search', [SearchController::class, 'index']);

    Route::get('/boards', [BoardController::class, 'index']);
    Route::post('/boards', [BoardController::class, 'store']);
    Route::get('/boards/{boardId}', [BoardController::class, 'show']);
    Route::put('/boards/{boardId}', [BoardController::class, 'update']);
    Route::delete('/boards/{boardId}', [BoardController::class, 'destroy']);
    Route::post('/boards/{boardId}/archive', [BoardController::class, 'archive']);
    Route::post('/boards/{boardId}/unarchive', [BoardController::class, 'unarchive']);
    Route::post('/boards/{boardId}/copy', [BoardController::class, 'copy']);
    Route::post('/boards/{boardId}/sections', [SectionController::class, 'store']);
    Route::post('/boards/{boardId}/sections/reorder', [SectionController::class, 'reorder']);
    Route::put('/boards/{boardId}/sections/{sectionId}', [SectionController::class, 'update']);
    Route::delete('/boards/{boardId}/sections/{sectionId}', [SectionController::class, 'destroy']);
    Route::get('/boards/{boardId}/sprints', [SprintController::class, 'index']);
    Route::post('/boards/{boardId}/sprints', [SprintController::class, 'store']);
    Route::put('/boards/{boardId}/sprints/{sprintId}', [SprintController::class, 'update']);
    Route::post('/boards/{boardId}/sprints/{sprintId}/start', [SprintController::class, 'start']);
    Route::post('/boards/{boardId}/sprints/{sprintId}/complete', [SprintController::class, 'complete']);
    Route::get('/boards/{boardId}/sprints/{sprintId}/report', [SprintController::class, 'report']);
    Route::delete('/boards/{boardId}/sprints/{sprintId}', [SprintController::class, 'destroy']);
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
    Route::put('/boards/{boardId}/cards/{cardId}/comments/{commentId}', [CardCommentController::class, 'update']);
    Route::delete('/boards/{boardId}/cards/{cardId}/comments/{commentId}', [CardCommentController::class, 'destroy']);
    Route::get('/boards/{boardId}/cards/{cardId}/comments/{commentId}/replies', [CardCommentController::class, 'replies']);
    Route::post('/boards/{boardId}/cards/{cardId}/comments/{commentId}/reactions', [CardCommentController::class, 'react']);

    // GIF picker (Tenor proxy — key stays server-side; hidden in the UI when unset).
    Route::get('/gifs/availability', [GifController::class, 'availability']);
    Route::get('/gifs/search', [GifController::class, 'search']);

    // WhatsApp thread on a card: read the conversation, reply to the customer.
    Route::get('/boards/{boardId}/cards/{cardId}/whatsapp', [WhatsappController::class, 'show']);
    Route::post('/boards/{boardId}/cards/{cardId}/whatsapp', [WhatsappController::class, 'store']);

    // WhatsApp stage automations (owner-level board config).
    Route::get('/boards/{boardId}/whatsapp/automations', [WhatsappAutomationController::class, 'index']);
    Route::put('/boards/{boardId}/whatsapp/automations/{sectionId}', [WhatsappAutomationController::class, 'upsert']);
    Route::delete('/boards/{boardId}/whatsapp/automations/{sectionId}', [WhatsappAutomationController::class, 'destroy']);

    Route::post('/boards/{boardId}/cards/{cardId}/attachments', [CardImageController::class, 'store']);
    Route::delete('/boards/{boardId}/cards/{cardId}/attachments/{imageId}', [CardImageController::class, 'destroy']);

    Route::post('/boards/{boardId}/cards/{cardId}/documents', [CardDocumentController::class, 'store']);
    Route::get('/boards/{boardId}/cards/{cardId}/documents/{documentId}/download', [CardDocumentController::class, 'download']);
    Route::delete('/boards/{boardId}/cards/{cardId}/documents/{documentId}', [CardDocumentController::class, 'destroy']);

    Route::post('/boards/{boardId}/cards/{cardId}/links', [CardLinkController::class, 'store']);
    Route::post('/boards/{boardId}/cards/{cardId}/links/{linkId}/refresh', [CardLinkController::class, 'refresh']);
    Route::delete('/boards/{boardId}/cards/{cardId}/links/{linkId}', [CardLinkController::class, 'destroy']);

    // Planning Poker — collaborative Scrum estimation on a card.
    Route::get('/boards/{boardId}/cards/{cardId}/planning', [PlanningController::class, 'show']);
    Route::post('/boards/{boardId}/cards/{cardId}/planning/join', [PlanningController::class, 'join']);
    Route::post('/boards/{boardId}/cards/{cardId}/planning/leave', [PlanningController::class, 'leave']);
    Route::post('/boards/{boardId}/cards/{cardId}/planning/vote', [PlanningController::class, 'vote']);
    Route::post('/boards/{boardId}/cards/{cardId}/planning/reveal', [PlanningController::class, 'reveal']);
    Route::post('/boards/{boardId}/cards/{cardId}/planning/reset', [PlanningController::class, 'reset']);
    Route::post('/boards/{boardId}/cards/{cardId}/planning/apply', [PlanningController::class, 'apply']);
    Route::post('/boards/{boardId}/cards/{cardId}/planning/ping', [PlanningController::class, 'ping']);
    Route::post('/boards/{boardId}/cards/{cardId}/planning/timer', [PlanningController::class, 'timer']);

    // AI endpoints — each costs an outbound LLM call, so they carry a tighter per-user
    // rate limit (throttle:ai) on top of the global api limiter.
    Route::middleware('throttle:ai')->group(function () {
        // Streamed card actions (summary, description, checklist, tests, WhatsApp reply,
        // rewrite). Results arrive over the board channel as ai.token/ai.done frames.
        Route::post('/boards/{boardId}/cards/{cardId}/ai/{action}', [AiAssistController::class, 'run'])
            ->whereIn('action', AiAssistController::ACTIONS);

        // Synchronous structured suggestions (JSON). 'points'/'triage' are not in ACTIONS,
        // so they never match the streaming route above.
        Route::post('/boards/{boardId}/cards/{cardId}/ai/points', [AiAssistController::class, 'suggestPoints']);
        Route::post('/boards/{boardId}/cards/{cardId}/ai/triage', [AiAssistController::class, 'suggestTriage']);
        Route::post('/boards/{boardId}/cards/{cardId}/ai/subtasks', [AiAssistController::class, 'suggestSubtasks']);

        // Board-level standup / sprint summary (streamed over the board channel).
        Route::post('/boards/{boardId}/ai/standup', [AiAssistController::class, 'standup']);
    });

    // Sentinel (QA) — N test cases per card, each with N runs (reports).
    Route::get('/boards/{boardId}/cards/{cardId}/qa', [QaController::class, 'index']);
    Route::post('/boards/{boardId}/cards/{cardId}/qa/cases', [QaController::class, 'storeCase']);
    Route::put('/boards/{boardId}/cards/{cardId}/qa/cases/{caseId}', [QaController::class, 'updateCase']);
    Route::delete('/boards/{boardId}/cards/{cardId}/qa/cases/{caseId}', [QaController::class, 'destroyCase']);
    Route::post('/boards/{boardId}/cards/{cardId}/qa/cases/{caseId}/runs', [QaController::class, 'storeRun']);
    Route::post('/boards/{boardId}/cards/{cardId}/qa/cases/{caseId}/bug', [QaController::class, 'linkBug']);
    Route::post('/boards/{boardId}/cards/{cardId}/qa/cases/{caseId}/verdict', [QaController::class, 'setVerdict']);
    Route::post('/boards/{boardId}/cards/{cardId}/qa/cases/{caseId}/ci-token', [QaController::class, 'ciToken']);

    // Sentinel (QA) — global reusable-step library (per board). Editing propagates.
    Route::get('/boards/{boardId}/qa/steps', [StepController::class, 'index']);
    Route::post('/boards/{boardId}/qa/steps', [StepController::class, 'store']);
    Route::put('/boards/{boardId}/qa/steps/{stepId}', [StepController::class, 'update']);
    Route::delete('/boards/{boardId}/qa/steps/{stepId}', [StepController::class, 'destroy']);

    // Sentinel (QA) — test plans / suites (per board, cross-card).
    Route::get('/boards/{boardId}/qa/overview', [TestPlanController::class, 'overview']);
    Route::get('/boards/{boardId}/qa/plans', [TestPlanController::class, 'index']);
    Route::post('/boards/{boardId}/qa/plans', [TestPlanController::class, 'store']);
    Route::put('/boards/{boardId}/qa/plans/{planId}', [TestPlanController::class, 'update']);
    Route::delete('/boards/{boardId}/qa/plans/{planId}', [TestPlanController::class, 'destroy']);

    // Board-scoped inline-image upload for rich-text (works before a card exists).
    Route::post('/boards/{boardId}/uploads', [ImageUploadController::class, 'store']);

    Route::get('/boards/{boardId}/activity', [BoardActivityController::class, 'index']);

    Route::get('/boards/{boardId}/messages', [BoardMessageController::class, 'index']);
    Route::post('/boards/{boardId}/messages', [BoardMessageController::class, 'store']);
    Route::delete('/boards/{boardId}/messages/{messageId}', [BoardMessageController::class, 'destroy']);

    Route::post('/boards/{boardId}/tags', [TagController::class, 'store']);
    Route::put('/boards/{boardId}/tags/{tagId}', [TagController::class, 'update']);
    Route::delete('/boards/{boardId}/tags/{tagId}', [TagController::class, 'destroy']);

    Route::get('/boards/{boardId}/share/candidates', [BoardShareController::class, 'candidates']);
    Route::post('/boards/{boardId}/share', [BoardShareController::class, 'store']);
    Route::put('/boards/{boardId}/share/{userId}', [BoardShareController::class, 'update']);
    Route::delete('/boards/{boardId}/share/{userId}', [BoardShareController::class, 'destroy']);

    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{projectId}', [ProjectController::class, 'show']);
    Route::put('/projects/{projectId}', [ProjectController::class, 'update']);
    Route::delete('/projects/{projectId}', [ProjectController::class, 'destroy']);
    Route::post('/projects/{projectId}/archive', [ProjectController::class, 'archive']);
    Route::post('/projects/{projectId}/unarchive', [ProjectController::class, 'unarchive']);
    Route::post('/projects/{projectId}/copy', [ProjectController::class, 'copy']);

    Route::get('/projects/{projectId}/members/candidates', [ProjectMemberController::class, 'candidates']);
    Route::post('/projects/{projectId}/members', [ProjectMemberController::class, 'store']);
    Route::put('/projects/{projectId}/members/{userId}', [ProjectMemberController::class, 'update']);
    Route::delete('/projects/{projectId}/members/{userId}', [ProjectMemberController::class, 'destroy']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/preferences', [NotificationPreferenceController::class, 'show']);
    Route::put('/notifications/preferences', [NotificationPreferenceController::class, 'update']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllRead']);

    Route::get('/boards/{boardId}/templates', [CardTemplateController::class, 'index']);
    Route::post('/boards/{boardId}/templates', [CardTemplateController::class, 'store']);
    Route::delete('/boards/{boardId}/templates/{templateId}', [CardTemplateController::class, 'destroy']);

    Route::get('/boards/{boardId}/cards/{cardId}/subtasks', [CardController::class, 'subtasks']);
    Route::post('/boards/{boardId}/cards/{cardId}/subtasks', [CardController::class, 'storeSubtask']);
    Route::put('/boards/{boardId}/cards/{cardId}/subtasks/{subtaskId}', [CardController::class, 'updateSubtask']);
});

// Vortex — private admin dashboard API (see routes/vortex.php).
Route::prefix('vortex')
    ->middleware(['auth:sanctum', 'vortex.admin'])
    ->group(base_path('routes/vortex.php'));
