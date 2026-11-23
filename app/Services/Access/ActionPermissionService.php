<?php

namespace App\Services\Access;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Permisos por ACCIÓN dentro de un módulo — complementario a
 * ModuleAccessService (que decide qué módulos VE cada rol; "Ver" en la matriz).
 *
 * 15-oct-2026 (Fase 7): solo existían 4 acciones (eliminar carga, eliminar
 * periodos, exportar reportes, exportar colaboradores).
 *
 * Ampliado (prioridad 9): un permiso por cada operación REAL que existe en
 * routes/*.php. Cada acción declarada aquí está aplicada en backend con el
 * middleware `permission:modulo,accion` (EnsureActionPermission) en la ruta
 * correspondiente — si una acción no tiene ruta, no se cataloga. El admin
 * siempre puede todo y no se configura.
 *
 * `column` ubica la acción en la matriz visual Ver/Crear/Editar/Eliminar/
 * Exportar/Autorizar (Ver = acceso al módulo). Una celda puede tener varias
 * acciones (p. ej. OKR); una celda sin acciones se muestra como "—".
 *
 * Persiste en `role_action_permissions` (rol, módulo, acción) con caché
 * permanente (CACHE_KEY) que se invalida al guardar o borrar.
 */
class ActionPermissionService
{
    /** Columnas de la matriz visual de permisos (clave => encabezado). */
    public const COLUMNS = [
        // 27-oct-2026 — alcance de CONSULTA dentro del módulo (p. ej. reportes
        // propios vs de todos). Distinto de "Ver" (= acceso al módulo).
        'consultar' => 'Consultar',
        'crear' => 'Crear',
        'editar' => 'Editar',
        'eliminar' => 'Eliminar',
        'exportar' => 'Exportar',
        'autorizar' => 'Autorizar',
    ];

