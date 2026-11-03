<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_types', function (Blueprint $table) {
            $table->string('code', 20)->nullable(false)->change();
            $table->enum('normal_balance', ['DEBIT', 'CREDIT'])->default('DEBIT')->nullable(false)->change();
            $table->boolean('is_system')->default(false)->after('normal_balance');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('code', 50)->nullable(false)->change();
            $table->boolean('is_system')->default(false)->after('is_active');
            $table->index(['account_type_id', 'is_active']);
            $table->index(['parent_id', 'is_active']);
        });

        Schema::table('finance_account_mappings', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'mapping_key']);
            $table->enum('scope', ['COMPANY', 'BRANCH'])->default('COMPANY')->after('id');
            $table->string('scope_key', 80)->default('COMPANY')->after('scope');
            $table->unique(['scope_key', 'mapping_key'], 'finance_mapping_scope_key_unique');
            $table->index(['scope', 'branch_id', 'is_active'], 'finance_mapping_scope_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('finance_account_mappings', function (Blueprint $table) {
            $table->dropUnique('finance_mapping_scope_key_unique');
            $table->dropIndex('finance_mapping_scope_lookup');
            $table->dropColumn(['scope', 'scope_key']);
            $table->unique(['branch_id', 'mapping_key']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['account_type_id', 'is_active']);
            $table->dropIndex(['parent_id', 'is_active']);
            $table->dropColumn('is_system');
            $table->string('code', 50)->nullable()->change();
        });

        Schema::table('account_types', function (Blueprint $table) {
            $table->dropColumn('is_system');
            $table->string('code', 20)->nullable()->change();
            $table->enum('normal_balance', ['DEBIT', 'CREDIT'])->nullable()->default(null)->change();
        });
    }
};
