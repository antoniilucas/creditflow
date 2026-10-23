<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $account = $request->user()->account()->firstOrFail();

        return response()->json([
            'account' => [
                'id' => $account->id,
                'balance_cents' => $account->balance_cents,
                'currency' => 'BRL',
            ],
        ]);
    }
}
