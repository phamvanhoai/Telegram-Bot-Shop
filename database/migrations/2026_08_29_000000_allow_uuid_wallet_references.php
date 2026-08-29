<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE wallet_transactions MODIFY reference_id VARCHAR(255) NULL');
        }
    }

    public function down(): void {}
};
