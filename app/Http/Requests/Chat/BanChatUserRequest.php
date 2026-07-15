<?php

namespace App\Http\Requests\Chat;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BanChatUserRequest extends FormRequest
{
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
        return [
            'duration' => 'nullable|integer|min:1|max:525600',
            'reason' => 'nullable|string|max:500',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'duration.integer' => '封禁时长必须为整数分钟',
            'duration.min' => '封禁时长至少为 1 分钟',
            'duration.max' => '封禁时长不能超过 525600 分钟',
            'reason.string' => '封禁原因必须是字符串',
            'reason.max' => '封禁原因不能超过 500 个字符',
        ];
    }
}
