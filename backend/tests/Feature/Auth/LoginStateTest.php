<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Agent;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RbacPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginStateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::query()->create(['name' => 'T', 'slug' => 'login-'.uniqid()]);
        $this->seed(RbacPermissionSeeder::class);
    }

    private function makeAgentUser(string $email, bool $active, string $approval): User
    {
        $agent = Agent::create([
            'tenant_id' => $this->tenant->id, 'agent_code' => 'IN'.substr(uniqid(), -6),
            'agent_type' => 'IN', 'kind' => 'individual', 'first_name' => 'A', 'last_name' => 'B',
            'email' => $email, 'approval_status' => $approval, 'signup_type' => 'individual',
            'active' => $active, 'joined_at' => now()->toDateString(), 'doc_status' => false,
        ]);

        return User::create([
            'tenant_id' => $this->tenant->id, 'agent_id' => $agent->id, 'name' => 'A B',
            'email' => $email, 'password' => Hash::make('secret-pass'),
            'role' => 'agent', 'role_id' => Role::query()->where('key', 'agent')->value('id'),
            'active' => $active, 'locale' => 'th',
        ]);
    }

    private function login(string $email, string $password = 'secret-pass')
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function test_unknown_email_returns_email_not_found(): void
    {
        $this->login('nobody@example.test')->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'email_not_found');
    }

    public function test_pending_agent_gets_pending_approval_not_email_not_found(): void
    {
        // The reported bug: a registered-but-unapproved agent used to see
        // "email not registered" even though a password reset found them.
        $this->makeAgentUser('pending@example.test', active: false, approval: 'pending');
        $this->login('pending@example.test')->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'account_pending_approval');
    }

    public function test_deactivated_agent_gets_account_inactive(): void
    {
        $this->makeAgentUser('off@example.test', active: false, approval: 'approved');
        $this->login('off@example.test')->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'account_inactive');
    }

    public function test_wrong_password_still_reported_as_invalid_password_for_inactive(): void
    {
        // Password is checked before state, so a wrong password on a pending
        // account still says invalid_password (doesn't leak the state).
        $this->makeAgentUser('pending2@example.test', active: false, approval: 'pending');
        $this->login('pending2@example.test', 'wrong')->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'invalid_password');
    }

    public function test_active_agent_logs_in(): void
    {
        $this->makeAgentUser('ok@example.test', active: true, approval: 'approved');
        $this->login('ok@example.test')->assertOk()->assertJsonStructure(['token']);
    }
}
