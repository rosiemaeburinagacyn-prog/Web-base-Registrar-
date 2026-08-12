<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_requests') || ! Schema::hasTable('document_request_items')) {
            return;
        }

        $amounts = [
            'TOR' => 150.00,
            'COR' => 50.00,
            'COG' => 75.00,
            'GOOD_MORAL' => 50.00,
            'OTHER' => 100.00,
        ];

        DB::table('document_requests')
            ->select(['id', 'document_type'])
            ->whereNotNull('document_type')
            ->orderBy('id')
            ->chunkById(100, function ($requests) use ($amounts) {
                foreach ($requests as $request) {
                    $hasItems = DB::table('document_request_items')
                        ->where('document_request_id', $request->id)
                        ->exists();

                    if ($hasItems) {
                        continue;
                    }

                    $unitPrice = $amounts[$request->document_type] ?? 0.00;

                    DB::table('document_request_items')->insert([
                        'document_request_id' => $request->id,
                        'document_type' => $request->document_type,
                        'quantity' => 1,
                        'unit_price' => $unitPrice,
                        'subtotal' => $unitPrice,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        //
    }
};
