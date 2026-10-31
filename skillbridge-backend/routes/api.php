<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AdminModerationController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SkillController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\JobUnderstandingController;
use App\Http\Controllers\Api\BidController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\PortfolioController;
use App\Http\Controllers\Api\SavedJobController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\ProviderAvailabilityController;
use App\Http\Controllers\Api\ProviderServiceAreaController;
use App\Http\Controllers\Api\ProviderDiscoveryController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\VerificationController;
use App\Http\Controllers\Api\ContractMilestoneController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\EscrowController;
use App\Http\Controllers\Api\DisputeController;
use App\Http\Controllers\Api\TrustIntelligenceController;
use App\Http\Controllers\Api\MarketplaceIntelligenceController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/profile', [ProfileController::class, 'show'])
    ->middleware('auth:sanctum');
Route::get('/profile/completion', [ProfileController::class, 'completion'])
    ->middleware('auth:sanctum');

Route::get('/users', [UserController::class, 'index']);
Route::get('/users/{id}', [UserController::class, 'show']);
Route::get('/providers', [ProviderDiscoveryController::class, 'index']);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::get('/me', [AuthController::class, 'me'])
    ->middleware('auth:sanctum');

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth:sanctum');

Route::put('/profile', [ProfileController::class, 'update'])
    ->middleware('auth:sanctum');

// --- Phase 3 Task 1: Provider verification ---
Route::get('/verification', [VerificationController::class, 'show'])
    ->middleware(['auth:sanctum', 'role:provider']);
Route::post('/verification', [VerificationController::class, 'submit'])
    ->middleware(['auth:sanctum', 'role:provider']);
Route::get('/admin/verifications', [VerificationController::class, 'adminIndex'])
    ->middleware(['auth:sanctum', 'role:admin']);
Route::get('/admin/verifications/{id}', [VerificationController::class, 'adminShow'])
    ->middleware(['auth:sanctum', 'role:admin']);
Route::post('/admin/verifications/{id}/approve', [VerificationController::class, 'approve'])
    ->middleware(['auth:sanctum', 'role:admin']);
Route::post('/admin/verifications/{id}/reject', [VerificationController::class, 'reject'])
    ->middleware(['auth:sanctum', 'role:admin']);

// --- Provider availability and service areas ---
Route::get('/availability', [ProviderAvailabilityController::class, 'index'])
    ->middleware(['auth:sanctum', 'role:provider']);
Route::post('/availability', [ProviderAvailabilityController::class, 'store'])
    ->middleware(['auth:sanctum', 'role:provider']);
Route::put('/availability/{id}', [ProviderAvailabilityController::class, 'update'])
    ->middleware(['auth:sanctum', 'role:provider']);
Route::delete('/availability/{id}', [ProviderAvailabilityController::class, 'destroy'])
    ->middleware(['auth:sanctum', 'role:provider']);
Route::get('/service-areas', [ProviderServiceAreaController::class, 'index'])
    ->middleware(['auth:sanctum', 'role:provider']);
Route::post('/service-areas', [ProviderServiceAreaController::class, 'store'])
    ->middleware(['auth:sanctum', 'role:provider']);
Route::put('/service-areas/{id}', [ProviderServiceAreaController::class, 'update'])
    ->middleware(['auth:sanctum', 'role:provider']);
Route::delete('/service-areas/{id}', [ProviderServiceAreaController::class, 'destroy'])
    ->middleware(['auth:sanctum', 'role:provider']);

Route::get('/skills', [SkillController::class, 'index']);
Route::get('/categories', [CategoryController::class, 'index']);

// --- Services: isolated catalog domain ---
Route::get('/services', [ServiceController::class, 'index']);
Route::get('/services/mine', [ServiceController::class, 'mine'])
    ->middleware(['auth:sanctum', 'role:provider']);
Route::get('/services/{id}', [ServiceController::class, 'show']);
Route::post('/services', [ServiceController::class, 'store'])
    ->middleware(['auth:sanctum', 'active', 'role:provider']);
Route::put('/services/{id}', [ServiceController::class, 'update'])
    ->middleware(['auth:sanctum', 'role:provider']);
Route::delete('/services/{id}', [ServiceController::class, 'destroy'])
    ->middleware(['auth:sanctum', 'role:provider']);
Route::post('/services/{serviceId}/packages', [ServiceController::class, 'storePackage'])
    ->middleware(['auth:sanctum', 'role:provider']);
