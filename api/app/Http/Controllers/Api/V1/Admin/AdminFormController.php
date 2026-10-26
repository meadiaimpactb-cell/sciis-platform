<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\FormSchema;
use App\Models\Membership;
use App\Rules\FormAnswers;
use App\Services\AuditLogger;
use App\Services\FormSchemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FRM-01 — "additional questions" builder (settings.manage) and the membership review answers (memberships.review).
 *   GET  /admin/forms                                → one summary row per form key
 *   GET  /admin/forms/{key}                          → active version (all questions) + version history
 *   POST /admin/forms/{key}/publish {questions}      → new version (previous one deactivated)
 *   PUT  /admin/memberships/{id}/review-answers {extra_answers} → reviewer answers on the membership file
 */
class AdminFormController extends Controller
{
    public function __construct(private readonly FormSchemaService $forms, private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertCanManage($request);

        return $this->ok(['forms' => $this->forms->summary()]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $this->assertKey($key);
        // Membership reviewers read the review questions they answer; everything else is the builder's.
        if (! ($key === 'membership_review' && $request->user()->can('memberships.review'))) {
            $this->assertCanManage($request);
        }

        $active = $this->forms->active($key);
        $versions = FormSchema::query()->where('key', $key)->with('publisher:id,name')->orderByDesc('version')->limit(20)->get()
            ->map(fn (FormSchema $s): array => [
                'version' => $s->version,
                'is_active' => $s->is_active,
                'questions_count' => count(FormSchemaService::allQuestions($s)),
                'published_at' => $s->created_at?->toIso8601String(),
                'published_by' => $s->publisher?->name,
            ])->values()->all();

        return $this->ok([
            'key' => $key,
            'version' => (int) ($active?->version ?? 0),
            'questions' => FormSchemaService::allQuestions($active),
            'published_at' => $active?->created_at?->toIso8601String(),
            'versions' => $versions,
            'limits' => [
                'max_questions' => FormSchemaService::MAX_QUESTIONS,
                'max_options' => FormSchemaService::MAX_OPTIONS,
                'short_text_max' => FormSchemaService::SHORT_TEXT_MAX,
                'long_text_max' => FormSchemaService::LONG_TEXT_MAX,
            ],
        ]);
    }

    public function publish(Request $request, string $key): JsonResponse
    {
        $this->assertKey($key);
        $this->assertCanManage($request);

        $this->forms->publish($key, (array) $request->input('questions', []), $request->user());

        return $this->show($request, $key)->setStatusCode(201);
    }

    public function membershipReview(Request $request, Membership $membership): JsonResponse
    {
        $this->authorize('review', $membership);
        $request->validate(['extra_answers' => ['present', 'array', new FormAnswers('membership_review')]]);

        $before = $membership->extra_answers;
        $membership->forceFill(['extra_answers' => $this->forms->record('membership_review', (array) $request->input('extra_answers', []), $before)])->save();
        $this->audit->log('membership.review_answers_updated', $membership, ['extra_answers' => $before], ['extra_answers' => $membership->extra_answers], $request->user());

        return $this->ok((array) $this->forms->present($membership->extra_answers));
    }

    private function assertCanManage(Request $request): void
    {
        if (! $request->user()->can('settings.manage')) {
            abort(403, __('messages.errors.forbidden'));
        }
    }

    private function assertKey(string $key): void
    {
        if (! FormSchemaService::isKey($key)) {
            throw new NotFoundHttpException;
        }
    }
}
