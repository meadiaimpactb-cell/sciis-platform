<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AdminContentController;
use App\Http\Controllers\Api\V1\Admin\AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\AdminEvaluationController;
use App\Http\Controllers\Api\V1\Admin\AdminMatchingController;
use App\Http\Controllers\Api\V1\Admin\AdminMeetingController;
use App\Http\Controllers\Api\V1\Admin\AdminMembershipController;
use App\Http\Controllers\Api\V1\Admin\AdminOpportunityController;
use App\Http\Controllers\Api\V1\Admin\AdminReportController;
use App\Http\Controllers\Api\V1\Admin\AdminSettingsController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorController;
use App\Http\Controllers\Api\V1\Entrepreneur\EntrepreneurDashboardController;
use App\Http\Controllers\Api\V1\Entrepreneur\EntrepreneurOpportunityController;
use App\Http\Controllers\Api\V1\Investor\InterestController;
use App\Http\Controllers\Api\V1\Investor\InvestorDashboardController;
use App\Http\Controllers\Api\V1\Investor\InvestorProfileController;
use App\Http\Controllers\Api\V1\Investor\OpportunityBrowseController;
use App\Http\Controllers\Api\V1\Payments\PaymentController;
use App\Http\Controllers\Api\V1\Public\PublicContentController;
use App\Http\Controllers\Api\V1\Public\TaxonomyController;
use App\Http\Controllers\Api\V1\System\DocumentStreamController;
use App\Http\Controllers\Api\V1\System\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SCIIS API v1 — SPEC §6
|--------------------------------------------------------------------------
| Middleware vocabulary:
|   auth:sanctum       cookie session (SPA) or bearer token (mobile)
|   verified.full      email + phone verified (ACC-01)
|   member.approved    membership approved + terms accepted (INV-02)
|   2fa.enforced       TOTP mandatory for staff (ACC-05)
|   role:…             spatie roles (guard api)
|   feature:…          feature flag gate → 404 when off
*/

