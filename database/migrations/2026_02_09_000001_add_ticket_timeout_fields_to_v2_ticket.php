<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_ticket')) {
            return;
        }

        Schema::table('v2_ticket', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_ticket', 'user_remind_at')) {
                $table->integer('user_remind_at')->nullable()->after('updated_at');
            }
            if (!Schema::hasColumn('v2_ticket', 'auto_closed_at')) {
                $table->integer('auto_closed_at')->nullable()->after('user_remind_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_ticket')) {
            return;
        }

        Schema::table('v2_ticket', function (Blueprint $table) {
            if (Schema::hasColumn('v2_ticket', 'auto_closed_at')) {
                $table->dropColumn('auto_closed_at');
            }
            if (Schema::hasColumn('v2_ticket', 'user_remind_at')) {
                $table->dropColumn('user_remind_at');
            }
        });
    }
};
