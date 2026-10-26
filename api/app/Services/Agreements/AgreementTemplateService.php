<?php

declare(strict_types=1);

namespace App\Services\Agreements;

use App\Exceptions\DomainException;
use App\Models\AgreementTemplate;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * AGR-01 — agreement templates (Arabic + English, placeholders). A template is a family of versions sharing a `key`:
 * editing always writes a new, inactive version; activating a version deactivates the others of the same key.
 */
class AgreementTemplateService
{
    private const FIELDS = ['title', 'title_en', 'body', 'body_en', 'description', 'change_note'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): AgreementTemplate
    {
        $key = (string) $data['key'];
        if (AgreementTemplate::query()->where('key', $key)->exists()) {
            $message = __('agreements.errors.template_key_taken');
            throw new DomainException($message, 'agreement_template_key_taken', 422, ['key' => [$message]]);
        }

        $template = AgreementTemplate::query()->create(['key' => $key, 'version' => 1, 'is_active' => false, 'created_by' => $actor->id] + $this->payload($data));
        $this->audit->log('agreement_template.created', $template, null, ['key' => $key, 'version' => 1], $actor);

        return $template;
    }

    /** @param array<string, mixed> $data */
    public function revise(AgreementTemplate $template, array $data, User $actor): AgreementTemplate
    {
        return DB::transaction(function () use ($template, $data, $actor): AgreementTemplate {
            $next = ((int) AgreementTemplate::query()->where('key', $template->key)->lockForUpdate()->max('version')) + 1;
            $version = AgreementTemplate::query()->create(['key' => $template->key, 'version' => $next, 'is_active' => false, 'created_by' => $actor->id] + $this->payload($data));
            $this->audit->log('agreement_template.version_created', $version, null, ['key' => $template->key, 'version' => $next, 'from_version' => $template->version], $actor);

            return $version;
        });
    }

    public function activate(AgreementTemplate $template, User $actor): AgreementTemplate
    {
        return DB::transaction(function () use ($template, $actor): AgreementTemplate {
            AgreementTemplate::query()->where('key', $template->key)->whereKeyNot($template->id)->where('is_active', true)->update(['is_active' => false]);
            $template->forceFill(['is_active' => true, 'legal_approved_at' => $template->legal_approved_at ?? now()])->save();
            $this->audit->log('agreement_template.activated', $template, null, ['key' => $template->key, 'version' => $template->version], $actor);

            return $template;
        });
    }

    public function deactivate(AgreementTemplate $template, User $actor): AgreementTemplate
    {
        $template->forceFill(['is_active' => false])->save();
        $this->audit->log('agreement_template.deactivated', $template, null, ['key' => $template->key, 'version' => $template->version], $actor);

        return $template;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function payload(array $data): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = $data[$field] ?? null;
            $value = is_string($value) ? AgreementService::normalize($value) : null;
            $out[$field] = $value === '' ? null : $value;
        }
        $out['title'] = (string) $out['title'];
        $out['body'] = (string) $out['body'];

        return $out;
    }
}
