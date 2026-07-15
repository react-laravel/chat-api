<?php

namespace App\Http\Requests\Chat;

use Dogeow\PhpHelpers\CharLength;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateRoomRequest extends FormRequest
{
    // 房间名称长度限制(按字符数计算：中文/emoji 算 2，数字/字母算 1)
    private const MIN_ROOM_NAME_LENGTH = 2; // 最少 2 个字符

    private const MAX_ROOM_NAME_LENGTH = 20; // 最多 20 个字符

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // when returning rules as a pipe‑delimited string the
        // unit tests are able to explode() and verify individual
        // components.  this also keeps the syntax more compact for
        // simple rule sets. complex custom length validation is handled
        // in withValidator().
        return [
            'name' => 'required|string|max:255|unique:chat_rooms,name',
            'description' => 'nullable|string|max:1000',
            'is_private' => 'sometimes|boolean',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $name = $this->input('name');

            if (empty($name)) {
                return;
            }

            // 检查最小长度
            if (CharLength::belowMin($name, self::MIN_ROOM_NAME_LENGTH)) {
                $validator->errors()->add(
                    'name',
                    '房间名称至少需要'.self::MIN_ROOM_NAME_LENGTH.'个字符'
                );
            }

            // 检查最大长度
            if (CharLength::exceedsMax($name, self::MAX_ROOM_NAME_LENGTH)) {
                $validator->errors()->add(
                    'name',
                    '房间名称不能超过'.self::MAX_ROOM_NAME_LENGTH.'个字符(中文/emoji 算 2 个字符，数字/字母算 1 个字符)'
                );
            }
        });
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'Room Name',
            'description' => 'Room Description',
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => '房间名称是必需的',
            'name.unique' => '该房间名称已存在',
            'description.max' => '描述不能超过 1000 个字符',
        ];
    }
}
