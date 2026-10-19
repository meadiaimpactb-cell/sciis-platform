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
use App\Models\Membership;
use App\Models\NewsPost;
use App\Models\Opportunity;
use App\Models\Page;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ReferenceNumberGenerator;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
        $paginator = NewsPost::query()->published()->orderByDesc('published_at')->paginate($this->perPage($request));

        return response()->json([
            'data' => CmsContentResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }

    /** GET /public/events */
    public function events(Request $request): JsonResponse
    {
        return response()->json(['data' => CmsContentResource::collection(Event::query()->published()->orderBy('starts_at')->get())->resolve($request)]);
    }

    /** GET /public/stats — aggregated, non-personal (CMS-01). */
    public function stats(): JsonResponse
    {
        $stats = Cache::remember('public.stats', 300, fn (): array => [
            'approved_investors' => Membership::query()->where('status', MembershipStatus::Approved->value)->count(),
            'registered_entrepreneurs' => User::query()->entrepreneurs()->count(),
            'opportunities_submitted' => Opportunity::query()->whereNotNull('submitted_at')->count(),
            'opportunities_eligible' => Opportunity::query()->whereIn('status', [OpportunityStatus::Eligible->value, ...array_map(fn ($s) => $s->value, OpportunityStatus::connectedOrLater())])->count(),
            'connections' => Opportunity::query()->whereIn('status', array_map(fn ($s) => $s->value, OpportunityStatus::connectedOrLater()))->count(),
            'sectors_covered' => Opportunity::query()->whereNotNull('submitted_at')->distinct('sector_id')->count('sector_id'),
        ]);

        return $this->ok($stats);
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

    /** POST /public/contact — CMS-05 (honeypot validated in request + throttle:contact). */
    public function contact(ContactRequest $request, ReferenceNumberGenerator $refs, AuditLogger $audit): JsonResponse
    {
        $message = ContactMessage::query()->create($request->safe()->except('website') + [
            'reference_no' => $refs->nextContact(),
            'ip' => $request->ip(),
        ]);

        $audit->log('contact.received', $message, null, ['category' => $message->category->value]);

        return response()->json(['data' => ['reference_no' => $message->reference_no], 'message' => __('messages.contact.received')], 201);
    }
}
