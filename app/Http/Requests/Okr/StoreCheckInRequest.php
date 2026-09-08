<?php

namespace App\Http\Requests\Okr;

use Illuminate\Foundation\Http\FormRequest;

class StoreCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('okr.checkin') ?? false;
    }

    public function rules(): array
    {
        return [
            'main_blocker'       => ['nullable', 'string', 'max:2000'],
            'corrective_action'  => ['nullable', 'string', 'max:2000'],
            'action_responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'action_due_date'            => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
