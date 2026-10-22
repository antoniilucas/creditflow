<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        [$user, $token] = DB::transaction(function () use ($data): array {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $account = $user->account()->create([
                'balance_cents' => 0,
            ]);

            $user->setRelation('account', $account);
            $token = $user->createToken('creditflow-api')->plainTextToken;

            return [$user, $token];
        });

        return response()->json([
            'message' => 'Cadastro realizado com sucesso.',
            ...$this->authenticationData($user, $token),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::with('account')
            ->where('email', $credentials['email'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Credenciais inválidas.',
            ], 401);
        }

        $token = $user->createToken('creditflow-api')->plainTextToken;

        return response()->json($this->authenticationData($user, $token));
    }

    private function authenticationData(User $user, string $token): array
    {
        $user->loadMissing('account');

        return [
            'token_type' => 'Bearer',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'account' => [
                'id' => $user->account->id,
                'balance_cents' => $user->account->balance_cents,
                'currency' => 'BRL',
            ],
        ];
    }
}
