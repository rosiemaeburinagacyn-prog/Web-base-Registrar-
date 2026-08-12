<?php

namespace App\Services;

use App\Models\DocumentRequest;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentGatewayManager
{
    public function __construct(
        private readonly PayMongoGateway $paymongo,
        private readonly XenditGateway $xendit,
    ) {
    }

    public function createCheckout(DocumentRequest $documentRequest): Payment
    {
        return $this->activeGateway()->createCheckout($documentRequest);
    }

    public function verifyWebhook(Request $request): bool
    {
        return $this->webhookGateway($request)->verifyWebhook($request);
    }

    /**
     * @return array{resource_id: string, reference: string, document_request_id: mixed, status: string, payload: array<string, mixed>}
     */
    public function webhookEvent(Request $request): array
    {
        return $this->webhookGateway($request)->webhookEvent($request);
    }

    /**
     * @return null|array{resource_id: string, reference: string, document_request_id: mixed, status: string, payload: array<string, mixed>}
     */
    public function paymentStatus(Payment $payment): ?array
    {
        return match (Str::lower((string) $payment->provider)) {
            Payment::PROVIDER_PAYMONGO => $this->paymongo->paymentStatus($payment),
            Payment::PROVIDER_XENDIT => $this->xendit->paymentStatus($payment),
            default => null,
        };
    }

    private function activeGateway(): PayMongoGateway|XenditGateway
    {
        return match ($this->gatewayName()) {
            'fake', 'paymongo' => $this->paymongo,
            'xendit' => $this->xendit,
            default => throw ValidationException::withMessages([
                'payment' => 'Unsupported payment gateway. Set PAYMENT_GATEWAY to xendit, paymongo, or fake.',
            ]),
        };
    }

    private function webhookGateway(Request $request): PayMongoGateway|XenditGateway
    {
        $path = Str::lower($request->path());

        if (str_contains($path, 'xendit')) {
            return $this->xendit;
        }

        if (str_contains($path, 'paymongo')) {
            return $this->paymongo;
        }

        return $this->gatewayName() === 'xendit'
            ? $this->xendit
            : $this->paymongo;
    }

    private function gatewayName(): string
    {
        return Str::lower((string) config('registrar.payments.gateway', 'paymongo'));
    }
}
