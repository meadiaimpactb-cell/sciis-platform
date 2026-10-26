<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CmsContentRequest;
use App\Http\Resources\CmsContentResource;
use App\Models\ContactMessage;
use App\Models\Event;
use App\Models\Faq;
use App\Models\KnowledgeResource;
use App\Models\NewsPost;
use App\Models\Page;
use App\Models\Partner;
use App\Models\Story;
use App\Services\AuditLogger;
use App\Support\WebpEncoder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CMS-02..05 — pages / faqs / news / events + contact inbox + media uploads (BRD-03),
 * plus the growth content types: impact stories, partners and knowledge resources.
 */
class AdminContentController extends Controller
{
    private const TYPES = [
        'pages' => Page::class, 'faqs' => Faq::class, 'news' => NewsPost::class, 'events' => Event::class,
        'stories' => Story::class, 'partners' => Partner::class, 'resources' => KnowledgeResource::class,
    ];

    /** Slug prefix used when neither `slug` nor `title_en` is given (#32). */
    private const SLUG_PREFIX = [
        'pages' => 'page', 'faqs' => 'faq', 'news' => 'news', 'events' => 'event',
        'stories' => 'story', 'partners' => 'partner', 'resources' => 'resource',
    ];

    /** Growth types: deletable outright (no consents / links depend on them) and without legal versioning. */
    public const GROWTH_TYPES = ['stories', 'partners', 'resources'];

    private const FILE_MAX_KB = 10240;

    private const MEDIA_MAX_KB = 5120;

    private const MEDIA_MAX_WIDTH = 1920;

    private const MEDIA_WEBP_QUALITY = 82;

    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /admin/content/{type}?slug= */
    public function index(Request $request, string $type): JsonResponse
    {
        $this->assertCan($request);
        $model = $this->model($type);

        $paginator = $model::query()
            ->when($request->filled('slug'), fn (Builder $q) => $q->where('slug', $request->string('slug')->toString()))
            ->orderBy('sort')->orderByDesc('id')->paginate($this->perPage($request));

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

        $data = $this->payload($type, $request->validated());
        $data['slug'] = $this->uniqueSlug($model, $type, $data['slug'] ?? null, $data['title_en'] ?? null);
        $this->assertPartnerDisplayable($type, $data);

        $item = $model::query()->create($data + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
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

        $data = $this->payload($type, $request->validated());
        if (array_key_exists('slug', $data) && ($data['slug'] === null || $data['slug'] === '')) {
            unset($data['slug']); // never blank an existing slug
        }

        // meta is merged per top-level key, so editing one feature (hero images, page sections, cover) never wipes another.
        if (array_key_exists('meta', $data) && is_array($data['meta'])) {
            $data['meta'] = array_replace(is_array($item->getAttribute('meta')) ? $item->getAttribute('meta') : [], $data['meta']);
        }

        $this->assertPartnerDisplayable($type, $data + $item->only(['is_published', 'approved_for_display']));

        $before = $item->toArray();
        $item->fill($data + ['updated_by' => $request->user()->id])->save();
        $this->audit->log('content.updated', $item, $before, $item->getChanges(), $request->user());

        return $this->ok((new CmsContentResource($item))->resolve($request));
    }

    /** DELETE /admin/content/{type}/{id} — growth types only (stories / partners / resources). */
    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        $this->assertCan($request);
        if (! in_array($type, self::GROWTH_TYPES, true)) {
            throw new NotFoundHttpException;
        }
        /** @var Model $item */
        $item = $this->model($type)::query()->findOrFail($id);

        $this->audit->log('content.deleted', $item, $item->toArray(), null, $request->user());
        $item->delete();

        return $this->message(__('messages.deleted'));
    }

    /**
     * POST /admin/content/files (multipart `file`: pdf/docx/xlsx/pptx, max 10 MB) — downloadable knowledge resources.
     * Stored on the public disk (these guides are public by design); never accepts HTML/SVG/scripts.
     */
    public function file(Request $request): JsonResponse
    {
        $this->assertCan($request);
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,docx,xlsx,pptx', 'max:'.self::FILE_MAX_KB]]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $ext = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));
        $name = Str::uuid()->toString().'.'.$ext;
        $dir = 'content/files/'.now()->format('Y/m');
        Storage::disk('public')->putFileAs($dir, $file, $name);
        $path = "{$dir}/{$name}";

        $this->audit->log('content.file_uploaded', null, null, ['path' => $path, 'size' => $file->getSize()], $request->user());

        return response()->json(['data' => [
            'url' => Storage::disk('public')->url($path),
            'path' => $path,
            'name' => Str::limit($file->getClientOriginalName(), 180, ''),
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
        ]], 201);
    }

