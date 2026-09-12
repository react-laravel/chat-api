<?php

namespace App\Http\Requests\Chat;

use App\Models\Chat\ChatAttachment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendMessageRequest extends FormRequest
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
            'message' => 'required_without:attachment_ids|nullable|string|max:1000',
            'attachment_ids' => ['nullable', 'array', 'max:'.ChatAttachment::MAX_FILES_PER_MESSAGE],
            'attachment_ids.*' => ['integer', 'distinct', Rule::exists('chat_attachments', 'id')->where('room_id', $this->route('roomId'))->where('user_id', $this->user()?->id)->whereNull('message_id')],
            'message_type' => 'nullable|string|in:text',
            'reply_to_id' => ['nullable', 'integer', Rule::exists('chat_messages', 'id')
                ->where('room_id', $this->route('roomId'))->whereNull('deleted_at')],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'message' => 'Message',
            'message_type' => 'Message Type',
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
            'message.required' => 'Message content is required.',
            'message.max' => 'Message cannot exceed 1000 characters.',
            'message_type.in' => 'Only text messages can be sent by users.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'message_type' => $this->message_type ?? 'text',
        ]);
    }
}