Route::prefix('v1')->name('v1.')->group(function (): void {

    // ------------------------------------------------------------------ System
    Route::get('health', [HealthController::class, 'health'])->name('health');
    Route::get('version', [HealthController::class, 'version'])->name('version');
    Route::get('documents/{document}/stream', DocumentStreamController::class)->middleware('signed')->name('documents.stream');

    // ------------------------------------------------------------------ Public (CMS-01..05)
    Route::prefix('public')->name('public.')->middleware('throttle:public')->group(function (): void {
        Route::get('pages/{slug}', [PublicContentController::class, 'page'])->name('pages.show');
        Route::get('faqs', [PublicContentController::class, 'faqs'])->name('faqs');
        Route::get('news', [PublicContentController::class, 'news'])->name('news');
        Route::get('events', [PublicContentController::class, 'events'])->name('events');
        Route::get('stats', [PublicContentController::class, 'stats'])->name('stats');
        Route::get('featured-opportunities', [PublicContentController::class, 'featured'])->name('featured');
        Route::post('contact', [PublicContentController::class, 'contact'])->middleware('throttle:contact')->name('contact');
    });

    // Taxonomies & public settings (public read)
    Route::get('taxonomies', [TaxonomyController::class, 'index'])->middleware('throttle:public')->name('taxonomies.index');
    Route::get('taxonomies/{group}', [TaxonomyController::class, 'show'])->middleware('throttle:public')->name('taxonomies.show');
    Route::get('settings/public', [TaxonomyController::class, 'publicSettings'])->middleware('throttle:public')->name('settings.public');

    // ------------------------------------------------------------------ Auth (ACC-01..03)
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::middleware('throttle:auth')->group(function (): void {
            Route::post('register', [AuthController::class, 'register'])->name('register');
            Route::post('login', [AuthController::class, 'login'])->name('login');
            Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot');
            Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('reset');
        });

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');

            Route::middleware('throttle:otp')->group(function (): void {
                Route::post('verify-email', [AuthController::class, 'verifyEmail'])->name('verify-email');
                Route::post('verify-phone', [AuthController::class, 'verifyPhone'])->name('verify-phone');
                Route::post('resend-otp', [AuthController::class, 'resendOtp'])->name('resend-otp');
            });

            // ACC-05 — 2FA enrolment/challenge (not behind 2fa.enforced, by design)
            Route::prefix('2fa')->name('2fa.')->middleware('throttle:otp')->group(function (): void {
                Route::post('enable', [TwoFactorController::class, 'enable'])->name('enable');
                Route::post('confirm', [TwoFactorController::class, 'confirm'])->name('confirm');
                Route::post('challenge', [TwoFactorController::class, 'challenge'])->name('challenge');
                Route::delete('/', [TwoFactorController::class, 'disable'])->name('disable');
            });
        });
    });

    // ------------------------------------------------------------------ Me (ACC-07, NTF-02, PRV-03)
    Route::middleware('auth:sanctum')->name('me.')->group(function (): void {
        Route::get('me', [MeController::class, 'show'])->name('show');
        Route::patch('me', [MeController::class, 'update'])->name('update');
        Route::post('me/device-tokens', [MeController::class, 'storeDeviceToken'])->name('device-tokens.store');
        Route::delete('me/device-tokens/{token}', [MeController::class, 'deleteDeviceToken'])->name('device-tokens.delete');
        Route::get('me/notification-preferences', [MeController::class, 'notificationPreferences'])->name('notification-preferences.show');
        Route::put('me/notification-preferences', [MeController::class, 'updateNotificationPreferences'])->name('notification-preferences.update');
        Route::get('me/notifications', [MeController::class, 'notifications'])->name('notifications');
        Route::post('me/notifications/read', [MeController::class, 'markNotificationsRead'])->name('notifications.read');
        Route::post('me/data-requests', [MeController::class, 'dataRequest'])->name('data-requests');
    });

    // ------------------------------------------------------------------ Investor (INV, OPP, MAT, MTG)
    Route::middleware(['auth:sanctum', 'verified.full'])->group(function (): void {
        // Profile & membership (registered level)
        Route::prefix('investor')->name('investor.')->group(function (): void {
            Route::get('profile', [InvestorProfileController::class, 'show'])->name('profile.show');
            Route::put('profile', [InvestorProfileController::class, 'update'])->name('profile.update');
            Route::post('membership/submit', [InvestorProfileController::class, 'submitMembership'])->name('membership.submit');
            Route::get('membership', [InvestorProfileController::class, 'membership'])->name('membership.show');
        });

        // Approved members only
        Route::middleware('member.approved')->group(function (): void {
            Route::prefix('investor')->name('investor.')->group(function (): void {
                Route::get('dashboard', InvestorDashboardController::class)->name('dashboard');
                Route::get('saved', [OpportunityBrowseController::class, 'saved'])->name('saved');
                Route::get('interests', [InterestController::class, 'index'])->name('interests.index');
                Route::get('interests/{interest}', [InterestController::class, 'show'])->name('interests.show');
                Route::post('interests/{interest}/close', [InterestController::class, 'close'])->name('interests.close');
                Route::get('info-requests', [InterestController::class, 'infoRequests'])->name('info-requests');
                Route::get('nominations', [InterestController::class, 'nominations'])->name('nominations.index');
                Route::post('nominations/{nomination}/respond', [InterestController::class, 'respondNomination'])->name('nominations.respond');
                Route::get('meetings', [InterestController::class, 'meetings'])->name('meetings');
            });

            Route::name('opportunities.')->group(function (): void {
                Route::get('opportunities', [OpportunityBrowseController::class, 'index'])->name('index');
                Route::get('opportunities/{opportunity}', [OpportunityBrowseController::class, 'show'])->name('show');
                Route::post('opportunities/{opportunity}/save', [OpportunityBrowseController::class, 'save'])->name('save');
                Route::delete('opportunities/{opportunity}/save', [OpportunityBrowseController::class, 'unsave'])->name('unsave');
                Route::post('opportunities/{opportunity}/interest', [InterestController::class, 'express'])->name('interest');
                Route::post('opportunities/{opportunity}/info-requests', [InterestController::class, 'infoRequest'])->name('info-requests');
                Route::post('opportunities/{opportunity}/nda-accept', [InterestController::class, 'acceptNda'])->name('nda-accept');
                Route::get('opportunities/{opportunity}/documents', [InterestController::class, 'documents'])->name('documents');
            });

            Route::get('documents/{document}/download-url', [InterestController::class, 'downloadUrl'])->middleware('throttle:downloads')->name('documents.download-url');
        });

        // -------------------------------------------------------------- Entrepreneur (ENT)
        Route::prefix('entrepreneur')->name('entrepreneur.')->group(function (): void {
            Route::get('dashboard', [EntrepreneurDashboardController::class, 'dashboard'])->name('dashboard');

            Route::get('opportunities', [EntrepreneurOpportunityController::class, 'index'])->name('opportunities.index');
            Route::post('opportunities', [EntrepreneurOpportunityController::class, 'store'])->name('opportunities.store');
            Route::get('opportunities/{opportunity}', [EntrepreneurOpportunityController::class, 'show'])->name('opportunities.show');
            Route::patch('opportunities/{opportunity}', [EntrepreneurOpportunityController::class, 'update'])->name('opportunities.update');
            Route::get('opportunities/{opportunity}/checklist', [EntrepreneurOpportunityController::class, 'checklist'])->name('opportunities.checklist');
            Route::post('opportunities/{opportunity}/submit', [EntrepreneurOpportunityController::class, 'submit'])->name('opportunities.submit');
            Route::post('opportunities/{opportunity}/withdraw', [EntrepreneurOpportunityController::class, 'withdraw'])->name('opportunities.withdraw');
            Route::get('opportunities/{opportunity}/indicators', [EntrepreneurOpportunityController::class, 'indicators'])->name('opportunities.indicators.index');
            Route::post('opportunities/{opportunity}/indicators', [EntrepreneurOpportunityController::class, 'storeIndicator'])->name('opportunities.indicators.store');
            Route::delete('opportunities/{opportunity}/indicators/{indicator}', [EntrepreneurOpportunityController::class, 'destroyIndicator'])->name('opportunities.indicators.destroy');
            Route::post('opportunities/{opportunity}/documents', [EntrepreneurOpportunityController::class, 'uploadDocument'])->middleware('throttle:uploads')->name('opportunities.documents.store');
            Route::get('opportunities/{opportunity}/preview', [EntrepreneurOpportunityController::class, 'preview'])->name('opportunities.preview');
            Route::post('opportunities/{opportunity}/teaser/approve', [EntrepreneurOpportunityController::class, 'approveTeaser'])->name('opportunities.teaser.approve');

            Route::get('access-requests', [EntrepreneurDashboardController::class, 'accessRequests'])->name('access-requests.index');
            Route::post('access-requests/{interest}/decide', [EntrepreneurDashboardController::class, 'decide'])->name('access-requests.decide');
            Route::get('info-requests', [EntrepreneurDashboardController::class, 'infoRequests'])->name('info-requests.index');
            Route::post('info-requests/{infoRequest}/answer', [EntrepreneurDashboardController::class, 'answer'])->name('info-requests.answer');
            Route::get('meetings', [EntrepreneurDashboardController::class, 'meetings'])->name('meetings');
        });
    });

    // ------------------------------------------------------------------ Admin / staff (ADM, EVL, MAT, MTG, RPT, AUD, CMS)
    $staffRoles = 'role:super_admin|membership_officer|reviewer|evaluator|matching_officer|agreements_officer|impact_officer|advisory_member|reports_viewer';

    Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'verified.full', '2fa.enforced', $staffRoles])->group(function (): void {
        Route::get('dashboard', AdminDashboardController::class)->name('dashboard');

        // Opportunities
        Route::get('opportunities', [AdminOpportunityController::class, 'index'])->name('opportunities.index');
        Route::get('opportunities/{opportunity}', [AdminOpportunityController::class, 'show'])->name('opportunities.show');
        Route::post('opportunities/{opportunity}/assign', [AdminOpportunityController::class, 'assign'])->name('opportunities.assign');
        Route::post('opportunities/{opportunity}/transition', [AdminOpportunityController::class, 'transition'])->name('opportunities.transition');
        Route::post('opportunities/{opportunity}/completion-requests', [AdminOpportunityController::class, 'completionRequest'])->name('opportunities.completion-requests');
        Route::post('opportunities/{opportunity}/notes', [AdminOpportunityController::class, 'note'])->name('opportunities.notes');
        Route::post('opportunities/{opportunity}/documents/{document}/review', [AdminOpportunityController::class, 'reviewDocument'])->name('opportunities.documents.review');
        Route::get('documents/{document}/download-url', [AdminOpportunityController::class, 'documentDownloadUrl'])->middleware('throttle:downloads')->name('documents.download-url');

        // Evaluation (EVL)
        Route::post('opportunities/{opportunity}/evaluation/assign', [AdminEvaluationController::class, 'assign'])->name('evaluation.assign');
        Route::get('opportunities/{opportunity}/evaluation', [AdminEvaluationController::class, 'comparison'])->name('evaluation.show');
        Route::post('opportunities/{opportunity}/evaluation/decide', [AdminEvaluationController::class, 'decide'])->name('evaluation.decide');
        Route::post('evaluation-assignments/{assignment}/coi', [AdminEvaluationController::class, 'coi'])->name('evaluation-assignments.coi');
        Route::get('evaluation-assignments/{assignment}/score', [AdminEvaluationController::class, 'scoreForm'])->name('evaluation-assignments.score.show');
        Route::post('evaluation-assignments/{assignment}/score', [AdminEvaluationController::class, 'score'])->name('evaluation-assignments.score.store');
        Route::get('evaluation-criteria', [AdminEvaluationController::class, 'criteria'])->name('evaluation-criteria.index');
        Route::post('evaluation-criteria', [AdminEvaluationController::class, 'storeCriterion'])->name('evaluation-criteria.store');
        Route::patch('evaluation-criteria/{criterion}', [AdminEvaluationController::class, 'updateCriterion'])->name('evaluation-criteria.update');

        // Matching / teaser / interests / info requests (MAT, OPP-05)
        Route::post('opportunities/{opportunity}/teaser', [AdminMatchingController::class, 'teaser'])->name('teaser.store');
        Route::post('opportunities/{opportunity}/teaser/approve', [AdminMatchingController::class, 'approveTeaser'])->name('teaser.approve');
        Route::get('opportunities/{opportunity}/candidates', [AdminMatchingController::class, 'candidates'])->name('candidates');
        Route::post('opportunities/{opportunity}/nominations', [AdminMatchingController::class, 'nominate'])->name('nominations.store');
        Route::get('interests', [AdminMatchingController::class, 'interests'])->name('interests.index');
        Route::post('interests/{interest}/transition', [AdminMatchingController::class, 'transitionInterest'])->name('interests.transition');
        Route::get('info-requests', [AdminMatchingController::class, 'infoRequests'])->name('info-requests.index');
        Route::post('info-requests/{infoRequest}/forward', [AdminMatchingController::class, 'forwardInfoRequest'])->name('info-requests.forward');
        Route::post('info-requests/{infoRequest}/reply', [AdminMatchingController::class, 'replyInfoRequest'])->name('info-requests.reply');

        // Meetings (MTG)
        Route::get('meetings', [AdminMeetingController::class, 'index'])->name('meetings.index');
        Route::post('meetings', [AdminMeetingController::class, 'store'])->name('meetings.store');
        Route::patch('meetings/{meeting}', [AdminMeetingController::class, 'update'])->name('meetings.update');
        Route::post('meetings/{meeting}/minutes', [AdminMeetingController::class, 'minutes'])->name('meetings.minutes');

        // Memberships (ADM-06)
        Route::get('memberships', [AdminMembershipController::class, 'index'])->name('memberships.index');
        Route::get('memberships/{membership}', [AdminMembershipController::class, 'show'])->name('memberships.show');
        Route::post('memberships/{membership}/transition', [AdminMembershipController::class, 'transition'])->name('memberships.transition');

        // Users & roles (ACC-06)
        Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
        Route::post('users', [AdminUserController::class, 'store'])->name('users.store');
        Route::patch('users/{user}', [AdminUserController::class, 'update'])->name('users.update');

        // Settings & taxonomies (ADM-03, FRM-04)
        Route::get('settings', [AdminSettingsController::class, 'index'])->name('settings.index');
        Route::put('settings', [AdminSettingsController::class, 'update'])->name('settings.update');
        Route::get('taxonomies/{group}', [AdminSettingsController::class, 'taxonomy'])->name('taxonomies.index');
        Route::post('taxonomies/{group}', [AdminSettingsController::class, 'storeTerm'])->name('taxonomies.store');
        Route::patch('taxonomies/{group}/{term}', [AdminSettingsController::class, 'updateTerm'])->name('taxonomies.update');
        Route::delete('taxonomies/{group}/{term}', [AdminSettingsController::class, 'destroyTerm'])->name('taxonomies.destroy');

        // Reports & audit (RPT, AUD)
        Route::get('reports', [AdminReportController::class, 'index'])->name('reports.index');
        Route::get('reports/{key}/export.xlsx', [AdminReportController::class, 'export'])->name('reports.export');
        Route::get('reports/{key}', [AdminReportController::class, 'show'])->name('reports.show');
        Route::get('audit-logs', [AdminReportController::class, 'auditLogs'])->name('audit-logs');

        // Content & contact (CMS)
        Route::get('content/{type}', [AdminContentController::class, 'index'])->whereIn('type', ['pages', 'faqs', 'news', 'events'])->name('content.index');
        Route::post('content/{type}', [AdminContentController::class, 'store'])->whereIn('type', ['pages', 'faqs', 'news', 'events'])->name('content.store');
        Route::patch('content/{type}/{id}', [AdminContentController::class, 'update'])->whereIn('type', ['pages', 'faqs', 'news', 'events'])->name('content.update');
        Route::get('contact-messages', [AdminContentController::class, 'contactMessages'])->name('contact-messages.index');
        Route::patch('contact-messages/{contactMessage}', [AdminContentController::class, 'updateContactMessage'])->name('contact-messages.update');

        // Payments admin (behind FEATURE_PAYMENTS)
        Route::middleware('feature:payments')->group(function (): void {
            Route::get('payments', [PaymentController::class, 'adminIndex'])->name('payments.index');
            Route::get('payments/export.xlsx', [PaymentController::class, 'adminExport'])->name('payments.export');
            Route::post('payments/{payment}/refund', [PaymentController::class, 'refund'])->name('payments.refund');
        });
    });

    // ------------------------------------------------------------------ Payments (FEATURE_PAYMENTS) — fees/services/training/sponsorship only
    Route::middleware('feature:payments')->name('payments.')->group(function (): void {
        Route::middleware(['auth:sanctum', 'verified.full'])->group(function (): void {
            Route::post('payments/intents', [PaymentController::class, 'createIntent'])->name('intents');
            Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('show');
            Route::post('payments/{payment}/cancel', [PaymentController::class, 'cancel'])->name('cancel');
        });
        Route::post('webhooks/moyasar', [PaymentController::class, 'webhook'])->middleware('throttle:webhooks')->name('webhook');
    });
});
