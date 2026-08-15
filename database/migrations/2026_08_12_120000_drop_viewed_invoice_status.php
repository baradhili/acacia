<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The 'viewed' invoice status has been removed (the state machine now goes
 * draft -> sent -> partially_paid/overdue -> paid). Any production invoices
 * still sitting in 'viewed' are folded back into 'sent', which is the
 * semantically equivalent outstanding state and is present in every query
 * that previously included 'viewed'.
 *
 * This is a one-time data fix; the `status` column is already a string, so
 * there is no schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('invoices')
            ->where('status', 'viewed')
            ->update(['status' => 'sent']);
    }

    public function down(): void
    {
        // Intentionally a no-op: we cannot reconstruct which 'sent' invoices
        // were originally 'viewed', so rolling back would fabricate data.
    }
};
