<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AdminAgreementController;
use App\Http\Controllers\Api\V1\Admin\AdminAgreementTemplateController;
use App\Http\Controllers\Api\V1\Admin\AdminContentController;
use App\Http\Controllers\Api\V1\Admin\AdminDashboardController;
use App\Http\Controllers\Api\V1\Agreements\InvestorAgreementController;
use App\Http\Controllers\Api\V1\Agreements\OwnerAgreementController;
use App\Http\Controllers\Api\V1\Admin\AdminFollowUpController;
use App\Http\Controllers\Api\V1\Admin\AdminFormController;
use App\Http\Controllers\Api\V1\Admin\AdminEvaluationController;
use App\Http\Controllers\Api\V1\Admin\AdminMatchingController;
use App\Http\Controllers\Api\V1\Admin\AdminMeetingController;
use App\Http\Controllers\Api\V1\Admin\AdminMembershipController;
use App\Http\Controllers\Api\V1\Admin\AdminOpportunityController;
use App\Http\Controllers\Api\V1\Admin\AdminReportController;
use App\Http\Controllers\Api\V1\Admin\AdminAdvisoryController;
use App\Http\Controllers\Api\V1\Admin\AdminAssignmentController;
use App\Http\Controllers\Api\V1\Admin\AdminBulkController;
use App\Http\Controllers\Api\V1\Admin\AdminPeriodicReportController;
use App\Http\Controllers\Api\V1\Admin\AdminReportBuilderController;
use App\Http\Controllers\Api\V1\Public\PublicPeriodicReportController;
use App\Http\Controllers\Api\V1\Admin\AdminRoleController;
use App\Http\Controllers\Api\V1\Admin\AdminNotificationTemplateController;
use App\Http\Controllers\Api\V1\Admin\AdminSettingsController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\IdentityVerificationController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\PreferencesController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorController;
use App\Http\Controllers\Api\V1\Entrepreneur\EntrepreneurDashboardController;
use App\Http\Controllers\Api\V1\Entrepreneur\EntrepreneurFollowUpController;
use App\Http\Controllers\Api\V1\Entrepreneur\EntrepreneurOpportunityController;
use App\Http\Controllers\Api\V1\Investor\InterestController;
use App\Http\Controllers\Api\V1\Investor\InvestorDashboardController;
use App\Http\Controllers\Api\V1\Investor\InvestorDocumentController;
use App\Http\Controllers\Api\V1\Investor\InvestorNoteController;
use App\Http\Controllers\Api\V1\Investor\InvestorPortfolioController;
use App\Http\Controllers\Api\V1\Investor\InvestorProfileController;
use App\Http\Controllers\Api\V1\Investor\NdaController;
use App\Http\Controllers\Api\V1\Investor\OpportunityBrowseController;
use App\Http\Controllers\Api\V1\Investor\OpportunityCompareController;
use App\Http\Controllers\Api\V1\Investor\SavedFilterController;
use App\Http\Controllers\Api\V1\Meetings\MeetingActionController;
use App\Http\Controllers\Api\V1\Events\AdminEventRegistrationController;
use App\Http\Controllers\Api\V1\Events\CalendarFeedController;
use App\Http\Controllers\Api\V1\Events\EventRegistrationController;
use App\Http\Controllers\Api\V1\Messages\MessageController;
use App\Http\Controllers\Api\V1\Organization\OrganizationInvitationController;
use App\Http\Controllers\Api\V1\Organization\OrganizationTeamController;
use App\Http\Controllers\Api\V1\Payments\PaymentController;
use App\Http\Controllers\Api\V1\Public\FormSchemaController;
use App\Http\Controllers\Api\V1\Public\PublicContentController;
use App\Http\Controllers\Api\V1\Public\TaxonomyController;
use App\Http\Controllers\Api\V1\System\DocumentStreamController;
use App\Http\Controllers\Api\V1\System\HealthController;
use App\Http\Controllers\Api\V1\System\OwnDocumentController;
use App\Http\Middleware\PublicCache;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SCIIS API v1 — SPEC §6
|--------------------------------------------------------------------------
| Middleware vocabulary:
|   auth:sanctum       cookie session (SPA) or bearer token (mobile)
|   verified.full      email + phone verified (ACC-01)
|   member.approved    membership approved + terms accepted (INV-02)
|   2fa                any user with 2FA enabled must have passed the TOTP challenge (ACC-05) —
|                      on every auth:sanctum route except GET /me, /auth/logout, /auth/2fa/challenge
|   2fa.enforced       staff area: TOTP enrolment mandatory for staff + challenge passed (ACC-05)
|   role:…             spatie roles (guard api)
|   feature:…          feature flag gate → 404 when off
*/

