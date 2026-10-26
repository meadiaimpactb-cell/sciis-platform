<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Investor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Investor\InvestorNoteRequest;
use App\Models\InvestorNote;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OPP-09 — the investor's private notes on an opportunity. Strictly personal: only the author lists, edits or deletes
 * them (anyone else gets 404), no staff/owner endpoint exposes them, and the audit trail never stores the text.
 */
class InvestorNoteController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /opportunities/{uuid}/notes — my notes, newest first. */
    public function index(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('interact', $opportunity);
        $notes = InvestorNote::query()->where('user_id', $request->user()->id)->where('opportunity_id', $opportunity->id)->orderByDesc('id')->get();

        return response()->json(['data' => $notes->map(fn (InvestorNote $n): array => $this->row($n))->values()->all()]);
    }

    /** POST /opportunities/{uuid}/notes {body, tags?} */
    public function store(InvestorNoteRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('interact', $opportunity);
        $note = InvestorNote::query()->create([
            'user_id' => $request->user()->id,
            'opportunity_id' => $opportunity->id,
            'body' => trim((string) $request->validated('body')),
            'tags' => $this->tags($request->validated('tags')),
        ]);
        $this->audit->log('investor_note.created', $note, null, ['opportunity_id' => $opportunity->id], $request->user());

        return response()->json(['data' => $this->row($note), 'message' => __('messages.discovery.note_saved')], 201);
    }

    /** PATCH /investor/notes/{id} {body, tags?} */
    public function update(InvestorNoteRequest $request, InvestorNote $note): JsonResponse
    {
        $this->ensureOwned($request->user(), $note);
        $note->fill(['body' => trim((string) $request->validated('body'))]);
        if ($request->has('tags')) {
            $note->tags = $this->tags($request->validated('tags'));
        }
        $note->save();
        $this->audit->log('investor_note.updated', $note, null, ['opportunity_id' => $note->opportunity_id], $request->user());

        return $this->ok($this->row($note));
    }

    /** DELETE /investor/notes/{id} */
    public function destroy(Request $request, InvestorNote $note): JsonResponse
    {
        $this->ensureOwned($request->user(), $note);
        $this->audit->log('investor_note.deleted', $note, null, ['opportunity_id' => $note->opportunity_id], $request->user());
        $note->delete();

        return $this->message(__('messages.discovery.note_deleted'));
    }

    /** @return array<string, mixed> */
    private function row(InvestorNote $note): array
    {
        return [
            'id' => $note->id,
            'body' => $note->body,
            'tags' => array_values((array) ($note->tags ?? [])),
            'created_at' => $note->created_at?->toIso8601String(),
            'updated_at' => $note->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<int, string>|null */
    private function tags(mixed $tags): ?array
    {
        $clean = array_values(array_unique(array_filter(array_map(fn ($t): string => is_scalar($t) ? trim((string) $t) : '', (array) $tags))));

        return $clean === [] ? null : $clean;
    }

    private function ensureOwned(User $user, InvestorNote $note): void
    {
        abort_unless((int) $note->user_id === (int) $user->id, 404);
    }
}
