<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedBigInteger('amount_cents');
            $table->string('idempotency_key', 100)->nullable();
            $table->foreignId('reversal_of_id')
                ->nullable()
                ->unique()
                ->constrained('transactions');
            $table->timestamps();

            $table->unique(['account_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
