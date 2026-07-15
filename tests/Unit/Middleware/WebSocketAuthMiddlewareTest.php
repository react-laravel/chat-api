<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\WebSocketAuthMiddleware;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class WebSocketAuthMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private WebSocketAuthMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new WebSocketAuthMiddleware;
    }

    public function test_returns_401_when_no_token_provided(): void
    {
        $request = Request::create('/test', 'GET');

        $response = $this->middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('缺少 token', $data['error']);
    }

    public function test_returns_401_when_token_invalid(): void
    {
        $request = Request::create('/test?token=invalid-token', 'GET');

        $response = $this->middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Token 无效', $data['error']);
    }

    public function test_passes_with_valid_token_from_query(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $request = Request::create("/test?token={$token}", 'GET');

        $nextCalled = false;
        $response = $this->middleware->handle($request, function () use (&$nextCalled) {
            $nextCalled = true;

            return new Response('ok', 200);
        });

        $this->assertTrue($nextCalled);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_passes_with_valid_token_from_bearer_header(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $request = Request::create('/test', 'GET');
        $request->headers->set('Authorization', 'Bearer '.$token);

        $nextCalled = false;
        $response = $this->middleware->handle($request, function () use (&$nextCalled) {
            $nextCalled = true;

            return new Response('ok', 200);
        });

        $this->assertTrue($nextCalled);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_query_token_takes_precedence_over_bearer(): void
    {
        $user = User::factory()->create();
        $validToken = $user->createToken('valid-token')->plainTextToken;
        $invalidToken = 'invalid';

        $request = Request::create("/test?token={$validToken}", 'GET');
        $request->headers->set('Authorization', 'Bearer '.$invalidToken);

        $nextCalled = false;
        $response = $this->middleware->handle($request, function () use (&$nextCalled) {
            $nextCalled = true;

            return new Response('ok', 200);
        });

        $this->assertTrue($nextCalled);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_returns_401_when_token_expired(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['*'], now()->subDay())->plainTextToken;

        $request = Request::create("/test?token={$token}", 'GET');

        $response = $this->middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Token 已过期', $data['error']);
    }

    public function test_sets_authenticated_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $request = Request::create("/test?token={$token}", 'GET');

        $this->middleware->handle($request, function () use ($user) {
            $this->assertNotNull(auth()->user());
            $this->assertEquals($user->id, auth()->id());

            return new Response('ok', 200);
        });
    }

    public function test_updates_last_used_at_on_success(): void
    {
        $user = User::factory()->create();
        $accessToken = $user->createToken('test-token');

        $this->assertNull($accessToken->accessToken->last_used_at);

        $request = Request::create('/test?token='.$accessToken->plainTextToken, 'GET');

        $this->middleware->handle($request, fn () => new Response('ok', 200));

        $accessToken->accessToken->refresh();
        $this->assertNotNull($accessToken->accessToken->last_used_at);
    }
}
