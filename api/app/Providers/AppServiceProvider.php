<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\OtpChannel;
use App\Models\AdvisoryReferral;
use App\Models\Agreement;
use App\Models\AgreementReview;
use App\Models\AgreementSignature;
use App\Models\AgreementTemplate;
use App\Models\AgreementVersion;
use App\Models\AuditLog;
use App\Models\CalendarFeed;
use App\Models\CompletionRequest;
use App\Models\Consent;
use App\Models\ContactMessage;
use App\Models\DataRequest;
use App\Models\DeviceToken;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\Evaluation;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationCriterion;
use App\Models\EvaluationDecision;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Faq;
use App\Models\FileAccessGrant;
use App\Models\FollowUp;
use App\Models\FormSchema;
use App\Models\ImpactIndicator;
use App\Models\IndicatorValue;
use App\Models\InfoRequest;
use App\Models\Interest;
use App\Models\InternalNote;
use App\Models\InvestorNote;
use App\Models\InvestorProfile;
use App\Models\InvestorUpdate;
use App\Models\Invoice;
use App\Models\KnowledgeResource;
use App\Models\Meeting;
use App\Models\MeetingAction;
use App\Models\MeetingParticipant;
use App\Models\Membership;
use App\Models\MessageThread;
use App\Models\Milestone;
use App\Models\NewsPost;
use App\Models\Nomination;
use App\Models\NotificationPreference;
use App\Models\Opportunity;
use App\Models\OpportunityStatusTransition;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OtpCode;
use App\Models\Page;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PeriodicReport;
use App\Models\ProgressReport;
use App\Models\SavedFilter;
use App\Models\SavedOpportunity;
use App\Models\SavedReport;
use App\Models\Setting;
use App\Models\Story;
use App\Models\TaxonomyTerm;
use App\Models\Teaser;
use App\Models\ThreadMessage;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Services\Agreements\Signatures\SignatureProvider;
use App\Services\Agreements\Signatures\SignatureProviders;
use App\Services\Identity\IdentityVerifier;
use App\Services\Identity\IdentityVerifierFactory;
use App\Services\NotificationTemplateService;
use App\Services\Payments\Gateways\FakeGateway;
use App\Services\Payments\Gateways\MoyasarGateway;
use App\Services\Payments\Gateways\PaymentGateway;
use App\Services\SettingsService;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use App\Services\Sms\UnifonicSmsSender;
use App\Services\WhatsApp\CloudApiWhatsAppSender;
use App\Services\WhatsApp\LogWhatsAppSender;
use App\Services\WhatsApp\WhatsAppSender;
use App\Support\TemplateOverrideLoader;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(NotificationTemplateService::class);
        // AGR-05 — e-signature driver (`sciis.agreements.signature_driver`, default `simple`).
        $this->app->bind(SignatureProvider::class, fn ($app): SignatureProvider => SignatureProviders::make($app));

        // NTF-01 — admin-edited notification texts are laid over lang/*/notifications.php.
        $this->app->extend('translation.loader', fn (Loader $loader, $app): Loader => new TemplateOverrideLoader($loader, fn () => $app->make(NotificationTemplateService::class)));

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

        // NTF-04 — WhatsApp driver: log (default) | cloud_api (Meta WhatsApp Cloud API).
        $this->app->singleton(WhatsAppSender::class, function (): WhatsAppSender {
            return match ((string) config('sciis.whatsapp.driver', 'log')) {
                'cloud_api' => new CloudApiWhatsAppSender(
                    (string) config('services.whatsapp.phone_number_id', ''),
                    (string) config('services.whatsapp.token', ''),
                    (string) config('services.whatsapp.api_version', 'v21.0'),
                    (string) config('services.whatsapp.endpoint', 'https://graph.facebook.com'),
                ),
                default => new LogWhatsAppSender,
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

        // ACC-09 — national identity verification driver (none | nafath | fake).
        $this->app->singleton(IdentityVerifier::class, fn (): IdentityVerifier => IdentityVerifierFactory::make());
    }

    public function boot(): void
    {
        // Surface mass-assignment mistakes early outside production (no lazy-loading strictness: resources load lazily by design).
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Stable morph aliases for every model so audit/notification/consent rows never embed FQCNs (and enforced).
        Relation::enforceMorphMap([
            'advisory_referral' => AdvisoryReferral::class,
            'agreement' => Agreement::class,
            'agreement_review' => AgreementReview::class,
            'agreement_signature' => AgreementSignature::class,
            'agreement_template' => AgreementTemplate::class,
            'agreement_version' => AgreementVersion::class,
            'audit_log' => AuditLog::class,
            'completion_request' => CompletionRequest::class,
            'consent' => Consent::class,
            'contact_message' => ContactMessage::class,
            'data_request' => DataRequest::class,
            'device_token' => DeviceToken::class,
            'document' => Document::class,
            'document_access_log' => DocumentAccessLog::class,
            'evaluation' => Evaluation::class,
            'evaluation_assignment' => EvaluationAssignment::class,
            'evaluation_criterion' => EvaluationCriterion::class,
            'evaluation_decision' => EvaluationDecision::class,
            'event' => Event::class,
            'faq' => Faq::class,
            'file_access_grant' => FileAccessGrant::class,
            'follow_up' => FollowUp::class,
            'form_schema' => FormSchema::class,
            'impact_indicator' => ImpactIndicator::class,
            'indicator_value' => IndicatorValue::class,
            'info_request' => InfoRequest::class,
            'interest' => Interest::class,
            'internal_note' => InternalNote::class,
            'investor_note' => InvestorNote::class,
            'investor_profile' => InvestorProfile::class,
            'investor_update' => InvestorUpdate::class,
            'invoice' => Invoice::class,
            'knowledge_resource' => KnowledgeResource::class,
            'meeting' => Meeting::class,
            'meeting_action' => MeetingAction::class,
            'meeting_participant' => MeetingParticipant::class,
            'membership' => Membership::class,
            'milestone' => Milestone::class,
            'news_post' => NewsPost::class,
            'nomination' => Nomination::class,
            'notification_preference' => NotificationPreference::class,
            'opportunity' => Opportunity::class,
            'opportunity_status_transition' => OpportunityStatusTransition::class,
            'organization' => Organization::class,
            'otp_code' => OtpCode::class,
            'page' => Page::class,
            'partner' => Partner::class,
            'payment' => Payment::class,
            'payment_event' => PaymentEvent::class,
            'periodic_report' => PeriodicReport::class,
            'progress_report' => ProgressReport::class,
            'saved_filter' => SavedFilter::class,
            'saved_opportunity' => SavedOpportunity::class,
            'saved_report' => SavedReport::class,
            'setting' => Setting::class,
            'story' => Story::class,
            'taxonomy_term' => TaxonomyTerm::class,
            'teaser' => Teaser::class,
            'user' => User::class,
            // ACC-08 / MTG-03 / MTG-04 / MTG-05
            'organization_invitation' => OrganizationInvitation::class,
            'message_thread' => MessageThread::class,
            'thread_message' => ThreadMessage::class,
            'event_registration' => EventRegistration::class,
            'calendar_feed' => CalendarFeed::class,
        ]);

        // ACC-03 — reset links open the SPA, not an (absent) server-rendered page.
        ResetPassword::createUrlUsing(fn (object $notifiable, string $token): string => rtrim((string) config('app.frontend_url'), '/')
            .(($notifiable->locale ?? 'ar') === 'en' ? '/en' : '')
            .'/auth/reset?token='.$token.'&email='.urlencode((string) $notifiable->getEmailForPasswordReset()));

        Notification::resolved(function (ChannelManager $manager): void {
            $manager->extend('sms', fn () => $this->app->make(SmsChannel::class));
            $manager->extend('whatsapp', fn () => $this->app->make(WhatsAppChannel::class));
        });

        // Standard verification layer — the ONE password policy (register, reset, change, staff create).
        // Every rule uses `Password::defaults()`; messages: validation.password.* / validation.min.string (ar/en).
        Password::defaults(fn (): Password => Password::min(10)->mixedCase()->numbers()->symbols());

        $this->configureRateLimiting();
    }

    /** SPEC §6 — rate limiting per route group. */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => self::isSiteBuild($request) ? Limit::none() : Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        // OTP sends/verifications and 2FA attempts: 5 per minute per user AND channel (email | phone | 2fa).
        RateLimiter::for('otp', fn (Request $request) => Limit::perMinute(5)->by(($request->user()?->id ?: $request->ip()).'|'.self::otpThrottleChannel($request)));
        // Browsers call the public endpoints directly in the static hosting build (page load + post-hydration refresh).
        RateLimiter::for('public', fn (Request $request) => self::isSiteBuild($request) ? Limit::none() : Limit::perMinute(240)->by($request->ip()));
        RateLimiter::for('contact', fn (Request $request) => Limit::perHour(5)->by($request->ip()));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('downloads', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
        // MTG-04 public event registration (honeypot too), MTG-05 calendar feed polling, MTG-03 posting messages.
        RateLimiter::for('event-registration', fn (Request $request) => Limit::perHour(10)->by($request->ip()));
        RateLimiter::for('calendar-feed', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('messages', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
    }

    /**
     * The static site build (`nuxi generate`) renders every public page against the live API from one machine, which
     * would trip the per-IP limits and leave pages unrendered. It sends `X-Prerender-Token` = SCIIS_PRERENDER_TOKEN
     * (a server-side secret, never shipped to browsers); only GET requests qualify.
     */
    private static function isSiteBuild(Request $request): bool
    {
        $token = (string) config('sciis.prerender_token');

        return $token !== '' && $request->isMethod('GET') && hash_equals($token, (string) $request->header('X-Prerender-Token'));
    }

    /** Channel bucket for `throttle:otp`: legacy routes imply it, the standard ones carry `channel`. */
    private static function otpThrottleChannel(Request $request): string
    {
        $route = (string) $request->route()?->getName();

        return match (true) {
            str_contains($route, '.2fa.') => '2fa',
            str_ends_with($route, 'verify-email') => OtpChannel::Email->value,
            str_ends_with($route, 'verify-phone') => OtpChannel::Sms->value,
            default => OtpChannel::fromInput($request->input('channel'))?->value ?? 'unknown',
        };
    }
}
