<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('document_requests')
            ->whereNull('request_reference')
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(100, function ($requests) {
                foreach ($requests as $request) {
                    DB::table('document_requests')
                        ->where('id', $request->id)
                        ->update([
                            'request_reference' => 'REQ-'.now()->format('YmdHis').'-'.str_pad((string) $request->id, 5, '0', STR_PAD_LEFT),
                        ]);
                }
            });

        DB::table('payments')
            ->whereIn('status', ['Paid', 'Approved'])
            ->update(['payment_status' => 'Approved']);

        DB::table('payments')
            ->where('status', 'Rejected')
            ->update(['payment_status' => 'Rejected']);

        DB::table('payments')
            ->where('status', 'Pending Verification')
            ->update(['payment_status' => 'Pending Verification']);
    }

    public function down(): void
    {
        //
    }
};
