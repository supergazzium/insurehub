<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Agent;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RbacPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LineLoginTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::query()->create(['name' => 'T', 'slug' => 'line-t-'.uniqid()]);
        $this->seed(RbacPermissionSeeder::class);
        config([
            'services.line.channel_id' => 'cid',
            'services.line.channel_secret' => 'csec',
            'services.line.redirect' => 'https://api.test/api/v1/auth/line/callback',
            'app.frontend_url' => 'https://front.test/insurehub',
        ]);
    }

    private function hitCallback(string $sub, string $name = 'LINE User')
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode(['sub' => $sub, 'name' => $name])), '+/', '-_'), '=');
        Http::fake(['api.line.me/*' => Http::response(['id_token' => 'h.'.$payload.'.s', 'access_token' => 'x'], 200)]);
        $state = Crypt::encryptString((string) json_encode([
            'mode' => 'login', 'uid' => null, 'nonce' => 'n', 'exp' => now()->addMinutes(5)->timestamp,
        ]));

        return $this->get('/api/v1/auth/line/callback?code=abc&state='.urlencode($state));
    }

    public function test_first_line_login_auto_creates_a_pending_agent_and_redirects_to_pending(): void
    {
        $res = $this->hitCallback('Uabc123', 'สมชาย');

        $res->assertRedirect();
        $this->assertStringContainsString('line_registered=pending', (string) $res->headers->get('Location'));

        $user = User::query()->where('line_user_id', 'Uabc123')->firstOrFail();
        $this->assertFalse((bool) $user->active);
        $agent = Agent::query()->whereKey($user->agent_id)->firstOrFail();
        $this->assertSame('pending', $agent->approval_status);
        $this->assertFalse((bool) $agent->active);
        $this->assertSame('line', $agent->signup_source);
        $this->assertSame('สมชาย', $user->name);
    }

    public function test_second_login_before_approval_says_pending_without_duplicating(): void
    {
        $this->hitCallback('Udup');
        $this->hitCallback('Udup')->assertRedirect();

        $this->assertSame(1, User::query()->where('line_user_id', 'Udup')->count());
        $this->assertStringContainsString('line_pending=1', (string) $this->hitCallback('Udup')->headers->get('Location'));
    }

    public function test_login_succeeds_with_a_token_once_the_agent_is_active(): void
    {
        $this->hitCallback('Uactive');
        $user = User::query()->where('line_user_id', 'Uactive')->firstOrFail();
        Agent::query()->whereKey($user->agent_id)->update(['active' => true, 'approval_status' => 'approved']);
        $user->update(['active' => true]);

        $res = $this->hitCallback('Uactive');
        $res->assertRedirect();
        $this->assertStringContainsString('line_token=', (string) $res->headers->get('Location'));
    }
}
