<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TransactionService
{
    public function create(
        Account $account,
        string $type,
        int $amountCents,
        string $idempotencyKey,
    ): Transaction {
        return DB::transaction(function () use ($account, $type, $amountCents, $idempotencyKey): Transaction {
            $lockedAccount = Account::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existingTransaction = $lockedAccount->transactions()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingTransaction) {
                if ($existingTransaction->type !== $type || $existingTransaction->amount_cents !== $amountCents) {
                    throw new ConflictHttpException(
                        'Esta chave de idempotencia ja foi usada com outros dados.'
                    );
                }

                return $existingTransaction;
            }

            $balance = $lockedAccount->balance_cents;

            if ($type === Transaction::TYPE_DEBIT && $balance < $amountCents) {
                throw ValidationException::withMessages([
                    'amount_cents' => 'Saldo insuficiente para realizar o debito.',
                ]);
            }

            if ($type === Transaction::TYPE_CREDIT && $amountCents > PHP_INT_MAX - $balance) {
                throw ValidationException::withMessages([
                    'amount_cents' => 'O credito ultrapassa o limite de saldo suportado.',
                ]);
            }

            $lockedAccount->balance_cents = $type === Transaction::TYPE_CREDIT
                ? $balance + $amountCents
                : $balance - $amountCents;
            $lockedAccount->save();

            return $lockedAccount->transactions()->create([
                'responsible_user_id' => $lockedAccount->user_id,
                'type' => $type,
                'amount_cents' => $amountCents,
                'idempotency_key' => $idempotencyKey,
            ]);
        }, 3);
    }

    public function reverse(Account $account, int $transactionId): Transaction
    {
        return DB::transaction(function () use ($account, $transactionId): Transaction {
            $lockedAccount = Account::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $original = $lockedAccount->transactions()
                ->whereKey($transactionId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($original->type, [Transaction::TYPE_CREDIT, Transaction::TYPE_DEBIT], true)) {
                throw new ConflictHttpException('Um estorno nao pode ser estornado.');
            }

            if ($original->reversedBy()->exists()) {
                throw new ConflictHttpException('Esta movimentacao ja foi estornada.');
            }

            $balance = $lockedAccount->balance_cents;

            if ($original->type === Transaction::TYPE_CREDIT && $balance < $original->amount_cents) {
                throw ValidationException::withMessages([
                    'transaction' => 'Saldo insuficiente para estornar este credito.',
                ]);
            }

            if ($original->type === Transaction::TYPE_DEBIT && $original->amount_cents > PHP_INT_MAX - $balance) {
                throw ValidationException::withMessages([
                    'transaction' => 'O estorno ultrapassa o limite de saldo suportado.',
                ]);
            }

            $lockedAccount->balance_cents = $original->type === Transaction::TYPE_CREDIT
                ? $balance - $original->amount_cents
                : $balance + $original->amount_cents;
            $lockedAccount->save();

            return $lockedAccount->transactions()->create([
                'responsible_user_id' => $lockedAccount->user_id,
                'type' => Transaction::TYPE_REVERSAL,
                'amount_cents' => $original->amount_cents,
                'reversal_of_id' => $original->id,
            ]);
        }, 3);
    }
}
