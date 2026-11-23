<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuración administrable del módulo OKR (umbrales de semáforo, rangos de cierre).
 *
 * Tabla: `okr_settings`. Cada fila es un par clave/valor; `value` se guarda como
 * JSON y se decodifica con el cast `array`.
 */
class OkrSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'array'];

    /**
     * Obtiene el valor (ya decodificado) de una clave de configuración OKR.
     *
     * @param  string  $key  Clave de configuración.
     * @param  mixed  $default  Valor a devolver si la clave no existe o su valor es null.
     * @return mixed Valor decodificado o el predeterminado.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        // ->first() (no ->value()) para que el cast 'array' decodifique el JSON —
        // Query Builder::value() devuelve la columna cruda, sin pasar por Eloquent.
        $row = static::query()->where('key', $key)->first();

        return $row?->value ?? $default;
    }
}
