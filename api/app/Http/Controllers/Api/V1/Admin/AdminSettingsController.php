<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TaxonomyTermRequest;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Http\Resources\TaxonomyTermResource;
use App\Models\Setting;
use App\Models\TaxonomyTerm;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** ADM-03 (settings) + FRM-04 (taxonomies) — super_admin / settings.manage. */
class AdminSettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings, private readonly AuditLogger $audit) {}

    /** GET /admin/settings */
    public function index(Request $request): JsonResponse
    {
        $this->assertCan($request);

        $rows = Setting::query()->orderBy('group')->orderBy('key')->get()->map(fn (Setting $s): array => [
            'key' => $s->key, 'value' => $s->value, 'group' => $s->group, 'description' => $s->description, 'is_public' => $s->is_public, 'updated_at' => $s->updated_at?->toIso8601String(),
        ]);

        return $this->ok(['settings' => $rows->all(), 'features' => config('sciis.features')]);
    }

    /** PUT /admin/settings */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $this->assertCan($request);
        $this->settings->setMany($request->validated('settings'), $request->user());

        return $this->index($request);
    }

    /** GET /admin/taxonomies/{group} */
    public function taxonomy(Request $request, string $group): JsonResponse
    {
        $this->assertGroup($group);

        return response()->json(['data' => TaxonomyTermResource::collection(TaxonomyTerm::query()->group($group)->ordered()->get())->resolve($request)]);
    }

    /** POST /admin/taxonomies/{group} */
    public function storeTerm(TaxonomyTermRequest $request, string $group): JsonResponse
    {
        $this->assertCan($request);
        $this->assertGroup($group);

        $term = TaxonomyTerm::query()->create($request->validated() + ['group' => $group]);
        $this->audit->log('taxonomy.created', $term, null, $term->toArray(), $request->user());

        return response()->json(['data' => (new TaxonomyTermResource($term))->resolve($request)], 201);
    }

    /** PATCH /admin/taxonomies/{group}/{term} */
    public function updateTerm(TaxonomyTermRequest $request, string $group, TaxonomyTerm $term): JsonResponse
    {
        $this->assertCan($request);
        $this->assertGroup($group);
        if ($term->group !== $group) {
            throw new NotFoundHttpException;
        }

        $before = $term->toArray();
        $term->fill($request->validated())->save();
        $this->audit->log('taxonomy.updated', $term, $before, $term->getChanges(), $request->user());

        return $this->ok((new TaxonomyTermResource($term))->resolve($request));
    }

    /** DELETE /admin/taxonomies/{group}/{term} — soft: deactivates (terms are referenced by FK). */
    public function destroyTerm(Request $request, string $group, TaxonomyTerm $term): JsonResponse
    {
        $this->assertCan($request);
        $this->assertGroup($group);
        if ($term->group !== $group) {
            throw new NotFoundHttpException;
        }

        $term->is_active = false;
        $term->save();
        $this->audit->log('taxonomy.deactivated', $term, ['is_active' => true], ['is_active' => false], $request->user());

        return $this->message(__('messages.deleted'));
    }

    /**
     * GET /admin/system-status — launch checklist for production (BKP-01/03, NTF-02, ACC-01/05): is mail/SMS really
     * delivered, does cron run (scheduler heartbeat), when was the last backup, are temporary flags still on.
     * Each check: {key, ok, value}; the web turns keys into readable text.
     */
    public function systemStatus(Request $request): JsonResponse
    {
        $this->assertCan($request);

        $heartbeat = Cache::get('sciis.scheduler.heartbeat');
        $backup = Cache::get('sciis.backup.last');
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $mailer = (string) config('mail.default');

        $checks = [
            ['key' => 'mail', 'ok' => ! in_array($mailer, ['log', 'array'], true), 'value' => $mailer === 'smtp' ? config('mail.from.address') : $mailer],
            ['key' => 'sms', 'ok' => config('sciis.sms.driver') !== 'log', 'value' => (string) config('sciis.sms.driver')],
            // NTF-04 — WhatsApp driver (log | cloud_api) and whether the Cloud API credentials are present.
            ['key' => 'whatsapp', 'ok' => app(WhatsAppSender::class)->driver() === 'cloud_api' && (string) config('services.whatsapp.phone_number_id') !== '' && (string) config('services.whatsapp.token') !== '', 'value' => app(WhatsAppSender::class)->driver()],
            ['key' => 'scheduler', 'ok' => $heartbeat !== null && Carbon::parse($heartbeat)->gt(now()->subMinutes(5)), 'value' => $heartbeat],
            ['key' => 'backup', 'ok' => $backup !== null && Carbon::parse($backup['at'])->gt(now()->subHours(36)), 'value' => $backup['at'] ?? null],
            ['key' => 'queue', 'ok' => $failedJobs === 0, 'value' => config('queue.default').' · '.$failedJobs],
            ['key' => 'scan', 'ok' => config('sciis.documents.scan_driver') !== 'none', 'value' => (string) config('sciis.documents.scan_driver')],
            ['key' => 'verification', 'ok' => ! config('sciis.verification.optional'), 'value' => config('sciis.verification.phone') ? 'email+phone' : 'email'],
            ['key' => 'staff_2fa', 'ok' => (bool) config('sciis.two_factor.enforce_for_staff'), 'value' => null],
            ['key' => 'debug', 'ok' => ! config('app.debug'), 'value' => (string) config('app.env')],
        ];

        return $this->ok(['checks' => $checks, 'checked_at' => now()->toIso8601String()]);
    }

    private function assertCan(Request $request): void
    {
        if (! $request->user()->can('settings.manage')) {
            abort(403, __('messages.errors.forbidden'));
        }
    }

    private function assertGroup(string $group): void
    {
        if (! in_array($group, TaxonomyTerm::GROUPS, true)) {
            throw new NotFoundHttpException;
        }
    }
}
