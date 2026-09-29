<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allocation rows are one-per (payment, document) pair — both
     * allocateToInvoice() and allocateToBill() find-or-increment on that
     * key. The lookup-then-insert they historically used could race and
     * leave duplicate pairs (silently double-counting the allocation),
     * so the index enforces the invariant the code already assumes.
     * Any pre-existing duplicates are merged first: amounts sum, which
     * is exactly what every reader of the table already computed.
     */
    public function up(): void
    {
        $pairs = [
            'payment_allocations' => ['payment_id', 'invoice_id'],
            'bill_payment_allocations' => ['bill_payment_id', 'bill_id'],
        ];

        foreach ($pairs as $table => [$owner, $target]) {
            DB::table($table)
                ->select($owner, $target, DB::raw('COUNT(*) as pair_count'))
                ->groupBy($owner, $target)
                ->having('pair_count', '>', 1)
                ->get()
                ->each(function ($duplicate) use ($table, $owner, $target) {
                    $rows = DB::table($table)
                        ->where($owner, $duplicate->{$owner})
                        ->where($target, $duplicate->{$target})
                        ->orderBy('id')
                        ->get();

                    $keep = $rows->first();
                    DB::table($table)
                        ->where($owner, $duplicate->{$owner})
                        ->where($target, $duplicate->{$target})
                        ->where('id', '!=', $keep->id)
                        ->delete();
                    DB::table($table)
                        ->where('id', $keep->id)
                        ->update(['amount' => $rows->sum('amount')]);
                });

            Schema::table($table, function (Blueprint $blueprint) use ($owner, $target, $table) {
                $blueprint->unique([$owner, $target], "{$table}_owner_pair_unique");
            });
        }
    }

    public function down(): void
    {
        foreach (['payment_allocations', 'bill_payment_allocations'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex("{$table}_owner_pair_unique");
            });
        }
    }
};
