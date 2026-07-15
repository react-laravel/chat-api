<?php

namespace App\Http\Requests\Chat;

use App\Models\Chat\ChatMessageReport;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportMessageRequest extends FormRequest
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
            'report_type' => [
                'required',
                Rule::in([
                    ChatMessageReport::TYPE_INAPPROPRIATE_CONTENT,
                    ChatMessageReport::TYPE_SPAM,
                    ChatMessageReport::TYPE_HARASSMENT,
                    ChatMessageReport::TYPE_HATE_SPEECH,
                    ChatMessageReport::TYPE_VIOLENCE,
                    ChatMessageReport::TYPE_SEXUAL_CONTENT,
                    ChatMessageReport::TYPE_MISINFORMATION,
                    ChatMessageReport::TYPE_OTHER,
                ]),
            ],
            'reason' => 'nullable|string|max:500',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'report_type.required' => '举报类型不能为空',
            'report_type.in' => '举报类型无效',
            'reason.max' => '举报原因不能超过 500 个字符',
        ];
    }
}
