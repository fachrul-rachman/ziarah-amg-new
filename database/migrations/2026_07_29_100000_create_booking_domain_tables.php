<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_email_unique');
        });
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (LOWER(email))');

        Schema::create('zones', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE zones ADD CONSTRAINT zones_name_trimmed CHECK (name = BTRIM(name) AND name <> \'\')');
        DB::statement('CREATE UNIQUE INDEX zones_name_lower_unique ON zones (LOWER(name))');

        Schema::create('time_slots', function (Blueprint $table): void {
            $table->id();
            $table->time('start_time', precision: 0)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE time_slots ADD CONSTRAINT time_slots_exact_hour CHECK (EXTRACT(MINUTE FROM start_time) = 0 AND EXTRACT(SECOND FROM start_time) = 0)');

        Schema::create('settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->unsignedInteger('daily_booking_limit');
            $table->string('operations_email')->nullable();
            $table->text('discord_webhook')->nullable();
            $table->jsonb('embed_allowed_origins')->default('[]');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE settings ADD CONSTRAINT settings_singleton CHECK (id = 1)');
        DB::statement('ALTER TABLE settings ADD CONSTRAINT settings_positive_daily_limit CHECK (daily_booking_limit > 0)');

        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_reference')->unique();
            $table->string('status', 16)->default('confirmed')->index();
            $table->date('visit_date')->index();
            $table->time('visit_time', precision: 0);
            $table->foreignId('zone_id')->constrained()->restrictOnDelete();
            $table->string('zone_name_snapshot');
            $table->string('lot_number');
            $table->boolean('tent_required');
            $table->unsignedSmallInteger('chair_count');
            $table->string('customer_name');
            $table->string('customer_email')->index();
            $table->string('customer_phone', 50)->index();
            $table->string('deceased_name');
            $table->text('additional_notes')->nullable();
            $table->timestampTz('ethics_confirmed_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['visit_date', 'visit_time']);
            $table->index(['status', 'visit_date', 'visit_time']);
        });
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_valid_status CHECK (status IN ('confirmed', 'cancelled', 'completed'))");
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_valid_lot CHECK (lot_number ~ '^[A-Z0-9]+$')");
        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_valid_chair_count CHECK (chair_count BETWEEN 2 AND 6)');
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_valid_status_timestamps CHECK (
            (status = 'confirmed' AND cancelled_at IS NULL AND completed_at IS NULL)
            OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND completed_at IS NULL)
            OR (status = 'completed' AND cancelled_at IS NULL AND completed_at IS NOT NULL)
        )");

        Schema::create('booking_management_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();

            $table->index('booking_id');
        });

        Schema::create('booking_date_locks', function (Blueprint $table): void {
            $table->date('visit_date')->primary();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_date_locks');
        Schema::dropIfExists('booking_management_tokens');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('time_slots');
        Schema::dropIfExists('zones');

        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
        Schema::table('users', function (Blueprint $table): void {
            $table->unique('email');
        });
    }
};
