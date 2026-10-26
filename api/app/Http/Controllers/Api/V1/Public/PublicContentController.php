<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\MembershipStatus;
use App\Enums\OpportunityStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\ContactRequest;
use App\Http\Resources\CmsContentResource;
use App\Http\Resources\OpportunityCardResource;
use App\Models\ContactMessage;
use App\Models\Event;
use App\Models\Faq;
use App\Models\KnowledgeResource;
use App\Models\Membership;
use App\Models\NewsPost;
use App\Models\Opportunity;
use App\Models\Page;
use App\Models\Partner;
use App\Models\Setting;
use App\Models\Story;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Notifications\ContactReceivedNotification;
use App\Services\AuditLogger;
use App\Services\ReferenceNumberGenerator;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/** CMS-01..05 — public site content, stats, contact form, featured teasers. */
class PublicContentController extends Controller
{
    /** GET /public/pages/{slug} */
    public function page(Request $request, string $slug): JsonResponse
    {
        $page = Page::query()->published()->where('slug', $slug)->firstOrFail();

        return $this->ok((new CmsContentResource($page))->resolve($request));
    }

    /** GET /public/faqs */
    public function faqs(Request $request): JsonResponse
    {
        return response()->json(['data' => CmsContentResource::collection(Faq::query()->published()->orderBy('sort')->get())->resolve($request)]);
    }

    /** GET /public/news */
    public function news(Request $request): JsonResponse
    {
        $paginator = NewsPost::query()->published()->orderByDesc('published_at')->orderByDesc('id')->paginate($this->perPage($request));

        return response()->json([
            'data' => CmsContentResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }

    /** GET /public/news/{slug} */
    public function newsShow(Request $request, string $slug): JsonResponse
    {
        $post = NewsPost::query()->published()->where('slug', $slug)->firstOrFail();

        return $this->ok((new CmsContentResource($post))->resolve($request));
    }

    /** GET /public/events */
    public function events(Request $request): JsonResponse
    {
        return response()->json(['data' => CmsContentResource::collection(Event::query()->published()->orderBy('starts_at')->get())->resolve($request)]);
    }

    /** GET /public/events/{slug} */
    public function eventShow(Request $request, string $slug): JsonResponse
    {
        $event = Event::query()->published()->where('slug', $slug)->firstOrFail();

        return $this->ok((new CmsContentResource($event))->resolve($request));
    }

    /** GET /public/stories — published impact stories (the home section / page hide when empty). */
    public function stories(Request $request): JsonResponse
    {
        $items = Story::query()->published()->orderBy('sort')->orderByDesc('published_at')->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => CmsContentResource::collection($items)->resolve($request)]);
    }

    /** GET /public/partners — published partners whose name/logo display was approved (never anonymous logos). */
    public function partners(Request $request): JsonResponse
    {
        $items = Partner::query()->displayable()->orderBy('sort')->orderBy('id')->limit(100)->get();

        return response()->json(['data' => CmsContentResource::collection($items)->resolve($request)]);
    }

    /** GET /public/resources?audience=investor|entrepreneur — curated guides (audience filter includes `all`). */
    public function resources(Request $request): JsonResponse
    {
        $audience = $request->string('audience')->toString();
        $items = KnowledgeResource::query()->published()->forAudience($audience !== '' ? $audience : null)
            ->orderBy('sort')->orderByDesc('published_at')->orderByDesc('id')->limit(200)->get();

        return response()->json(['data' => CmsContentResource::collection($items)->resolve($request)]);
    }

    /** GET /public/stats — aggregated, non-personal (CMS-01). */
    public function stats(): JsonResponse
    {
        return $this->ok($this->publicStats());
    }

