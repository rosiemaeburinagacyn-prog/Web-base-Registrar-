<?php

namespace App\Http\Controllers;

use App\Models\DocumentRequest;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\RegistrarStatusNotification;
use App\Services\OfficialReceiptGenerator;
use App\Services\PaymentGatewayManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    public function show(Request $request, DocumentRequest $documentRequest): View
    {
        $this->authorizeStudentRequest($request, $documentRequest);

        return view('payments.gcash', [
            'documentRequest' => $documentRequest->load(['academicYear', 'items', 'latestPayment']),
            'payment' => $documentRequest->latestPayment,
        ]);
    }

    public function submitProof(Request $request, DocumentRequest $documentRequest, PaymentGatewayManager $gateway): RedirectResponse
    {
        $this->authorizeStudentRequest($request, $documentRequest);

        if ($documentRequest->isPaid()) {
            return redirect()
                ->route('student.dashboard')
                ->with('success', 'This request is already paid.');
        }

        $payment = DB::transaction(function () use ($documentRequest, $gateway) {
            $payment = $gateway->createCheckout($documentRequest);

            $documentRequest->update([
                'payment_status' => DocumentRequest::PAYMENT_PENDING,
                'request_status' => DocumentRequest::STATUS_PENDING,
            ]);

            return $payment;
        });

        if (! $payment->checkout_url) {
            throw ValidationException::withMessages([
                'payment' => 'Unable to open payment checkout. Please try again later.',
            ]);
        }

        return redirect()->away($payment->checkout_url);
    }

    public function cancel(Request $request, DocumentRequest $documentRequest): RedirectResponse
    {
        $this->authorizeStudentRequest($request, $documentRequest);

        return redirect()
            ->route('student.dashboard')
            ->with('success', 'Payment cancelled. You can continue checkout later.');
    }

    public function return(Request $request, Payment $payment, PaymentGatewayManager $gateway, OfficialReceiptGenerator $receiptGenerator): RedirectResponse
    {
        $this->authorizeStudentPayment($request, $payment);

        if (! $payment->isPaid() && $event = $gateway->paymentStatus($payment)) {
            $this->applyGatewayStatus(
                $payment,
                $event['status'],
                $event['payload'],
                $receiptGenerator,
                $event['resource_id']
            );

            $payment->refresh();
        }

        $message = $payment->isPaid()
            ? 'Payment confirmed. Your request is now pending registrar processing.'
            : 'Payment is still pending gateway confirmation. The dashboard will update automatically after the webhook is received.';

        return redirect()->route('student.dashboard')->with('success', $message);
    }

    public function demoCheckout(Request $request, Payment $payment): View
    {
        abort_unless(config('registrar.payments.gateway') === 'fake', 404);
        $this->authorizeStudentPayment($request, $payment);

        return view('payments.demo-checkout', [
            'payment' => $payment->load(['documentRequest.items', 'documentRequest.academicYear']),
            'documentRequest' => $payment->documentRequest,
            'paymentMethods' => ['GCash', 'Maya', 'Card'],
        ]);
    }

    public function demoPay(Request $request, Payment $payment, OfficialReceiptGenerator $receiptGenerator): RedirectResponse
    {
        abort_unless(config('registrar.payments.gateway') === 'fake', 404);
        $this->authorizeStudentPayment($request, $payment);

        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'in:GCash,Maya,Card'],
        ]);

        if ($payment->isPaid()) {
            return redirect()
                ->route('student.dashboard')
                ->with('success', 'This request is already paid.');
        }

        $payment->update([
            'payment_method' => $validated['payment_method'],
        ]);

        $resourceId = $payment->checkout_session_id ?: $payment->gateway_transaction_id ?: $payment->reference;

        $this->applyGatewayStatus(
            $payment,
            Payment::STATUS_PAID,
            $this->demoPaidPayload($payment, $resourceId, $validated['payment_method']),
            $receiptGenerator,
            $resourceId
        );

        return redirect()
            ->route('student.dashboard')
            ->with('success', 'Demo payment completed. Official receipt generated and request sent to the registrar.');
    }

    public function webhook(Request $request, PaymentGatewayManager $gateway, OfficialReceiptGenerator $receiptGenerator): JsonResponse
    {
        Log::info('Payment webhook received.', [
            'path' => $request->path(),
            'ip' => $request->ip(),
            'has_paymongo_signature' => $request->hasHeader('Paymongo-Signature')
                || $request->hasHeader('PayMongo-Signature')
                || $request->hasHeader('X-Paymongo-Signature'),
            'has_xendit_token' => $request->hasHeader('x-callback-token')
                || $request->hasHeader('X-CALLBACK-TOKEN'),
        ]);

        if (! $gateway->verifyWebhook($request)) {
            Log::warning('Payment webhook rejected because signature verification failed.', [
                'path' => $request->path(),
            ]);

            return response()->json(['message' => 'Invalid webhook signature.'], Response::HTTP_UNAUTHORIZED);
        }

        $event = $gateway->webhookEvent($request);
        Log::info('Payment webhook parsed.', [
            'resource_id' => $event['resource_id'],
            'reference' => $event['reference'],
            'document_request_id' => $event['document_request_id'],
            'status' => $event['status'],
        ]);

        $payment = $this->paymentFromGatewayPayload(
            $event['resource_id'],
            $event['reference'],
            $event['document_request_id']
        );

        if (! $payment) {
            Log::warning('Payment webhook could not match a local payment.', [
                'resource_id' => $event['resource_id'],
                'reference' => $event['reference'],
                'document_request_id' => $event['document_request_id'],
            ]);

            return response()->json(['message' => 'Payment not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->applyGatewayStatus(
            $payment,
            $event['status'],
            $event['payload'],
            $receiptGenerator,
            $event['resource_id']
        );

        return response()->json(['message' => 'Webhook processed.']);
    }

    public function verificationIndex(): View
    {
        return $this->paymentReportView('Payment Reports', 'admin.dashboard');
    }

    public function cashierDashboard(Request $request): View
    {
        $payments = Payment::query()
            ->with(['documentRequest.academicYear', 'documentRequest.items', 'student', 'user'])
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $todayPaid = Payment::query()
            ->where('payment_status', Payment::STATUS_PAID)
            ->whereDate('paid_at', today())
            ->sum('amount');

        return view('cashier.dashboard', [
            'payments' => $payments,
            'pendingPaymentCount' => Payment::query()->where('payment_status', Payment::STATUS_PENDING)->count(),
            'approvedPaymentCount' => Payment::query()->where('payment_status', Payment::STATUS_PAID)->count(),
            'failedPaymentCount' => Payment::query()->where('payment_status', Payment::STATUS_FAILED)->count(),
            'dailyCollectionTotal' => $todayPaid,
        ]);
    }

    public function pendingPayments(): View
    {
        return $this->paymentReportView('Cashier Transaction History', 'cashier.dashboard');
    }

    public function approve(Request $request, Payment $payment): RedirectResponse
    {
        throw ValidationException::withMessages([
            'payment' => 'Manual payment approval is disabled. Payment status is updated only by the payment gateway webhook.',
        ]);
    }

    public function reject(Request $request, Payment $payment): RedirectResponse
    {
        throw ValidationException::withMessages([
            'payment' => 'Manual payment rejection is disabled. Payment status is updated only by the payment gateway webhook.',
        ]);
    }

    private function paymentReportView(string $title, string $dashboardRouteName): View
    {
        $payments = Payment::query()
            ->with(['documentRequest.academicYear', 'documentRequest.items', 'student', 'user'])
            ->latest()
            ->paginate(10);

        return view('admin.payments.verification', [
            'payments' => $payments,
            'title' => $title,
            'dashboardRouteName' => $dashboardRouteName,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function demoPaidPayload(Payment $payment, string $resourceId, string $paymentMethod = Payment::METHOD_GCASH): array
    {
        return [
            'data' => [
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'data' => [
                        'id' => $resourceId,
                        'attributes' => [
                            'status' => 'paid',
                            'reference_number' => $payment->reference,
                            'payment_method' => $paymentMethod,
                            'metadata' => [
                                'document_request_id' => (string) $payment->document_request_id,
                                'mode' => 'demo',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function authorizeStudentRequest(Request $request, DocumentRequest $documentRequest): void
    {
        abort_unless((int) $documentRequest->user_id === (int) $request->user()->id, 403);
    }

    private function authorizeStudentPayment(Request $request, Payment $payment): void
    {
        abort_unless(
            (int) $payment->user_id === (int) $request->user()->id
            || (int) $payment->student_id === (int) $request->user()->id,
            403
        );
    }

    private function paymentFromGatewayPayload(string $resourceId, string $reference, mixed $documentRequestId): ?Payment
    {
        return Payment::query()
            ->when($resourceId !== '', function ($query) use ($resourceId) {
                $query->where(function ($query) use ($resourceId) {
                    $query->where('checkout_session_id', $resourceId)
                        ->orWhere('gateway_transaction_id', $resourceId)
                        ->orWhere('reference_number', $resourceId);
                });
            })
            ->when($reference !== '', fn ($query) => $query->orWhere('reference', $reference))
            ->when($documentRequestId, fn ($query) => $query->orWhere('document_request_id', $documentRequestId))
            ->latest()
            ->first();
    }

    private function applyGatewayStatus(Payment $payment, string $status, array $payload, OfficialReceiptGenerator $receiptGenerator, string $resourceId): void
    {
        DB::transaction(function () use ($payment, $status, $payload, $receiptGenerator, $resourceId) {
            $payment->loadMissing('documentRequest.user');

            $payment->update([
                'payment_status' => $status,
                'status' => $status,
                'gateway_transaction_id' => $resourceId ?: $payment->gateway_transaction_id,
                'reference_number' => $this->gatewayReferenceNumber($payload, $payment),
                'paid_at' => $status === Payment::STATUS_PAID ? ($payment->paid_at ?: now()) : $payment->paid_at,
                'verified_at' => $status === Payment::STATUS_PAID ? ($payment->verified_at ?: now()) : $payment->verified_at,
                'gateway_payload' => $payload,
                'rejection_reason' => in_array($status, [Payment::STATUS_FAILED, Payment::STATUS_REFUNDED], true) ? 'Gateway status: '.$status : null,
            ]);

            $documentRequest = $payment->documentRequest;

            if (! $documentRequest) {
                return;
            }

            if ($status === Payment::STATUS_PAID) {
                $documentRequest->update([
                    'payment_status' => DocumentRequest::PAYMENT_PAID,
                    'request_status' => DocumentRequest::STATUS_PENDING,
                    'reviewed_at' => now(),
                    'admin_note' => null,
                ]);

                $payment = $receiptGenerator->ensure($payment->fresh(['documentRequest.items', 'cashier', 'verifier']));

                $documentRequest->user?->notify(new RegistrarStatusNotification(
                    'Payment confirmed',
                    'Your payment for '.$documentRequest->request_reference.' was confirmed. The request is now pending registrar processing.',
                    $documentRequest
                ));

                User::query()
                    ->where('role', User::ROLE_REGISTRAR)
                    ->get()
                    ->each(fn (User $registrar) => $registrar->notify(new RegistrarStatusNotification(
                        'Paid request ready for processing',
                        $documentRequest->student_name.' has paid request '.$documentRequest->request_reference.'.',
                        $documentRequest
                    )));

                return;
            }

            if ($status === Payment::STATUS_FAILED) {
                $documentRequest->update([
                    'payment_status' => DocumentRequest::PAYMENT_FAILED,
                    'request_status' => DocumentRequest::STATUS_PAYMENT_REJECTED,
                    'admin_note' => 'Gateway payment failed.',
                ]);
            }

            if ($status === Payment::STATUS_REFUNDED) {
                $documentRequest->update([
                    'payment_status' => DocumentRequest::PAYMENT_REFUNDED,
                    'admin_note' => 'Gateway payment refunded.',
                ]);
            }
        });
    }

    private function gatewayReferenceNumber(array $payload, Payment $payment): ?string
    {
        return data_get($payload, 'data.attributes.data.id')
            ?: data_get($payload, 'id')
            ?: data_get($payload, 'data.payment_id')
            ?: data_get($payload, 'data.payment_request_id')
            ?: data_get($payload, 'data.id')
            ?: $payment->reference_number;
    }
}
