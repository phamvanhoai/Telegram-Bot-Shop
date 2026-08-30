<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_broadcasts', function (Blueprint $table): void {
            $table->string('status', 20)->default('sending')->after('failed_count')->index();
        });

        DB::table('notification_broadcasts')->whereNotNull('completed_at')->update(['status' => 'completed']);
    }

    public function down(): void
    {
        Schema::table('notification_broadcasts', fn (Blueprint $table) => $table->dropColumn('status'));
    }
};
