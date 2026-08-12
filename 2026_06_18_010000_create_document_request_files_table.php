<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_request_files')) {
            Schema::create('document_request_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('document_request_id')->constrained()->cascadeOnDelete();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('file_path');
                $table->string('original_name')->nullable();
                $table->string('mime_type', 100)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->timestamp('uploaded_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('document_requests') || ! Schema::hasColumn('document_requests', 'uploaded_file')) {
            return;
        }

        DB::table('document_requests')
            ->whereNotNull('uploaded_file')
            ->orderBy('id')
            ->select(['id', 'uploaded_file', 'updated_at'])
            ->chunkById(100, function ($requests) {
                foreach ($requests as $request) {
                    $exists = DB::table('document_request_files')
                        ->where('document_request_id', $request->id)
                        ->where('file_path', $request->uploaded_file)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('document_request_files')->insert([
                        'document_request_id' => $request->id,
                        'file_path' => $request->uploaded_file,
                        'original_name' => basename($request->uploaded_file),
                        'mime_type' => 'application/pdf',
                        'uploaded_at' => $request->updated_at,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_request_files');
    }
};
