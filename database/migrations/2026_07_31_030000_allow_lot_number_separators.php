<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_valid_lot');
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_valid_lot CHECK (lot_number ~ '^[A-Z0-9]+([/-][A-Z0-9]+)*$')");
    }

    public function down(): void
    {
        if (DB::table('bookings')->whereRaw("lot_number !~ '^[A-Z0-9]+$'")->exists()) {
            throw new RuntimeException(
                'Cannot remove lot separators while bookings use them.',
            );
        }

        DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_valid_lot');
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_valid_lot CHECK (lot_number ~ '^[A-Z0-9]+$')");
    }
};
