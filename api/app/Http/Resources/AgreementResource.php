<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AgreementParty;
use App\Models\Agreement;
use App\Models\AgreementSignature;
use App\Models\AgreementVersion;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Agreements\AgreementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AGR-02..05 — agreement payloads.
 *  - list:  one row of the staff list / the party list;
 *  - staff: full file (every version with its reviews, signature evidence, allowed actions, audit trail);
 *  - party: what the owner / investor may see (AGR-04): approved versions only, no internal review notes or evidence
 *           of the other party beyond name and time.
 *
 * @mixin Agreement
 */
class AgreementResource extends JsonResource
{
    private string $mode = 'list';

    public static function listItem(Agreement $agreement): self
    {
        return new self($agreement);
    }

    public static function staff(Agreement $agreement): self
    {
        $r = new self($agreement);
        $r->mode = 'staff';

        return $r;
    }

    public static function party(Agreement $agreement): self
    {
        $r = new self($agreement);
        $r->mode = 'party';

        return $r;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Agreement $a */
        $a = $this->resource;
        $a->loadMissing(['opportunity.owner', 'investor', 'currentVersion', 'template']);

        $base = [
            'uuid' => $a->uuid,
            'number' => $a->number(),
            'title' => $a->title,
            'status' => $a->status->value,
            'opportunity' => $a->opportunity ? ['uuid' => $a->opportunity->uuid, 'name' => $a->opportunity->name, 'reference_no' => $a->opportunity->reference_no, 'status' => $a->opportunity->status->value] : null,
            'owner' => self::person($a->opportunity?->owner),
            'investor' => self::person($a->investor),
            'current_version' => $a->currentVersion ? ['version' => $a->currentVersion->version, 'created_at' => $a->currentVersion->created_at?->toIso8601String()] : null,
            'signature_method' => $a->signature_method,
            'sent_for_signature_at' => $a->sent_for_signature_at?->toIso8601String(),
            'signed_at' => $a->signed_at?->toIso8601String(),
            'archived_at' => $a->archived_at?->toIso8601String(),
            'cancelled_at' => $a->cancelled_at?->toIso8601String(),
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ];

        return match ($this->mode) {
            'staff' => $base + $this->staffDetail($a, $request),
            'party' => $base + $this->partyDetail($a, $request),
            default => $base + ['template' => $a->template ? ['key' => $a->template->key, 'title' => $a->template->title, 'version' => $a->template->version] : null],
        };
    }

