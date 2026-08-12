<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_requests')) {
            return;
        }

        if (! Schema::hasColumn('document_requests', 'request_reference')) {
            Schema::table('document_requests', function (Blueprint $table) {
                $table->string('request_reference')->nullable()->unique()->after('id');
            });
        }

        if (! Schema::hasColumn('document_requests', 'academic_year_id')) {
            Schema::table('document_requests', function (Blueprint $table) {
                $table->foreignId('academic_year_id')
                    ->nullable()
                    ->after('document_type')
                    ->constrained()
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('document_requests', 'academic_year')) {
            Schema::table('document_requests', function (Blueprint $table) {
                $table->string('academic_year')->nullable()->after('academic_year_id');
            });
        }

        if (! Schema::hasColumn('document_requests', 'semester')) {
            Schema::table('document_requests', function (Blueprint $table) {
                $table->string('semester', 30)->nullable()->after('academic_year');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('document_requests')) {
            return;
        }

        foreach (['semester', 'academic_year', 'academic_year_id', 'request_reference'] as $column) {
            if (Schema::hasColumn('document_requests', $column)) {
                Schema::table('document_requests', function (Blueprint $table) use ($column) {
                    if ($column === 'academic_year_id') {
                        $table->dropConstrainedForeignId($column);

                        return;
                    }

                    $table->dropColumn($column);
                });
            }
        }
    }
};
