<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TransactionController extends Controller
{
    public function credit(Request $request, TransactionService $transactions): JsonResponse
    {
        return $this->store($request, $transactions, Transaction::TYPE_CREDIT);
    }

    public function debit(Request $request, TransactionService $transactions): JsonResponse
    {
        return $this->store($request, $transactions, Transaction::TYPE_DEBIT);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['sometimes', 'required', Rule::in(Transaction::TYPES)],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if (
            isset($filters['date_from'], $filters['date_to'])
            && $filters['date_to'] < $filters['date_from']
        ) {
            throw ValidationException::withMessages([
                'date_to' => 'A data final deve ser igual ou posterior a data inicial.',
            ]);
        }

        $account = $request->user()->account()->firstOrFail();
        $query = $account->transactions()->with('reversedBy:id,reversal_of_id');

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $transactions = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        $transactions->getCollection()->transform(
            fn (Transaction $transaction): array => $this->transactionData(
                $transaction,
            )
        );

        return response()->json($transactions);
    }

    public function reverse(
        Request $request,
        int $transactionId,
        TransactionService $transactions,
    ): JsonResponse {
        $account = $request->user()->account()->firstOrFail();
        $transaction = $transactions->reverse($account, $transactionId);

        return response()->json([
            'transaction' => $this->transactionData($transaction),
        ], 201);
    }

    private function store(
        Request $request,
        TransactionService $transactions,
        string $type,
    ): JsonResponse {
        $data = validator([
            'amount_cents' => $request->input('amount_cents'),
            'idempotency_key' => $request->header('Idempotency-Key'),
        ], [
            'amount_cents' => ['required', 'integer:strict', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ])->validate();

        $account = $request->user()->account()->firstOrFail();

        $transaction = $transactions->create(
            $account,
            $type,
            (int) $data['amount_cents'],
            $data['idempotency_key'],
        );

        return response()->json([
            'transaction' => $this->transactionData($transaction),
        ], $transaction->wasRecentlyCreated ? 201 : 200);
    }

    private function transactionData(Transaction $transaction): array
    {
        $transaction->loadMissing('reversedBy:id,reversal_of_id');

        return [
            'id' => $transaction->id,
            'responsible_user_id' => $transaction->responsible_user_id,
            'type' => $transaction->type,
            'amount_cents' => $transaction->amount_cents,
            'reversal_of_id' => $transaction->reversal_of_id,
            'reversed_by_id' => $transaction->reversedBy?->id,
            'created_at' => $transaction->created_at->toISOString(),
        ];
    }
}
