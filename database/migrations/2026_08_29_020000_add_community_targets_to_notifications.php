<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_broadcasts', function (Blueprint $table): void {
            $table->string('audience')->default('users')->after('message');
        });
        Schema::table('notification_recipients', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('chat_id')->nullable()->after('user_id');
            $table->string('recipient_name')->nullable()->after('chat_id');
            $table->unique(['notification_broadcast_id', 'chat_id']);
        });
    }

    public function down(): void
    {
        Schema::table('notification_recipients', function (Blueprint $table): void {
            $table->dropUnique(['notification_broadcast_id', 'chat_id']);
            $table->dropColumn(['chat_id', 'recipient_name']);
        });
        Schema::table('notification_broadcasts', fn (Blueprint $table) => $table->dropColumn('audience'));
    }
};
