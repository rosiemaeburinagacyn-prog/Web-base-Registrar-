<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class DocumentRequest extends Model
{
    public const PAYMENT_PENDING = 'Pending';
    public const PAYMENT_PENDING_VERIFICATION = self::PAYMENT_PENDING;
    public const PAYMENT_PAID = 'Paid';
    public const PAYMENT_APPROVED = self::PAYMENT_PAID;
    public const PAYMENT_FAILED = 'Failed';
    public const PAYMENT_REJECTED = self::PAYMENT_FAILED;
    public const PAYMENT_REFUNDED = 'Refunded';

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_PENDING = 'Pending';
    public const STATUS_PENDING_PAYMENT = self::STATUS_PENDING;
    public const STATUS_PAYMENT_SUBMITTED = self::STATUS_PENDING;
    public const STATUS_PENDING_PAYMENT_VERIFICATION = self::STATUS_PENDING;
    public const STATUS_AWAITING_PAYMENT_CONFIRMATION = self::STATUS_PENDING;
    public const STATUS_PAYMENT_APPROVED = self::STATUS_PENDING;
    public const STATUS_PAYMENT_REJECTED = 'Payment Failed';
    public const STATUS_FOR_PROCESSING = 'Processing';
    public const STATUS_PROCESSING = self::STATUS_FOR_PROCESSING;
    public const STATUS_READY_FOR_PICKUP = 'Ready for Pickup';
    public const STATUS_READY_FOR_DOWNLOAD = self::STATUS_READY_FOR_PICKUP;
    public const STATUS_READY_FOR_RELEASE = self::STATUS_READY_FOR_PICKUP;
    public const STATUS_RELEASED = 'Released';
    public const STATUS_COMPLETED = self::STATUS_RELEASED;

    public const STATUS_FOR_REVIEW = self::STATUS_AWAITING_PAYMENT_CONFIRMATION;
    public const STATUS_APPROVED = self::STATUS_PAYMENT_APPROVED;
    public const STATUS_REJECTED = self::STATUS_PAYMENT_REJECTED;

    public const DOCUMENT_TYPES = [
        'TOR' => [
            'label' => 'Transcript of Records',
            'amount' => 150.00,
        ],
        'COR' => [
            'label' => 'Certificate of Registration',
            'amount' => 50.00,
        ],
        'COG' => [
            'label' => 'Certificate of Grades',
            'amount' => 75.00,
        ],
        'GOOD_MORAL' => [
            'label' => 'Good Moral Certificate',
            'amount' => 50.00,
        ],
        'OTHER' => [
            'label' => 'Other Documents',
            'amount' => 100.00,
        ],
    ];

    public const SEMESTERS = [
        'First Semester',
        'Second Semester',
        'Summer',
    ];

    public const REQUEST_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_PAYMENT_REJECTED,
        self::STATUS_FOR_PROCESSING,
        self::STATUS_READY_FOR_PICKUP,
        self::STATUS_RELEASED,
    ];

    protected $fillable = [
        'user_id',
        'request_reference',
        'student_name',
        'student_id',
        'document_type',
        'amount',
        'academic_year_id',
        'academic_year',
        'semester',
        'payment_status',
        'request_status',
        'uploaded_file',
        'admin_note',
        'reviewed_at',
        'completed_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'completed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentRequestItem::class);
    }

    public function completedFiles(): HasMany
    {
        return $this->hasMany(DocumentRequestFile::class);
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public static function documentTypeKeys(): array
    {
        return array_keys(self::DOCUMENT_TYPES);
    }

    public static function amountFor(?string $documentType): float
    {
        return self::DOCUMENT_TYPES[$documentType]['amount'] ?? 0.00;
    }

    public function documentLabel(): string
    {
        if ($this->relationLoaded('items') && $this->items->count() > 1) {
            return 'Multiple Documents';
        }

        return self::DOCUMENT_TYPES[$this->document_type]['label'] ?? (string) $this->document_type;
    }

    public function amount(): float
    {
        if ($this->relationLoaded('items') && $this->items->isNotEmpty()) {
            return (float) $this->items->sum(fn (DocumentRequestItem $item) => (float) $item->subtotal);
        }

        if (! $this->relationLoaded('items') && $this->items()->exists()) {
            return (float) $this->items()->sum('subtotal');
        }

        return (float) ($this->amount ?: self::amountFor($this->document_type));
    }

    public function itemSummary(): Collection
    {
        if ($this->relationLoaded('items') && $this->items->isNotEmpty()) {
            return $this->items;
        }

        if (! $this->relationLoaded('items')) {
            $items = $this->items()->get();

            if ($items->isNotEmpty()) {
                return $items;
            }
        }

        return collect([
            new DocumentRequestItem([
                'document_type' => $this->document_type,
                'quantity' => 1,
                'unit_price' => self::amountFor($this->document_type),
                'subtotal' => self::amountFor($this->document_type),
            ]),
        ]);
    }

    public function isPaid(): bool
    {
        return in_array($this->payment_status, [self::PAYMENT_PAID, 'Approved'], true);
    }

    public function isRejected(): bool
    {
        return $this->request_status === self::STATUS_PAYMENT_REJECTED;
    }

    public function canBeDownloadedByStudent(): bool
    {
        $hasFiles = $this->relationLoaded('completedFiles')
            ? $this->completedFiles->isNotEmpty()
            : $this->completedFiles()->exists();

        return in_array($this->request_status, [
            self::STATUS_RELEASED,
            'Ready for Download',
            'Completed',
        ], true) && ($hasFiles || (bool) $this->uploaded_file);
    }
}