    /**
     * module => [action => [label, column, description]].
     * El orden es el orden en la matriz y en el paso 3 del asistente de roles.
     */
    public const ACTIONS = [
        'carga' => [
            'subir' => ['label' => 'Subir y analizar archivos', 'column' => 'crear', 'description' => 'Cargar fuentes del periodo y analizarlas.'],
            'generar' => ['label' => 'Generar radiografía', 'column' => 'crear', 'description' => 'Generar, cancelar o forzar la generación del reporte del periodo.'],
            'procesar' => ['label' => 'Procesar datos e incidencias', 'column' => 'editar', 'description' => 'Actualizar la base de datos, reprocesar fuentes y resolver incidencias.'],
            'eliminar' => ['label' => 'Eliminar archivos cargados', 'column' => 'eliminar', 'description' => 'Borrar un archivo ya cargado.'],
            'descargar' => ['label' => 'Descargar archivo original', 'column' => 'exportar', 'description' => 'Descargar la fuente tal como se subió.'],
        ],
        'periodos' => [
            'crear' => ['label' => 'Crear periodos', 'column' => 'crear', 'description' => 'Dar de alta un periodo nuevo.'],
            'eliminar' => ['label' => 'Eliminar periodos', 'column' => 'eliminar', 'description' => 'Borrar un periodo y lo que depende de él.'],
            'cerrar' => ['label' => 'Cerrar y reabrir periodos', 'column' => 'autorizar', 'description' => 'Cerrar un periodo o volver a abrirlo.'],
        ],
        'colaboradores' => [
            'editar' => ['label' => 'Asignar sucursal y puesto', 'column' => 'editar', 'description' => 'Asignaciones por periodo, coincidencias y puesto.'],
            'exportar' => ['label' => 'Exportar historial de colaboradores', 'column' => 'exportar', 'description' => 'Descargar el Excel de colaboradores del periodo.'],
            // 18-oct-2026 — conciliación autorizada de altas/bajas. Sin valor por
            // defecto para ningún nivel: solo el administrador hasta que lo habilite en Roles.
            'conciliar' => ['label' => 'Conciliar altas y bajas', 'column' => 'autorizar', 'description' => 'Confirmar o descartar posibles bajas del periodo (queda en la bitácora).'],
        ],
        'reportes' => [
            // 27-oct-2026 — alcance por AUTOR del reporte. El alcance por sucursal
            // (Usuarios → alcance) se aplica ENCIMA: "de todos" = de cualquier
            // usuario, pero solo de las sucursales que el usuario puede consultar.
            'ver_propios' => ['label' => 'Ver reportes propios', 'column' => 'consultar', 'description' => 'Consultar y abrir solo los reportes mensuales que generó este mismo usuario.'],
            'ver_todos' => ['label' => 'Ver reportes de todos', 'column' => 'consultar', 'description' => 'Consultar los reportes generados por cualquier usuario, dentro de las sucursales de su alcance (alcance general = todas).'],
            'editar' => ['label' => 'Consolidar y ajustar saldo inicial', 'column' => 'editar', 'description' => 'Consolidar el periodo y capturar el saldo inicial.'],
            'exportar' => ['label' => 'Exportar Excel/PDF', 'column' => 'exportar', 'description' => 'Descargar radiografía, comparativos y consolidados que puede ver.'],
        ],
        'efectividad' => [
            'calcular' => ['label' => 'Subir archivos y calcular', 'column' => 'crear', 'description' => 'Usar la calculadora de efectividad.'],
            'exportar' => ['label' => 'Exportar resultados (PDF/imagen)', 'column' => 'exportar', 'description' => 'Descargar el resultado en PDF o imagen.'],
            // 29-oct-2026 — botón de Excel por separado: sin valor por defecto para ningún
            // nivel (no está en LEVEL_DEFAULTS), así solo el administrador lo trae y se designa
            // explícitamente por rol en Roles y permisos. Mismo formato que el PDF/imagen.
            'exportar_excel' => ['label' => 'Exportar a Excel', 'column' => 'exportar', 'description' => 'Descargar el resultado en Excel (.xlsx), con el mismo formato que el PDF/imagen.'],
        ],
        // 05-nov-2026 — Estado de Resultados. Sin LEVEL_DEFAULTS: solo el administrador
        // hasta que se asignen explícitamente en Roles y permisos.
        // 06-nov-2026 — claves guardadas intactas (procesar = conciliar, clasificar = reclasificar,
        // ajustes = editar ajustes); se agregan ver_nomina, ver_dg y aprobar, también sin defaults.
        'estado_resultados' => [
            'ver_nomina' => ['label' => 'Consultar detalle de nómina', 'column' => 'consultar', 'description' => 'Ver el desglose y los movimientos de nómina. Sin este permiso sólo se ven los totales.'],
            'ver_dg' => ['label' => 'Consultar detalle de Dirección General', 'column' => 'consultar', 'description' => 'Ver el desglose y los movimientos de DG. Sin este permiso sólo se ve el total.'],
            'cargar' => ['label' => 'Cargar estados de cuenta', 'column' => 'crear', 'description' => 'Subir los Excel complementarios (cuenta administrativa y de excedentes).'],
            'procesar' => ['label' => 'Conciliar (procesar conciliación)', 'column' => 'editar', 'description' => 'Conciliar contra ERP y aplicar las reglas de clasificación.'],
            'clasificar' => ['label' => 'Reclasificar movimientos', 'column' => 'autorizar', 'description' => 'Asignar o corregir el tratamiento financiero de un movimiento (queda en la bitácora).'],
            'ajustes' => ['label' => 'Editar ajustes contables', 'column' => 'editar', 'description' => 'Depreciación, amortización, intereses, ISR, CAPEX y capital de deuda.'],
            // 20-nov-2026 — catálogo de conceptos (global): texto del estado de cuenta → concepto del reporte.
            'catalogo' => ['label' => 'Administrar catálogo de conceptos', 'column' => 'editar', 'description' => 'Crear, editar, desactivar o borrar las reglas «texto del estado de cuenta → concepto del reporte» (aplican al procesar la conciliación).'],
            'aprobar' => ['label' => 'Aprobar y abrir nueva versión', 'column' => 'autorizar', 'description' => 'Aprobar (congelar) el Estado de Resultados del periodo y, con motivo, abrir una nueva versión.'],
            'exportar' => ['label' => 'Exportar Excel/PDF', 'column' => 'exportar', 'description' => 'Descargar el Estado de Resultados en Excel o PDF.'],
        ],
        'okr' => [
            'crear' => ['label' => 'Crear OKR', 'column' => 'crear', 'description' => 'Asignar un OKR nuevo (asistente).'],
            'cargar_colocacion' => ['label' => 'Cargar colocación', 'column' => 'crear', 'description' => 'Subir el archivo semanal de colocación.'],
            'cargar_evidencia' => ['label' => 'Cargar evidencia', 'column' => 'crear', 'description' => 'Adjuntar evidencias a un OKR.'],
            'registrar_seguimiento' => ['label' => 'Registrar seguimiento', 'column' => 'crear', 'description' => 'Check-ins y recálculo de avance.'],
            'configurar_kr' => ['label' => 'Configurar Key Results', 'column' => 'editar', 'description' => 'Agregar, completar, editar o quitar KR de un borrador.'],
            'editar_borradores' => ['label' => 'Editar borradores, metas y pesos', 'column' => 'editar', 'description' => 'Cambiar meta y pesos de un OKR.'],
            'administrar_otros' => ['label' => 'Administrar OKR de otros empleados', 'column' => 'editar', 'description' => 'Editar y dar seguimiento a OKR de los que no es responsable (dentro de lo que su rol puede ver).'],
            'anular_documentos' => ['label' => 'Anular documentos', 'column' => 'eliminar', 'description' => 'Anular una Carta Compromiso o un Warning (queda en la bitácora).'],
            'exportar' => ['label' => 'Descargar documentos', 'column' => 'exportar', 'description' => 'Descargar cartas, Warnings y evidencias en PDF/archivo.'],
            'activar' => ['label' => 'Activar OKR', 'column' => 'autorizar', 'description' => 'Revisar y activar un OKR en borrador.'],
            'revisar_carta' => ['label' => 'Revisar Carta Compromiso', 'column' => 'autorizar', 'description' => 'Vista previa y revisión antes de emitir.'],
            'emitir_carta' => ['label' => 'Emitir Carta Compromiso', 'column' => 'autorizar', 'description' => 'Emitir la carta, asignar titular y subir la copia firmada.'],
            'generar_warning' => ['label' => 'Preparar Warning', 'column' => 'autorizar', 'description' => 'Evaluar semanas elegibles y subir la copia firmada.'],
            'autorizar_warning' => ['label' => 'Autorizar y emitir Warning', 'column' => 'autorizar', 'description' => 'Emitir el Warning (requiere también "Preparar Warning").'],
        ],
        'usuarios' => [
            'crear' => ['label' => 'Crear usuarios', 'column' => 'crear', 'description' => 'Dar de alta usuarios con un rol permitido.'],
            'invitar' => ['label' => 'Enviar invitaciones', 'column' => 'crear', 'description' => 'Enviar o reenviar el correo para establecer contraseña.'],
            'editar' => ['label' => 'Vincular colaborador', 'column' => 'editar', 'description' => 'Ligar el usuario con su colaborador real.'],
            'asignar_rol' => ['label' => 'Cambiar rol', 'column' => 'editar', 'description' => 'Cambiar el rol de otro usuario (nunca a Administrador).'],
            'gestionar_acceso' => ['label' => 'Activar, revocar y bloquear acceso', 'column' => 'autorizar', 'description' => 'Habilitar o bloquear el acceso de otros usuarios.'],
            'restablecer' => ['label' => 'Restablecer contraseñas', 'column' => 'autorizar', 'description' => 'Asignar una contraseña nueva a otro usuario.'],
        ],
        'roles' => [
            'crear' => ['label' => 'Crear roles', 'column' => 'crear', 'description' => 'Crear roles nuevos.'],
            'editar' => ['label' => 'Editar roles', 'column' => 'editar', 'description' => 'Cambiar módulos y acciones de un rol.'],
            'eliminar' => ['label' => 'Eliminar roles', 'column' => 'eliminar', 'description' => 'Eliminar un rol personalizado sin usuarios.'],
        ],
    ];

