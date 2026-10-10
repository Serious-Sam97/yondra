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
use App\Http\Controllers\CardHistoryController;
use App\Http\Controllers\CardImageController;
use App\Http\Controllers\CardImportController;
use App\Http\Controllers\CardInvoiceController;
use App\Http\Controllers\CardLinkController;
use App\Http\Controllers\CardPaymentController;
use App\Http\Controllers\CardTemplateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailAutomationController;
use App\Http\Controllers\ErrorIngestController;
use App\Http\Controllers\GifController;
use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\ImageUploadController;
use App\Http\Controllers\ImportModelController;
use App\Http\Controllers\IntakeConfirmationController;
use App\Http\Controllers\IntakeWebhookController;
use App\Http\Controllers\MascotController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\PaymentMilestoneController;
use App\Http\Controllers\PlanningController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\QaController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\SprintController;
use App\Http\Controllers\StepController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TestPlanController;
use App\Http\Controllers\VortexController;
use App\Http\Controllers\WhatsappAutomationController;
use App\Http\Controllers\WhatsappController;
use App\Http\Controllers\WhatsappReengagementController;
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
Route::post('/webhooks/github/{boardId}', [GitHubWebhookController::class, 'handle'])->middleware('card.history:webhook,github');

// Inbound WhatsApp Cloud API webhooks — public: GET verify handshake, POST HMAC-signed.
Route::get('/webhooks/whatsapp/{boardId}', [WhatsappWebhookController::class, 'verify']);
Route::post('/webhooks/whatsapp/{boardId}', [WhatsappWebhookController::class, 'handle'])->middleware('card.history:webhook,whatsapp');

// Inbound Sentinel CI results — public, authenticated by the case's unguessable ci_token.
Route::post('/webhooks/qa-ci/{token}', [QaController::class, 'ciHook'])->middleware('card.history:ci');

// Inbound form intake (JotForm → auto-create card) — public, authenticated by the
// board's unguessable intake_token. Throttled: a form endpoint is a spam target.
Route::post('/webhooks/intake/{token}', [IntakeWebhookController::class, 'handle'])->middleware(['throttle:60,1', 'card.history:webhook,intake']);

// Public opt-in confirmation landing (YON-52) — a form submitter clicks this from
// the confirmation email; the unguessable contact token is the credential. GET so
// it opens in a browser; returns an HTML page, not JSON.
Route::get('/webhooks/intake/confirm/{token}', [IntakeConfirmationController::class, 'confirm'])
    ->name('intake.confirm')
    ->middleware('throttle:60,1');

