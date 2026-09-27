<?php
/** Aplica en la instancia los dos ajustes de acceso del 27 de septiembre. */
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');

set_config('forcelogin', 1);
set_config('auth_instructions', '<p>Entra con el mismo correo y la misma contraseña '
    . 'que elegiste al inscribirte en SWARD. No hay ninguna otra clave.</p>');
purge_all_caches();

printf("forcelogin        = %s\n", get_config('core', 'forcelogin'));
printf("auth_instructions = %s\n", strip_tags(get_config('core', 'auth_instructions')));
