<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payments;

use App\Enums\PaymentPurpose;
use App\Exports\PaymentsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RefundPaymentRequest;
use App\Http\Requests\Payments\CreateIntentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\AuditLogger;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Payments (behind FEATURE_PAYMENTS) — membership fee / service fee / training / sponsorship ONLY (SPEC §0).
 * No escrow, no investor money, no securities.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments, private readonly AuditLogger $audit) {}

    /** POST /payments/intents */
    public function createIntent(CreateIntentRequest $request): JsonResponse
    {
        $result = $this->payments->createIntent(
            $request->user(),
            PaymentPurpose::from($request->validated('purpose')),
            $request->validated('amount') !== null ? (float) $request->validated('amount') : null,
            $request->validated('idempotency_key'),
            $request->validated('description'),
        );
        $payment = $result['payment'];

        return response()->json(['data' => [
            'payment_uuid' => $payment->uuid,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'gateway' => $payment->gateway,
            'gateway_payment_id' => $payment->gateway_payment_id,
            'publishable_key' => $this->payments->gateway()->publishableKey(),
            'callback_url' => $payment->gateway_payload['callback_url'] ?? null,
            'status' => $payment->status->value,
        ]], $result['created'] ? 201 : 200);
    }

    /** GET /payments/{uuid} */
    public function show(Request $request, Payment $payment): JsonResponse
    {
        if ($payment->user_id !== $request->user()->id && ! $request->user()->isStaff()) {
            abort(403, __('messages.errors.forbidden'));
        }

        return $this->ok((new PaymentResource($this->payments->sync($payment)->load('invoice')))->resolve($request));
    }

    /** POST /payments/{uuid}/cancel */
    public function cancel(Request $request, Payment $payment): JsonResponse
    {
        if ($payment->user_id !== $request->user()->id) {
            abort(403, __('messages.errors.forbidden'));
        }

        return $this->ok((new PaymentResource($this->payments->cancel($payment, $request->user())))->resolve($request));
    }

    /** POST /webhooks/moyasar — signature verified, idempotent, logged. */
    public function webhook(Request $request): JsonResponse
    {
        $signature = $request->header('X-Moyasar-Signature') ?? $request->header('X-Webhook-Secret');
        $headers = ['x-moyasar-signature' => $request->header('X-Moyasar-Signature'), 'x-webhook-secret' => $request->header('X-Webhook-Secret')];

        $event = $this->payments->handleWebhook((string) $request->getContent(), $signature, array_filter($headers));

        return response()->json(['received' => true, 'event_id' => $event->id]);
    }

    /** GET /admin/payments */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->assertStaff($request);

        $paginator = $this->adminQuery($request)->with(['user', 'invoice'])->orderByDesc('id')->paginate($this->perPage($request));

        return response()->json([
            'data' => PaymentResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }

    /** GET /admin/payments/export.xlsx */
    public function adminExport(Request $request): BinaryFileResponse
    {
        $this->assertStaff($request);
        if (! $request->user()->can('reports.export')) {
            abort(403, __('messages.errors.forbidden'));
        }
        $this->audit->log('payments.export', null, null, $request->only(['status', 'purpose', 'from', 'to']), $request->user());

        return Excel::download(new PaymentsExport($this->adminQuery($request)), 'payments-'.now()->format('Ymd-His').'.xlsx');
    }

    /** POST /admin/payments/{uuid}/refund */
    public function refund(RefundPaymentRequest $request, Payment $payment): JsonResponse
    {
        $this->assertStaff($request);
        if (! $request->user()->hasRole('super_admin')) {
            abort(403, __('messages.errors.forbidden'));
        }

        $payment = $this->payments->refund($payment, $request->user(), $request->validated('amount') !== null ? (float) $request->validated('amount') : null);

        return $this->ok((new PaymentResource($payment->load('invoice')))->resolve($request));
    }

    private function adminQuery(Request $request): Builder
    {
        return Payment::query()
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when($request->filled('purpose'), fn (Builder $q) => $q->where('purpose', $request->input('purpose')))
            ->when($request->filled('from'), fn (Builder $q) => $q->where('created_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->where('created_at', '<=', $request->input('to')));
    }

    private function assertStaff(Request $request): void
    {
        if (! $request->user()->isStaff() || ! $request->user()->can('reports.view')) {
            abort(403, __('messages.errors.forbidden'));
        }
    }
}
