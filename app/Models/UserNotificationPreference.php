<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preferencia de notificación de un usuario para un tipo de evento
 * (23-nov-2026). Sin fila = correo y push activados. La campana interna
 * (canal `database`) no se puede desactivar: es el historial del usuario.
 *
 * Tabla: `user_notification_preferences`. Cada fila es (usuario, tipo de evento)
 * con dos banderas: `mail_enabled` y `push_enabled` (bool).
 */
class UserNotificationPreference extends Model
{
    /** Lectura de archivos terminada (actualización de BD del periodo). */
    public const TYPE_CARGA = 'carga_lectura';

    /** Radiografía generada (éxito o error). */
    public const TYPE_REPORTE = 'reporte_generado';

    /** Nuevo mes operativo creado. */
    public const TYPE_PERIODO = 'periodo_nuevo';

    /** Tipo => etiqueta y descripción visibles en Configuración > Notificaciones. */
    public const TYPES = [
        self::TYPE_CARGA => [
            'label' => 'Lectura de archivos terminada',
            'description' => 'Cuando termina "Cargar registros" del periodo, con éxito o con error.',
        ],
        self::TYPE_REPORTE => [
            'label' => 'Reporte generado',
            'description' => 'Cuando la Radiografía queda lista para consultar o si no se pudo generar.',
        ],
        self::TYPE_PERIODO => [
            'label' => 'Nuevo periodo mensual',
            'description' => 'Cuando alguien crea un nuevo mes operativo (solo si tienes acceso a Carga o Periodos).',
        ],
    ];

    /** Canal de correo electrónico. */
    public const CHANNEL_MAIL = 'mail';

    /** Canal de notificación push del navegador. */
    public const CHANNEL_PUSH = 'push';

    protected $fillable = ['user_id', 'type', 'mail_enabled', 'push_enabled'];

    /**
     * Casts de atributos: las banderas de canal se leen como booleanos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mail_enabled' => 'boolean',
            'push_enabled' => 'boolean',
        ];
    }

    /** Usuario dueño de la preferencia. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ¿El usuario quiere este canal para este tipo? Ante cualquier duda (tabla sin migrar), sí.
     * Si no hay fila guardada también devuelve true (todo activado por defecto).
     *
     * @param  User  $user  Usuario destinatario.
     * @param  string  $type  Tipo de evento (constantes TYPE_*).
     * @param  string  $channel  Canal (CHANNEL_MAIL o CHANNEL_PUSH); cualquier otro valor se evalúa como push.
     * @return bool true si se debe enviar por ese canal.
     */
    public static function allows(User $user, string $type, string $channel): bool
    {
        try {
            $pref = static::query()->where('user_id', $user->id)->where('type', $type)->first();
        } catch (\Throwable) {
            return true;
        }

        if (! $pref) {
            return true;
        }

        return $channel === self::CHANNEL_MAIL ? $pref->mail_enabled : $pref->push_enabled;
    }

    /**
     * Matriz completa para la vista de configuración (incluye los tipos sin fila).
     * Los tipos sin preferencia guardada aparecen con correo y push activados.
     *
     * @param  User  $user  Usuario cuya configuración se muestra.
     * @return list<array{type:string,label:string,description:string,mail:bool,push:bool}>
     */
    public static function matrixFor(User $user): array
    {
        $stored = static::query()->where('user_id', $user->id)->get()->keyBy('type');

        $rows = [];
        foreach (self::TYPES as $type => $meta) {
            $pref = $stored->get($type);
            $rows[] = [
                'type' => $type,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'mail' => $pref ? $pref->mail_enabled : true,
                'push' => $pref ? $pref->push_enabled : true,
            ];
        }

        return $rows;
    }
}
