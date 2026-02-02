<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_ticket_message')) {
            return;
        }

        Schema::table('v2_ticket_message', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_ticket_message', 'notify_admin_status')) {
                $table->unsignedTinyInteger('notify_admin_status')
                    ->default(0)
                    ->comment('工单邮件通知管理员状态：0未发送 1已入队 2已发送');
            }
            if (!Schema::hasColumn('v2_ticket_message', 'notify_user_status')) {
                $table->unsignedTinyInteger('notify_user_status')
                    ->default(0)
                    ->comment('工单邮件通知用户状态：0未发送 1已入队 2已发送');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_ticket_message')) {
            return;
        }

        Schema::table('v2_ticket_message', function (Blueprint $table) {
            if (Schema::hasColumn('v2_ticket_message', 'notify_admin_status')) {
                $table->dropColumn('notify_admin_status');
            }
            if (Schema::hasColumn('v2_ticket_message', 'notify_user_status')) {
                $table->dropColumn('notify_user_status');
            }
        });
    }
};
