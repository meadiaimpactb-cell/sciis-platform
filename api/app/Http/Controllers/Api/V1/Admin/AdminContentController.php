<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CmsContentRequest;
use App\Http\Resources\CmsContentResource;
use App\Models\ContactMessage;
use App\Models\Event;
use App\Models\Faq;
use App\Models\NewsPost;
use App\Models\Page;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** CMS-02..05 — pages / faqs / news / events + contact inbox. */
class AdminContentController extends Controller
{
    private const TYPES = ['pages' => Page::class, 'faqs' => Faq::class, 'news' => NewsPost::class, 'events' => Event::class];

    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /admin/content/{type} */
    public function index(Request $request, string $type): JsonResponse
    {
        $this->assertCan($request);
        $model = $this->model($type);

        $paginator = $model::query()->orderBy('sort')->orderByDesc('id')->paginate($this->perPage($request));

        return response()->json([
            'data' => CmsContentResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }

    /** POST /admin/content/{type} */
    public function store(CmsContentRequest $request, string $type): JsonResponse
    {
        $this->assertCan($request);
        $model = $this->model($type);

        $item = $model::query()->create($this->payload($type, $request->validated()) + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        $this->audit->log('content.created', $item, null, ['type' => $type, 'slug' => $item->slug], $request->user());

        return response()->json(['data' => (new CmsContentResource($item))->resolve($request)], 201);
    }

    /** PATCH /admin/content/{type}/{id} */
    public function update(CmsContentRequest $request, string $type, int $id): JsonResponse
    {
        $this->assertCan($request);
        $model = $this->model($type);
        /** @var Model $item */
        $item = $model::query()->findOrFail($id);

        $before = $item->toArray();
        $item->fill($this->payload($type, $request->validated()) + ['updated_by' => $request->user()->id])->save();
        $this->audit->log('content.updated', $item, $before, $item->getChanges(), $request->user());

        return $this->ok((new CmsContentResource($item))->resolve($request));
    }

    /** GET /admin/contact-messages */
    public function contactMessages(Request $request): JsonResponse
    {
        $this->assertCan($request);

        $paginator = ContactMessage::query()
            ->with('assignee')
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when($request->filled('category'), fn (Builder $q) => $q->where('category', $request->input('category')))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (ContactMessage $m): array => [
                'id' => $m->id, 'reference_no' => $m->reference_no, 'category' => $m->category->value, 'category_label' => $m->category->label(),
                'name' => $m->name, 'email' => $m->email, 'phone' => $m->phone, 'message' => $m->message, 'status' => $m->status,
                'assigned_to' => $m->assignee?->name, 'created_at' => $m->created_at?->toIso8601String(),
            ])->all(),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }

    /** PATCH /admin/contact-messages/{id} {status, assigned_to} */
    public function updateContactMessage(Request $request, ContactMessage $contactMessage): JsonResponse
    {
        $this->assertCan($request);
        $data = $request->validate(['status' => ['sometimes', 'in:new,in_progress,closed'], 'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id']]);
        $contactMessage->fill($data)->save();
        $this->audit->log('contact.updated', $contactMessage, null, $data, $request->user());

        return $this->message(__('messages.saved'));
    }

    /** @return class-string<Model> */
    private function model(string $type): string
    {
        return self::TYPES[$type] ?? throw new NotFoundHttpException;
    }

    /** Drops fields that do not belong to this content type. @param array<string, mixed> $data @return array<string, mixed> */
    private function payload(string $type, array $data): array
    {
        if ($type !== 'events') {
            unset($data['starts_at'], $data['ends_at'], $data['location'], $data['registration_url']);
        }
        if ($type !== 'faqs') {
            unset($data['category']);
        }

        return $data;
    }

    private function assertCan(Request $request): void
    {
        if (! $request->user()->can('settings.manage')) {
            abort(403, __('messages.errors.forbidden'));
        }
    }
}
