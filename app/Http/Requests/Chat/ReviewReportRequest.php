<?php

namespace App\Http\Requests\Chat;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['resolve', 'dismiss', 'escalate'])],
            'notes' => 'nullable|string|max:1000',
            'delete_message' => 'boolean',
            'mute_user' => 'boolean',
            'mute_duration' => 'nullable|integer|min:1|max:10080',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.required' => '操作类型不能为空',
            'action.in' => '操作类型必须为 resolve、dismiss 或 escalate',
            'notes.max' => '审核备注不能超过 1000 个字符',
            'mute_duration.min' => '禁言时长至少为 1 分钟',
            'mute_duration.max' => '禁言时长不能超过 10080 分钟',
        ];
    }
}
