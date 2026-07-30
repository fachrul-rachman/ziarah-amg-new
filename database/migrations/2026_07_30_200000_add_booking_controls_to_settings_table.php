<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('booking_window_days')->default(100);
            $table->string('booking_limit_mode', 16)->default('daily');
            $table->unsignedInteger('hourly_booking_limit')->nullable();
        });

        DB::statement('ALTER TABLE settings ADD CONSTRAINT settings_valid_booking_window CHECK (booking_window_days BETWEEN 1 AND 100)');
        DB::statement("ALTER TABLE settings ADD CONSTRAINT settings_valid_booking_limit_mode CHECK (booking_limit_mode IN ('daily', 'hourly'))");
        DB::statement('ALTER TABLE settings ADD CONSTRAINT settings_positive_hourly_limit CHECK (hourly_booking_limit IS NULL OR hourly_booking_limit > 0)');
        DB::statement("ALTER TABLE settings ADD CONSTRAINT settings_hourly_limit_required CHECK (booking_limit_mode <> 'hourly' OR hourly_booking_limit IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE settings DROP CONSTRAINT IF EXISTS settings_hourly_limit_required');
        DB::statement('ALTER TABLE settings DROP CONSTRAINT IF EXISTS settings_positive_hourly_limit');
        DB::statement('ALTER TABLE settings DROP CONSTRAINT IF EXISTS settings_valid_booking_limit_mode');
        DB::statement('ALTER TABLE settings DROP CONSTRAINT IF EXISTS settings_valid_booking_window');

        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn([
                'booking_window_days',
                'booking_limit_mode',
                'hourly_booking_limit',
            ]);
        });
    }
};
