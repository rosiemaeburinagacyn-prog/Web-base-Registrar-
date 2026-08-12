<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'student_number')) {
                    $table->string('student_number', 50)->nullable()->unique()->after('id');
                }

                if (! Schema::hasColumn('users', 'course')) {
                    $table->string('course')->nullable()->after('email');
                }

                if (! Schema::hasColumn('users', 'year_level')) {
                    $table->string('year_level', 30)->nullable()->after('course');
                }

                if (! Schema::hasColumn('users', 'school_email')) {
                    $table->string('school_email')->nullable()->unique()->after('year_level');
                }

                if (! Schema::hasColumn('users', 'account_status')) {
                    $table->string('account_status', 30)->default('active')->after('role')->index();
                }

                if (! Schema::hasColumn('users', 'verification_status')) {
                    $table->string('verification_status', 30)->default('unsubmitted')->after('account_status')->index();
                }

                if (! Schema::hasColumn('users', 'school_id_path')) {
                    $table->string('school_id_path')->nullable()->after('verification_status');
                }

                if (! Schema::hasColumn('users', 'selfie_id_path')) {
                    $table->string('selfie_id_path')->nullable()->after('school_id_path');
                }

                if (! Schema::hasColumn('users', 'verification_submitted_at')) {
                    $table->timestamp('verification_submitted_at')->nullable()->after('selfie_id_path');
                }

                if (! Schema::hasColumn('users', 'verification_reviewed_at')) {
                    $table->timestamp('verification_reviewed_at')->nullable()->after('verification_submitted_at');
                }

                if (! Schema::hasColumn('users', 'verification_reviewed_by')) {
                    $table->foreignId('verification_reviewed_by')
                        ->nullable()
                        ->after('verification_reviewed_at')
                        ->constrained('users')
                        ->nullOnDelete();
                }

                if (! Schema::hasColumn('users', 'verification_note')) {
                    $table->text('verification_note')->nullable()->after('verification_reviewed_by');
                }

                if (! Schema::hasColumn('users', 'password_reset_otp_hash')) {
                    $table->string('password_reset_otp_hash')->nullable()->after('remember_token');
                }

                if (! Schema::hasColumn('users', 'password_reset_otp_expires_at')) {
                    $table->timestamp('password_reset_otp_expires_at')->nullable()->after('password_reset_otp_hash');
                }
            });

            DB::table('users')
                ->where('role', 'student')
                ->whereNull('school_email')
                ->update(['school_email' => DB::raw('email')]);

            DB::table('users')
                ->where('role', 'student')
                ->whereNull('verification_status')
                ->update(['verification_status' => 'unsubmitted']);
        }

        if (! Schema::hasTable('students')) {
            Schema::create('students', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
                $table->string('student_number', 50)->unique();
                $table->string('full_name');
                $table->string('course')->nullable();
                $table->string('year_level', 30)->nullable();
                $table->string('school_email')->unique();
                $table->string('password_hash')->nullable();
                $table->string('account_status', 30)->default('active')->index();
                $table->string('verification_status', 30)->default('unsubmitted')->index();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('document_requests')) {
            Schema::table('document_requests', function (Blueprint $table) {
                if (! Schema::hasColumn('document_requests', 'amount')) {
                    $table->decimal('amount', 10, 2)->nullable()->after('document_type');
                }
            });
        }

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (! Schema::hasColumn('payments', 'gateway_transaction_id')) {
                    $table->string('gateway_transaction_id')->nullable()->index()->after('reference');
                }

                if (! Schema::hasColumn('payments', 'checkout_session_id')) {
                    $table->string('checkout_session_id')->nullable()->index()->after('gateway_transaction_id');
                }

                if (! Schema::hasColumn('payments', 'checkout_url')) {
                    $table->text('checkout_url')->nullable()->after('checkout_session_id');
                }

                if (! Schema::hasColumn('payments', 'gateway_payload')) {
                    $table->json('gateway_payload')->nullable()->after('metadata');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payments')) {
            foreach (['gateway_payload', 'checkout_url', 'checkout_session_id', 'gateway_transaction_id'] as $column) {
                if (Schema::hasColumn('payments', $column)) {
                    Schema::table('payments', function (Blueprint $table) use ($column) {
                        $table->dropColumn($column);
                    });
                }
            }
        }

        if (Schema::hasTable('document_requests') && Schema::hasColumn('document_requests', 'amount')) {
            Schema::table('document_requests', function (Blueprint $table) {
                $table->dropColumn('amount');
            });
        }

        Schema::dropIfExists('students');

        if (! Schema::hasTable('users')) {
            return;
        }

        foreach ([
            'password_reset_otp_expires_at',
            'password_reset_otp_hash',
            'verification_note',
            'verification_reviewed_by',
            'verification_reviewed_at',
            'verification_submitted_at',
            'selfie_id_path',
            'school_id_path',
            'verification_status',
            'account_status',
            'school_email',
            'year_level',
            'course',
            'student_number',
        ] as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', function (Blueprint $table) use ($column) {
                    if ($column === 'verification_reviewed_by') {
                        $table->dropConstrainedForeignId($column);

                        return;
                    }

                    $table->dropColumn($column);
                });
            }
        }
    }
};
