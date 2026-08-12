<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        if (! Schema::hasColumn('payments', 'receipt_number')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('receipt_number', 50)->nullable()->unique()->after('id');
            });
        }

        if (! Schema::hasColumn('payments', 'cashier_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->foreignId('cashier_id')
                    ->nullable()
                    ->after('verified_by')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('payments', 'official_receipt_path')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('official_receipt_path')->nullable()->after('rejection_reason');
            });
        }

        if (! Schema::hasColumn('payments', 'generated_at')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->timestamp('generated_at')->nullable()->after('official_receipt_path');
            });
        }

        if (! Schema::hasTable('document_request_items')) {
            Schema::create('document_request_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('document_request_id')->constrained()->cascadeOnDelete();
                $table->string('document_type');
                $table->integer('quantity')->default(1);
                $table->decimal('unit_price', 10, 2);
                $table->decimal('subtotal', 10, 2);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('document_request_items')) {
            Schema::dropIfExists('document_request_items');
        }

        if (! Schema::hasTable('payments')) {
            return;
        }

        $columnsToRemove = [
            'generated_at',
            'official_receipt_path',
            'cashier_id',
            'receipt_number',
        ];

        foreach ($columnsToRemove as $column) {
            if (Schema::hasColumn('payments', $column)) {
                Schema::table('payments', function (Blueprint $table) use ($column) {
                    if (in_array($column, ['cashier_id'], true)) {
                        $table->dropConstrainedForeignId($column);
                        return;
                    }
                    $table->dropColumn($column);
                });
            }
        }
    }
};