Route::put('/services/{serviceId}/packages/{packageId}', [ServiceController::class, 'updatePackage'])
    ->middleware(['auth:sanctum', 'role:provider']);
Route::delete('/services/{serviceId}/packages/{packageId}', [ServiceController::class, 'destroyPackage'])
    ->middleware(['auth:sanctum', 'role:provider']);

// --- Portfolio: isolated provider showcase domain ---
Route::get('/portfolio/providers/{providerId}', [PortfolioController::class, 'publicForProvider']);
Route::get('/portfolio/my', [PortfolioController::class, 'mine'])
    ->middleware(['auth:sanctum', 'role:provider']);
Route::post('/portfolio', [PortfolioController::class, 'store'])
    ->middleware(['auth:sanctum', 'active', 'role:provider']);
Route::get('/portfolio/{id}', [PortfolioController::class, 'show'])
    ->middleware('auth:sanctum');
Route::put('/portfolio/{id}', [PortfolioController::class, 'update'])
    ->middleware(['auth:sanctum', 'role:provider']);
Route::delete('/portfolio/{id}', [PortfolioController::class, 'destroy'])
    ->middleware(['auth:sanctum', 'role:provider']);

Route::get('/my-skills', [SkillController::class, 'mySkills'])
    ->middleware('auth:sanctum');
Route::post('/my-skills/{skillId}', [SkillController::class, 'addSkill'])
    ->middleware('auth:sanctum');

Route::delete('/my-skills/{skillId}', [SkillController::class, 'removeSkill'])
    ->middleware('auth:sanctum');

// Aliases: frontend's attachSkill()/detachSkill() call these paths, with
// skill_id sent in the body for attach rather than in the URL.
Route::post('/profile/skills', [SkillController::class, 'attachSkill'])
    ->middleware('auth:sanctum');
Route::delete('/profile/skills/{skillId}', [SkillController::class, 'removeSkill'])
    ->middleware('auth:sanctum');

Route::get('/jobs', [JobController::class, 'index']);

// --- Phase 4 Task 2: deterministic provider matching and recommendations ---
Route::get('/jobs/{id}/matches', [RecommendationController::class, 'matches'])
    ->middleware(['auth:sanctum', 'role:seeker']);
Route::get('/recommendations', [RecommendationController::class, 'index'])
    ->middleware('auth:sanctum');

Route::get('/jobs/{id}', [JobController::class, 'show']);

Route::post('/jobs', [JobController::class, 'store'])
  ->middleware(['auth:sanctum', 'active', 'role:seeker']);
Route::post('/jobs/understand', [JobUnderstandingController::class, 'parse'])
  ->middleware(['auth:sanctum', 'active', 'role:seeker']);
Route::post('/jobs/{id}/understand', [JobUnderstandingController::class, 'parse'])
  ->middleware(['auth:sanctum', 'active', 'role:seeker']);
Route::post('/jobs/{id}/proposal-assistance', [TrustIntelligenceController::class, 'proposal'])
  ->middleware(['auth:sanctum', 'active', 'role:provider']);

  Route::get('/my-jobs', [JobController::class, 'myJobs'])
    ->middleware(['auth:sanctum', 'role:seeker']);

// --- Jobs: update/cancel/delete + received bids (owner only) ---
Route::put('/jobs/{id}', [JobController::class, 'update'])
    ->middleware(['auth:sanctum', 'role:seeker']);

Route::post('/jobs/{id}/cancel', [JobController::class, 'cancel'])
    ->middleware(['auth:sanctum', 'role:seeker']);

Route::delete('/jobs/{id}', [JobController::class, 'destroy'])
    ->middleware(['auth:sanctum', 'role:seeker']);

Route::get('/jobs/{id}/bids', [JobController::class, 'bids'])
    ->middleware('auth:sanctum');

// --- Saved jobs and favorites ---
Route::post('/jobs/{jobId}/save', [SavedJobController::class, 'store'])->middleware('auth:sanctum');
Route::delete('/jobs/{jobId}/save', [SavedJobController::class, 'destroy'])->middleware('auth:sanctum');
Route::get('/saved-jobs', [SavedJobController::class, 'index'])->middleware('auth:sanctum');
Route::post('/favorites/provider/{providerId}', [FavoriteController::class, 'storeProvider'])->middleware('auth:sanctum');
Route::delete('/favorites/provider/{providerId}', [FavoriteController::class, 'destroyProvider'])->middleware('auth:sanctum');
Route::post('/favorites/service/{serviceId}', [FavoriteController::class, 'storeService'])->middleware('auth:sanctum');
Route::delete('/favorites/service/{serviceId}', [FavoriteController::class, 'destroyService'])->middleware('auth:sanctum');
Route::get('/favorites', [FavoriteController::class, 'index'])->middleware('auth:sanctum');

