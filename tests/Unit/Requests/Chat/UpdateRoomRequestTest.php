<?php

namespace Tests\Unit\Requests\Chat;

use App\Http\Requests\Chat\UpdateRoomRequest;
use Tests\TestCase;

class UpdateRoomRequestTest extends TestCase
{
    private UpdateRoomRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = new UpdateRoomRequest;
    }

    public function test_authorize_returns_true(): void
    {
        $this->assertTrue($this->request->authorize());
    }

    public function test_rules_requires_name(): void
    {
        $rules = $this->request->rules();

        $this->assertArrayHasKey('name', $rules);
    }

    public function test_rules_has_optional_description(): void
    {
        $rules = $this->request->rules();

        $this->assertArrayHasKey('description', $rules);
    }

    public function test_attributes_returns_values(): void
    {
        $attributes = $this->request->attributes();

        $this->assertArrayHasKey('name', $attributes);
    }

    public function test_messages_returns_values(): void
    {
        $messages = $this->request->messages();

        $this->assertArrayHasKey('name.required', $messages);
    }

    public function test_with_validator_validates_name_min_length(): void
    {
        $this->request->merge(['name' => 'a']); // 1 char, below min of 2

        $validator = $this->app['validator']->make(
            $this->request->all(),
            $this->request->rules(),
            $this->request->messages(),
            $this->request->attributes()
        );

        $this->request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    public function test_with_validator_validates_name_max_length(): void
    {
        $this->request->merge(['name' => str_repeat('a', 25)]); // 25 chars, exceeds max of 20

        $validator = $this->app['validator']->make(
            $this->request->all(),
            $this->request->rules(),
            $this->request->messages(),
            $this->request->attributes()
        );

        $this->request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    public function test_with_validator_allows_valid_name_length(): void
    {
        $this->request->merge(['name' => 'ab']); // 2 chars, exactly min

        $validator = $this->app['validator']->make(
            $this->request->all(),
            $this->request->rules(),
            $this->request->messages(),
            $this->request->attributes()
        );

        $this->request->withValidator($validator);

        $this->assertFalse($validator->fails());
    }

    public function test_with_validator_skips_validation_when_name_empty(): void
    {
        $this->request->merge(['name' => '']);

        $validator = $this->app['validator']->make(
            $this->request->all(),
            $this->request->rules(),
            $this->request->messages(),
            $this->request->attributes()
        );

        $this->request->withValidator($validator);

        // Should not add error for empty name (handled by 'required' rule)
        $this->assertTrue($validator->fails());
    }
}
