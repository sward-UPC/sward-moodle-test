# Plugins de Moodle del proyecto

- `theme/sward`: tema hijo de Boost. Deja en la barra principal solo «Mis
  cursos» (sin Home ni Dashboard, que duplicaban lo mismo y confundían a los
  participantes) y usa el color de la aplicación SWARD.

Se instala copiando la carpeta en `/var/www/html/theme/` del contenedor y
corriendo `admin/cli/upgrade.php`; lo activa `seed/validacion/configurar_sitio.php`.

```bash
docker cp moodle/theme/sward sward-moodle-app:/var/www/html/theme/sward
docker exec sward-moodle-app php /var/www/html/admin/cli/upgrade.php --non-interactive
docker cp seed/validacion/configurar_sitio.php sward-moodle-app:/tmp/
docker exec sward-moodle-app php /tmp/configurar_sitio.php
```

En la nube lo hace el arranque de la instancia (sward-infra, `MoodleStack`).
