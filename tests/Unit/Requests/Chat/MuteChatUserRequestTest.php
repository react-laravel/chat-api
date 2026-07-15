<?php

namespace Tests\Unit\Requests\Chat;

use App\Http\Requests\Chat\MuteChatUserRequest;
use Tests\TestCase;

class MuteChatUserRequestTest extends TestCase
{
    private MuteChatUserRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = new MuteChatUserRequest;
    }

    public function test_authorize_returns_true(): void
    {
        $this->assertTrue($this->request->authorize());
    }

    public function test_rules_define_duration_and_reason_constraints(): void
    {
        $rules = $this->request->rules();

        $this->assertArrayHasKey('duration', $rules);
        $this->assertStringContainsString('nullable', $rules['duration']);
        $this->assertStringContainsString('integer', $rules['duration']);
        $this->assertStringContainsString('min:1', $rules['duration']);
        $this->assertStringContainsString('max:10080', $rules['duration']);

        $this->assertArrayHasKey('reason', $rules);
        $this->assertStringContainsString('max:500', $rules['reason']);
    }

    public function test_messages_define_duration_and_reason_errors(): void
    {
        $messages = $this->request->messages();

        $this->assertSame('禁言时长必须为整数分钟', $messages['duration.integer']);
        $this->assertSame('禁言时长至少为 1 分钟', $messages['duration.min']);
        $this->assertSame('禁言时长不能超过 10080 分钟', $messages['duration.max']);
        $this->assertSame('禁言原因不能超过 500 个字符', $messages['reason.max']);
    }
}
