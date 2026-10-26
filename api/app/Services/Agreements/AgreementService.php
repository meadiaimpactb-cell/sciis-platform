<?php

declare(strict_types=1);

namespace App\Services\Agreements;

use App\Enums\AgreementParty;
use App\Enums\AgreementReviewDecision;
use App\Enums\AgreementStatus;
use App\Enums\DocumentKind;
use App\Enums\InterestStatus;
use App\Enums\OpportunityStatus;
use App\Enums\Sensitivity;
use App\Exceptions\DomainException;
use App\Models\Agreement;
use App\Models\AgreementReview;
use App\Models\AgreementSignature;
use App\Models\AgreementTemplate;
use App\Models\AgreementVersion;
use App\Models\Interest;
use App\Models\User;
use App\Notifications\AgreementNotification;
use App\Services\Agreements\Signatures\SignatureProvider;
use App\Services\Agreements\Signatures\SignatureRequest;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use App\Services\NotificationService;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AGR-02..05 — the ONLY place an agreement changes: versions (every edit is a new version), legal review, sending
 * for signature, signatures (SignatureProvider) or a wet-signed PDF, archive and cancellation. Every step writes an
 * audit row on the agreement and the parties / reviewers are notified at the key steps.
 */
class AgreementService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly DocumentService $documents,
        private readonly AgreementPlaceholders $placeholders,
        private readonly SignatureProvider $signatures,
    ) {}

    // ---------------------------------------------------------------- AGR-02 drafting

    /** Connections (interest connected / in meeting, opportunity connected or later) without an agreement yet. */
    public function connectionsQuery(): Builder
    {
        return Interest::query()
            ->whereIn('status', [InterestStatus::Connected->value, InterestStatus::Meeting->value])
            ->whereHas('opportunity', fn (Builder $q) => $q->whereIn('status', array_map(fn (OpportunityStatus $s): string => $s->value, OpportunityStatus::connectedOrLater())))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('agreements')
                ->whereColumn('agreements.interest_id', 'interests.id')
                ->where('agreements.status', '!=', AgreementStatus::Cancelled->value));
    }

    public function assertDraftable(Interest $interest): void
    {
        $interest->loadMissing('opportunity');
        $connected = in_array($interest->status, [InterestStatus::Connected, InterestStatus::Meeting], true);
        if (! $connected || $interest->opportunity === null || ! $interest->opportunity->status->isConnectedOrLater()) {
            throw DomainException::make('agreements.errors.not_connected', 'agreement_not_connected');
        }

        $exists = Agreement::query()->where('interest_id', $interest->id)->where('status', '!=', AgreementStatus::Cancelled->value)->exists();
        if ($exists) {
            throw DomainException::make('agreements.errors.exists', 'agreement_exists');
        }
    }

    /**
     * Placeholders of a template with their values for this connection (defaults, then the staff overrides).
     *
     * @param  array<string, string|null>  $overrides
     * @return list<array{key: string, value: string, auto: bool}>
     */
    public function placeholderValues(Interest $interest, AgreementTemplate $template, array $overrides = [], ?Agreement $agreement = null): array
    {
        $defaults = $this->placeholders->defaults($interest, $agreement);
        $out = [];
        foreach (AgreementPlaceholders::keys($template->body, $template->body_en) as $key) {
            $value = array_key_exists($key, $overrides) && trim((string) $overrides[$key]) !== '' ? trim((string) $overrides[$key]) : ($defaults[$key] ?? '');
            $out[] = ['key' => $key, 'value' => $value, 'auto' => in_array($key, AgreementPlaceholders::AUTO, true)];
        }

        return $out;
    }

    /** @param array<string, string|null> $values */
    public function create(Interest $interest, AgreementTemplate $template, array $values, ?string $title, User $actor): Agreement
    {
        if (! $template->is_active) {
            throw DomainException::make('agreements.errors.template_inactive', 'agreement_template_inactive');
        }
        $this->assertDraftable($interest);

        return DB::transaction(function () use ($interest, $template, $values, $title, $actor): Agreement {
            $interest->loadMissing('opportunity');
            $agreement = Agreement::query()->create([
                'opportunity_id' => $interest->opportunity_id,
                'interest_id' => $interest->id,
                'investor_user_id' => $interest->investor_user_id,
                'template_id' => $template->id,
                'title' => $title !== null && trim($title) !== '' ? trim($title) : $this->defaultTitle($template, $interest),
                'status' => AgreementStatus::Draft,
                'created_by' => $actor->id,
            ]);

            $filled = collect($this->placeholderValues($interest, $template, $values, $agreement))->pluck('value', 'key')->all();
            $this->addVersion(
                $agreement,
                (string) AgreementPlaceholders::fill($template->body, $filled),
                AgreementPlaceholders::fill($template->body_en, $filled),
                __('agreements.version.from_template', ['title' => $template->title, 'version' => $template->version]),
                $actor,
                $filled,
            );

            $this->audit->log('agreement.created', $agreement, null, [
                'number' => $agreement->number(), 'interest_id' => $interest->id, 'template' => $template->key, 'template_version' => $template->version,
            ], $actor);

            return $agreement->refresh();
        });
    }

    /** AGR-02 — a new version of the text; editing an approved / sent agreement returns it to draft (new review). */
    public function revise(Agreement $agreement, string $body, ?string $bodyEn, ?string $note, User $actor): AgreementVersion
    {
        if (! $agreement->status->isEditable()) {
            throw DomainException::make('agreements.errors.not_editable', 'agreement_not_editable');
        }

        $body = self::normalize($body);
        $bodyEn = $bodyEn !== null ? self::normalize($bodyEn) : null;
        $current = $agreement->currentVersion;
        if ($current !== null && hash_equals((string) $current->text_hash, AgreementVersion::hashText($body, $bodyEn))) {
            throw DomainException::make('agreements.errors.no_changes', 'agreement_no_changes', 422, [], ['body' => [__('agreements.errors.no_changes')]]);
        }

        return DB::transaction(function () use ($agreement, $body, $bodyEn, $note, $actor): AgreementVersion {
            $from = $agreement->status;
            $version = $this->addVersion($agreement, $body, $bodyEn, $note, $actor, $agreement->currentVersion?->placeholders);

            if (in_array($from, [AgreementStatus::Approved, AgreementStatus::SentForSignature], true)) {
                $agreement->forceFill(['status' => AgreementStatus::Draft, 'approved_at' => null, 'approved_by' => null, 'sent_for_signature_at' => null])->save();
            }

            $this->audit->log('agreement.version_created', $agreement, ['status' => $from->value], [
                'version' => $version->version, 'text_hash' => $version->text_hash, 'status' => $agreement->status->value, 'note' => $note,
            ], $actor);

            return $version;
        });
    }

    // ---------------------------------------------------------------- AGR-03 legal review

    public function requestReview(Agreement $agreement, User $actor, ?string $note = null): Agreement
    {
        $this->assertTransition($agreement, AgreementStatus::LegalReview);
        if ($agreement->currentVersion === null) {
            throw DomainException::make('agreements.errors.no_version', 'agreement_no_version');
        }

        $agreement = DB::transaction(function () use ($agreement, $actor, $note): Agreement {
            $from = $agreement->status;
            $agreement->forceFill(['status' => AgreementStatus::LegalReview, 'review_requested_at' => now()])->save();
            $this->audit->log('agreement.review_requested', $agreement, ['status' => $from->value], [
                'status' => AgreementStatus::LegalReview->value, 'version' => $agreement->currentVersion->version, 'note' => $note,
            ], $actor);

            return $agreement;
        });

        $this->notify($this->approvers()->reject(fn (User $u): bool => $u->id === $actor->id), $agreement, 'review_requested', $note);

        return $agreement;
    }

    /** @throws AuthorizationException */
    public function review(Agreement $agreement, User $reviewer, AgreementReviewDecision $decision, ?string $note): AgreementReview
    {
        if ($agreement->status !== AgreementStatus::LegalReview) {
            throw DomainException::make('agreements.errors.not_in_review', 'agreement_not_in_review');
        }
        $version = $agreement->currentVersion ?? throw DomainException::make('agreements.errors.no_version', 'agreement_no_version');

        if ($decision === AgreementReviewDecision::Approved) {
            if (! $reviewer->can('agreements.approve')) {
                throw new AuthorizationException(__('agreements.errors.approve_forbidden'));
            }
            // Four-eyes: the author of a version cannot approve it.
            if ($version->proposed_by !== null && $version->proposed_by === $reviewer->id) {
                throw DomainException::make('agreements.errors.self_approval', 'agreement_self_approval', 403);
            }
        }

        $review = DB::transaction(function () use ($agreement, $version, $reviewer, $decision, $note): AgreementReview {
            $review = AgreementReview::query()->create(['version_id' => $version->id, 'reviewer_id' => $reviewer->id, 'decision' => $decision->value, 'note' => $note]);

            $approved = $decision === AgreementReviewDecision::Approved;
            $agreement->forceFill($approved
                ? ['status' => AgreementStatus::Approved, 'approved_at' => now(), 'approved_by' => $reviewer->id]
                : ['status' => AgreementStatus::ChangesRequested])->save();

            $this->audit->log($approved ? 'agreement.approved' : 'agreement.changes_requested', $agreement, ['status' => AgreementStatus::LegalReview->value], [
                'status' => $agreement->status->value, 'version' => $version->version, 'text_hash' => $version->text_hash, 'note' => $note,
            ], $reviewer);

            return $review;
        });

        $recipients = User::query()->whereIn('id', array_filter([$agreement->created_by, $version->proposed_by]))->get()
            ->reject(fn (User $u): bool => $u->id === $reviewer->id);
        $this->notify($recipients, $agreement, $decision === AgreementReviewDecision::Approved ? 'approved' : 'changes_requested', $note);

        return $review;
    }

    // ---------------------------------------------------------------- AGR-05 signature

    public function sendForSignature(Agreement $agreement, User $actor): Agreement
    {
        $this->assertTransition($agreement, AgreementStatus::SentForSignature);
        if (! $agreement->currentVersion?->isApproved()) {
            throw DomainException::make('agreements.errors.not_approved', 'agreement_not_approved');
        }

        DB::transaction(function () use ($agreement, $actor): void {
            $agreement->forceFill(['status' => AgreementStatus::SentForSignature, 'sent_for_signature_at' => now()])->save();
            $this->audit->log('agreement.sent_for_signature', $agreement, ['status' => AgreementStatus::Approved->value], [
                'status' => AgreementStatus::SentForSignature->value, 'version' => $agreement->currentVersion->version, 'text_hash' => $agreement->currentVersion->text_hash,
            ], $actor);
        });

        $this->notify($this->parties($agreement), $agreement, 'sent_for_signature');

        return $agreement;
    }

    /**
     * AGR-05 — a party signs the current version. `$textHash` / `$versionNo` are what the signer was shown: a version
     * changed in the meantime is refused. Returns the recorded signature, or the provider's redirect URL when pending.
     *
     * @return array{signature: AgreementSignature|null, redirect_url: string|null}
     *
     * @throws AuthorizationException
     */
    public function sign(Agreement $agreement, User $signer, int $versionNo, string $textHash, ?string $locale, ?string $ip, ?string $userAgent): array
    {
        $party = $agreement->partyFor($signer) ?? throw new AuthorizationException(__('agreements.errors.not_party'));
        if ($agreement->status !== AgreementStatus::SentForSignature) {
            throw DomainException::make('agreements.errors.not_open_for_signature', 'agreement_not_open_for_signature');
        }

        $version = $agreement->currentVersion ?? throw DomainException::make('agreements.errors.no_version', 'agreement_no_version');
        if ($version->version !== $versionNo || ! hash_equals((string) $version->text_hash, strtolower(trim($textHash)))) {
            throw DomainException::make('agreements.errors.text_changed', 'agreement_text_changed', 409);
        }
        if (isset($agreement->signaturesOn($version)[$party->value])) {
            throw DomainException::make('agreements.errors.already_signed', 'agreement_already_signed');
        }

        $locale = in_array($locale, ['ar', 'en'], true) ? $locale : $signer->preferredLocale();
        $consent = $this->consentText($agreement, $version, $signer, $locale);
        $outcome = $this->signatures->request(new SignatureRequest($agreement, $version, $signer, $party, $consent, $locale, $ip, $userAgent));

        if (! $outcome->completed) {
            $this->audit->log('agreement.signature_requested', $agreement, null, [
                'party' => $party->value, 'version' => $version->version, 'provider' => $this->signatures->key(), 'reference' => $outcome->reference,
            ], $signer);

            return ['signature' => null, 'redirect_url' => $outcome->redirectUrl];
        }

        return ['signature' => $this->recordSignature($agreement, $version, $signer, $party, $this->signatures->key(), $outcome->reference, $consent, $locale, $ip, $userAgent, $outcome->signedAt), 'redirect_url' => null];
    }

    /** The consent statement shown next to the checkbox and stored with the signature. */
    public function consentText(Agreement $agreement, AgreementVersion $version, User $signer, string $locale): string
    {
        return (string) __('agreements.sign.consent', ['name' => $signer->name, 'number' => $agreement->number(), 'version' => $version->version], $locale);
    }

    /**
     * Writes the immutable evidence row (also the entry point for a licensed provider's callback). When both parties
     * have signed the version, the agreement becomes signed (read-only) and everyone is notified.
     */
    public function recordSignature(
        Agreement $agreement,
        AgreementVersion $version,
        User $signer,
        AgreementParty $party,
        string $provider,
        ?string $reference,
        string $consentText,
        string $locale,
        ?string $ip,
        ?string $userAgent,
        ?CarbonInterface $signedAt = null,
    ): AgreementSignature {
        [$signature, $completed] = DB::transaction(function () use ($agreement, $version, $signer, $party, $provider, $reference, $consentText, $locale, $ip, $userAgent, $signedAt): array {
            $locked = Agreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== AgreementStatus::SentForSignature || $locked->current_version_id !== $version->id) {
                throw DomainException::make('agreements.errors.text_changed', 'agreement_text_changed', 409);
            }
            if (AgreementSignature::query()->where('version_id', $version->id)->where('party', $party->value)->exists()) {
                throw DomainException::make('agreements.errors.already_signed', 'agreement_already_signed');
            }

            $signature = AgreementSignature::query()->create([
                'agreement_id' => $agreement->id,
                'version_id' => $version->id,
                'user_id' => $signer->id,
                'party' => $party,
                'signer_name' => $signer->name,
                'signer_email' => $signer->email,
                'provider' => $provider,
                'provider_reference' => $reference,
                'text_hash' => $version->text_hash,
                'consent_text' => $consentText,
                'locale' => $locale,
                'ip' => $ip,
                'user_agent' => $userAgent !== null ? substr($userAgent, 0, 1000) : null,
                'signed_at' => $signedAt ?? now(),
            ]);

            $this->audit->log('agreement.signed_by_party', $agreement, null, [
                'party' => $party->value, 'version' => $version->version, 'text_hash' => $version->text_hash, 'provider' => $provider, 'signature_id' => $signature->id,
            ], $signer);

            $agreement->unsetRelation('signatures');
            $completed = $agreement->isFullySignedOn($version);
            if ($completed) {
                $agreement->forceFill(['status' => AgreementStatus::Signed, 'signed_at' => now(), 'signature_method' => 'electronic', 'signature_provider' => $provider])->save();
                $this->audit->log('agreement.signed', $agreement, ['status' => AgreementStatus::SentForSignature->value], [
                    'status' => AgreementStatus::Signed->value, 'version' => $version->version, 'text_hash' => $version->text_hash, 'method' => 'electronic', 'provider' => $provider,
                ], $signer);
            }

            return [$signature, $completed];
        });

        if ($completed) {
            $agreement->refresh();
            $this->notify($this->parties($agreement)->merge($this->creator($agreement)), $agreement, 'signed');
        }

        return $signature;
    }

    /** AGR-05 alternative — a wet-signed PDF of the approved version, through the secure document pipeline. */
    public function uploadWetSigned(Agreement $agreement, UploadedFile $file, User $actor): Agreement
    {
        if (! in_array($agreement->status, [AgreementStatus::Approved, AgreementStatus::SentForSignature], true)) {
            throw DomainException::make('agreements.errors.not_open_for_signature', 'agreement_not_open_for_signature');
        }
        $version = $agreement->currentVersion;
        if (! $version?->isApproved()) {
            throw DomainException::make('agreements.errors.not_approved', 'agreement_not_approved');
        }

        $document = $this->documents->upload($file, $actor, $agreement, DocumentKind::Agreement, __('agreements.wet.document_title', ['number' => $agreement->number(), 'version' => $version->version]), Sensitivity::Confidential);

        DB::transaction(function () use ($agreement, $version, $document, $actor): void {
            $from = $agreement->status;
            $agreement->forceFill([
                'status' => AgreementStatus::Signed, 'signed_at' => now(), 'signed_document_id' => $document->id, 'signature_method' => 'wet', 'signature_provider' => null,
            ])->save();
            $this->audit->log('agreement.signed', $agreement, ['status' => $from->value], [
                'status' => AgreementStatus::Signed->value, 'version' => $version->version, 'text_hash' => $version->text_hash, 'method' => 'wet',
                'document_uuid' => $document->uuid, 'sha256' => $document->sha256,
            ], $actor);
        });

        $this->notify($this->parties($agreement)->merge($this->creator($agreement))->reject(fn (User $u): bool => $u->id === $actor->id), $agreement, 'signed');

        return $agreement;
    }

    // ---------------------------------------------------------------- AGR-04 archive / cancel

    public function archive(Agreement $agreement, User $actor): Agreement
    {
        $this->assertTransition($agreement, AgreementStatus::Archived);
        $agreement->forceFill(['status' => AgreementStatus::Archived, 'archived_at' => now()])->save();
        $this->audit->log('agreement.archived', $agreement, ['status' => AgreementStatus::Signed->value], ['status' => AgreementStatus::Archived->value], $actor);

        return $agreement;
    }

    public function cancel(Agreement $agreement, User $actor, string $reason): Agreement
    {
        $this->assertTransition($agreement, AgreementStatus::Cancelled);
        $from = $agreement->status;
        $agreement->forceFill(['status' => AgreementStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
        $this->audit->log('agreement.cancelled', $agreement, ['status' => $from->value], ['status' => AgreementStatus::Cancelled->value, 'reason' => $reason], $actor);

        return $agreement;
    }

    /**
     * Staff actions available now (drives the admin UI; the endpoints re-check everything).
     *
     * @return list<string>
     */
    public function allowedActions(Agreement $agreement, User $user): array
    {
        $manage = $user->can('agreements.manage');
        $approve = $user->can('agreements.approve');
        $status = $agreement->status;
        $actions = [];

        if ($manage && $status->isEditable()) {
            $actions[] = 'edit';
        }
        if ($manage && $status->canTransitionTo(AgreementStatus::LegalReview)) {
            $actions[] = 'submit_review';
        }
        if ($status === AgreementStatus::LegalReview) {
            $author = $agreement->currentVersion?->proposed_by;
            if ($approve && ($author === null || $author !== $user->id)) {
                $actions[] = 'approve';
            }
            if ($manage || $approve) {
                $actions[] = 'request_changes';
            }
        }
        if ($manage && $status === AgreementStatus::Approved) {
            $actions[] = 'send_for_signature';
        }
        if ($manage && in_array($status, [AgreementStatus::Approved, AgreementStatus::SentForSignature], true)) {
            $actions[] = 'upload_signed';
        }
        if ($manage && $status === AgreementStatus::Signed) {
            $actions[] = 'archive';
        }
        if ($manage && $status->canTransitionTo(AgreementStatus::Cancelled)) {
            $actions[] = 'cancel';
        }

        return $actions;
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, string>|null $placeholders */
    private function addVersion(Agreement $agreement, string $body, ?string $bodyEn, ?string $note, User $actor, ?array $placeholders = null): AgreementVersion
    {
        $body = self::normalize($body);
        $bodyEn = $bodyEn !== null && trim($bodyEn) !== '' ? self::normalize($bodyEn) : null;
        $next = ((int) $agreement->versions()->max('version')) + 1;

        $version = AgreementVersion::query()->create([
            'agreement_id' => $agreement->id,
            'version' => $next,
            'body' => $body,
            'body_en' => $bodyEn,
            'placeholders' => $placeholders,
            'change_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'proposed_by' => $actor->id,
            'created_at' => now(),
        ]);

        $agreement->forceFill(['current_version_id' => $version->id])->save();
        $agreement->setRelation('currentVersion', $version);

        return $version;
    }

    /** Line endings normalised to "\n" and outer whitespace trimmed, so the hash covers exactly what is shown. */
    public static function normalize(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    private function assertTransition(Agreement $agreement, AgreementStatus $to): void
    {
        if (! $agreement->status->canTransitionTo($to)) {
            throw DomainException::make('agreements.errors.invalid_transition', 'agreement_invalid_transition', 422, [
                'from' => $agreement->status->label(), 'to' => $to->label(),
            ]);
        }
    }

    private function defaultTitle(AgreementTemplate $template, Interest $interest): string
    {
        return trim($template->title.' — '.($interest->opportunity?->name ?? ''), ' —');
    }

    /** @return Collection<int, User> */
    private function approvers(): Collection
    {
        return User::query()->staff()->active()->permission('agreements.approve')->get();
    }

    /** @return Collection<int, User> */
    private function parties(Agreement $agreement): Collection
    {
        $agreement->loadMissing(['opportunity.owner', 'investor']);

        return collect([$agreement->opportunity?->owner, $agreement->investor])->filter()->values();
    }

    /** @return Collection<int, User> */
    private function creator(Agreement $agreement): Collection
    {
        return User::query()->whereKey($agreement->created_by)->get();
    }

    /** @param Collection<int, User> $users */
    private function notify(Collection $users, Agreement $agreement, string $event, ?string $note = null): void
    {
        foreach ($users->unique('id') as $user) {
            $this->notifications->send($user, new AgreementNotification($agreement, $event, $note));
        }
    }
}
