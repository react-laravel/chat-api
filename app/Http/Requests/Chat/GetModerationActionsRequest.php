<?php

namespace App\Http\Requests\Chat;

use App\Models\Chat\ChatModerationAction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GetModerationActionsRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ACTION_TYPES = [
        ChatModerationAction::ACTION_DELETE_MESSAGE,
        ChatModerationAction::ACTION_MUTE_USER,
        ChatModerationAction::ACTION_UNMUTE_USER,
        ChatModerationAction::ACTION_TIMEOUT_USER,
        ChatModerationAction::ACTION_BAN_USER,
        ChatModerationAction::ACTION_UNBAN_USER,
        ChatModerationAction::ACTION_CONTENT_FILTER,
        ChatModerationAction::ACTION_SPAM_DETECTION,
        ChatModerationAction::ACTION_REPORT_MESSAGE,
    ];

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
            'per_page' => 'nullable|integer|min:1|max:100',
            'action_type' => ['nullable', 'string', Rule::in(self::ACTION_TYPES)],
            'target_user_id' => 'nullable|integer',
        ];
    }

    /**
     * @return array{per_page:int, action_type:?string, target_user_id:?int}
     */
    public function validatedFilters(): array
    {
        $validated = $this->validated();

        return [
            'per_page' => (int) ($validated['per_page'] ?? 20),
            'action_type' => $validated['action_type'] ?? null,
            'target_user_id' => isset($validated['target_user_id']) ? (int) $validated['target_user_id'] : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'per_page.integer' => '每页数量必须为整数',
            'per_page.min' => '每页数量至少为 1',
            'per_page.max' => '每页数量不能超过 100',
            'action_type.string' => '操作类型格式不正确',
            'target_user_id.integer' => '目标用户 ID 必须为整数',
        ];
    }
}