    /**
     * 27-oct-2026 — dependencias entre acciones del MISMO módulo:
     * módulo => acción => acciones que requiere. Al guardar un rol se agregan
     * siempre las requeridas (sanitize()) y la UI las marca/desmarca juntas.
     */
    public const REQUIRES = [
        'reportes' => [
            'ver_todos' => ['ver_propios'],
            'editar' => ['ver_propios'],
            'exportar' => ['ver_propios'],
        ],
    ];

    /** Acciones que existían antes de ampliar el catálogo — sus filas guardadas se respetan tal cual. */
    public const LEGACY_ACTIONS = [
        'carga' => ['eliminar'], 'periodos' => ['eliminar'], 'reportes' => ['exportar'], 'colaboradores' => ['exportar'],
    ];

    /** Roles configurables de respaldo si el catálogo de roles no devuelve ninguno. */
    public const CONFIGURABLE_ROLES = [User::ROLE_GERENCIAL, User::ROLE_COLABORADOR];

    /**
     * Valores por defecto por NIVEL BASE para las acciones NUEVAS — reproducen
     * lo que cada nivel podía hacer antes de que existiera el permiso (ver la
     * migración 2026_10_17_000002, que los siembra para los roles existentes).
     * Usuarios/roles y "administrar OKR de otros" nunca se dan por defecto.
     */
    public const LEVEL_DEFAULTS = [
        User::ROLE_GERENCIAL => [
            'carga' => ['subir', 'generar', 'procesar', 'eliminar', 'descargar'],
            'periodos' => ['crear', 'eliminar', 'cerrar'],
            'colaboradores' => ['editar', 'exportar'],
            'reportes' => ['ver_propios', 'ver_todos', 'editar', 'exportar'],
            'efectividad' => ['calcular', 'exportar'],
            'okr' => ['crear', 'cargar_colocacion', 'cargar_evidencia', 'registrar_seguimiento', 'configurar_kr', 'editar_borradores',
                'anular_documentos', 'exportar', 'activar', 'revisar_carta', 'emitir_carta', 'generar_warning', 'autorizar_warning'],
        ],
        // Colaborador: los módulos de Reportería que se le habiliten se operaban
        // completos (el gate era solo de módulo); en OKR, lo propio. La Policy
        // de OKR sigue limitando a SUS objetivos.
        User::ROLE_COLABORADOR => [
            'carga' => ['subir', 'generar', 'procesar', 'descargar'],
            'periodos' => ['crear', 'cerrar'],
            'colaboradores' => ['editar'],
            // Hasta hoy, con el módulo habilitado se listaban los reportes de todos (dentro de su alcance).
            'reportes' => ['ver_propios', 'ver_todos', 'editar'],
            'efectividad' => ['calcular', 'exportar'],
            'okr' => ['cargar_colocacion', 'cargar_evidencia', 'registrar_seguimiento', 'configurar_kr', 'editar_borradores', 'exportar', 'revisar_carta'],
        ],
    ];