    /**
     * POST /admin/content/media (multipart `file`, images only, max 5 MB) — BRD-03.
     * Every image is re-encoded to WebP (GD, q≈82, max width 1920) and stored on the public disk.
     */
    public function media(Request $request): JsonResponse
    {
        $this->assertCan($request);
        $request->validate(['file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:'.self::MEDIA_MAX_KB]]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $encoded = WebpEncoder::encode($file, self::MEDIA_MAX_WIDTH, self::MEDIA_WEBP_QUALITY);
        if ($encoded === null) {
            abort(422, __('messages.content.image_failed'));
        }

        $path = sprintf('content/%s/%s.webp', now()->format('Y/m'), Str::uuid()->toString());
        Storage::disk('public')->put($path, $encoded['bytes']);

        $this->audit->log('content.media_uploaded', null, null, ['path' => $path, 'size' => strlen($encoded['bytes'])], $request->user());

        return response()->json(['data' => [
            'url' => Storage::disk('public')->url($path),
            'path' => $path,
            'width' => $encoded['width'],
            'height' => $encoded['height'],
        ]], 201);
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
                'assigned_to' => $m->assignee?->name, 'assigned_to_id' => $m->assigned_to, 'created_at' => $m->created_at?->toIso8601String(),
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

    /**
     * #32 — slug generation: explicit slug → Str::slug(title_en) → `{prefix}-{n}`; always unique per table.
     *
     * @param  class-string<Model>  $model
     */
    private function uniqueSlug(string $model, string $type, ?string $slug, ?string $titleEn): string
    {
        $base = $slug !== null && $slug !== '' ? $slug : Str::slug((string) $titleEn);
        if ($base === '') {
            $prefix = self::SLUG_PREFIX[$type] ?? 'item';
            $n = $model::query()->count() + 1;
            while ($model::query()->where('slug', "{$prefix}-{$n}")->exists()) {
                $n++;
            }

            return "{$prefix}-{$n}";
        }

        $candidate = Str::limit($base, 150, '');
        $i = 2;
        while ($model::query()->where('slug', $candidate)->exists()) {
            $candidate = Str::limit($base, 150, '').'-'.$i++;
        }

        return $candidate;
    }

    private function payload(string $type, array $data): array
    {
        $growth = in_array($type, self::GROWTH_TYPES, true);
        if ($growth) {
            unset($data['version']); // growth tables have no legal versioning
        } else {
            unset($data['link_url']);
        }
        if ($type !== 'stories') {
            unset($data['sector_ar'], $data['sector_en'], $data['beneficiaries'], $data['impact_metric_ar'], $data['impact_metric_en']);
        }
        if ($type !== 'partners') {
            unset($data['approved_for_display']);
        }
        if ($type !== 'resources') {
            unset($data['audience']);
        } elseif (array_key_exists('audience', $data) && $data['audience'] === null) {
            $data['audience'] = 'all';
        }
        if ($type !== 'events') {
            unset($data['starts_at'], $data['ends_at'], $data['location'], $data['registration_url']);
        }
        if ($type !== 'faqs') {
            unset($data['category']);
        }
        if ($type !== 'news') {
            unset($data['author_name'], $data['author_title'], $data['author_bio'], $data['author_avatar_url'], $data['author_linkedin_url']);
        }
        if (array_key_exists('meta', $data) && is_array($data['meta']) && array_key_exists('hero_images', $data['meta'])) {
            $data['meta']['hero_images'] = array_values(array_map(fn (array $img): array => [
                'url' => (string) $img['url'],
                'path' => $img['path'] ?? null,
                'alt_ar' => $img['alt_ar'] ?? null,
                'alt_en' => $img['alt_en'] ?? null,
                'width' => isset($img['width']) ? (int) $img['width'] : null,
                'height' => isset($img['height']) ? (int) $img['height'] : null,
            ], (array) ($data['meta']['hero_images'] ?? [])));
        }
        foreach (['cover', 'logo'] as $imageKey) {
            if (array_key_exists('meta', $data) && is_array($data['meta']) && is_array($data['meta'][$imageKey] ?? null)) {
                $img = $data['meta'][$imageKey];
                $data['meta'][$imageKey] = [
                    'url' => (string) $img['url'],
                    'path' => $img['path'] ?? null,
                    'alt_ar' => $img['alt_ar'] ?? null,
                    'alt_en' => $img['alt_en'] ?? null,
                    'width' => isset($img['width']) ? (int) $img['width'] : null,
                    'height' => isset($img['height']) ? (int) $img['height'] : null,
                ];
            }
        }

        return $data;
    }

    /**
     * Doc rule — partners are shown only with an approved name, never as anonymous logos:
     * publishing a partner requires `approved_for_display`.
     *
     * @param  array<string, mixed>  $state
     */
    private function assertPartnerDisplayable(string $type, array $state): void
    {
        if ($type === 'partners' && (bool) ($state['is_published'] ?? false) && ! (bool) ($state['approved_for_display'] ?? false)) {
            throw ValidationException::withMessages(['approved_for_display' => __('messages.content.partner_not_approved')]);
        }
    }

    private function assertCan(Request $request): void
    {
        if (! $request->user()->can('settings.manage')) {
            abort(403, __('messages.errors.forbidden'));
        }
    }
}