// --- Bids ---
Route::post('/jobs/{jobId}/bids', [BidController::class, 'store'])
    ->middleware(['auth:sanctum', 'role:provider']);

Route::get('/my-bids', [BidController::class, 'myBids'])
    ->middleware(['auth:sanctum', 'role:provider']);

Route::post('/bids/{id}/withdraw', [BidController::class, 'withdraw'])
    ->middleware(['auth:sanctum', 'role:provider']);

Route::post('/bids/{id}/accept', [BidController::class, 'accept'])
    ->middleware(['auth:sanctum', 'role:seeker']);

// --- Contracts ---
Route::get('/contracts', [ContractController::class, 'index'])
    ->middleware('auth:sanctum');

Route::get('/contracts/{id}', [ContractController::class, 'show'])
    ->middleware('auth:sanctum');

Route::post('/contracts/{id}/start', [ContractController::class, 'start'])
    ->middleware('auth:sanctum');

Route::post('/contracts/{id}/complete', [ContractController::class, 'complete'])
    ->middleware('auth:sanctum');

Route::post('/contracts/{id}/cancel', [ContractController::class, 'cancel'])
    ->middleware('auth:sanctum');

// Explicit escrow actions for the frontend's Contract Detail buttons.
// Idempotent — start()/complete()/cancel() already auto-create the same
// transaction rows, so these confirm/return the existing one rather than
// duplicating it.
Route::post('/contracts/{id}/deposit', [ContractController::class, 'deposit'])
    ->middleware('auth:sanctum');

Route::post('/contracts/{id}/release', [ContractController::class, 'release'])
    ->middleware('auth:sanctum');

Route::post('/contracts/{id}/refund', [ContractController::class, 'refund'])
    ->middleware('auth:sanctum');

// --- Phase 3 Task 2: Contract milestones ---
Route::get('/contracts/{contract}/milestones', [ContractMilestoneController::class, 'index'])
    ->middleware('auth:sanctum');
Route::post('/contracts/{contract}/milestones', [ContractMilestoneController::class, 'store'])
    ->middleware('auth:sanctum');
Route::patch('/milestones/{id}', [ContractMilestoneController::class, 'update'])
    ->middleware('auth:sanctum');
Route::delete('/milestones/{id}', [ContractMilestoneController::class, 'destroy'])
    ->middleware('auth:sanctum');
Route::post('/milestones/{id}/start', [ContractMilestoneController::class, 'start'])
    ->middleware('auth:sanctum');
Route::post('/milestones/{id}/submit', [ContractMilestoneController::class, 'submit'])
    ->middleware('auth:sanctum');
Route::post('/milestones/{id}/approve', [ContractMilestoneController::class, 'approve'])
    ->middleware('auth:sanctum');
Route::post('/milestones/{id}/reject', [ContractMilestoneController::class, 'reject'])
    ->middleware('auth:sanctum');
// --- Phase 3 Task 3: Payment foundation ---
Route::post('/contracts/{contract}/payments', [PaymentController::class, 'store'])
    ->middleware('auth:sanctum');
Route::get('/payments/{id}', [PaymentController::class, 'show'])
    ->middleware('auth:sanctum');
Route::post('/payments/webhook/{provider}', [PaymentController::class, 'webhook']);
// --- Phase 3 Task 4: Escrow ledger ---
Route::get('/contracts/{contract}/escrow', [EscrowController::class, 'show'])
    ->middleware('auth:sanctum');
Route::post('/contracts/{contract}/escrow/release', [EscrowController::class, 'release'])
    ->middleware('auth:sanctum');
Route::post('/contracts/{contract}/escrow/refund', [EscrowController::class, 'refund'])
    ->middleware('auth:sanctum');
Route::get('/escrow/{id}', [EscrowController::class, 'byId'])
    ->middleware('auth:sanctum');
// --- Phase 3 Task 5: Disputes and resolution ---
Route::post('/contracts/{contract}/disputes', [DisputeController::class, 'store'])
    ->middleware('auth:sanctum');
