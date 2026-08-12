<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const STATUS_PENDING = 'Pending';
    public const STATUS_PENDING_VERIFICATION = self::STATUS_PENDING;
    public const STATUS_APPROVED = 'Paid';
    public const STATUS_PAID = self::STATUS_APPROVED;
    public const STATUS_FAILED = 'Failed';
    public const STATUS_REJECTED = self::STATUS_FAILED;
    public const STATUS_REFUNDED = 'Refunded';

    public const METHOD_GCASH = 'GCash';
    public const PROVIDER_PAYMONGO = 'paymongo';
    public const PROVIDER_XENDIT = 'xendit';
    public const PROVIDER_FAKE = 'fake';

    protected $fillable = [
        'request_id',
        'receipt_number',
        'student_id',
        'document_request_id',
        'user_id',
        'reference',
        'gateway_transaction_id',
        'checkout_session_id',
        'checkout_url',
        'provider',
        'amount',
        'payment_method',
        'reference_number',
        'proof_of_payment',
        'payment_status',
        'verified_by',
        'cashier_id',
        'verified_at',
        'rejection_reason',
        'official_receipt_path',
        'generated_at',
        'status',
        'paid_at',
        'metadata',
        'gateway_payload',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
            'generated_at' => 'datetime',
            'metadata' => 'array',
            'gateway_payload' => 'array',
        ];
    }

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class, 'request_id');
    }

    public function isPaid(): bool
    {
        return in_array($this->payment_status, [self::STATUS_PAID, 'Approved'], true);
    }
}
