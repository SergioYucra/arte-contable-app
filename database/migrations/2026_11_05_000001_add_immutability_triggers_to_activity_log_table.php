<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * S4 — Inmutabilidad de la bitácora a nivel de base de datos.
 *
 * Triggers BEFORE DELETE / BEFORE UPDATE sobre la tabla de auditoría: cierran la vía
 * DB::table('activity_log')->delete() y cualquier SQL directo, que el modelo no puede ver.
 *
 * Notas:
 *  - Se usa unprepared(): MySQL no admite CREATE TRIGGER en sentencias preparadas (error 1295)
 *    y Laravel desactiva la emulación de prepares.
 *  - TRUNCATE y DROP no disparan triggers en MySQL/MariaDB: en producción el usuario de BD de la
 *    aplicación no debe tener el privilegio DROP (checklist H10 de despliegue). En PostgreSQL sí se
 *    bloquea TRUNCATE; en SQLite truncate() se compila como DELETE y queda cubierto.
 *  - Crear triggers requiere el privilegio TRIGGER y, en MySQL con binlog activo (lo habitual), además
 *    SUPER o log_bin_trust_function_creators=1 (error 1419). Si falta, la migración falla a propósito
 *    con una explicación: no se deja la bitácora sin protección en silencio. Para omitirlos a
 *    conciencia (hosting que no los permite): ACTIVITY_LOG_DB_TRIGGERS=false.
 *  - Una migración futura que haga ->change() sobre esta tabla en SQLite recrea la tabla y pierde
 *    los triggers; cualquier backfill (UPDATE activity_log ...) debe soltarlos y recrearlos.
 *  - El comando activitylog:clean (config delete_records_older_than_days) deja de funcionar: es
 *    intencional, la bitácora no se depura.
 */
return new class extends Migration
{
    private const MESSAGE = 'Los registros de auditoria son inmutables: no pueden modificarse ni eliminarse.';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Omisión explícita y consciente (ver config/activitylog.php → database_triggers)
        if (! config('activitylog.database_triggers', true)) {
            return;
        }

        $connection = DB::connection(config('activitylog.database_connection'));

        try {
            $this->createTriggers($connection);
        } catch (QueryException $e) {
            // 1419: binlog activo sin SUPER / log_bin_trust_function_creators. 1142: falta el privilegio TRIGGER.
            if (in_array($e->errorInfo[1] ?? null, [1419, 1142], true)) {
                throw new RuntimeException(
                    'No se pudieron crear los triggers que protegen la bitácora de auditoría (S4): el usuario de '
                    .'la base de datos no tiene permiso para crear triggers. Opciones: (1) que el administrador de '
                    .'MySQL active log_bin_trust_function_creators=1 o ejecute esta migración con un usuario con '
                    .'privilegio SUPER; (2) si el hosting no lo permite, definir ACTIVITY_LOG_DB_TRIGGERS=false en '
                    .'el .env para continuar solo con la protección del modelo.',
                    previous: $e
                );
            }

            throw $e;
        }
    }

    private function createTriggers(Connection $connection): void
    {
        $table = $connection->getTablePrefix().config('activitylog.table_name', 'activity_log');

        switch ($connection->getDriverName()) {
            case 'sqlite':
                foreach (['delete' => 'DELETE', 'update' => 'UPDATE'] as $suffix => $event) {
                    $connection->unprepared(
                        "CREATE TRIGGER IF NOT EXISTS \"{$table}_immutable_{$suffix}\" BEFORE {$event} ON \"{$table}\" "
                        ."BEGIN SELECT RAISE(ABORT, '".self::MESSAGE."'); END;"
                    );
                }
                break;

            case 'mysql':
            case 'mariadb':
                foreach (['delete' => 'DELETE', 'update' => 'UPDATE'] as $suffix => $event) {
                    $connection->unprepared(
                        "CREATE TRIGGER `{$table}_immutable_{$suffix}` BEFORE {$event} ON `{$table}` "
                        ."FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."'"
                    );
                }
                break;

            case 'pgsql':
                $connection->unprepared(
                    "CREATE OR REPLACE FUNCTION {$table}_immutable() RETURNS trigger AS \$\$ "
                    ."BEGIN RAISE EXCEPTION '".self::MESSAGE."'; END; \$\$ LANGUAGE plpgsql;"
                );
                $connection->unprepared(
                    "CREATE TRIGGER {$table}_immutable_row BEFORE UPDATE OR DELETE ON \"{$table}\" "
                    ."FOR EACH ROW EXECUTE FUNCTION {$table}_immutable();"
                );
                $connection->unprepared(
                    "CREATE TRIGGER {$table}_immutable_truncate BEFORE TRUNCATE ON \"{$table}\" "
                    ."FOR EACH STATEMENT EXECUTE FUNCTION {$table}_immutable();"
                );
                break;

            default:
                // Driver sin soporte conocido (p. ej. sqlsrv): la protección queda en el modelo
                // y en los privilegios del usuario de BD.
                break;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = DB::connection(config('activitylog.database_connection'));
        $table = $connection->getTablePrefix().config('activitylog.table_name', 'activity_log');

        switch ($connection->getDriverName()) {
            case 'sqlite':
                $connection->unprepared("DROP TRIGGER IF EXISTS \"{$table}_immutable_delete\";");
                $connection->unprepared("DROP TRIGGER IF EXISTS \"{$table}_immutable_update\";");
                break;

            case 'mysql':
            case 'mariadb':
                $connection->unprepared("DROP TRIGGER IF EXISTS `{$table}_immutable_delete`");
                $connection->unprepared("DROP TRIGGER IF EXISTS `{$table}_immutable_update`");
                break;

            case 'pgsql':
                $connection->unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_row ON \"{$table}\";");
                $connection->unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_truncate ON \"{$table}\";");
                $connection->unprepared("DROP FUNCTION IF EXISTS {$table}_immutable();");
                break;

            default:
                break;
        }
    }
};