Route::prefix('v1')->name('v1.')->group(function (): void {

    // ------------------------------------------------------------------ System
    Route::get('health', [HealthController::class, 'health'])->name('health');
    Route::get('version', [HealthController::class, 'version'])->name('version');
    Route::get('documents/{document}/stream', DocumentStreamController::class)->middleware('signed')->name('documents.stream');

    // ------------------------------------------------------------------ Public (CMS-01..05)
    Route::prefix('public')->name('public.')->middleware(['throttle:public', PublicCache::class])->group(function (): void {
        Route::get('pages/{slug}', [PublicContentController::class, 'page'])->name('pages.show');
        Route::get('faqs', [PublicContentController::class, 'faqs'])->name('faqs');
        Route::get('news', [PublicContentController::class, 'news'])->name('news');
        Route::get('news/{slug}', [PublicContentController::class, 'newsShow'])->name('news.show');
        Route::get('events', [PublicContentController::class, 'events'])->name('events');
        Route::get('events/{slug}', [PublicContentController::class, 'eventShow'])->name('events.show');
        Route::get('stories', [PublicContentController::class, 'stories'])->name('stories');
        Route::get('partners', [PublicContentController::class, 'partners'])->name('partners');
        Route::get('resources', [PublicContentController::class, 'resources'])->name('resources');
        Route::get('stats', [PublicContentController::class, 'stats'])->name('stats');
        // Journey 11.10 — periodic reports published to the public audience (aggregates only).
        Route::get('periodic-reports', [PublicPeriodicReportController::class, 'index'])->name('periodic-reports.index');
        Route::get('periodic-reports/{uuid}', [PublicPeriodicReportController::class, 'show'])->whereUuid('uuid')->name('periodic-reports.show');
        Route::get('periodic-reports/{uuid}/pdf', [PublicPeriodicReportController::class, 'pdf'])->whereUuid('uuid')->middleware('throttle:downloads')->name('periodic-reports.pdf');
        Route::get('content-version', [PublicContentController::class, 'contentVersion'])->name('content-version');
        Route::get('featured-opportunities', [PublicContentController::class, 'featured'])->name('featured');
        Route::post('contact', [PublicContentController::class, 'contact'])->middleware('throttle:contact')->name('contact');
    });

    // MTG-04 — on-platform event registration (live status is never cached; honeypot + throttle like CMS-05).
    Route::prefix('public')->name('public.')->middleware('throttle:public')->group(function (): void {
        Route::get('events/{slug}/registration', [EventRegistrationController::class, 'status'])->name('events.registration');
        Route::post('events/{slug}/registrations', [EventRegistrationController::class, 'store'])->middleware('throttle:event-registration')->name('events.registrations.store');
        Route::get('events/{slug}/calendar.ics', [EventRegistrationController::class, 'ics'])->name('events.ics');
    });
    // MTG-05 — personal calendar subscription feed (secret token in the URL; Google / Microsoft 365 poll it).
    Route::get('calendar/{token}.ics', [CalendarFeedController::class, 'feed'])->where('token', '[A-Za-z0-9]{20,80}')->middleware('throttle:calendar-feed')->name('calendar.feed');
    // ACC-08 — organisation invitation link (prefill + accept = register into the organisation).
    Route::prefix('auth/invitations')->name('auth.invitations.')->middleware('throttle:auth')->group(function (): void {
        Route::get('{token}', [OrganizationInvitationController::class, 'show'])->where('token', '[A-Za-z0-9]{20,80}')->name('show');
        Route::post('{token}/accept', [OrganizationInvitationController::class, 'accept'])->where('token', '[A-Za-z0-9]{20,80}')->name('accept');
    });

    // Taxonomies & public settings (public read)
    Route::get('taxonomies', [TaxonomyController::class, 'index'])->middleware('throttle:public')->name('taxonomies.index');
    Route::get('taxonomies/{group}', [TaxonomyController::class, 'show'])->middleware('throttle:public')->name('taxonomies.show');
    Route::get('settings/public', [TaxonomyController::class, 'publicSettings'])->middleware(['throttle:public', PublicCache::class])->name('settings.public');
    // FRM-01 — active additional questions of a public form (registration / investor profile / project form).
    Route::get('forms/{key}', FormSchemaController::class)->middleware('throttle:public')->name('forms.show');

    // ------------------------------------------------------------------ Auth (ACC-01..03)
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::middleware('throttle:auth')->group(function (): void {
            Route::post('register', [AuthController::class, 'register'])->name('register');
            Route::post('login', [AuthController::class, 'login'])->name('login');
            Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot');
            Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('reset');
        });

        Route::middleware(['auth:sanctum', '2fa'])->group(function (): void {
            // Reachable while the 2FA challenge is pending.
            Route::post('logout', [AuthController::class, 'logout'])->withoutMiddleware('2fa')->name('logout');
            // ACC-07: an active user (typing, clicking) keeps the session alive while no other request is made.
            Route::post('keepalive', fn () => response()->noContent())->name('keepalive');

            // OTP — throttle:otp = 5/min per user + channel.
            Route::middleware('throttle:otp')->group(function (): void {
                // Standard contract: {channel: email|phone} / {channel, code}.
                Route::post('otp/send', [AuthController::class, 'sendOtp'])->name('otp.send');
                Route::post('verify-otp', [AuthController::class, 'verifyOtp'])->name('verify-otp');
                // Legacy endpoints (kept for compatibility — same logic).
                Route::post('verify-email', [AuthController::class, 'verifyEmail'])->name('verify-email');
                Route::post('verify-phone', [AuthController::class, 'verifyPhone'])->name('verify-phone');
                Route::post('resend-otp', [AuthController::class, 'resendOtp'])->name('resend-otp');
            });

            // ACC-05 — TOTP 2FA for every user (no role guard; not behind 2fa.enforced, by design).
            Route::prefix('2fa')->name('2fa.')->middleware('throttle:otp')->group(function (): void {
                Route::post('enable', [TwoFactorController::class, 'enable'])->name('enable');
                Route::post('confirm', [TwoFactorController::class, 'confirm'])->name('confirm');
                Route::post('challenge', [TwoFactorController::class, 'challenge'])->withoutMiddleware('2fa')->name('challenge');
                Route::post('send-code', [TwoFactorController::class, 'sendCode'])->withoutMiddleware('2fa')->name('send-code');
                Route::post('disable', [TwoFactorController::class, 'disable'])->name('disable');
                Route::delete('/', [TwoFactorController::class, 'disable'])->name('destroy'); // legacy alias of POST /2fa/disable
            });
        });
    });

    // ------------------------------------------------------------------ Me (ACC-07, NTF-02, PRV-03)
    Route::middleware(['auth:sanctum', '2fa'])->name('me.')->group(function (): void {
        // GET /me stays reachable before the 2FA challenge — the client reads `two_factor_passed` from it.
        Route::get('me', [MeController::class, 'show'])->withoutMiddleware('2fa')->name('show');
        Route::patch('me', [MeController::class, 'update'])->name('update');
        Route::post('me/device-tokens', [MeController::class, 'storeDeviceToken'])->name('device-tokens.store');
        Route::delete('me/device-tokens/{token}', [MeController::class, 'deleteDeviceToken'])->name('device-tokens.delete');
        Route::get('me/notification-preferences', [MeController::class, 'notificationPreferences'])->name('notification-preferences.show');
        Route::put('me/notification-preferences', [MeController::class, 'updateNotificationPreferences'])->name('notification-preferences.update');
        Route::get('me/notifications', [MeController::class, 'notifications'])->name('notifications');
        Route::post('me/notifications/read', [MeController::class, 'markNotificationsRead'])->name('notifications.read');
        Route::post('me/data-requests', [MeController::class, 'dataRequest'])->name('data-requests');
        Route::post('me/avatar', [MeController::class, 'uploadAvatar'])->middleware('throttle:uploads')->name('avatar.store');
        Route::delete('me/avatar', [MeController::class, 'deleteAvatar'])->name('avatar.delete');
        // DOC-02/03 — the uploader (or the opportunity owner) opens their own documents, any version; access logged.
        Route::get('me/documents/{document}/download-url', OwnDocumentController::class)->middleware('throttle:downloads')->name('documents.download-url');
        Route::put('me/password', [MeController::class, 'updatePassword'])->middleware('throttle:auth')->name('password.update');
        // UX-05 / BRD-04 — calendar (gregorian | hijri | both) and portal theme preferences.
        Route::patch('me/preferences', [PreferencesController::class, 'update'])->name('preferences.update');
        // ACC-09 — national identity verification (Nafath when configured; `available: false` otherwise).
        Route::get('me/identity-verification', [IdentityVerificationController::class, 'show'])->name('identity.show');
        Route::post('me/identity-verification', [IdentityVerificationController::class, 'store'])->middleware('throttle:5,1')->name('identity.store');
        Route::get('me/identity-verification/{verification}', [IdentityVerificationController::class, 'poll'])->name('identity.poll');
        // MTG-05 — personal calendar subscription feed (show / create-or-regenerate / revoke).
        Route::get('me/calendar-feed', [CalendarFeedController::class, 'show'])->name('calendar-feed.show');
        Route::post('me/calendar-feed', [CalendarFeedController::class, 'regenerate'])->name('calendar-feed.regenerate');
        Route::delete('me/calendar-feed', [CalendarFeedController::class, 'revoke'])->name('calendar-feed.revoke');
    });

    // ------------------------------------------------------------------ Investor (INV, OPP, MAT, MTG)
    Route::middleware(['auth:sanctum', '2fa', 'verified.full'])->group(function (): void {
        // Profile & membership (registered level)
        Route::prefix('investor')->name('investor.')->group(function (): void {
            Route::get('profile', [InvestorProfileController::class, 'show'])->name('profile.show');
            Route::put('profile', [InvestorProfileController::class, 'update'])->name('profile.update');
            // INV-01 — CV (individual) / CR + authorization letter + impact policy (institutional), via DocumentService.
            Route::post('profile/documents', [InvestorDocumentController::class, 'store'])->middleware('throttle:uploads')->name('profile.documents.store');
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
                Route::post('info-requests/{infoRequest}/close', [InterestController::class, 'closeInfoRequest'])->name('info-requests.close'); // §6.3
                Route::get('nominations', [InterestController::class, 'nominations'])->name('nominations.index');
                Route::post('nominations/{nomination}/respond', [InterestController::class, 'respondNomination'])->name('nominations.respond');
                Route::get('meetings', [InterestController::class, 'meetings'])->name('meetings');
                // OPP-07/08 — saved catalogue searches + alert switches; OPP-09 — private notes (author only).
                Route::get('saved-filters', [SavedFilterController::class, 'index'])->name('saved-filters.index');
                Route::post('saved-filters', [SavedFilterController::class, 'store'])->name('saved-filters.store');
                Route::patch('saved-filters/{savedFilter}', [SavedFilterController::class, 'update'])->name('saved-filters.update');
                Route::delete('saved-filters/{savedFilter}', [SavedFilterController::class, 'destroy'])->name('saved-filters.destroy');
                Route::put('alerts/profile', [SavedFilterController::class, 'profileAlerts'])->name('alerts.profile');
                Route::patch('notes/{note}', [InvestorNoteController::class, 'update'])->name('notes.update');
                Route::delete('notes/{note}', [InvestorNoteController::class, 'destroy'])->name('notes.destroy');
                // AGR-04/05 — the connected investor's agreements (read-only, click-to-sign).
                Route::get('agreements', [InvestorAgreementController::class, 'index'])->name('agreements.index');
                Route::get('agreements/{agreement}', [InvestorAgreementController::class, 'show'])->name('agreements.show');
                Route::post('agreements/{agreement}/sign', [InvestorAgreementController::class, 'sign'])->name('agreements.sign');
                Route::get('agreements/{agreement}/signed-copy/download-url', [InvestorAgreementController::class, 'signedCopyUrl'])->middleware('throttle:downloads')->name('agreements.signed-copy');
                // INV-06 — linked projects: execution status, milestones, accepted reports, verified impact, owner updates.
                Route::get('portfolio', [InvestorPortfolioController::class, 'index'])->name('portfolio.index');
                Route::get('portfolio/documents/{document}/download-url', [InvestorPortfolioController::class, 'documentUrl'])->middleware('throttle:downloads')->name('portfolio.documents.download-url');
                Route::get('portfolio/{opportunity}', [InvestorPortfolioController::class, 'show'])->name('portfolio.show');
            });

            Route::name('opportunities.')->group(function (): void {
                Route::get('opportunities', [OpportunityBrowseController::class, 'index'])->name('index');
                Route::get('opportunities/compare', OpportunityCompareController::class)->name('compare'); // OPP-12 (before {opportunity})
                Route::get('opportunities/{opportunity}/notes', [InvestorNoteController::class, 'index'])->name('notes.index'); // OPP-09
                Route::post('opportunities/{opportunity}/notes', [InvestorNoteController::class, 'store'])->name('notes.store');
                Route::get('opportunities/{opportunity}', [OpportunityBrowseController::class, 'show'])->name('show');
                Route::post('opportunities/{opportunity}/save', [OpportunityBrowseController::class, 'save'])->name('save');
                Route::delete('opportunities/{opportunity}/save', [OpportunityBrowseController::class, 'unsave'])->name('unsave');
                Route::post('opportunities/{opportunity}/interest', [InterestController::class, 'express'])->name('interest');
                Route::post('opportunities/{opportunity}/info-requests', [InterestController::class, 'infoRequest'])->name('info-requests');
                Route::get('opportunities/{opportunity}/nda', [NdaController::class, 'show'])->name('nda.show'); // MSG-02
                Route::post('opportunities/{opportunity}/nda-accept', [NdaController::class, 'accept'])->name('nda-accept');
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
            Route::post('access-requests/{interest}/revoke', [EntrepreneurDashboardController::class, 'revoke'])->name('access-requests.revoke'); // ENT-06
            Route::get('info-requests', [EntrepreneurDashboardController::class, 'infoRequests'])->name('info-requests.index');
            Route::post('info-requests/{infoRequest}/answer', [EntrepreneurDashboardController::class, 'answer'])->name('info-requests.answer');
            Route::get('meetings', [EntrepreneurDashboardController::class, 'meetings'])->name('meetings');
            // AGR-04/05 — the project owner's agreements (read-only, click-to-sign).
            Route::get('agreements', [OwnerAgreementController::class, 'index'])->name('agreements.index');
            Route::get('agreements/{agreement}', [OwnerAgreementController::class, 'show'])->name('agreements.show');
            Route::post('agreements/{agreement}/sign', [OwnerAgreementController::class, 'sign'])->name('agreements.sign');
            Route::get('agreements/{agreement}/signed-copy/download-url', [OwnerAgreementController::class, 'signedCopyUrl'])->middleware('throttle:downloads')->name('agreements.signed-copy');

            // PRJ-01..03, IMP-01/02, ENT-10 — post-connection follow-up (owner side).
            Route::prefix('follow-up')->name('follow-up.')->group(function (): void {
                Route::get('/', [EntrepreneurFollowUpController::class, 'index'])->name('index');
                Route::get('{opportunity}', [EntrepreneurFollowUpController::class, 'show'])->name('show');
                Route::post('{opportunity}/milestones', [EntrepreneurFollowUpController::class, 'storeMilestone'])->name('milestones.store');
                Route::patch('{opportunity}/milestones/{milestone}', [EntrepreneurFollowUpController::class, 'updateMilestone'])->name('milestones.update');
                Route::delete('{opportunity}/milestones/{milestone}', [EntrepreneurFollowUpController::class, 'destroyMilestone'])->name('milestones.destroy');
                Route::post('{opportunity}/milestones/{milestone}/complete', [EntrepreneurFollowUpController::class, 'completeMilestone'])->middleware('throttle:uploads')->name('milestones.complete');
                Route::post('{opportunity}/plan/submit', [EntrepreneurFollowUpController::class, 'submitPlan'])->name('plan.submit');
                Route::post('{opportunity}/plan/revise', [EntrepreneurFollowUpController::class, 'revisePlan'])->name('plan.revise');
                Route::post('{opportunity}/reports', [EntrepreneurFollowUpController::class, 'storeReport'])->middleware('throttle:uploads')->name('reports.store');
                Route::post('{opportunity}/reports/{report}/resubmit', [EntrepreneurFollowUpController::class, 'resubmitReport'])->middleware('throttle:uploads')->name('reports.resubmit');
                Route::post('{opportunity}/indicators/{indicator}/values', [EntrepreneurFollowUpController::class, 'storeIndicatorValue'])->middleware('throttle:uploads')->name('indicator-values.store');
                Route::post('{opportunity}/indicator-values/{value}/resubmit', [EntrepreneurFollowUpController::class, 'resubmitIndicatorValue'])->middleware('throttle:uploads')->name('indicator-values.resubmit');
                Route::post('{opportunity}/updates', [EntrepreneurFollowUpController::class, 'storeUpdate'])->middleware('throttle:uploads')->name('updates.store');
                Route::put('{opportunity}/remediation', [EntrepreneurFollowUpController::class, 'remediation'])->name('remediation');
            });
        });

        // MTG-02 — meeting follow-up actions: the assignee (investor, owner or staff) or meeting staff mark them done.
        Route::patch('meeting-actions/{meetingAction}', [MeetingActionController::class, 'update'])->name('meeting-actions.update');

        // ACC-08 — organisation accounts: team (all colleagues), invitations / members / transfer (representative).
        Route::prefix('organization')->name('organization.')->group(function (): void {
            Route::get('team', [OrganizationTeamController::class, 'show'])->name('team');
            Route::post('invitations', [OrganizationTeamController::class, 'invite'])->middleware('throttle:20,60')->name('invitations.store');
            Route::post('invitations/{invitation}/resend', [OrganizationTeamController::class, 'resend'])->middleware('throttle:20,60')->name('invitations.resend');
            Route::delete('invitations/{invitation}', [OrganizationTeamController::class, 'revoke'])->name('invitations.revoke');
            Route::patch('members/{member}', [OrganizationTeamController::class, 'updateMember'])->name('members.update');
            Route::post('representative', [OrganizationTeamController::class, 'transfer'])->name('representative');
        });

        // MTG-03 — messages tied to a record (party side; staff use /admin/messages).
        Route::prefix('messages')->name('messages.')->group(function (): void {
            Route::get('/', [MessageController::class, 'index'])->name('index');
            Route::get('unread-count', [MessageController::class, 'unreadCount'])->name('unread-count');
            Route::get('attachments/{document}/download-url', [MessageController::class, 'attachmentUrl'])->middleware('throttle:downloads')->name('attachments.url');
            Route::get('{type}/{key}', [MessageController::class, 'show'])->whereIn('type', ['opportunity', 'interest', 'nomination'])->name('show');
            Route::post('{type}/{key}', [MessageController::class, 'store'])->whereIn('type', ['opportunity', 'interest', 'nomination'])->middleware('throttle:messages')->name('store');
        });
    });

    // ------------------------------------------------------------------ Admin / staff (ADM, EVL, MAT, MTG, RPT, AUD, CMS)
    Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'verified.full', '2fa.enforced', 'staff'])->group(function (): void {
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
        Route::get('opportunities/{opportunity}/my-assignment', [AdminEvaluationController::class, 'myAssignment'])->name('evaluation.mine');
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
        Route::post('info-requests/{infoRequest}/close', [AdminMatchingController::class, 'closeInfoRequest'])->name('info-requests.close'); // §6.3

        // Meetings (MTG)
        Route::get('meetings', [AdminMeetingController::class, 'index'])->name('meetings.index');
        Route::post('meetings', [AdminMeetingController::class, 'store'])->name('meetings.store');
        Route::patch('meetings/{meeting}', [AdminMeetingController::class, 'update'])->name('meetings.update');
        Route::post('meetings/{meeting}/minutes', [AdminMeetingController::class, 'minutes'])->name('meetings.minutes');

        // Agreements (AGR-01..05) — agreements.manage / agreements.approve
        Route::get('agreement-templates', [AdminAgreementTemplateController::class, 'index'])->name('agreement-templates.index');
        Route::post('agreement-templates', [AdminAgreementTemplateController::class, 'store'])->name('agreement-templates.store');
        Route::get('agreement-templates/{template}', [AdminAgreementTemplateController::class, 'show'])->name('agreement-templates.show');
        Route::post('agreement-templates/{template}/versions', [AdminAgreementTemplateController::class, 'revise'])->name('agreement-templates.revise');
        Route::post('agreement-templates/{template}/activate', [AdminAgreementTemplateController::class, 'activate'])->name('agreement-templates.activate');
        Route::post('agreement-templates/{template}/deactivate', [AdminAgreementTemplateController::class, 'deactivate'])->name('agreement-templates.deactivate');
        Route::get('agreements', [AdminAgreementController::class, 'index'])->name('agreements.index');
        Route::get('agreements/connections', [AdminAgreementController::class, 'connections'])->name('agreements.connections');
        Route::get('agreements/draft-preview', [AdminAgreementController::class, 'preview'])->name('agreements.preview');
        Route::post('agreements', [AdminAgreementController::class, 'store'])->name('agreements.store');
        Route::get('agreements/{agreement}', [AdminAgreementController::class, 'show'])->name('agreements.show');
        Route::post('agreements/{agreement}/versions', [AdminAgreementController::class, 'revise'])->name('agreements.revise');
        Route::get('agreements/{agreement}/compare', [AdminAgreementController::class, 'compare'])->name('agreements.compare');
        Route::post('agreements/{agreement}/submit-review', [AdminAgreementController::class, 'submitReview'])->name('agreements.submit-review');
        Route::post('agreements/{agreement}/review', [AdminAgreementController::class, 'review'])->name('agreements.review');
        Route::post('agreements/{agreement}/send-for-signature', [AdminAgreementController::class, 'sendForSignature'])->name('agreements.send-for-signature');
        Route::post('agreements/{agreement}/signed-copy', [AdminAgreementController::class, 'uploadSigned'])->middleware('throttle:uploads')->name('agreements.signed-copy.store');
        Route::get('agreements/{agreement}/signed-copy/download-url', [AdminAgreementController::class, 'signedCopyUrl'])->middleware('throttle:downloads')->name('agreements.signed-copy');
        Route::post('agreements/{agreement}/archive', [AdminAgreementController::class, 'archive'])->name('agreements.archive');
        Route::post('agreements/{agreement}/cancel', [AdminAgreementController::class, 'cancel'])->name('agreements.cancel');

        // Follow-up & impact (PRJ-01..03, IMP-01/02, ENT-10) — impact.verify.
        Route::prefix('follow-up')->name('follow-up.')->group(function (): void {
            Route::get('/', [AdminFollowUpController::class, 'index'])->name('index');
            Route::get('documents/{document}/download-url', [AdminFollowUpController::class, 'documentUrl'])->middleware('throttle:downloads')->name('documents.download-url');
            Route::get('{opportunity}', [AdminFollowUpController::class, 'show'])->name('show');
            Route::post('{opportunity}/plan/review', [AdminFollowUpController::class, 'reviewPlan'])->name('plan.review');
            Route::post('{opportunity}/milestones/{milestone}/reopen', [AdminFollowUpController::class, 'reopenMilestone'])->name('milestones.reopen');
            Route::post('{opportunity}/reports/{report}/review', [AdminFollowUpController::class, 'reviewReport'])->name('reports.review');
            Route::post('{opportunity}/indicator-values/{value}/review', [AdminFollowUpController::class, 'reviewIndicatorValue'])->name('indicator-values.review');
            Route::post('{opportunity}/updates/{update}/review', [AdminFollowUpController::class, 'reviewUpdate'])->name('updates.review');
            Route::put('{opportunity}/status', [AdminFollowUpController::class, 'overrideStatus'])->name('status');
        });

        // Memberships (ADM-06)
        Route::get('memberships', [AdminMembershipController::class, 'index'])->name('memberships.index');
        Route::get('memberships/{membership}', [AdminMembershipController::class, 'show'])->name('memberships.show');
        Route::post('memberships/{membership}/transition', [AdminMembershipController::class, 'transition'])->name('memberships.transition');
        Route::put('memberships/{membership}/review-answers', [AdminFormController::class, 'membershipReview'])->name('memberships.review-answers'); // FRM-01

        // Additional questions builder (FRM-01) — versioned schemas for the investor profile, project and membership review forms.
        Route::get('forms', [AdminFormController::class, 'index'])->name('forms.index');
        Route::get('forms/{key}', [AdminFormController::class, 'show'])->name('forms.show');
        Route::post('forms/{key}/publish', [AdminFormController::class, 'publish'])->name('forms.publish');

        // Users & roles (ACC-06)
        Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
        Route::post('users', [AdminUserController::class, 'store'])->name('users.store');
        Route::get('users/{user}', [AdminUserController::class, 'show'])->name('users.show'); // ADM-06 user file
        Route::patch('users/{user}', [AdminUserController::class, 'update'])->name('users.update');
        Route::get('roles', [AdminRoleController::class, 'index'])->name('roles.index');
        Route::get('roles/approval-matrix', [AdminRoleController::class, 'matrix'])->name('roles.matrix'); // ADM-07
        Route::post('roles', [AdminRoleController::class, 'store'])->name('roles.store');
        Route::patch('roles/{role}', [AdminRoleController::class, 'update'])->name('roles.update');

        // Settings & taxonomies (ADM-03, FRM-04)
        Route::get('settings', [AdminSettingsController::class, 'index'])->name('settings.index');
        Route::put('settings', [AdminSettingsController::class, 'update'])->name('settings.update');
        Route::get('system-status', [AdminSettingsController::class, 'systemStatus'])->name('system-status');
        Route::get('notification-templates', [AdminNotificationTemplateController::class, 'index'])->name('notification-templates.index');
        Route::put('notification-templates', [AdminNotificationTemplateController::class, 'update'])->name('notification-templates.update');
        Route::get('taxonomies/{group}', [AdminSettingsController::class, 'taxonomy'])->name('taxonomies.index');
        Route::post('taxonomies/{group}', [AdminSettingsController::class, 'storeTerm'])->name('taxonomies.store');
        Route::patch('taxonomies/{group}/{term}', [AdminSettingsController::class, 'updateTerm'])->name('taxonomies.update');
        Route::delete('taxonomies/{group}/{term}', [AdminSettingsController::class, 'destroyTerm'])->name('taxonomies.destroy');

        // Reports & audit (RPT, AUD)
        Route::get('reports', [AdminReportController::class, 'index'])->name('reports.index');
        Route::get('reports/{key}/export.xlsx', [AdminReportController::class, 'export'])->name('reports.export');
        Route::get('reports/{key}/export.pdf', [AdminReportController::class, 'exportPdf'])->middleware('throttle:downloads')->name('reports.export-pdf'); // RPT-06
        Route::get('reports/{key}/drilldown', [AdminReportController::class, 'drilldown'])->name('reports.drilldown'); // RPT-03
        Route::get('reports/{key}', [AdminReportController::class, 'show'])->name('reports.show');
        Route::get('audit-logs/export.xlsx', [AdminReportController::class, 'auditLogsExport'])->name('audit-logs.export');
        Route::get('audit-logs', [AdminReportController::class, 'auditLogs'])->name('audit-logs');

        // RPT-04 custom report builder + RPT-05 saved & scheduled reports
        Route::get('report-builder', [AdminReportBuilderController::class, 'catalog'])->name('report-builder.catalog');
        Route::post('report-builder/preview', [AdminReportBuilderController::class, 'preview'])->name('report-builder.preview');
        Route::get('report-builder/export', [AdminReportBuilderController::class, 'export'])->middleware('throttle:downloads')->name('report-builder.export');
        Route::get('report-recipients', [AdminReportBuilderController::class, 'recipients'])->name('report-recipients');
        Route::get('saved-reports', [AdminReportBuilderController::class, 'index'])->name('saved-reports.index');
        Route::post('saved-reports', [AdminReportBuilderController::class, 'store'])->name('saved-reports.store');
        Route::patch('saved-reports/{savedReport}', [AdminReportBuilderController::class, 'update'])->name('saved-reports.update');
        Route::delete('saved-reports/{savedReport}', [AdminReportBuilderController::class, 'destroy'])->name('saved-reports.destroy');
        Route::get('saved-reports/{savedReport}/run', [AdminReportBuilderController::class, 'run'])->name('saved-reports.run');
        Route::get('saved-reports/{savedReport}/export', [AdminReportBuilderController::class, 'exportSaved'])->middleware('throttle:downloads')->name('saved-reports.export');
        Route::put('saved-reports/{savedReport}/schedule', [AdminReportBuilderController::class, 'schedule'])->name('saved-reports.schedule');

        // Journey 11.10 — periodic reports: draft → approve (reports.approve) → publish
        Route::get('periodic-reports', [AdminPeriodicReportController::class, 'index'])->name('periodic-reports.index');
        Route::post('periodic-reports', [AdminPeriodicReportController::class, 'store'])->name('periodic-reports.store');
        Route::get('periodic-reports/{periodicReport}', [AdminPeriodicReportController::class, 'show'])->name('periodic-reports.show');
        Route::patch('periodic-reports/{periodicReport}', [AdminPeriodicReportController::class, 'update'])->name('periodic-reports.update');
        Route::post('periodic-reports/{periodicReport}/approve', [AdminPeriodicReportController::class, 'approve'])->name('periodic-reports.approve');
        Route::post('periodic-reports/{periodicReport}/return', [AdminPeriodicReportController::class, 'returnToDraft'])->name('periodic-reports.return');
        Route::post('periodic-reports/{periodicReport}/publish', [AdminPeriodicReportController::class, 'publish'])->name('periodic-reports.publish');
        Route::post('periodic-reports/{periodicReport}/unpublish', [AdminPeriodicReportController::class, 'unpublish'])->name('periodic-reports.unpublish');
        Route::get('periodic-reports/{periodicReport}/export.pdf', [AdminPeriodicReportController::class, 'pdf'])->middleware('throttle:downloads')->name('periodic-reports.pdf');

        // ADM-08 bulk actions (max 100 per request) + ADM-09 reviewer pools / evaluator suggestions
        Route::prefix('bulk')->name('bulk.')->group(function (): void {
            Route::post('opportunities/assign', [AdminBulkController::class, 'assignOpportunities'])->name('opportunities.assign');
            Route::post('opportunities/remind', [AdminBulkController::class, 'remindOpportunities'])->name('opportunities.remind');
            Route::get('opportunities/export', [AdminBulkController::class, 'exportOpportunities'])->middleware('throttle:downloads')->name('opportunities.export');
            Route::post('memberships/assign', [AdminBulkController::class, 'assignMemberships'])->name('memberships.assign');
            Route::post('memberships/remind', [AdminBulkController::class, 'remindMemberships'])->name('memberships.remind');
            Route::get('memberships/export', [AdminBulkController::class, 'exportMemberships'])->middleware('throttle:downloads')->name('memberships.export');
        });
        Route::get('assignment/reviewers', [AdminAssignmentController::class, 'reviewers'])->name('assignment.reviewers');
        Route::get('opportunities/{opportunity}/evaluation/suggest', [AdminAssignmentController::class, 'suggestEvaluators'])->name('evaluation.suggest');

        // EVL-04 advisory committee referrals
        Route::get('advisory-referrals', [AdminAdvisoryController::class, 'index'])->name('advisory-referrals.index');
        Route::get('advisory-referrals/advisors', [AdminAdvisoryController::class, 'advisors'])->name('advisory-referrals.advisors');
        Route::post('advisory-referrals/{advisoryReferral}/opinion', [AdminAdvisoryController::class, 'opinion'])->name('advisory-referrals.opinion');
        Route::post('advisory-referrals/{advisoryReferral}/end', [AdminAdvisoryController::class, 'end'])->name('advisory-referrals.end');
        Route::get('opportunities/{opportunity}/advisory-referrals', [AdminAdvisoryController::class, 'forOpportunity'])->name('opportunities.advisory-referrals.index');
        Route::post('opportunities/{opportunity}/advisory-referrals', [AdminAdvisoryController::class, 'store'])->name('opportunities.advisory-referrals.store');

        // Content & contact (CMS)
        Route::post('content/media', [AdminContentController::class, 'media'])->name('content.media');
        Route::post('content/files', [AdminContentController::class, 'file'])->name('content.files');
        Route::get('content/{type}', [AdminContentController::class, 'index'])->whereIn('type', ['pages', 'faqs', 'news', 'events', 'stories', 'partners', 'resources'])->name('content.index');
        Route::post('content/{type}', [AdminContentController::class, 'store'])->whereIn('type', ['pages', 'faqs', 'news', 'events', 'stories', 'partners', 'resources'])->name('content.store');
        Route::patch('content/{type}/{id}', [AdminContentController::class, 'update'])->whereIn('type', ['pages', 'faqs', 'news', 'events', 'stories', 'partners', 'resources'])->name('content.update');
        Route::delete('content/{type}/{id}', [AdminContentController::class, 'destroy'])->whereIn('type', AdminContentController::GROWTH_TYPES)->name('content.destroy');
        Route::get('contact-messages', [AdminContentController::class, 'contactMessages'])->name('contact-messages.index');
        Route::patch('contact-messages/{contactMessage}', [AdminContentController::class, 'updateContactMessage'])->name('contact-messages.update');

        // MTG-04 — event registration settings, registrants, attendance, export.
        Route::get('events', [AdminEventRegistrationController::class, 'index'])->name('events.index');
        Route::put('events/{event}/registration-settings', [AdminEventRegistrationController::class, 'settings'])->whereNumber('event')->name('events.registration-settings');
        Route::get('events/{event}/registrations/export.xlsx', [AdminEventRegistrationController::class, 'export'])->whereNumber('event')->name('events.registrations.export');
        Route::get('events/{event}/registrations', [AdminEventRegistrationController::class, 'registrations'])->whereNumber('event')->name('events.registrations.index');
        Route::patch('event-registrations/{registration}', [AdminEventRegistrationController::class, 'update'])->name('event-registrations.update');

        // MTG-03 — messages tied to a record (staff side).
        Route::prefix('messages')->name('messages.')->group(function (): void {
            Route::get('/', [MessageController::class, 'index'])->name('index');
            Route::get('unread-count', [MessageController::class, 'unreadCount'])->name('unread-count');
            Route::get('attachments/{document}/download-url', [MessageController::class, 'attachmentUrl'])->middleware('throttle:downloads')->name('attachments.url');
            Route::get('{type}/{key}', [MessageController::class, 'show'])->whereIn('type', ['opportunity', 'interest', 'nomination'])->name('show');
            Route::post('{type}/{key}', [MessageController::class, 'store'])->whereIn('type', ['opportunity', 'interest', 'nomination'])->middleware('throttle:messages')->name('store');
        });

        // Payments admin (behind FEATURE_PAYMENTS)
        Route::middleware('feature:payments')->group(function (): void {
            Route::get('payments', [PaymentController::class, 'adminIndex'])->name('payments.index');
            Route::get('payments/export.xlsx', [PaymentController::class, 'adminExport'])->name('payments.export');
            Route::post('payments/{payment}/refund', [PaymentController::class, 'refund'])->name('payments.refund');
        });
    });

    // ------------------------------------------------------------------ Payments (FEATURE_PAYMENTS) — fees/services/training/sponsorship only
    Route::middleware('feature:payments')->name('payments.')->group(function (): void {
        Route::middleware(['auth:sanctum', '2fa', 'verified.full'])->group(function (): void {
            Route::post('payments/intents', [PaymentController::class, 'createIntent'])->name('intents');
            Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('show');
            Route::post('payments/{payment}/cancel', [PaymentController::class, 'cancel'])->name('cancel');
        });
        Route::post('webhooks/moyasar', [PaymentController::class, 'webhook'])->middleware('throttle:webhooks')->name('webhook');
    });
});
