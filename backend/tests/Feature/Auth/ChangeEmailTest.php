<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Agent;
use App\Models\EmailVerificationToken;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RbacPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChangeEmailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::query()->create(['name' => 'T', 'slug' => 'ce-'.uniqid()]);
        $this->seed(RbacPermissionSeeder::class);
    }

    private function lineAgentUser(string $placeholderEmail): User
    {
        $agent = Agent::create([
            'tenant_id' => $this->tenant->id, 'agent_code' => 'IN'.substr(uniqid(), -6),
            'agent_type' => 'IN', 'kind' => 'individual', 'first_name' => 'A', 'last_name' => 'B',
            'email' => $placeholderEmail, 'approval_status' => 'approved', 'signup_type' => 'individual',
            'active' => true, 'joined_at' => now()->toDateString(), 'doc_status' => false,
        ]);

        return User::create([
            'tenant_id' => $this->tenant->id, 'agent_id' => $agent->id, 'name' => 'A B',
            'email' => $placeholderEmail, 'password' => Hash::make('x'),
            'role' => 'agent', 'role_id' => Role::query()->where('key', 'agent')->value('id'),
            'active' => true, 'locale' => 'th', 'line_user_id' => 'U'.uniqid(),
        ]);
    }

    private function otp(string $email, string $token, bool $expired = false, bool $consumed = false): void
    {
        EmailVerificationToken::create([
            'token' => $token, 'email' => $email,
            'expires_at' => $expired ? now()->subMinute() : now()->addMinutes(10),
            'consumed_at' => $consumed ? now() : null,
        ]);
    }

    public function test_line_agent_sets_a_real_email_with_a_valid_otp(): void
    {
        $user = $this->lineAgentUser('line_Uabc@line.local');
        Sanctum::actingAs($user);
        $token = str_repeat('a', 64);
        $this->otp('real@example.test', $token);

        $this->postJson('/api/v1/me/agent/change-email', ['email' => 'real@example.test', 'emailOtpToken' => $token])
            ->assertOk()->assertJsonPath('email', 'real@example.test');

        $this->assertSame('real@example.test', $user->fresh()->email);
        $this->assertSame('real@example.test', Agent::query()->whereKey($user->agent_id)->value('email'));
        $this->assertNotNull(EmailVerificationToken::query()->where('token', $token)->value('consumed_at'));
    }

    public function test_otp_bound_to_a_different_email_is_rejected(): void
    {
        $user = $this->lineAgentUser('line_Ux@line.local');
        Sanctum::actingAs($user);
        $token = str_repeat('b', 64);
        $this->otp('someone-else@example.test', $token);

        $this->postJson('/api/v1/me/agent/change-email', ['email' => 'mine@example.test', 'emailOtpToken' => $token])
            ->assertStatus(422)->assertJsonValidationErrors('emailOtpToken');
        $this->assertStringEndsWith('@line.local', (string) $user->fresh()->email);
    }

    public function test_expired_otp_is_rejected(): void
    {
        $user = $this->lineAgentUser('line_Uy@line.local');
        Sanctum::actingAs($user);
        $token = str_repeat('c', 64);
        $this->otp('new@example.test', $token, expired: true);

        $this->postJson('/api/v1/me/agent/change-email', ['email' => 'new@example.test', 'emailOtpToken' => $token])
            ->assertStatus(422)->assertJsonValidationErrors('emailOtpToken');
    }

    public function test_duplicate_email_is_rejected(): void
    {
        // Another user already owns the target email.
        User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'X', 'email' => 'taken@example.test',
            'password' => Hash::make('x'), 'role' => 'agent',
            'role_id' => Role::query()->where('key', 'agent')->value('id'), 'active' => true, 'locale' => 'th',
        ]);
        $user = $this->lineAgentUser('line_Uz@line.local');
        Sanctum::actingAs($user);
        $token = str_repeat('d', 64);
        $this->otp('taken@example.test', $token);

        $this->postJson('/api/v1/me/agent/change-email', ['email' => 'taken@example.test', 'emailOtpToken' => $token])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }
}
