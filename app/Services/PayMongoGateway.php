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

class PayMongoGateway
{
    public function createCheckout(DocumentRequest $documentRequest): Payment
    {
        $documentRequest->loadMissing(['items', 'user', 'latestPayment']);

        if ($documentRequest->latestPayment?->isPaid()) {
            return $documentRequest->latestPayment;
        }

        $existingPayment = $documentRequest->payments()
            ->where('payment_status', Payment::STATUS_PENDING)
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
            'provider' => $this->fakeMode() ? Payment::PROVIDER_FAKE : Payment::PROVIDER_PAYMONGO,
            'amount' => $documentRequest->amount(),
            'payment_method' => Payment::METHOD_GCASH,
            'payment_status' => Payment::STATUS_PENDING,
            'status' => Payment::STATUS_PENDING,
            'metadata' => $this->metadata($documentRequest),
        ]);

        if ($this->fakeMode()) {
            $sessionId = 'cs_test_'.$payment->id.'_'.Str::lower(Str::random(8));

            $payment->update([
                'gateway_transaction_id' => $sessionId,
                'checkout_session_id' => $sessionId,
                'checkout_url' => route('payments.demo.checkout', $payment),
                'gateway_payload' => ['mode' => 'fake', 'checkout_session_id' => $sessionId],
            ]);

            return $payment->fresh();
        }

        $secretKey = trim((string) config('registrar.paymongo.secret_key'));

        if ($secretKey === '') {
            $payment->update([
                'payment_status' => Payment::STATUS_FAILED,
                'status' => Payment::STATUS_FAILED,
                'gateway_payload' => ['error' => 'Missing PAYMONGO_SECRET_KEY'],
            ]);

            throw ValidationException::withMessages([
                'payment' => 'Online payment is not configured. Set PAYMONGO_SECRET_KEY before accepting live GCash payments.',
            ]);
        }

        try {
            $response = Http::withBasicAuth($secretKey, '')
                ->acceptJson()
                ->asJson()
                ->post(rtrim((string) config('registrar.paymongo.base_url'), '/').'/v2/checkout_sessions', [
                    'data' => [
                        'attributes' => [
                            'line_items' => $this->lineItems($documentRequest),
                            'payment_method_types' => ['gcash'],
                            'success_url' => route('payments.return', $payment),
                            'cancel_url' => route('payments.return', ['payment' => $payment, 'cancelled' => 1]),
                            'reference_number' => $payment->reference,
                            'send_email_receipt' => true,
                            'metadata' => [
                                'document_request_id' => (string) $documentRequest->id,
                                'request_reference' => $documentRequest->request_reference,
                                'student_number' => $documentRequest->student_id,
                            ],
                        ],
                    ],
                ])
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
                'payment' => $this->checkoutErrorMessage($exception),
            ]);
        }

        $sessionId = data_get($response, 'data.id');
        $checkoutUrl = data_get($response, 'data.attributes.checkout_url');

        if (! $sessionId || ! $checkoutUrl) {
            $payment->update([
                'payment_status' => Payment::STATUS_FAILED,
                'status' => Payment::STATUS_FAILED,
                'gateway_payload' => $response,
            ]);

            throw ValidationException::withMessages([
                'payment' => 'PayMongo did not return a checkout URL.',
            ]);
        }

        $payment->update([
            'gateway_transaction_id' => $sessionId,
            'checkout_session_id' => $sessionId,
            'checkout_url' => $checkoutUrl,
            'gateway_payload' => $response,
        ]);

        return $payment->fresh();
    }

    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) config('registrar.paymongo.webhook_secret');

        if ($secret === '') {
            return true;
        }

        $signatureHeader = $request->header('Paymongo-Signature')
            ?: $request->header('PayMongo-Signature')
            ?: $request->header('X-Paymongo-Signature');

        if (! $signatureHeader) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key && $value) {
                $parts[$key] = $value;
            }
        }

        $timestamp = $parts['t'] ?? null;
        $signature = $parts['v1'] ?? $parts['te'] ?? $parts['li'] ?? null;

        if (! $timestamp || ! $signature) {
            return false;
        }

        $payload = $timestamp.'.'.$request->getContent();
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * @return array{resource_id: string, reference: string, document_request_id: mixed, status: string, payload: array<string, mixed>}
     */
    public function webhookEvent(Request $request): array
    {
        return $this->eventFromPayload($request->json()->all());
    }

    /**
     * @return null|array{resource_id: string, reference: string, document_request_id: mixed, status: string, payload: array<string, mixed>}
     */
    public function paymentStatus(Payment $payment): ?array
    {
        $secretKey = trim((string) config('registrar.paymongo.secret_key'));
        $sessionId = $payment->checkout_session_id ?: $payment->gateway_transaction_id;

        if ($secretKey === '' || ! $sessionId) {
            return null;
        }

        try {
            $payload = Http::withBasicAuth($secretKey, '')
                ->acceptJson()
                ->get(rtrim((string) config('registrar.paymongo.base_url'), '/').'/v2/checkout_sessions/'.$sessionId)
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            Log::warning('Unable to sync PayMongo checkout session status.', [
                'payment_id' => $payment->id,
                'checkout_session_id' => $sessionId,
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
        $resource = data_get($payload, 'data.attributes.data')
            ?: data_get($payload, 'data.data')
            ?: data_get($payload, 'data');

        $attributes = (array) data_get($resource, 'attributes', []);
        $eventType = (string) (
            data_get($payload, 'data.attributes.type')
            ?: data_get($payload, 'data.type')
            ?: data_get($payload, 'type')
            ?: data_get($resource, 'type')
            ?: ''
        );

        return [
            'resource_id' => (string) data_get($resource, 'id', ''),
            'reference' => (string) (
                $attributes['reference_number']
                ?? data_get($attributes, 'metadata.reference')
                ?? data_get($payload, 'data.attributes.reference_number')
                ?? ''
            ),
            'document_request_id' => data_get($attributes, 'metadata.document_request_id')
                ?: data_get($payload, 'data.attributes.metadata.document_request_id'),
            'status' => $this->statusFromGatewayPayload($eventType, $this->gatewayStatus($attributes)),
            'payload' => $payload,
        ];
    }

    /**
     * @return array<int, array{name: string, amount: int, currency: string, quantity: int}>
     */
    private function lineItems(DocumentRequest $documentRequest): array
    {
        return $documentRequest->itemSummary()
            ->map(fn (DocumentRequestItem $item) => [
                'name' => $item->label(),
                'amount' => (int) round(((float) $item->unit_price) * 100),
                'currency' => 'PHP',
                'quantity' => (int) $item->quantity,
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

    private function paymentReference(): string
    {
        do {
            $reference = 'PAY-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
        } while (Payment::where('reference', $reference)->exists());

        return $reference;
    }

    private function fakeMode(): bool
    {
        return config('registrar.payments.gateway') === 'fake'
            || (app()->environment('testing') && ! config('registrar.paymongo.secret_key'));
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

        if (str_contains($text, 'paid')
            || str_contains($text, 'success')
            || str_contains($text, 'succeed')
            || str_contains($text, 'complete')) {
            return Payment::STATUS_PAID;
        }

        return Payment::STATUS_PENDING;
    }

    private function checkoutErrorMessage(RequestException $exception): string
    {
        $errors = (array) $exception->response?->json('errors', []);
        $firstError = (array) ($errors[0] ?? []);
        $code = (string) ($firstError['code'] ?? '');
        $detail = (string) ($firstError['detail'] ?? '');

        if (in_array($code, ['api_key_invalid', 'authentication_failed'], true)) {
            return 'PayMongo rejected the API key. Save a valid PayMongo test secret key in PAYMONGO_SECRET_KEY, then run php artisan config:clear.';
        }

        if ($detail !== '') {
            return 'PayMongo rejected the checkout request: '.$detail;
        }

        return 'Unable to create the PayMongo checkout session. Please try again later.';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function gatewayStatus(array $attributes): string
    {
        $statuses = [
            (string) ($attributes['status'] ?? ''),
            (string) data_get($attributes, 'payment_intent.attributes.status', ''),
            (string) data_get($attributes, 'payment.attributes.status', ''),
        ];

        foreach ((array) ($attributes['payments'] ?? []) as $payment) {
            $statuses[] = (string) data_get($payment, 'attributes.status', '');
        }

        return implode(' ', array_filter($statuses));
    }
}
