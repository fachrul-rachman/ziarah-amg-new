<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->string('period', 32);
            $table->string('channel', 16);
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->string('last_error_summary')->nullable();
            $table->timestampsTz();

            $table->unique(['report_date', 'period', 'channel']);
            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE report_dispatches ADD CONSTRAINT report_dispatches_valid_period CHECK (period IN ('morning_0700_1100', 'afternoon_1200_1700'))");
        DB::statement("ALTER TABLE report_dispatches ADD CONSTRAINT report_dispatches_valid_channel CHECK (channel IN ('email', 'discord'))");
        DB::statement("ALTER TABLE report_dispatches ADD CONSTRAINT report_dispatches_valid_status CHECK (status IN ('pending', 'processing', 'sent', 'failed', 'skipped'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_dispatches');
    }
};