    /** @return array<string, mixed> */
    private function staffDetail(Agreement $a, Request $request): array
    {
        $a->loadMissing(['versions.reviews.reviewer', 'versions.proposer', 'signatures', 'signedDocument', 'creator', 'approver', 'interest']);
        $user = $request->user();
        $current = $a->currentVersion;
        $signed = $a->signaturesOn($current);

        return [
            'interest' => $a->interest ? ['id' => $a->interest->id, 'status' => $a->interest->status->value] : null,
            'template' => $a->template ? ['id' => $a->template->id, 'key' => $a->template->key, 'title' => $a->template->title, 'version' => $a->template->version] : null,
            'versions' => $a->versions->sortByDesc('version')->values()->map(fn (AgreementVersion $v): array => self::version($v, true))->all(),
            'reviews' => $a->versions->flatMap(fn (AgreementVersion $v) => $v->reviews->map(fn ($r): array => [
                'id' => $r->id, 'version' => $v->version, 'decision' => $r->decision, 'note' => $r->note,
                'reviewer' => $r->reviewer ? ['id' => $r->reviewer->id, 'name' => $r->reviewer->name] : null,
                'created_at' => $r->created_at?->toIso8601String(),
            ]))->sortByDesc('id')->values()->all(),
            'signatures' => $a->signatures->sortByDesc('id')->values()->map(fn (AgreementSignature $s): array => self::signature($s, $a, true))->all(),
            'parties_status' => self::partiesStatus($signed),
            'signature_provider' => $a->signature_provider,
            'signed_document' => $a->signedDocument ? self::document($a) : null,
            'review_requested_at' => $a->review_requested_at?->toIso8601String(),
            'approved_at' => $a->approved_at?->toIso8601String(),
            'approved_by' => $a->approver ? ['id' => $a->approver->id, 'name' => $a->approver->name] : null,
            'cancel_reason' => $a->cancel_reason,
            'created_by' => $a->creator ? ['id' => $a->creator->id, 'name' => $a->creator->name] : null,
            'allowed_actions' => $user instanceof User ? app(AgreementService::class)->allowedActions($a, $user) : [],
            'audit' => AuditLog::query()->with('user')
                ->where('auditable_type', $a->getMorphClass())->where('auditable_id', $a->id)
                ->orderByDesc('id')->limit(200)->get()
                ->map(fn (AuditLog $l): array => [
                    'id' => $l->id, 'action' => $l->action, 'user' => $l->user ? ['name' => $l->user->name] : null,
                    'after' => array_intersect_key((array) $l->after, array_flip(['status', 'version', 'party', 'note', 'reason', 'method', 'provider', 'text_hash', 'template', 'number'])),
                    'created_at' => $l->created_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function partyDetail(Agreement $a, Request $request): array
    {
        $a->loadMissing(['versions.reviews', 'signatures', 'signedDocument']);
        $user = $request->user();
        $party = $user instanceof User ? $a->partyFor($user) : null;

        $approved = $a->versions->filter(fn (AgreementVersion $v): bool => $v->isApproved())->sortByDesc('version')->values();
        // While it is sent / signed the current version is the approved one; during a revision the last approved stays visible.
        $shown = $a->currentVersion && $a->currentVersion->isApproved() ? $a->currentVersion : $approved->first();
        $signed = $shown ? $a->signaturesOn($shown) : [];
        $canSign = $party !== null && $shown !== null && $a->status->value === 'sent_for_signature'
            && $shown->id === $a->current_version_id && ! isset($signed[$party->value]);

        return [
            'my_party' => $party?->value,
            'version' => $shown ? self::version($shown, false) : null,
            'versions' => $approved->map(fn (AgreementVersion $v): array => self::version($v, false) + ['is_shown' => $v->id === $shown?->id])->all(),
            'signatures' => collect($signed)->values()->map(fn (AgreementSignature $s): array => self::signature($s, $a, $party !== null && $s->party === $party))->all(),
            'parties_status' => self::partiesStatus($signed),
            'can_sign' => $canSign,
            'consent_text' => $canSign && $user instanceof User ? app(AgreementService::class)->consentText($a, $shown, $user, app()->getLocale() === 'en' ? 'en' : 'ar') : null,
            'signed_document' => $a->signedDocument ? self::document($a) : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function version(AgreementVersion $v, bool $staff): array
    {
        $review = $v->latestReview();
        $out = [
            'id' => $v->id,
            'version' => $v->version,
            'body' => $v->body,
            'body_en' => $v->body_en,
            'text_hash' => $v->text_hash,
            'is_approved' => $review?->decision === 'approved',
            'approved_at' => $review?->decision === 'approved' ? $review->created_at?->toIso8601String() : null,
            'created_at' => $v->created_at?->toIso8601String(),
        ];

        if ($staff) {
            $out += [
                'change_note' => $v->change_note,
                'placeholders' => $v->placeholders ?? [],
                'proposed_by' => $v->proposer ? ['id' => $v->proposer->id, 'name' => $v->proposer->name] : null,
                'review' => $review ? ['decision' => $review->decision, 'note' => $review->note, 'reviewer' => $review->reviewer?->name, 'created_at' => $review->created_at?->toIso8601String()] : null,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function signature(AgreementSignature $s, Agreement $a, bool $evidence): array
    {
        $version = $a->versions->firstWhere('id', $s->version_id);
        $out = [
            'id' => $s->id,
            'party' => $s->party->value,
            'version' => $version?->version,
            'signer_name' => $s->signer_name,
            'signed_at' => $s->signed_at?->toIso8601String(),
            'provider' => $s->provider,
            'text_hash' => $s->text_hash,
        ];

        if ($evidence) {
            $out += ['ip' => $s->ip, 'user_agent' => $s->user_agent, 'consent_text' => $s->consent_text, 'locale' => $s->locale, 'provider_reference' => $s->provider_reference, 'signer_email' => $s->signer_email];
        }

        return $out;
    }

    /**
     * @param  array<string, AgreementSignature>  $signed
     * @return array<string, array{signed: bool, signed_at: string|null, signer_name: string|null}>
     */
    private static function partiesStatus(array $signed): array
    {
        $out = [];
        foreach (AgreementParty::cases() as $party) {
            $s = $signed[$party->value] ?? null;
            $out[$party->value] = ['signed' => $s !== null, 'signed_at' => $s?->signed_at?->toIso8601String(), 'signer_name' => $s?->signer_name];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function document(Agreement $a): array
    {
        $d = $a->signedDocument;

        return ['uuid' => $d->uuid, 'title' => $d->title, 'size_bytes' => $d->size_bytes, 'sha256' => $d->sha256, 'downloadable' => $d->isDownloadable(), 'uploaded_at' => $d->created_at?->toIso8601String()];
    }

    /** @return array{id: int, name: string}|null */
    private static function person(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }
}
