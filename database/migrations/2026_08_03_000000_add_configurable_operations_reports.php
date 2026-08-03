<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations_report_configurations', function (Blueprint $table): void {
            $table->id();
            $table->date('effective_from')->unique();
            $table->unsignedSmallInteger('minimum_lead_hours');
            $table->jsonb('report_schedules');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE operations_report_configurations ADD CONSTRAINT operations_report_configurations_positive_lead CHECK (minimum_lead_hours > 0)');
        DB::statement("ALTER TABLE operations_report_configurations ADD CONSTRAINT operations_report_configurations_valid_schedules CHECK (jsonb_typeof(report_schedules) = 'array' AND jsonb_array_length(report_schedules) BETWEEN 1 AND 3)");

        $defaults = json_encode([
            ['day_offset' => -1, 'time' => '15:00'],
            ['day_offset' => 0, 'time' => '07:00'],
        ], JSON_THROW_ON_ERROR);
        $effectiveFrom = CarbonImmutable::now((string) config('app.business_timezone'))
            ->startOfDay()
            ->addDays(2)
            ->toDateString();

        DB::table('operations_report_configurations')->insert([
            'effective_from' => $effectiveFrom,
            'minimum_lead_hours' => 18,
            'report_schedules' => $defaults,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('report_dispatches', function (Blueprint $table): void {
            $table->jsonb('visit_times')->nullable();
        });
        DB::statement("ALTER TABLE report_dispatches ADD CONSTRAINT report_dispatches_valid_visit_times CHECK (visit_times IS NULL OR (jsonb_typeof(visit_times) = 'array' AND jsonb_array_length(visit_times) > 0))");
        DB::statement('ALTER TABLE report_dispatches DROP CONSTRAINT report_dispatches_valid_period');
    }

    public function down(): void
    {
        if (DB::table('report_dispatches')->whereLike('period', 'scheduled_%')->exists()) {
            throw new RuntimeException('Cannot remove configurable reports while dispatch history exists.');
        }

        DB::statement("ALTER TABLE report_dispatches ADD CONSTRAINT report_dispatches_valid_period CHECK (period IN ('morning_0700_1100', 'morning_final_0700_1100', 'afternoon_1200_1700'))");
        DB::statement('ALTER TABLE report_dispatches DROP CONSTRAINT report_dispatches_valid_visit_times');
        Schema::table('report_dispatches', function (Blueprint $table): void {
            $table->dropColumn('visit_times');
        });
        Schema::dropIfExists('operations_report_configurations');
    }
};
