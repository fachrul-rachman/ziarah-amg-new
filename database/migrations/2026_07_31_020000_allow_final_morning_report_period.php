<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE report_dispatches DROP CONSTRAINT report_dispatches_valid_period');
        DB::statement("ALTER TABLE report_dispatches ADD CONSTRAINT report_dispatches_valid_period CHECK (period IN ('morning_0700_1100', 'morning_final_0700_1100', 'afternoon_1200_1700'))");
    }

    public function down(): void
    {
        if (DB::table('report_dispatches')
            ->where('period', 'morning_final_0700_1100')
            ->exists()) {
            throw new RuntimeException(
                'Cannot remove final morning report support while dispatch history exists.',
            );
        }

        DB::statement('ALTER TABLE report_dispatches DROP CONSTRAINT report_dispatches_valid_period');
        DB::statement("ALTER TABLE report_dispatches ADD CONSTRAINT report_dispatches_valid_period CHECK (period IN ('morning_0700_1100', 'afternoon_1200_1700'))");
    }
};