    /** Clave de caché de la matriz rol → módulo → acciones. */
    private const CACHE_KEY = 'role_action_permissions.v1';

    /**
     * Matriz rol → módulo → acciones permitidas según BD (con caché).
     *
     * Solo roles configurables y acciones existentes en ACTIONS (en orden del
     * catálogo). Si la tabla no existe devuelve LEVEL_DEFAULTS.
     *
     * @return array<string, array<string, list<string>>> rol => módulo => acciones permitidas.
     */
    public function matrix(): array
    {
        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('role_action_permissions')
                ->get(['role', 'module', 'action'])
                ->groupBy('role')
                ->map(fn ($rows) => $rows->groupBy('module')->map(fn ($r) => $r->pluck('action')->all())->all())
                ->all());
        } catch (\Throwable) {
            return self::LEVEL_DEFAULTS;
        }

        $matrix = [];
        foreach ($this->configurableRoles() as $role) {
            $matrix[$role] = [];
            foreach (self::ACTIONS as $module => $actions) {
                $matrix[$role][$module] = array_values(array_intersect(array_keys($actions), $stored[$role][$module] ?? []));
            }
        }

        return $matrix;
    }

    /**
     * ¿El usuario puede ejecutar la acción del módulo?
     *
     * Nunca confiar solo en la UI — el backend vuelve a comprobar esto en cada
     * endpoint sensible (middleware EnsureActionPermission). Admin = siempre;
     * sin usuario = nunca; resto según la matriz de su rol (o nivel base).
     *
     * @param  User|null  $user  Usuario autenticado.
     * @param  string  $module  Clave del módulo.
     * @param  string  $action  Clave de la acción.
     */
    public function canAction(?User $user, string $module, string $action): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return in_array($action, $this->matrix()[$this->permissionKeyFor($user)][$module] ?? [], true);
    }

    /**
     * Acciones del usuario agrupadas por módulo.
     *
     * @param  User|null  $user  Usuario autenticado.
     * @return array<string, list<string>> módulo => acciones del usuario (admin: todas).
     */
    public function actionsFor(?User $user): array
    {
        if (! $user) {
            return [];
        }
        if ($user->isAdmin()) {
            return array_map(fn ($a) => array_keys($a), self::ACTIONS);
        }

        return $this->matrix()[$this->permissionKeyFor($user)] ?? [];
    }

    /**
     * Lista plana "modulo.accion" — se comparte al frontend para ocultar botones (solo UX).
     *
     * @param  User|null  $user  Usuario autenticado.
     * @return list<string>
     */
    public function flatFor(?User $user): array
    {
        $out = [];
        foreach ($this->actionsFor($user) as $module => $actions) {
            foreach ($actions as $action) {
                $out[] = "{$module}.{$action}";
            }
        }

        return $out;
    }

    /**
     * Clave de permisos del usuario: su propio rol si es configurable; si no, su nivel base.
     *
     * @param  User  $user  Usuario no administrador.
     * @return string Clave de rol a buscar en la matriz.
     */
    private function permissionKeyFor(User $user): string
    {
        return in_array($user->role, $this->configurableRoles(), true)
            ? $user->role
            : ($user->isGerencial() ? User::ROLE_GERENCIAL : User::ROLE_COLABORADOR);
    }

    /**
     * 16-oct-2026 — roles del sistema + personalizados (todos menos admin).
     *
     * @return list<string>
     */
    private function configurableRoles(): array
    {
        return app(RoleCatalogService::class)->configurableKeys() ?: self::CONFIGURABLE_ROLES;
    }

    /**
     * Guarda acciones de un rol solo para los módulos enviados (atajo de save()).
     *
     * @param  string  $role  Clave del rol.
     * @param  array<string, list<string>>  $actions  módulo => acciones (los módulos no enviados se conservan)
     */
    public function saveRole(string $role, array $actions): void
    {
        $this->save([$role => $actions]);
    }

    /**
     * Reemplaza TODAS las acciones del rol (cualquier módulo no enviado queda
     * sin acciones) — usado por el asistente de roles para que no queden
     * privilegios residuales de un módulo que se quitó.
     *
     * @param  string  $role  Clave del rol.
     * @param  array<string, list<string>>  $actions  módulo => acciones.
     */
    public function replaceRole(string $role, array $actions): void
    {
        $full = [];
        foreach (array_keys(self::ACTIONS) as $module) {
            $full[$module] = array_values($actions[$module] ?? []);
        }
        $this->save([$role => $full]);
    }

    /**
     * Borra todas las acciones de un rol (al eliminar el rol) e invalida la caché.
     *
     * @param  string  $role  Clave del rol.
     */
    public function deleteRole(string $role): void
    {
        DB::table('role_action_permissions')->where('role', $role)->delete();
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Por cada rol enviado, reemplaza las acciones SOLO de los módulos enviados
     * (un payload parcial nunca borra permisos de módulos que no incluye).
     *
     * Corre en transacción sobre `role_action_permissions`, descarta roles no
     * configurables y acciones inexistentes, agrega las dependencias de
     * REQUIRES (withRequired()) e invalida la caché.
     *
     * @param  array<string, array<string, list<string>>>  $matrix  rol => módulo => acciones.
     */
    public function save(array $matrix): void
    {
        DB::transaction(function () use ($matrix) {
            $now = now();
            foreach (array_intersect(array_keys($matrix), $this->configurableRoles()) as $role) {
                $modules = array_values(array_intersect(array_keys(self::ACTIONS), array_keys($matrix[$role] ?? [])));
                if ($modules === []) {
                    continue;
                }
                DB::table('role_action_permissions')->where('role', $role)->whereIn('module', $modules)->delete();
                $rows = [];
                foreach ($modules as $module) {
                    foreach (self::withRequired($module, array_values(array_intersect(array_keys(self::ACTIONS[$module]), (array) ($matrix[$role][$module] ?? [])))) as $action) {
                        $rows[] = ['role' => $role, 'module' => $module, 'action' => $action, 'created_at' => $now, 'updated_at' => $now];
                    }
                }
                if ($rows !== []) {
                    DB::table('role_action_permissions')->insert($rows);
                }
            }
        });

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Catálogo para la UI: lista plana de [module, action, label, column, description].
     *
     * Incluye además `requires` (acciones de las que depende).
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        $out = [];
        foreach (self::ACTIONS as $module => $actions) {
            foreach ($actions as $action => $meta) {
                $out[] = ['module' => $module, 'action' => $action] + $meta + ['requires' => self::REQUIRES[$module][$action] ?? []];
            }
        }

        return $out;
    }

    /**
     * Validación de un payload módulo => acciones: devuelve solo pares existentes.
     *
     * Devuelve TODOS los módulos del catálogo (vacíos si no se enviaron) y
     * agrega las acciones requeridas.
     *
     * @param  array<string, mixed>  $actions  Payload recibido de la UI.
     * @return array<string, list<string>>
     */
    public function sanitize(array $actions): array
    {
        $clean = [];
        foreach (self::ACTIONS as $module => $defs) {
            $clean[$module] = self::withRequired($module, array_values(array_intersect(array_keys($defs), (array) ($actions[$module] ?? []))));
        }

        return $clean;
    }

    /**
     * Agrega las acciones requeridas (REQUIRES, transitivo) y devuelve la lista
     * en el orden del catálogo. Nunca quita nada.
     *
     * @param  string  $module  Clave del módulo.
     * @param  list<string>  $actions  Acciones elegidas.
     * @return list<string> Acciones elegidas + requeridas, en orden del catálogo.
     */
    public static function withRequired(string $module, array $actions): array
    {
        $set = array_fill_keys($actions, true);
        $pending = $actions;
        while ($pending !== []) {
            $action = array_pop($pending);
            foreach (self::REQUIRES[$module][$action] ?? [] as $required) {
                if (! isset($set[$required])) {
                    $set[$required] = true;
                    $pending[] = $required;
                }
            }
        }

        return array_values(array_filter(array_keys(self::ACTIONS[$module] ?? []), fn ($a) => isset($set[$a])));
    }

    /**
     * ¿La acción está declarada en el catálogo ACTIONS?
     *
     * @param  string  $module  Clave del módulo.
     * @param  string  $action  Clave de la acción.
     */
    public static function exists(string $module, string $action): bool
    {
        return isset(self::ACTIONS[$module][$action]);
    }
}