    /** @return array<string, int> */
    private function publicStats(): array
    {
        return Cache::remember('public.stats', 300, fn (): array => [
            'approved_investors' => Membership::query()->where('status', MembershipStatus::Approved->value)->count(),
            'registered_entrepreneurs' => User::query()->entrepreneurs()->count(),
            'opportunities_submitted' => Opportunity::query()->whereNotNull('submitted_at')->count(),
            'opportunities_eligible' => Opportunity::query()->whereIn('status', [OpportunityStatus::Eligible->value, ...array_map(fn ($s) => $s->value, OpportunityStatus::connectedOrLater())])->count(),
            'connections' => Opportunity::query()->whereIn('status', array_map(fn ($s) => $s->value, OpportunityStatus::connectedOrLater()))->count(),
            'sectors_covered' => Opportunity::query()->whereNotNull('submitted_at')->distinct('sector_id')->count('sector_id'),
        ]);
    }

    /**
     * GET /public/content-version — a short fingerprint of everything the public site shows (CMS pages, news, events,
     * FAQ, stories, partners, resources, settings, taxonomy labels, the public stats and the listed featured cards).
     * The static site compares it with the version it was built from and only re-fetches its data when it changed.
     * Each table contributes max(updated_at) + row count, so additions, edits and deletions all change it.
     */
    public function contentVersion(): JsonResponse
    {
        // Only what visitors see: logins (users.updated_at) and draft edits must not invalidate every visitor's page.
        $cms = [Page::class, NewsPost::class, Event::class, Faq::class, Story::class, Partner::class, KnowledgeResource::class, Setting::class, TaxonomyTerm::class];
        $parts = array_map(fn (string $model): string => $model::query()->max('updated_at').'#'.$model::query()->count(), $cms);
        $listed = Opportunity::query()->where('status', OpportunityStatus::Eligible->value)->where('is_listed', true);
        $parts[] = 'featured:'.(clone $listed)->max('updated_at').'#'.$listed->count();
        $parts[] = 'stats:'.json_encode($this->publicStats());

        return $this->ok(['version' => substr(sha1(implode('|', $parts)), 0, 16)]);
    }

    /** GET /public/featured-opportunities — teaser cards only when settings.featured_public=true. */
    public function featured(Request $request, SettingsService $settings): JsonResponse
    {
        if (! $settings->bool('public.featured_public', false)) {
            return response()->json(['data' => []]);
        }

        $items = Opportunity::query()
            ->where('status', OpportunityStatus::Eligible->value)
            ->where('is_listed', true)
            ->withApprovedTeaser()
            ->with(['sector', 'regions', 'investmentTypes', 'beneficiaryGroups', 'organization', 'teaser', 'fundingRange'])
            ->latest('updated_at')
            ->limit(6)
            ->get();

        return response()->json(['data' => OpportunityCardResource::collection($items)->resolve($request)]);
    }

    /**
     * POST /public/contact — CMS-05 (honeypot validated in request + throttle:contact).
     * Auto-routes by category via settings `contact.routing.{category}` (staff user id), e-mails the sender
     * a confirmation with the reference number and notifies the assigned staff user (database).
     */
    public function contact(ContactRequest $request, ReferenceNumberGenerator $refs, AuditLogger $audit, SettingsService $settings): JsonResponse
    {
        $data = $request->safe()->except('website');
        $assignee = $this->routeFor((string) $data['category'], $settings);

        $message = ContactMessage::query()->create($data + [
            'reference_no' => $refs->nextContact(),
            'ip' => $request->ip(),
            'assigned_to' => $assignee?->id,
        ]);

        $audit->log('contact.received', $message, null, ['category' => $message->category->value, 'assigned_to' => $assignee?->id]);

        Notification::route('mail', [$message->email => $message->name])->notify(new ContactReceivedNotification($message));
        if ($assignee !== null) {
            $assignee->notify(new ContactReceivedNotification($message));
        }

        return response()->json(['data' => ['reference_no' => $message->reference_no], 'message' => __('messages.contact.received')], 201);
    }

    /** Staff user configured for a contact category (settings `contact.routing.{category}`), if any and active. */
    private function routeFor(string $category, SettingsService $settings): ?User
    {
        $userId = (int) $settings->get("contact.routing.{$category}", 0);
        if ($userId <= 0) {
            return null;
        }

        $user = User::query()->find($userId);

        return $user instanceof User && $user->isStaff() ? $user : null;
    }
}
