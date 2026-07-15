<?php

namespace Tests\Unit\Requests\Chat;

use App\Http\Requests\Chat\SendMessageRequest;
use Tests\TestCase;

class SendMessageRequestTest extends TestCase
{
    private SendMessageRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = new SendMessageRequest;
    }

    public function test_authorize_returns_true()
    {
        $this->assertTrue($this->request->authorize());
    }

    public function test_validation_rules_contain_required_fields()
    {
        $rules = $this->request->rules();

        $this->assertArrayHasKey('message', $rules);
        $this->assertArrayHasKey('message_type', $rules);
    }

    public function test_message_validation_rules()
    {
        $rules = $this->request->rules();

        $this->assertStringContainsString('required', $rules['message']);
        $this->assertStringContainsString('string', $rules['message']);
        $this->assertStringContainsString('max:2000', $rules['message']);
    }

    public function test_message_type_validation_rules()
    {
        $rules = $this->request->rules();

        $this->assertStringContainsString('nullable', $rules['message_type']);
        $this->assertStringContainsString('string', $rules['message_type']);
        $this->assertStringContainsString('in:text,system', $rules['message_type']);
    }

    public function test_validation_attributes_contain_custom_attributes()
    {
        $attributes = $this->request->attributes();

        $this->assertArrayHasKey('message', $attributes);
        $this->assertArrayHasKey('message_type', $attributes);
        $this->assertEquals('Message', $attributes['message']);
        $this->assertEquals('Message Type', $attributes['message_type']);
    }

    public function test_validation_messages_contain_custom_messages()
    {
        $messages = $this->request->messages();

        $this->assertArrayHasKey('message.required', $messages);
        $this->assertArrayHasKey('message.max', $messages);
        $this->assertArrayHasKey('message_type.in', $messages);
    }

    public function test_message_required_message()
    {
        $messages = $this->request->messages();

        $this->assertEquals('Message content is required.', $messages['message.required']);
    }

    public function test_message_max_message()
    {
        $messages = $this->request->messages();

        $this->assertEquals('Message cannot exceed 2000 characters.', $messages['message.max']);
    }

    public function test_message_type_in_message()
    {
        $messages = $this->request->messages();

        $this->assertEquals('Message type must be either text or system.', $messages['message_type.in']);
    }
}
