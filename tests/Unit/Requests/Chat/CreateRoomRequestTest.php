<?php

namespace Tests\Unit\Requests\Chat;

use App\Http\Requests\Chat\CreateRoomRequest;
use Tests\TestCase;

class CreateRoomRequestTest extends TestCase
{
    private CreateRoomRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = new CreateRoomRequest;
    }

    public function test_authorize_returns_true()
    {
        $this->assertTrue($this->request->authorize());
    }

    public function test_validation_rules_contain_required_fields()
    {
        $rules = $this->request->rules();

        $this->assertArrayHasKey('name', $rules);
        $this->assertArrayHasKey('description', $rules);
    }

    public function test_name_validation_rules()
    {
        $rules = $this->request->rules();

        $this->assertStringContainsString('required', $rules['name']);
        $this->assertStringContainsString('string', $rules['name']);
        $this->assertStringContainsString('max:255', $rules['name']);
        $this->assertStringContainsString('unique:chat_rooms,name', $rules['name']);
    }

    public function test_description_validation_rules()
    {
        $rules = $this->request->rules();

        $this->assertStringContainsString('nullable', $rules['description']);
        $this->assertStringContainsString('string', $rules['description']);
        $this->assertStringContainsString('max:1000', $rules['description']);
    }

    public function test_validation_attributes_contain_custom_attributes()
    {
        $attributes = $this->request->attributes();

        $this->assertArrayHasKey('name', $attributes);
        $this->assertArrayHasKey('description', $attributes);
        $this->assertEquals('Room Name', $attributes['name']);
        $this->assertEquals('Room Description', $attributes['description']);
    }

    public function test_validation_messages_contain_custom_messages()
    {
        $messages = $this->request->messages();

        $this->assertArrayHasKey('name.required', $messages);
        $this->assertArrayHasKey('name.unique', $messages);
        // max message is currently not defined in this request
        $this->assertArrayHasKey('description.max', $messages);
    }

    public function test_name_required_message()
    {
        $messages = $this->request->messages();

        $this->assertEquals('房间名称是必需的', $messages['name.required']);
    }

    public function test_name_unique_message()
    {
        $messages = $this->request->messages();

        $this->assertEquals('该房间名称已存在', $messages['name.unique']);
    }

    public function test_description_max_message()
    {
        $messages = $this->request->messages();

        // localized message
        $this->assertEquals('描述不能超过 1000 个字符', $messages['description.max']);
    }

    // 字符长度验证已由 Feature 测试覆盖
    // 参见 tests/Feature/ChatControllerTest.php:
    // - test_create_room_with_name_too_short()
    // - test_create_room_with_name_too_long()

    public function test_validates_empty_name_skips_char_length_check()
    {
        // 空名称应该跳过字符长度检查(会被 required 规则捕获)
        $validator = \Validator::make(
            ['name' => ''],
            $this->request->rules()
        );

        $this->request->setValidator($validator);
        $this->request->withValidator($validator);

        // withValidator 的 after 回调应该提前 return，不添加额外错误
        // 只有 required 规则的错误
        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('name'));

        // 验证没有字符长度相关的错误
        $errors = $validator->errors()->get('name');
        $allErrors = implode(' ', $errors);
        $this->assertStringNotContainsString('至少需要', $allErrors);
        $this->assertStringNotContainsString('不能超过', $allErrors);
    }

    public function test_validates_valid_name_passes()
    {
        // 有效的名称应该通过字符长度检查
        // 使用一个唯一的名称避免 unique 规则失败
        $uniqueName = '测试房间'.uniqid();

        $validator = \Validator::make(
            ['name' => $uniqueName],
            ['name' => 'required|string|max:255'] // 移除 unique 规则以便测试字符长度验证
        );

        $this->request->setValidator($validator);
        $this->request->withValidator($validator);

        // 字符长度验证应该通过
        $this->assertFalse($validator->fails());
    }
}
