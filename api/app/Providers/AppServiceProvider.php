<?php

declare(strict_types=1);

namespace App\Providers;

use App\Notifications\Channels\SmsChannel;
use App\Services\Payments\Gateways\FakeGateway;
use App\Services\Payments\Gateways\MoyasarGateway;
use App\Services\Payments\Gateways\PaymentGateway;
use App\Services\SettingsService;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use App\Services\Sms\UnifonicSmsSender;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);

        $this->app->singleton(SmsSender::class, function (): SmsSender {
            return match ((string) config('sciis.sms.driver', 'log')) {
                'unifonic' => new UnifonicSmsSender(
                    (string) config('services.unifonic.app_sid', ''),
                    (string) (app(SettingsService::class)->get('notifications.sms_sender', config('services.unifonic.sender_id', 'SCIIS'))),
                    (string) config('services.unifonic.endpoint'),
                ),
                default => new LogSmsSender,
            };
        });

        $this->app->singleton(PaymentGateway::class, function (): PaymentGateway {
            return match ((string) config('sciis.payments.gateway', 'moyasar')) {
                'fake' => new FakeGateway((string) config('services.moyasar.webhook_secret', 'test-webhook-secret')),
                default => new MoyasarGateway(
                    (string) config('services.moyasar.secret_key', ''),
                    (string) config('services.moyasar.publishable_key', ''),
                    (string) config('services.moyasar.webhook_secret', ''),
                    (string) config('services.moyasar.endpoint', 'https://api.moyasar.com/v1'),
                ),
            };
        });
    }

    public function boot(): void
    {
        // Surface mass-assignment mistakes early outside production (no lazy-loading strictness: resources load lazily by design).
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Stable morph aliases for every model so audit/notification/consent rows never embed FQCNs (and enforced).
        Relation::enforceMorphMap([
            'advisory_referral' => \App\Models\AdvisoryReferral::class,
            'agreement' => \App\Models\Agreement::class,
            'agreement_review' => \App\Models\AgreementReview::class,
            'agreement_template' => \App\Models\AgreementTemplate::class,
            'agreement_version' => \App\Models\AgreementVersion::class,
            'audit_log' => \App\Models\AuditLog::class,
            'completion_request' => \App\Models\CompletionRequest::class,
            'consent' => \App\Models\Consent::class,
            'contact_message' => \App\Models\ContactMessage::class,
            'data_request' => \App\Models\DataRequest::class,
            'device_token' => \App\Models\DeviceToken::class,
            'document' => \App\Models\Document::class,
            'document_access_log' => \App\Models\DocumentAccessLog::class,
            'evaluation' => \App\Models\Evaluation::class,
            'evaluation_assignment' => \App\Models\EvaluationAssignment::class,
            'evaluation_criterion' => \App\Models\EvaluationCriterion::class,
            'evaluation_decision' => \App\Models\EvaluationDecision::class,
            'event' => \App\Models\Event::class,
            'faq' => \App\Models\Faq::class,
            'file_access_grant' => \App\Models\FileAccessGrant::class,
            'form_schema' => \App\Models\FormSchema::class,
            'impact_indicator' => \App\Models\ImpactIndicator::class,
            'indicator_value' => \App\Models\IndicatorValue::class,
            'info_request' => \App\Models\InfoRequest::class,
            'interest' => \App\Models\Interest::class,
            'internal_note' => \App\Models\InternalNote::class,
            'investor_note' => \App\Models\InvestorNote::class,
            'investor_profile' => \App\Models\InvestorProfile::class,
            'investor_update' => \App\Models\InvestorUpdate::class,
            'invoice' => \App\Models\Invoice::class,
            'meeting' => \App\Models\Meeting::class,
            'meeting_action' => \App\Models\MeetingAction::class,
            'meeting_participant' => \App\Models\MeetingParticipant::class,
            'membership' => \App\Models\Membership::class,
            'milestone' => \App\Models\Milestone::class,
            'news_post' => \App\Models\NewsPost::class,
            'nomination' => \App\Models\Nomination::class,
            'notification_preference' => \App\Models\NotificationPreference::class,
            'opportunity' => \App\Models\Opportunity::class,
            'opportunity_status_transition' => \App\Models\OpportunityStatusTransition::class,
            'organization' => \App\Models\Organization::class,
            'otp_code' => \App\Models\OtpCode::class,
            'page' => \App\Models\Page::class,
            'payment' => \App\Models\Payment::class,
            'payment_event' => \App\Models\PaymentEvent::class,
            'progress_report' => \App\Models\ProgressReport::class,
            'saved_filter' => \App\Models\SavedFilter::class,
            'saved_opportunity' => \App\Models\SavedOpportunity::class,
            'setting' => \App\Models\Setting::class,
            'taxonomy_term' => \App\Models\TaxonomyTerm::class,
            'teaser' => \App\Models\Teaser::class,
            'user' => \App\Models\User::class,
        ]);

        // ACC-03 — reset links open the SPA, not an (absent) server-rendered page.
        ResetPassword::createUrlUsing(fn (object $notifiable, string $token): string => rtrim((string) config('app.frontend_url'), '/')
            .'/auth/reset-password?token='.$token.'&email='.urlencode((string) $notifiable->getEmailForPasswordReset()));

        Notification::resolved(function (ChannelManager $manager): void {
            $manager->extend('sms', fn () => $this->app->make(SmsChannel::class));
        });

        $this->configureRateLimiting();
    }

    /** SPEC §6 — rate limiting per route group. */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('otp', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('contact', fn (Request $request) => Limit::perHour(5)->by($request->ip()));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('downloads', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
    }
}
