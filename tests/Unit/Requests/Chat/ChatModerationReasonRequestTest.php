<?php

namespace Tests\Unit\Requests\Chat;

use App\Http\Requests\Chat\ChatModerationReasonRequest;
use Tests\TestCase;

class ChatModerationReasonRequestTest extends TestCase
{
    private ChatModerationReasonRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = new ChatModerationReasonRequest;
    }

    public function test_authorize_returns_true(): void
    {
        $this->assertTrue($this->request->authorize());
    }

    public function test_rules_define_reason_constraints(): void
    {
        $rules = $this->request->rules();

        $this->assertArrayHasKey('reason', $rules);
        $this->assertStringContainsString('nullable', $rules['reason']);
        $this->assertStringContainsString('string', $rules['reason']);
        $this->assertStringContainsString('max:500', $rules['reason']);
    }

    public function test_messages_define_reason_errors(): void
    {
        $messages = $this->request->messages();

        $this->assertSame('原因必须是字符串', $messages['reason.string']);
        $this->assertSame('原因不能超过 500 个字符', $messages['reason.max']);
    }
}