// Inbound browser error reports (YON-74 Anomalies) — public, authenticated by the
// app-wide TELEMETRY_INGEST_TOKEN in the path. Throttled: an open endpoint is a
// spam target. Feeds the same Vortex error monitor as backend exceptions.
Route::post('/webhooks/errors/{token}', [ErrorIngestController::class, 'handle'])->middleware('throttle:120,1');

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
    // LGPD data-subject rights: export everything the account owns, or erase it.
    Route::get('/user/export', [AuthController::class, 'exportData']);
    Route::delete('/user', [AuthController::class, 'destroyAccount']);

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/reports/revenue', [ReportController::class, 'revenue']);
    Route::get('/reports/conversion', [ReportController::class, 'conversion']);
    Route::get('/reports/loss', [ReportController::class, 'loss']);
    Route::get('/reports/deals', [ReportController::class, 'deals']);
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
    // Bulk create cards from a custom JSON model (YON-121). Sits before the
    // {cardId} routes so "import" is never captured as a card id.
    Route::post('/boards/{boardId}/cards/import', [CardImportController::class, 'store'])->middleware('card.history:import');
    Route::get('/boards/{boardId}/cards/archived', [CardController::class, 'archived']);

    // Vortex mascot: notes for teammates + the comment "impression".
    Route::post('/boards/{boardId}/vortex-notes', [VortexController::class, 'storeNote'])->middleware('throttle:30,1');
    Route::get('/boards/{boardId}/vortex-notes', [VortexController::class, 'pendingNotes']);
    Route::get('/boards/{boardId}/vortex-impressions', [VortexController::class, 'impressions']);

    // Vortex MK-V (the mascot). /api/vortex is the admin error monitor — different thing.
    Route::post('/mascot/resolve', [MascotController::class, 'resolve'])->middleware('throttle:60,1,vx-resolve');
    Route::get('/mascot/soul', [MascotController::class, 'soul']);
    Route::post('/mascot/soul/events', [MascotController::class, 'events'])->middleware('throttle:60,1,vx-soul-events');
    Route::get('/mascot/diary', [MascotController::class, 'diary'])->middleware('throttle:20,1,vx-diary');
    Route::post('/mascot/dev/soul', [MascotController::class, 'devSoul']);
    Route::get('/mascot/memories', [MascotController::class, 'memories']);
    Route::get('/mascot/letters', [MascotController::class, 'letters']);
    Route::get('/mascot/fragments', [MascotController::class, 'fragments']);
    Route::post('/mascot/fragments/claim', [MascotController::class, 'claimFragment'])->middleware('throttle:30,1,vx-fragments-claim');
    Route::post('/mascot/fragments/guess', [MascotController::class, 'guess'])->middleware('throttle:30,1,vx-fragments-guess');
    Route::get('/mascot/below', [MascotController::class, 'belowWorld']);
    Route::post('/mascot/below/visit', [MascotController::class, 'belowVisit'])->middleware('throttle:60,1,vx-below-visit');
    Route::post('/mascot/below/act', [MascotController::class, 'belowAct'])->middleware('throttle:90,1,vx-below-act');
    Route::post('/mascot/below/talk', [MascotController::class, 'belowTalk'])->middleware('throttle:20,1,vx-below-talk');
    Route::get('/mascot/below/graveyard', [MascotController::class, 'belowGraveyard']);
    Route::get('/mascot/below/presence', [MascotController::class, 'belowPresence'])->middleware('throttle:20,1,vx-below-presence');
    Route::post('/mascot/below/wave', [MascotController::class, 'belowWave'])->middleware('throttle:10,1,vx-below-wave');
    Route::post('/mascot/below/purify', [MascotController::class, 'belowPurify'])->middleware('throttle:6,1,vx-below-purify');
    Route::get('/mascot/below/tower', [MascotController::class, 'belowTower']);
    Route::get('/mascot/side-c', [MascotController::class, 'sideC']);
    Route::match(['get', 'post'], '/mascot/outside', [MascotController::class, 'outside'])->middleware('throttle:20,1,vx-outside');
    Route::post('/mascot/forget', [MascotController::class, 'forgetEverything'])->middleware('throttle:5,1,vx-forget');
    Route::post('/mascot/telemetry', [MascotController::class, 'telemetry'])->middleware('throttle:5,1,vx-telemetry');
    Route::get('/mascot/dev/telemetry', [MascotController::class, 'devTelemetry'])->middleware('throttle:30,1,vx-devtel');
    Route::match(['get', 'post'], '/mascot/mk4', [MascotController::class, 'mk4'])->middleware('throttle:10,1,vx-mk4');
    Route::get('/mascot/push', [MascotController::class, 'push'])->middleware('throttle:30,1,vx-push');
    Route::post('/mascot/push/subscribe', [MascotController::class, 'pushSubscribe'])->middleware('throttle:10,1,vx-pushsub');
    Route::post('/mascot/push/unsubscribe', [MascotController::class, 'pushUnsubscribe'])->middleware('throttle:10,1,vx-pushsub');
    Route::get('/mascot/flags', [MascotController::class, 'flags'])->middleware('throttle:60,1,vx-flags');
    Route::get('/mascot/creator', [MascotController::class, 'creator'])->middleware('throttle:30,1,vx-creator');
    Route::post('/mascot/creator/tricks', [MascotController::class, 'creatorTrick'])->middleware('throttle:20,1,vx-trick');
    Route::delete('/mascot/creator/tricks/{id}', [MascotController::class, 'creatorTrick'])->middleware('throttle:20,1,vx-trick');
    Route::post('/mascot/creator/lines', [MascotController::class, 'creatorLine'])->middleware('throttle:20,1,vx-line');
    Route::delete('/mascot/creator/lines/{index}', [MascotController::class, 'creatorLine'])->whereNumber('index')->middleware('throttle:20,1,vx-line');
    Route::post('/mascot/creator/feedback', [MascotController::class, 'creatorFeedback'])->middleware('throttle:60,1,vx-feedback');
    Route::post('/mascot/creator/costume', [MascotController::class, 'creatorCostume'])->middleware('throttle:10,1,vx-costume');
    Route::get('/mascot/dev/content', [MascotController::class, 'devContent'])->middleware('throttle:30,1,vx-devcontent');
    Route::post('/mascot/dev/simulate', [MascotController::class, 'devSimulate'])->middleware('throttle:10,1,vx-sim');
    Route::get('/mascot/social', [MascotController::class, 'social'])->middleware('throttle:30,1,vx-social');
    Route::post('/mascot/social/settings', [MascotController::class, 'socialSettings'])->middleware('throttle:20,1,vx-social-settings');
    Route::post('/mascot/social/met', [MascotController::class, 'socialMet'])->middleware('throttle:30,1,vx-social-met');
    Route::post('/mascot/social/join', [MascotController::class, 'socialJoin'])->middleware('throttle:5,1,vx-social-join');
    Route::post('/mascot/social/prank', [MascotController::class, 'socialPrank'])->middleware('throttle:10,1,vx-social-prank');
    Route::post('/mascot/social/choir', [MascotController::class, 'socialChoir'])->middleware('throttle:60,1,vx-social-choir');
    Route::post('/mascot/social/plaque', [MascotController::class, 'socialPlaque'])->middleware('throttle:10,1,vx-social-plaque');
    Route::get('/mascot/social/spirit/{id}', [MascotController::class, 'socialSpirit'])->whereNumber('id');
    Route::post('/mascot/social/report', [MascotController::class, 'socialReport'])->middleware('throttle:10,1,vx-social-report');
    Route::match(['get', 'post'], '/mascot/admin/moderation', [MascotController::class, 'moderation']);
    Route::get('/mascot/dimensions', [MascotController::class, 'dimensions']);
    Route::post('/mascot/dimensions/return', [MascotController::class, 'dimensionReturn'])->middleware('throttle:20,1,vx-dim-return');
    Route::post('/mascot/dimensions/zero', [MascotController::class, 'dimensionZero'])->middleware('throttle:5,1,vx-dim-zero');
    Route::post('/mascot/reminders', [MascotController::class, 'remindersCreate'])->middleware('throttle:20,1,vx-reminders');
    Route::get('/mascot/reminders/due', [MascotController::class, 'remindersDue'])->middleware('throttle:10,1,vx-reminders-due');
    Route::get('/mascot/agent/blockers', [MascotController::class, 'blockers'])->middleware('throttle:10,1,vx-agent-blockers');
    Route::post('/mascot/agent/replies', [MascotController::class, 'replies'])->middleware('throttle:15,1,vx-agent-replies');
    Route::get('/mascot/agent/done-check', [MascotController::class, 'doneCheck'])->middleware('throttle:60,1,vx-agent-done');
    Route::post('/mascot/agent/faust', [MascotController::class, 'faust'])->middleware('throttle:5,1,vx-agent-faust');
    Route::get('/mascot/lab', [MascotController::class, 'lab']);
    Route::post('/mascot/lab/accelerate', [MascotController::class, 'labAccelerate'])->middleware('throttle:20,1,vx-lab-accelerate');
    Route::get('/mascot/lab/snapshot', [MascotController::class, 'labSnapshot'])->middleware('throttle:30,1,vx-lab-snapshot');
    Route::get('/mascot/lab/xray', [MascotController::class, 'labXray'])->middleware('throttle:30,1,vx-lab-xray');
    Route::get('/mascot/lab/compass', [MascotController::class, 'labCompass'])->middleware('throttle:30,1,vx-lab-compass');
    Route::post('/mascot/lab/translate', [MascotController::class, 'labTranslate'])->middleware('throttle:30,1,vx-lab-translate');
    Route::post('/mascot/lab/excuses', [MascotController::class, 'labExcuses'])->middleware('throttle:10,1,vx-lab-excuses');
    Route::post('/mascot/lab/distill', [MascotController::class, 'labDistill'])->middleware('throttle:6,1,vx-lab-distill');
    Route::get('/mascot/econ', [MascotController::class, 'econ']);
    Route::get('/mascot/album', [MascotController::class, 'album']);
    Route::post('/mascot/album/public', [MascotController::class, 'albumPublic'])->middleware('throttle:10,1,vx-album-public');
    Route::get('/mascot/album/{id}', [MascotController::class, 'teammateAlbum'])->whereNumber('id');
    Route::get('/mascot/dev/econ', [MascotController::class, 'econPanel']);
    Route::post('/mascot/econ/tick', [MascotController::class, 'econTick'])->middleware('throttle:4,1,vx-econ-tick');
    Route::get('/mascot/shop', [MascotController::class, 'shop']);
    Route::post('/mascot/shop/buy', [MascotController::class, 'buy'])->middleware('throttle:30,1,vx-shop-buy');
    Route::post('/mascot/econ/equip', [MascotController::class, 'equip'])->middleware('throttle:30,1,vx-econ-equip');
    Route::post('/mascot/econ/craft', [MascotController::class, 'craft'])->middleware('throttle:20,1,vx-econ-craft');
    Route::post('/mascot/econ/offer', [MascotController::class, 'offer'])->middleware('throttle:10,1,vx-econ-offer');
    Route::post('/mascot/econ/learn', [MascotController::class, 'learn'])->middleware('throttle:10,1,vx-econ-learn');
    Route::get('/mascot/trades', [MascotController::class, 'trades']);
    Route::post('/mascot/trades', [MascotController::class, 'tradeOffer'])->middleware('throttle:10,1,vx-trades');
    Route::post('/mascot/trades/respond', [MascotController::class, 'tradeRespond'])->middleware('throttle:20,1,vx-trades-respond');
    Route::get('/mascot/arcade', [MascotController::class, 'arcade']);
    Route::post('/mascot/arcade/start', [MascotController::class, 'arcadeStart'])->middleware('throttle:30,1,vx-arcade-start');
    Route::post('/mascot/arcade/finish', [MascotController::class, 'arcadeFinish'])->middleware('throttle:30,1,vx-arcade-finish');
    Route::get('/mascot/arcade/board', [MascotController::class, 'arcadeBoard']);
    Route::post('/mascot/arcade/tarot', [MascotController::class, 'arcadeTarot'])->middleware('throttle:10,1,vx-arcade-tarot');
    Route::post('/mascot/arcade/roulette', [MascotController::class, 'arcadeRoulette'])->middleware('throttle:10,1,vx-arcade-roulette');
    Route::post('/mascot/arcade/hide', [MascotController::class, 'arcadeHide'])->middleware('throttle:10,1,vx-arcade-hide');
    Route::get('/mascot/arcade/golden', [MascotController::class, 'arcadeGolden']);
    Route::post('/mascot/arcade/golden', [MascotController::class, 'arcadeGoldenClaim'])->middleware('throttle:10,1,vx-arcade-golden');
    Route::get('/mascot/radio', [MascotController::class, 'radioNow'])->middleware('throttle:30,1,vx-radio');
    Route::post('/mascot/radio/on', [MascotController::class, 'radioOn'])->middleware('throttle:20,1,vx-radio-on');
    Route::post('/mascot/radio/heard', [MascotController::class, 'radioHeard'])->middleware('throttle:10,1,vx-radio-heard');
    Route::post('/mascot/radio/rec', [MascotController::class, 'radioRec'])->middleware('throttle:10,1,vx-radio-rec');
    Route::get('/mascot/radio/team', [MascotController::class, 'radioTeam']);
    Route::post('/mascot/radio/dedicate', [MascotController::class, 'radioDedicate'])->middleware('throttle:10,1,vx-radio-dedicate');
    Route::get('/mascot/radio/void-hour', [MascotController::class, 'voidHour'])->middleware('throttle:10,1,vx-radio-void-hour');
    Route::get('/mascot/gazette', [MascotController::class, 'gazette']);
    Route::get('/mascot/story', [MascotController::class, 'story']);
    Route::post('/mascot/story/seen', [MascotController::class, 'storySeen'])->middleware('throttle:20,1,vx-story-seen');
    Route::get('/mascot/story/library', [MascotController::class, 'storyLibrary']);
    Route::get('/mascot/great-rewind', [MascotController::class, 'greatRewind'])->middleware('throttle:30,1,vx-great-rewind');
    Route::post('/mascot/ending', [MascotController::class, 'ending'])->middleware('throttle:10,1,vx-ending');
    Route::post('/mascot/new-tape', [MascotController::class, 'newTape'])->middleware('throttle:5,1,vx-new-tape');
    Route::post('/mascot/help', [MascotController::class, 'answerHelp'])->middleware('throttle:10,1,vx-help');
    Route::delete('/mascot/memories/{id?}', [MascotController::class, 'forget'])->whereNumber('id');
    Route::put('/boards/{boardId}/cards/reorder', [CardController::class, 'reorder']);
    Route::put('/boards/{boardId}/cards/{cardId}', [CardController::class, 'update']);
    Route::put('/boards/{boardId}/cards/{cardId}/restore', [CardController::class, 'restore']);
    Route::delete('/boards/{boardId}/cards/{cardId}', [CardController::class, 'destroy']);

    Route::post('/boards/{boardId}/cards/{cardId}/checklist', [CardChecklistController::class, 'store']);
    Route::put('/boards/{boardId}/cards/{cardId}/checklist/{itemId}', [CardChecklistController::class, 'update']);
    Route::delete('/boards/{boardId}/cards/{cardId}/checklist/{itemId}', [CardChecklistController::class, 'destroy']);

    Route::get('/boards/{boardId}/cards/{cardId}/comments', [CardCommentController::class, 'index']);
    Route::get('/boards/{boardId}/cards/{cardId}/history', [CardHistoryController::class, 'index']);
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
    Route::get('/boards/{boardId}/whatsapp/reengagement', [WhatsappReengagementController::class, 'show']);
    Route::put('/boards/{boardId}/whatsapp/reengagement', [WhatsappReengagementController::class, 'upsert']);

    // Email stage automations (owner-level board config).
    Route::get('/boards/{boardId}/email/automations', [EmailAutomationController::class, 'index']);
    Route::put('/boards/{boardId}/email/automations/{sectionId}', [EmailAutomationController::class, 'upsert']);
    Route::delete('/boards/{boardId}/email/automations/{sectionId}', [EmailAutomationController::class, 'destroy']);

    // Payment milestones (YON-63) — owner-level board config for the 50%/100% flow.
    Route::get('/boards/{boardId}/payment-milestones', [PaymentMilestoneController::class, 'index']);
    Route::post('/boards/{boardId}/payment-milestones', [PaymentMilestoneController::class, 'store']);
    Route::put('/boards/{boardId}/payment-milestones/{milestoneId}', [PaymentMilestoneController::class, 'update']);
    Route::delete('/boards/{boardId}/payment-milestones/{milestoneId}', [PaymentMilestoneController::class, 'destroy']);

    // Payment ledger on a CRM deal (YON-63).
    Route::get('/boards/{boardId}/cards/{cardId}/payments', [CardPaymentController::class, 'index']);
    Route::post('/boards/{boardId}/cards/{cardId}/payments', [CardPaymentController::class, 'store']);
    Route::delete('/boards/{boardId}/cards/{cardId}/payments/{paymentId}', [CardPaymentController::class, 'destroy']);

    // Manually issue / re-issue the deal's nota fiscal invoice (YON-68).
    Route::post('/boards/{boardId}/cards/{cardId}/invoice', [CardInvoiceController::class, 'store']);

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
    Route::post('/boards/{boardId}/cards/{cardId}/planning/apply', [PlanningController::class, 'apply'])->middleware('card.history:planning');
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

        // Board-level CRM assistant chat (YON-69) — multi-turn, streamed (scope:'crm-chat').
        Route::post('/boards/{boardId}/ai/crm-chat', [AiAssistController::class, 'crmChat']);

        // Vortex — the user-scoped workspace assistant (mascot chat). Multi-turn, streamed
        // over the caller's own private channel (scope:'vortex-chat'); no board in the URL.
        Route::post('/ai/vortex-chat', [AiAssistController::class, 'workspaceChat']);

        // Vortex's daily tape horoscope — one cached line per user per day (synchronous).
        Route::post('/ai/vortex-remark', [AiAssistController::class, 'vortexRemark']);
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
    Route::post('/projects/{projectId}/boards/reorder', [ProjectController::class, 'reorderBoards']);

    Route::get('/projects/{projectId}/members/candidates', [ProjectMemberController::class, 'candidates']);
    Route::post('/projects/{projectId}/members', [ProjectMemberController::class, 'store']);
    Route::put('/projects/{projectId}/members/{userId}', [ProjectMemberController::class, 'update']);
    Route::delete('/projects/{projectId}/members/{userId}', [ProjectMemberController::class, 'destroy']);

    // Custom JSON import models (YON-122) — project-scoped, reusable by any board.
    Route::get('/projects/{projectId}/import-models', [ImportModelController::class, 'index']);
    Route::post('/projects/{projectId}/import-models', [ImportModelController::class, 'store']);
    Route::put('/projects/{projectId}/import-models/{modelId}', [ImportModelController::class, 'update']);
    Route::delete('/projects/{projectId}/import-models/{modelId}', [ImportModelController::class, 'destroy']);

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

// Q-04 · his calendar feed: the token in the URL is the key (no session)
Route::get('/mascot/calendar/{token}.ics', [MascotController::class, 'calendar'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1');

// Q-10 · `npx yondra-vortex` reads this (read-only key, revocable from the profile)
Route::get('/mascot/terminal/{token}', [MascotController::class, 'terminal'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1');

// Yutopia — the isometric world synced with Yondra (see routes/yutopia.php).
Route::group([], base_path('routes/yutopia.php'));
