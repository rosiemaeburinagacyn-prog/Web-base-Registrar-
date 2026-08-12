<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        if (! Schema::hasColumn('payments', 'request_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->foreignId('request_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('document_requests')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('payments', 'student_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->foreignId('student_id')
                    ->nullable()
                    ->after('request_id')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('payments', 'payment_method')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('payment_method')->default('GCash')->after('amount');
            });
        }

        if (! Schema::hasColumn('payments', 'reference_number')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('reference_number')->nullable()->after('payment_method');
            });
        }

        if (! Schema::hasColumn('payments', 'proof_of_payment')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('proof_of_payment')->nullable()->after('reference_number');
            });
        }

        if (! Schema::hasColumn('payments', 'payment_status')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('payment_status', 40)->default('Pending Verification')->after('proof_of_payment')->index();
            });
        }

        if (! Schema::hasColumn('payments', 'verified_by')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->foreignId('verified_by')
                    ->nullable()
                    ->after('payment_status')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('payments', 'verified_at')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->timestamp('verified_at')->nullable()->after('verified_by');
            });
        }

        if (! Schema::hasColumn('payments', 'rejection_reason')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->text('rejection_reason')->nullable()->after('verified_at');
            });
        }

        DB::table('payments')
            ->whereNull('request_id')
            ->update(['request_id' => DB::raw('document_request_id')]);

        DB::table('payments')
            ->whereNull('student_id')
            ->update(['student_id' => DB::raw('user_id')]);

        DB::table('payments')
            ->whereNull('payment_status')
            ->update(['payment_status' => DB::raw('status')]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        foreach ([
            'rejection_reason',
            'verified_at',
            'verified_by',
            'payment_status',
            'proof_of_payment',
            'reference_number',
            'payment_method',
            'student_id',
            'request_id',
        ] as $column) {
            if (Schema::hasColumn('payments', $column)) {
                Schema::table('payments', function (Blueprint $table) use ($column) {
                    if (in_array($column, ['verified_by', 'student_id', 'request_id'], true)) {
                        $table->dropConstrainedForeignId($column);

                        return;
                    }

                    $table->dropColumn($column);
                });
            }
        }
    }
};
