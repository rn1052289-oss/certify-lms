<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Models\AiChatConversation;
use Illuminate\Foundation\Http\FormRequest;

class StoreConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AiChatConversation::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'source' => ['nullable', 'in:widget,full-screen'],
            'section_id' => ['nullable', 'ulid'],
            'enrollment_id' => ['nullable', 'ulid'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
