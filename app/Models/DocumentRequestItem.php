<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRequestItem extends Model
{
    protected $fillable = [
        'document_request_id',
        'document_type',
        'quantity',
        'unit_price',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    public function label(): string
    {
        return DocumentRequest::DOCUMENT_TYPES[$this->document_type]['label'] ?? (string) $this->document_type;
    }
}
