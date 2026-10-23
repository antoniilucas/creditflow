<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_user_account_and_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Tony',
            'email' => 'tony@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'tony@example.com')
            ->assertJsonPath('account.balance_cents', 0)
            ->assertJsonStructure(['token']);

        $user = User::where('email', 'tony@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('password-123', $user->password));
        $this->assertDatabaseHas('accounts', [
            'user_id' => $user->id,
            'balance_cents' => 0,
        ]);
    }

    public function test_registration_rejects_an_existing_email(): void
    {
        User::factory()->create(['email' => 'tony@example.com']);

        $this->postJson('/api/register', [
            'name' => 'Tony',
            'email' => 'tony@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_login_returns_a_token_for_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'tony@example.com',
            'password' => 'password-123',
        ]);
        $user->account()->create(['balance_cents' => 0]);

        $this->postJson('/api/login', [
            'email' => 'tony@example.com',
            'password' => 'password-123',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'tony@example.com')
            ->assertJsonPath('account.balance_cents', 0)
            ->assertJsonStructure(['token']);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'tony@example.com']);
        $user->account()->create(['balance_cents' => 0]);

        $this->postJson('/api/login', [
            'email' => 'tony@example.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    public function test_account_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/account')->assertUnauthorized();
    }

    public function test_transaction_endpoints_require_authentication(): void
    {
        $this->postJson('/api/transactions/credit', ['amount_cents' => 100], [
            'Idempotency-Key' => 'credit-unauthenticated',
        ])->assertUnauthorized();

        $this->getJson('/api/transactions')->assertUnauthorized();
    }

    public function test_account_endpoint_returns_the_authenticated_users_account(): void
    {
        $user = User::factory()->create();
        $account = $user->account()->create(['balance_cents' => 12345]);

        Sanctum::actingAs($user);

        $this->getJson('/api/account')
            ->assertOk()
            ->assertJsonPath('account.id', $account->id)
            ->assertJsonPath('account.balance_cents', 12345)
            ->assertJsonPath('account.currency', 'BRL');
    }

    public function test_sanctum_bearer_token_authenticates_the_account_endpoint(): void
    {
        $user = User::factory()->create();
        $user->account()->create(['balance_cents' => 750]);
        $token = $user->createToken('api-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/account')
            ->assertOk()
            ->assertJsonPath('account.balance_cents', 750);
    }
}
