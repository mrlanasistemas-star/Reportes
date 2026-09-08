<?php

namespace App\Http\Requests\Okr;

use App\Models\OkrKpi;
use App\Services\Okr\OkrKpiProviderRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "provider interno no debe poder escribirse libremente como PHP desde UI" —
 * provider_key solo puede ser una de las claves YA registradas en
 * OkrKpiProviderRegistry, nunca texto libre.
 */
class StoreKpiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('okr.kpi.manage') ?? false;
    }

    public function rules(): array
    {
        $registry = app(OkrKpiProviderRegistry::class);

        return [
            'code'         => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::unique('okr_kpis', 'code')->ignore($this->route('kpi'))],
            'name'         => ['required', 'string', 'max:191'],
            'description'  => ['nullable', 'string'],
            'unit'         => ['required', Rule::in(['currency', 'percentage', 'integer', 'decimal'])],
            'type'         => ['required', Rule::in([OkrKpi::TYPE_CUMULATIVE, OkrKpi::TYPE_BALANCE, OkrKpi::TYPE_PERCENTAGE])],
            'direction'    => ['required', Rule::in([OkrKpi::DIRECTION_INCREASE, OkrKpi::DIRECTION_DECREASE])],
            'automation'   => ['required', Rule::in([OkrKpi::AUTOMATION_AUTOMATIC, OkrKpi::AUTOMATION_MANUAL, OkrKpi::AUTOMATION_HYBRID])],
            'provider_key' => ['nullable', 'string', Rule::in($registry->registeredKeys())],
            'scopes'       => ['nullable', 'array'],
            'scopes.*'     => [Rule::in(['general', 'branch', 'employee'])],
            'is_active'    => ['boolean'],
        ];
    }
}
