# Backups de la base de datos

Backups en formato custom de `pg_dump` de la base de producción `nails_manager_pro`.
Esta carpeta está en `.gitignore` — los archivos acá nunca se commitean.

## Restaurar en la VPS de producción

**Advertencia: esto sobreescribe la base de producción actual. Solo hacelo si es realmente la intención (ej. recuperarse de un desastre).**

Datos de conexión: VPS `root@138.219.41.253` (key `~/.ssh/nailsmanager_vps`), Postgres `nmp_user@127.0.0.1:5432`, db `nails_manager_pro`, app en `/var/www/api`.

1. **Backup de seguridad del estado actual**, antes de tocar nada (por si hay que volver atrás):
   ```
   ssh -i ~/.ssh/nailsmanager_vps root@138.219.41.253 \
     "PGPASSWORD='<password del .env>' pg_dump -h 127.0.0.1 -U nmp_user -F c -d nails_manager_pro -f /root/backups/pre_restore_\$(date +%Y%m%d_%H%M%S).dump"
   ```

2. **Subir el dump a restaurar** a la VPS:
   ```
   scp -i ~/.ssh/nailsmanager_vps backups/<archivo>.dump root@138.219.41.253:/root/backups/
   ```

3. **Poner la app en mantenimiento y frenar lo que escribe a la DB** (si no, puede haber conflictos o datos inconsistentes durante el restore):
   ```
   ssh -i ~/.ssh/nailsmanager_vps root@138.219.41.253 "cd /var/www/api && php artisan down"
   ssh -i ~/.ssh/nailsmanager_vps root@138.219.41.253 "supervisorctl stop laravel-worker:laravel-worker_00"
   ```

4. **Restaurar** (pisa `nails_manager_pro` con el contenido del dump):
   ```
   ssh -i ~/.ssh/nailsmanager_vps root@138.219.41.253 \
     "PGPASSWORD='<password del .env>' pg_restore -h 127.0.0.1 -U nmp_user -d nails_manager_pro --clean --if-exists -F c /root/backups/<archivo>.dump"
   ```

5. **Levantar todo de nuevo**:
   ```
   ssh -i ~/.ssh/nailsmanager_vps root@138.219.41.253 "supervisorctl start laravel-worker:laravel-worker_00"
   ssh -i ~/.ssh/nailsmanager_vps root@138.219.41.253 "cd /var/www/api && php artisan up"
   ```

6. **Verificar** cantidades de filas en tablas clave (`users`, `turnos`, `clientes`) contra lo esperado antes de dar por buena la restauración.

La contraseña de Postgres está en `/var/www/api/.env` en la VPS (`DB_PASSWORD`) — no la hardcodees en este archivo ni en el repo.
