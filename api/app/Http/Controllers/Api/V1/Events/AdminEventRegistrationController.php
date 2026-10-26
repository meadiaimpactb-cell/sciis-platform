<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Events;

use App\Exports\EventRegistrationsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Events\EventRegistrationSettingsRequest;
use App\Http\Requests\Events\UpdateEventRegistrationRequest;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\AuditLogger;
use App\Services\EventRegistrationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** MTG-04 — staff side: registration settings per event, registrants, attendance, Excel export. */
class AdminEventRegistrationController extends Controller
{
    public function __construct(private readonly EventRegistrationService $registrations, private readonly AuditLogger $audit) {}

    /** GET /admin/events */
    public function index(Request $request): JsonResponse
    {
        $this->assertCan($request);
        $paginator = Event::query()
            ->when($request->filled('search'), fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('title_ar', 'like', '%'.$request->string('search').'%')->orWhere('title_en', 'like', '%'.$request->string('search').'%')))
            ->orderByDesc('starts_at')->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Event $e): array => $this->registrations->eventArray($e))->all(),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }

    /** PUT /admin/events/{event}/registration-settings */
    public function settings(EventRegistrationSettingsRequest $request, int $event): JsonResponse
    {
        $this->assertCan($request);
        $model = Event::query()->findOrFail($event);
        $this->registrations->updateSettings($model, $request->validated(), $request->user());

        return $this->ok($this->registrations->eventArray($model->refresh()));
    }

    /** GET /admin/events/{event}/registrations (?status, ?search) */
    public function registrations(Request $request, int $event): JsonResponse
    {
        $this->assertCan($request);
        $model = Event::query()->findOrFail($event);
        $paginator = $this->query($request, $model)->orderByDesc('id')->paginate($this->perPage($request));
        $base = EventRegistration::query()->where('event_id', $model->id);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (EventRegistration $r): array => $this->registrations->registrationArray($r))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(),
                'summary' => [
                    'registered' => (clone $base)->active()->count(),
                    'cancelled' => (clone $base)->where('status', EventRegistration::STATUS_CANCELLED)->count(),
                    'attended' => (clone $base)->active()->whereNotNull('attended_at')->count(),
                ],
            ],
        ]);
    }

    /** GET /admin/events/{event}/registrations/export.xlsx */
    public function export(Request $request, int $event): BinaryFileResponse
    {
        $this->assertCan($request);
        $model = Event::query()->findOrFail($event);
        $query = $this->query($request, $model);
        $this->audit->log('event.registrations_exported', $model, null, ['rows' => (clone $query)->count()], $request->user());

        return Excel::download(new EventRegistrationsExport($query), sprintf('event-%s-registrations-%s.xlsx', $model->slug, now()->format('Ymd-His')));
    }

    /** PATCH /admin/event-registrations/{registration} {attended?, status?} */
    public function update(UpdateEventRegistrationRequest $request, EventRegistration $registration): JsonResponse
    {
        $this->assertCan($request);
        $registration = $this->registrations->updateRegistration($registration, $request->validated(), $request->user());

        return $this->ok($this->registrations->registrationArray($registration));
    }

    private function query(Request $request, Event $event): Builder
    {
        $search = trim((string) $request->input('search', ''));

        return EventRegistration::query()->where('event_id', $event->id)
            ->when(in_array($request->input('status'), [EventRegistration::STATUS_REGISTERED, EventRegistration::STATUS_CANCELLED], true), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('organization', 'like', "%{$search}%")));
    }

    /** Event operations: content managers or meeting managers. */
    private function assertCan(Request $request): void
    {
        if (! $request->user()->can('settings.manage') && ! $request->user()->can('meetings.manage')) {
            abort(403, __('messages.errors.forbidden'));
        }
    }
}