Route::get('/contracts/{contract}/disputes', [DisputeController::class, 'index'])
    ->middleware('auth:sanctum');
Route::get('/disputes/{id}', [DisputeController::class, 'show'])
    ->middleware('auth:sanctum');
Route::post('/disputes/{id}/cancel', [DisputeController::class, 'cancel'])
    ->middleware('auth:sanctum');
Route::get('/admin/disputes', [DisputeController::class, 'adminIndex'])
    ->middleware(['auth:sanctum', 'role:admin']);
Route::get('/admin/disputes/{id}', [DisputeController::class, 'adminShow'])
    ->middleware(['auth:sanctum', 'role:admin']);
Route::post('/admin/disputes/{id}/resolve', [DisputeController::class, 'resolve'])
    ->middleware(['auth:sanctum', 'role:admin']);
Route::post('/admin/disputes/{id}/reject', [DisputeController::class, 'reject'])
    ->middleware(['auth:sanctum', 'role:admin']);
// --- Reviews ---
Route::post('/contracts/{contractId}/reviews', [ReviewController::class, 'store'])
    ->middleware('auth:sanctum');

// Alias: frontend's createReview() calls the singular path.
Route::post('/contracts/{contractId}/review', [ReviewController::class, 'store'])
    ->middleware('auth:sanctum');

Route::get('/contracts/{contractId}/reviews', [ReviewController::class, 'forContract'])
    ->middleware('auth:sanctum');

Route::get('/reviews/{id}', [ReviewController::class, 'show'])
    ->middleware('auth:sanctum');

Route::get('/providers/{providerId}/reviews', [ReviewController::class, 'forProvider']);

Route::get('/users/{userId}/reviews', [ReviewController::class, 'forUser']);

// --- Transactions (read-only, system generated) ---
Route::get('/transactions', [TransactionController::class, 'index'])
    ->middleware('auth:sanctum');

Route::get('/contracts/{contractId}/transactions', [TransactionController::class, 'forContract'])
    ->middleware('auth:sanctum');

// --- Legacy job-scoped messages (preserved for backward compat) ---
Route::get('/jobs/{jobId}/messages/{otherUserId}', [MessageController::class, 'thread'])
    ->middleware('auth:sanctum');

Route::post('/messages', [MessageController::class, 'store'])
    ->middleware('auth:sanctum');

// --- Notifications ---
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead']);
});

// --- In-app Conversations (Task 6B) ---
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/messaging-users',                    [ConversationController::class, 'users']);
    Route::get('/conversations',                       [ConversationController::class, 'index']);
    Route::post('/conversations',                      [ConversationController::class, 'store']);
    Route::get('/conversations/{id}/messages',         [ConversationController::class, 'messages']);
    Route::post('/conversations/{id}/messages',        [ConversationController::class, 'sendMessage']);
    Route::patch('/messages/{id}/read',                [ConversationController::class, 'markRead']);
});


// --- Phase 3 Task 7: Admin moderation and platform hardening ---
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function (): void {
    Route::get('/summary', [AdminModerationController::class, 'summary']);
    Route::get('/users', [AdminModerationController::class, 'users']);
    Route::get('/users/{user}', [AdminModerationController::class, 'user']);
    Route::patch('/users/{user}/suspend', [AdminModerationController::class, 'suspend']);
    Route::patch('/users/{user}/restore', [AdminModerationController::class, 'restore']);
    Route::get('/{target}', [AdminModerationController::class, 'content'])->where('target', 'jobs|services|portfolio|reviews');
    Route::get('/{target}/{id}', [AdminModerationController::class, 'contentItem'])->where('target', 'jobs|services|portfolio|reviews')->whereNumber('id');
    Route::patch('/{target}/{id}/hide', [AdminModerationController::class, 'hide'])->where('target', 'jobs|services|portfolio|reviews')->whereNumber('id');
    Route::patch('/{target}/{id}/restore', [AdminModerationController::class, 'restoreContent'])->where('target', 'jobs|services|portfolio|reviews')->whereNumber('id');
Route::get('/audit-logs', [AdminModerationController::class, 'auditLogs']);
    Route::get('/fraud/bids/{bidId}', [TrustIntelligenceController::class, 'fraudForBid']);
    Route::get('/intelligence', [MarketplaceIntelligenceController::class, 'index']);
});
