<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CompletionRequest;
use App\Models\Document;
use App\Models\InternalNote;
use App\Models\Meeting;
use App\Models\Nomination;
use App\Models\Opportunity;
use App\Models\OpportunityStatusTransition;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * ADM-06 — the staff file timeline: status transitions merged with document uploads / reviews, internal notes,
 * completion requests, meetings and nominations as typed events, oldest first. Staff only (internal notes,
 * investor identities and reviewer names are in it) — never exposed to the owner.
 *
 * Event: {id, type, at, actor: {id, name}|null, data: {...}} — `type` ∈ self::TYPES.
 */
class OpportunityTimeline
{
    public const TYPES = [
        'status', 'document_uploaded', 'document_reviewed', 'note', 'completion_requested', 'completion_resolved',
        'meeting_scheduled', 'nomination_sent', 'nomination_responded',
    ];

    /** @return array<int, array<string, mixed>> */
    public function events(Opportunity $opportunity): array
    {
        $events = [];

        foreach ($opportunity->transitions()->with('actor')->get() as $t) {
            /** @var OpportunityStatusTransition $t */
            $events[] = $this->event('status', $t->id, $t->created_at, $t->actor, [
                'from' => $t->from_status?->value,
                'to' => $t->to_status->value,
                'to_label' => $t->to_status->label(),
                'reason' => $t->reason,
            ]);
        }

        $documents = $opportunity->documents()->with('owner')->get();
        foreach ($documents as $d) {
            /** @var Document $d */
            $events[] = $this->event('document_uploaded', $d->id, $d->created_at, $d->owner, [
                'uuid' => $d->uuid,
                'title' => $d->title,
                'kind' => $d->kind->value,
                'kind_label' => $d->kind->label(),
                'version' => $d->version,
            ]);
        }

        if ($documents->isNotEmpty()) {
            $byId = $documents->keyBy('id');
            $reviews = AuditLog::query()->with('user')
                ->where('action', 'document.reviewed')
                ->where('auditable_type', (new Document)->getMorphClass())
                ->whereIn('auditable_id', $byId->keys()->all())
                ->orderBy('id')
                ->get();
            foreach ($reviews as $log) {
                $doc = $byId->get((int) $log->auditable_id);
                $events[] = $this->event('document_reviewed', $log->id, $log->created_at, $log->user, [
                    'uuid' => $doc?->uuid,
                    'title' => $doc?->title,
                    'kind' => $doc?->kind->value,
                    'kind_label' => $doc?->kind->label(),
                    'status' => $log->after['status'] ?? null,
                    'note' => $log->after['note'] ?? null,
                ]);
            }
        }

        foreach ($opportunity->internalNotes()->with('author')->get() as $n) {
            /** @var InternalNote $n */
            $events[] = $this->event('note', $n->id, $n->created_at, $n->author, ['body' => Str::limit((string) $n->body, 500)]);
        }

        foreach ($opportunity->completionRequests()->with('requester')->get() as $c) {
            /** @var CompletionRequest $c */
            $items = array_values(array_filter((array) $c->items, 'is_array'));
            $events[] = $this->event('completion_requested', $c->id, $c->created_at, $c->requester, [
                'items' => array_map(fn (array $i): array => ['field' => $i['field'] ?? null, 'document_kind' => $i['document_kind'] ?? null, 'note' => $i['note'] ?? null], $items),
                'items_count' => count($items),
                'due_at' => $c->due_at?->toIso8601String(),
            ]);
            if ($c->resolved_at !== null) {
                $events[] = $this->event('completion_resolved', $c->id, $c->resolved_at, null, ['items_count' => count($items)]);
            }
        }

        foreach ($opportunity->meetings()->with('organizer')->get() as $m) {
            /** @var Meeting $m */
            $events[] = $this->event('meeting_scheduled', $m->id, $m->created_at, $m->organizer, [
                'title' => $m->title,
                'starts_at' => $m->starts_at?->toIso8601String(),
                'status' => $m->status?->value,
                'status_label' => $m->status?->label(),
            ]);
        }

        foreach ($opportunity->nominations()->with(['investor', 'nominator'])->get() as $nom) {
            /** @var Nomination $nom */
            $investor = $nom->investor ? ['id' => $nom->investor->id, 'name' => $nom->investor->name] : null;
            $events[] = $this->event('nomination_sent', $nom->id, $nom->sent_at ?? $nom->created_at, $nom->nominator, [
                'investor' => $investor,
                'match_score' => $nom->match_score,
                'rationale' => $nom->rationale,
            ]);
            if ($nom->responded_at !== null && $nom->response !== null) {
                $events[] = $this->event('nomination_responded', $nom->id, $nom->responded_at, $nom->investor, [
                    'investor' => $investor,
                    'response' => $nom->response->value,
                    'response_label' => $nom->response->label(),
                ]);
            }
        }

        // Chronological; ties keep the declaration order of TYPES (a transition before the note written with it).
        $order = array_flip(self::TYPES);
        usort($events, fn (array $a, array $b): int => [$a['ts'], $order[$a['type']], $a['seq']] <=> [$b['ts'], $order[$b['type']], $b['seq']]);

        return array_map(function (array $e): array {
            unset($e['ts'], $e['seq']);

            return $e;
        }, $events);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function event(string $type, int $id, ?Carbon $at, ?User $actor, array $data): array
    {
        return [
            'id' => $type.'-'.$id,
            'type' => $type,
            'at' => $at?->toIso8601String(),
            'actor' => $actor ? ['id' => $actor->id, 'name' => $actor->name] : null,
            'data' => $data,
            'ts' => $at?->getTimestamp() ?? 0,
            'seq' => $id,
        ];
    }
}
