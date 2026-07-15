<?php

namespace Tests\Unit\Requests\Chat;

use App\Http\Requests\Chat\GetModerationActionsRequest;
use Tests\TestCase;

class GetModerationActionsRequestTest extends TestCase
{
    private GetModerationActionsRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = new GetModerationActionsRequest;
    }

    public function test_authorize_returns_true(): void
    {
        $this->assertTrue($this->request->authorize());
    }

    public function test_rules_define_filter_constraints(): void
    {
        $rules = $this->request->rules();

        $this->assertArrayHasKey('per_page', $rules);
        $this->assertStringContainsString('nullable', $rules['per_page']);
        $this->assertStringContainsString('integer', $rules['per_page']);
        $this->assertStringContainsString('min:1', $rules['per_page']);
        $this->assertStringContainsString('max:100', $rules['per_page']);

        $this->assertArrayHasKey('action_type', $rules);
        $this->assertIsArray($rules['action_type']);
        $this->assertArrayHasKey('target_user_id', $rules);
        $this->assertStringContainsString('integer', $rules['target_user_id']);
    }

    public function test_messages_define_filter_errors(): void
    {
        $messages = $this->request->messages();

        $this->assertSame('每页数量必须为整数', $messages['per_page.integer']);
        $this->assertSame('每页数量至少为 1', $messages['per_page.min']);
        $this->assertSame('每页数量不能超过 100', $messages['per_page.max']);
        $this->assertSame('目标用户 ID 必须为整数', $messages['target_user_id.integer']);
    }
}
