<?php

namespace App\Services;

use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\Payment;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class XenditGateway
{
    public function createCheckout(DocumentRequest $documentRequest): Payment
    {
        $documentRequest->loadMissing(['items', 'user', 'latestPayment']);

        if ($documentRequest->latestPayment?->isPaid()) {
            return $documentRequest->latestPayment;
        }

        $existingPayment = $documentRequest->payments()
            ->where('payment_status', Payment::STATUS_PENDING)
            ->where('provider', Payment::PROVIDER_XENDIT)
            ->whereNotNull('checkout_url')
            ->latest()
            ->first();

        if ($existingPayment) {
            return $existingPayment;
        }

        $payment = Payment::query()->create([
            'request_id' => $documentRequest->id,
            'student_id' => $documentRequest->user_id,
            'document_request_id' => $documentRequest->id,
            'user_id' => $documentRequest->user_id,
            'reference' => $this->paymentReference(),
            'provider' => Payment::PROVIDER_XENDIT,
            'amount' => $documentRequest->amount(),
            'payment_method' => Payment::METHOD_GCASH,
            'payment_status' => Payment::STATUS_PENDING,
            'status' => Payment::STATUS_PENDING,
            'metadata' => $this->metadata($documentRequest),
        ]);

        $secretKey = (string) config('registrar.xendit.secret_key');

        if ($secretKey === '') {
            $payment->update([
                'payment_status' => Payment::STATUS_FAILED,
                'status' => Payment::STATUS_FAILED,
                'gateway_payload' => ['error' => 'Missing XENDIT_SECRET_KEY'],
            ]);

            throw ValidationException::withMessages([
                'payment' => 'Online payment is not configured. Set XENDIT_SECRET_KEY before accepting live GCash payments.',
            ]);
        }

        try {
            $response = Http::withBasicAuth($secretKey, '')
                ->acceptJson()
                ->asJson()
                ->post(rtrim((string) config('registrar.xendit.base_url'), '/').'/v2/invoices', $this->invoicePayload($documentRequest, $payment))
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            $payment->update([
                'payment_status' => Payment::STATUS_FAILED,
                'status' => Payment::STATUS_FAILED,
                'gateway_payload' => [
                    'error' => $exception->getMessage(),
                    'response' => $exception->response?->json(),
                ],
            ]);

            throw ValidationException::withMessages([
                'payment' => 'Unable to create the Xendit checkout. Please try again later.',
            ]);
        }

        $invoiceId = (string) (data_get($response, 'id') ?: data_get($response, 'invoice_id'));
        $checkoutUrl = (string) data_get($response, 'invoice_url');

        if ($invoiceId === '' || $checkoutUrl === '') {
            $payment->update([
                'payment_status' => Payment::STATUS_FAILED,
                'status' => Payment::STATUS_FAILED,
                'gateway_payload' => $response,
            ]);

            throw ValidationException::withMessages([
                'payment' => 'Xendit did not return a checkout URL.',
            ]);
        }

        $payment->update([
            'gateway_transaction_id' => $invoiceId,
            'checkout_session_id' => $invoiceId,
            'checkout_url' => $checkoutUrl,
            'gateway_payload' => $response,
        ]);

        return $payment->fresh();
    }

    public function verifyWebhook(Request $request): bool
    {
        $token = (string) config('registrar.xendit.webhook_token');

        if ($token === '') {
            return true;
        }

        $header = (string) ($request->header('x-callback-token') ?: $request->header('X-CALLBACK-TOKEN'));

        return $header !== '' && hash_equals($token, $header);
    }

    /**
     * @return array{resource_id: string, reference: string, document_request_id: mixed, status: string, payload: array<string, mixed>}
     */
    public function webhookEvent(Request $request): array
    {
        $payload = $request->json()->all();

        return $this->eventFromPayload($payload);
    }

    /**
     * @return null|array{resource_id: string, reference: string, document_request_id: mixed, status: string, payload: array<string, mixed>}
     */
    public function paymentStatus(Payment $payment): ?array
    {
        $secretKey = (string) config('registrar.xendit.secret_key');
        $invoiceId = $payment->checkout_session_id ?: $payment->gateway_transaction_id;

        if ($secretKey === '' || ! $invoiceId) {
            return null;
        }

        try {
            $payload = Http::withBasicAuth($secretKey, '')
                ->acceptJson()
                ->get(rtrim((string) config('registrar.xendit.base_url'), '/').'/v2/invoices/'.$invoiceId)
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            Log::warning('Unable to sync Xendit invoice status.', [
                'payment_id' => $payment->id,
                'invoice_id' => $invoiceId,
                'error' => $exception->getMessage(),
                'response' => $exception->response?->json(),
            ]);

            return null;
        }

        return $this->eventFromPayload($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{resource_id: string, reference: string, document_request_id: mixed, status: string, payload: array<string, mixed>}
     */
    private function eventFromPayload(array $payload): array
    {
        $data = (array) data_get($payload, 'data', []);

        if ($data !== [] && data_get($payload, 'event')) {
            $eventType = (string) data_get($payload, 'event', '');

            return [
                'resource_id' => (string) (
                    data_get($data, 'payment_session_id')
                    ?: data_get($data, 'payment_request_id')
                    ?: data_get($data, 'id')
                ),
                'reference' => (string) data_get($data, 'reference_id', ''),
                'document_request_id' => data_get($data, 'metadata.document_request_id'),
                'status' => $this->statusFromGatewayPayload($eventType, (string) data_get($data, 'status', '')),
                'payload' => $payload,
            ];
        }

        $status = (string) data_get($payload, 'status', '');

        return [
            'resource_id' => (string) data_get($payload, 'id', ''),
            'reference' => (string) data_get($payload, 'external_id', ''),
            'document_request_id' => data_get($payload, 'metadata.document_request_id'),
            'status' => $this->statusFromGatewayPayload('invoice.'.$status, $status),
            'payload' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(DocumentRequest $documentRequest, Payment $payment): array
    {
        $user = $documentRequest->user;

        $payload = [
            'external_id' => $payment->reference,
            'amount' => (float) $payment->amount,
            'currency' => 'PHP',
            'description' => 'ISU Registrar document request '.$documentRequest->request_reference,
            'invoice_duration' => (int) config('registrar.xendit.invoice_duration', 86400),
            'payer_email' => $user?->school_email ?: $user?->email,
            'success_redirect_url' => route('payments.return', $payment),
            'failure_redirect_url' => route('payments.return', ['payment' => $payment, 'cancelled' => 1]),
            'items' => $this->lineItems($documentRequest),
            'customer' => [
                'given_names' => $this->cleanText($this->givenNames($documentRequest->student_name)),
                'surname' => $this->cleanText($this->surname($documentRequest->student_name)),
                'email' => $user?->school_email ?: $user?->email,
            ],
            'metadata' => [
                'document_request_id' => (string) $documentRequest->id,
                'request_reference' => $documentRequest->request_reference,
                'student_number' => $documentRequest->student_id,
                'payment_id' => (string) $payment->id,
            ],
        ];

        $paymentMethods = $this->paymentMethods();

        if ($paymentMethods !== []) {
            $payload['payment_methods'] = $paymentMethods;
        }

        return $payload;
    }

    /**
     * @return array<int, array{name: string, quantity: int, price: float, category: string}>
     */
    private function lineItems(DocumentRequest $documentRequest): array
    {
        return $documentRequest->itemSummary()
            ->map(fn (DocumentRequestItem $item) => [
                'name' => $item->label(),
                'quantity' => (int) $item->quantity,
                'price' => (float) $item->unit_price,
                'category' => 'Registrar Document',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(DocumentRequest $documentRequest): array
    {
        return [
            'request_reference' => $documentRequest->request_reference,
            'document_type' => $documentRequest->document_type,
            'documents' => $documentRequest->itemSummary()
                ->map(fn (DocumentRequestItem $item) => [
                    'document_type' => $item->document_type,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                ])
                ->values()
                ->all(),
            'academic_year' => $documentRequest->academic_year,
            'semester' => $documentRequest->semester,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function paymentMethods(): array
    {
        $configured = (string) config('registrar.xendit.payment_methods', 'GCASH');

        return collect(explode(',', $configured))
            ->map(fn (string $method) => Str::upper(trim($method)))
            ->filter()
            ->values()
            ->all();
    }

    private function paymentReference(): string
    {
        do {
            $reference = 'PAY-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
        } while (Payment::where('reference', $reference)->exists());

        return $reference;
    }

    private function statusFromGatewayPayload(string $eventType, string $gatewayStatus): string
    {
        $text = Str::lower($eventType.' '.$gatewayStatus);

        if (str_contains($text, 'refund')) {
            return Payment::STATUS_REFUNDED;
        }

        if (str_contains($text, 'fail') || str_contains($text, 'expire') || str_contains($text, 'cancel')) {
            return Payment::STATUS_FAILED;
        }

        if (str_contains($text, 'paid') || str_contains($text, 'settled') || str_contains($text, 'success') || str_contains($text, 'complete')) {
            return Payment::STATUS_PAID;
        }

        return Payment::STATUS_PENDING;
    }

    private function givenNames(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];

        if (count($parts) <= 1) {
            return $fullName ?: 'Student';
        }

        array_pop($parts);

        return implode(' ', $parts) ?: 'Student';
    }

    private function surname(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];

        return count($parts) > 1 ? (string) end($parts) : 'Student';
    }

    private function cleanText(?string $value): string
    {
        $clean = trim((string) preg_replace('/[^A-Za-z0-9 ]/', '', (string) $value));

        return $clean !== '' ? $clean : 'Student';
    }
}
