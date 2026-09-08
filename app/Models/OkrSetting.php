<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Configuración administrable (umbrales de semáforo, rangos de cierre). */
class OkrSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'array'];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        // ->first() (no ->value()) para que el cast 'array' decodifique el JSON —
        // Query Builder::value() devuelve la columna cruda, sin pasar por Eloquent.
        $row = static::query()->where('key', $key)->first();

        return $row?->value ?? $default;
    }
}
