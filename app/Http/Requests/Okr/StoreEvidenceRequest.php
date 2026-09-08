<?php

namespace App\Http\Requests\Okr;

use Illuminate\Foundation\Http\FormRequest;

class StoreEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('okr.evidence.upload') ?? false;
    }

    public function rules(): array
    {
        return [
            'file'              => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,pdf,jpg,jpeg,png,csv,docx'],
            'okr_key_result_id' => ['nullable', 'integer', 'exists:okr_key_results,id'],
            'week_number'       => ['nullable', 'integer', 'min:0'],
            'comment'           => ['nullable', 'string', 'max:1000'],
        ];
    }
}
