<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repair live databases whose migrations were marked as ran before the
     * registrar fields were added to the migration files.
     */
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role')->default('student')->after('password')->index();
            });
        }

        if (! Schema::hasTable('document_requests')) {
            return;
        }

        if (! Schema::hasColumn('document_requests', 'student_name')) {
            Schema::table('document_requests', function (Blueprint $table) {
                $table->string('student_name')->nullable()->after('user_id');
            });
        }

        if (! Schema::hasColumn('document_requests', 'student_id')) {
            Schema::table('document_requests', function (Blueprint $table) {
                $table->string('student_id', 50)->nullable()->after('student_name')->index();
            });
        }

        if (! Schema::hasColumn('document_requests', 'document_type')) {
            Schema::table('document_requests', function (Blueprint $table) {
                $table->string('document_type', 20)->nullable()->after('student_id');
            });
        }

        if (! Schema::hasColumn('document_requests', 'payment_status')) {
            Schema::table('document_requests', function (Blueprint $table) {
                $table->string('payment_status', 30)->default('Pending')->after('document_type')->index();
            });
        }

        if (! Schema::hasColumn('document_requests', 'request_status')) {
            Schema::table('document_requests', function (Blueprint $table) {
                $table->string('request_status', 40)->default('Pending Payment')->after('payment_status')->index();
            });
        }

        if (! Schema::hasColumn('document_requests', 'uploaded_file')) {
            Schema::table('document_requests', function (Blueprint $table) {
                $table->string('uploaded_file')->nullable()->after('request_status');
            });
        }

        if (! Schema::hasColumn('document_requests', 'admin_note')) {
            Schema::table('document_requests', function (Blueprint $table) {
                $table->text('admin_note')->nullable()->after('uploaded_file');
            });
        }

        if (! Schema::hasColumn('document_requests', 'reviewed_at')) {
            Schema::table('document_requests', function (Blueprint $table) {
                $table->timestamp('reviewed_at')->nullable()->after('admin_note');
            });
        }

        if (! Schema::hasColumn('document_requests', 'completed_at')) {
            Schema::table('document_requests', function (Blueprint $table) {
                $table->timestamp('completed_at')->nullable()->after('reviewed_at');
            });
        }

        if (! Schema::hasColumn('document_requests', 'released_at')) {
            Schema::table('document_requests', function (Blueprint $table) {
                $table->timestamp('released_at')->nullable()->after('completed_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }

        if (! Schema::hasTable('document_requests')) {
            return;
        }

        $columns = [
            'student_name',
            'student_id',
            'document_type',
            'payment_status',
            'request_status',
            'uploaded_file',
            'admin_note',
            'reviewed_at',
            'completed_at',
            'released_at',
        ];

        foreach ($columns as $column) {
            if (Schema::hasColumn('document_requests', $column)) {
                Schema::table('document_requests', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
