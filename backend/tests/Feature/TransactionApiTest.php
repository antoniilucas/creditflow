<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = $this->user->account()->create(['balance_cents' => 0]);

        Sanctum::actingAs($this->user);
    }

    public function test_credit_records_a_transaction_and_updates_the_balance(): void
    {
        $this->postJson('/api/transactions/credit', ['amount_cents' => 10000], [
            'Idempotency-Key' => 'credit-001',
        ])
            ->assertCreated()
            ->assertJsonPath('transaction.type', 'credit')
            ->assertJsonPath('transaction.amount_cents', 10000)
            ->assertJsonPath('transaction.responsible_user_id', $this->user->id);

        $this->assertSame(10000, $this->account->fresh()->balance_cents);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_credit_is_idempotent_for_the_same_key_and_payload(): void
    {
        $firstResponse = $this->postJson('/api/transactions/credit', ['amount_cents' => 10000], [
            'Idempotency-Key' => 'credit-001',
        ]);
        $firstResponse->assertCreated();

        $replayResponse = $this->postJson('/api/transactions/credit', ['amount_cents' => 10000], [
            'Idempotency-Key' => 'credit-001',
        ]);

        $replayResponse
            ->assertOk()
            ->assertJsonPath('transaction.id', $firstResponse->json('transaction.id'));

        $this->assertSame(10000, $this->account->fresh()->balance_cents);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_debit_is_idempotent_for_the_same_key_and_payload(): void
    {
        $this->account->update(['balance_cents' => 10000]);

        $firstResponse = $this->postJson('/api/transactions/debit', ['amount_cents' => 2500], [
            'Idempotency-Key' => 'debit-001',
        ]);
        $firstResponse->assertCreated();

        $this->postJson('/api/transactions/debit', ['amount_cents' => 2500], [
            'Idempotency-Key' => 'debit-001',
        ])
            ->assertOk()
            ->assertJsonPath('transaction.id', $firstResponse->json('transaction.id'));

        $this->assertSame(7500, $this->account->fresh()->balance_cents);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_idempotency_key_rejects_a_different_payload(): void
    {
        $this->postJson('/api/transactions/credit', ['amount_cents' => 10000], [
            'Idempotency-Key' => 'credit-001',
        ])->assertCreated();

        $this->postJson('/api/transactions/credit', ['amount_cents' => 15000], [
            'Idempotency-Key' => 'credit-001',
        ])->assertStatus(409);

        $this->assertSame(10000, $this->account->fresh()->balance_cents);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_idempotency_key_cannot_be_reused_for_another_operation_type(): void
    {
        $this->postJson('/api/transactions/credit', ['amount_cents' => 10000], [
            'Idempotency-Key' => 'shared-key-001',
        ])->assertCreated();

        $this->postJson('/api/transactions/debit', ['amount_cents' => 10000], [
            'Idempotency-Key' => 'shared-key-001',
        ])->assertStatus(409);

        $this->assertSame(10000, $this->account->fresh()->balance_cents);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_debit_updates_the_balance_and_rejects_insufficient_funds(): void
    {
        $this->account->update(['balance_cents' => 10000]);

        $this->postJson('/api/transactions/debit', ['amount_cents' => 2500], [
            'Idempotency-Key' => 'debit-001',
        ])
            ->assertCreated()
            ->assertJsonPath('transaction.type', 'debit');

        $this->assertSame(7500, $this->account->fresh()->balance_cents);

        $this->postJson('/api/transactions/debit', ['amount_cents' => 8000], [
            'Idempotency-Key' => 'debit-002',
        ])->assertUnprocessable();

        $this->assertSame(7500, $this->account->fresh()->balance_cents);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_reversal_creates_a_compensating_transaction_and_cannot_repeat(): void
    {
        $creditId = $this->postJson('/api/transactions/credit', ['amount_cents' => 10000], [
            'Idempotency-Key' => 'credit-001',
        ])->assertCreated()->json('transaction.id');

        $reversalResponse = $this->postJson("/api/transactions/{$creditId}/reverse")
            ->assertCreated()
            ->assertJsonPath('transaction.type', 'reversal')
            ->assertJsonPath('transaction.reversal_of_id', $creditId);
        $reversalId = $reversalResponse->json('transaction.id');

        $this->assertSame(0, $this->account->fresh()->balance_cents);
        $this->assertSame($reversalId, $this->getJson('/api/transactions?type=credit')
            ->assertOk()
            ->json('data.0.reversed_by_id'));

        $this->postJson("/api/transactions/{$creditId}/reverse")->assertStatus(409);

        $this->assertSame(0, $this->account->fresh()->balance_cents);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_reversal_of_a_debit_restores_the_balance(): void
    {
        $this->account->update(['balance_cents' => 10000]);
        $debitId = $this->postJson('/api/transactions/debit', ['amount_cents' => 2500], [
            'Idempotency-Key' => 'debit-001',
        ])->assertCreated()->json('transaction.id');

        $this->postJson("/api/transactions/{$debitId}/reverse")
            ->assertCreated()
            ->assertJsonPath('transaction.reversal_of_id', $debitId);

        $this->assertSame(10000, $this->account->fresh()->balance_cents);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_reversal_cannot_make_the_balance_negative(): void
    {
        $creditId = $this->postJson('/api/transactions/credit', ['amount_cents' => 10000], [
            'Idempotency-Key' => 'credit-001',
        ])->assertCreated()->json('transaction.id');

        $this->postJson('/api/transactions/debit', ['amount_cents' => 8000], [
            'Idempotency-Key' => 'debit-001',
        ])->assertCreated();

        $this->postJson("/api/transactions/{$creditId}/reverse")->assertUnprocessable();

        $this->assertSame(2000, $this->account->fresh()->balance_cents);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_statement_is_paginated_and_filters_by_type_and_date(): void
    {
        $this->postJson('/api/transactions/credit', ['amount_cents' => 10000], [
            'Idempotency-Key' => 'credit-001',
        ])->assertCreated();
        $this->postJson('/api/transactions/debit', ['amount_cents' => 2000], [
            'Idempotency-Key' => 'debit-001',
        ])->assertCreated();
        $this->postJson('/api/transactions/debit', ['amount_cents' => 1000], [
            'Idempotency-Key' => 'debit-002',
        ])->assertCreated();

        $this->getJson('/api/transactions?type=debit&date_from='.now()->toDateString().'&per_page=1')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('data.0.type', 'debit')
            ->assertJsonPath('data.0.responsible_user_id', $this->user->id);

        $this->getJson('/api/transactions?type=credit&date_to='.now()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'credit');
    }

    public function test_statement_rejects_an_invalid_date_range(): void
    {
        $this->getJson('/api/transactions?date_from=2026-10-22&date_to=2026-10-21')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
    }

    public function test_users_cannot_view_or_reverse_another_users_transactions(): void
    {
        $otherUser = User::factory()->create();
        $otherAccount = $otherUser->account()->create(['balance_cents' => 10000]);
        $otherTransaction = $otherAccount->transactions()->create([
            'responsible_user_id' => $otherUser->id,
            'type' => 'credit',
            'amount_cents' => 10000,
            'idempotency_key' => 'other-credit-001',
        ]);

        $this->postJson("/api/transactions/{$otherTransaction->id}/reverse")->assertNotFound();
        $this->getJson('/api/transactions')->assertJsonCount(0, 'data');
        $this->assertSame(10000, $otherAccount->fresh()->balance_cents);
    }

    public function test_credit_requires_a_positive_amount_and_idempotency_key(): void
    {
        $this->postJson('/api/transactions/credit', ['amount_cents' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount_cents', 'idempotency_key']);

        $this->assertSame(0, $this->account->fresh()->balance_cents);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_credit_rejects_fractional_amounts(): void
    {
        $this->postJson('/api/transactions/credit', ['amount_cents' => 1.5], [
            'Idempotency-Key' => 'credit-fractional',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount_cents');

        $this->assertSame(0, $this->account->fresh()->balance_cents);
        $this->assertDatabaseCount('transactions', 0);
    }
}
