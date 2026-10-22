<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('responsible_user_id')
                ->nullable()
                ->constrained('users');
        });

        DB::table('transactions')
            ->join('accounts', 'transactions.account_id', '=', 'accounts.id')
            ->select([
                'transactions.id as transaction_id',
                'accounts.user_id as responsible_user_id',
            ])
            ->chunkById(500, function ($transactions): void {
                foreach ($transactions->groupBy('responsible_user_id') as $userId => $rows) {
                    DB::table('transactions')
                        ->whereIn('id', $rows->pluck('transaction_id'))
                        ->update(['responsible_user_id' => $userId]);
                }
            }, 'transactions.id', 'transaction_id');

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('responsible_user_id')->nullable(false)->change();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->index(
                ['account_id', 'created_at'],
                'transactions_account_created_at_index',
            );
            $table->index(
                ['account_id', 'type', 'created_at'],
                'transactions_account_type_created_at_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_account_created_at_index');
            $table->dropIndex('transactions_account_type_created_at_index');
            $table->dropForeign(['responsible_user_id']);
            $table->dropColumn('responsible_user_id');
        });
    }
};
