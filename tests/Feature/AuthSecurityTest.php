<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_rejects_invalid_credentials(): void
    {
        User::create([
            'username' => 'admin_test',
            'nom' => 'Admin',
            'prenom' => 'Test',
            'password' => Hash::make('MotDePasseTresFort!123'),
            'role' => 'ADMIN',
            'actif' => true,
        ]);

        $this->postJson('/api/login', [
            'username' => 'admin_test',
            'password' => 'mauvais-mot-de-passe',
        ])->assertUnauthorized()
          ->assertJsonPath('message', 'Identifiants incorrects');
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::create([
            'username' => 'user_inactif',
            'nom' => 'User',
            'prenom' => 'Inactif',
            'password' => Hash::make('MotDePasseTresFort!123'),
            'role' => 'USER',
            'actif' => false,
        ]);

        $this->postJson('/api/login', [
            'username' => 'user_inactif',
            'password' => 'MotDePasseTresFort!123',
        ])->assertForbidden();
    }

    public function test_protected_route_requires_authentication(): void
    {
        $this->getJson('/api/users')->assertUnauthorized();
    }

    public function test_non_admin_cannot_create_an_account(): void
    {
        $user = User::create([
            'username' => 'simple_user',
            'nom' => 'Simple',
            'prenom' => 'User',
            'password' => Hash::make('MotDePasseTresFort!123'),
            'role' => 'USER',
            'actif' => true,
        ]);

        $token = JWTAuth::fromUser($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/register', [
                'username' => 'nouveau_user',
                'nom' => 'Nouveau',
                'prenom' => 'User',
                'password' => 'MotDePasseTresFort!456',
                'role' => 'USER',
            ])->assertForbidden();
    }

    public function test_api_responses_contain_security_headers(): void
    {
        $this->getJson('/up')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }
}
