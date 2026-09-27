<?php
/**
 * SWARD — La pantalla de ingreso del aula virtual dice lo justo.
 *
 * Dos ajustes, los dos del 27 de septiembre de 2026:
 *
 * 1. `forcelogin`: sin esto la portada enseña el catálogo del estudio a
 *    cualquiera con el enlace, y el participante que llega desde el correo
 *    aterriza en una lista de cursos en vez de en el acceso.
 *
 * 2. `forgottenpasswordurl`: la recuperación de Moodle cambiaría **solo** la
 *    contraseña del aula virtual, y volverían a ser dos distintas. El enlace
 *    lleva a la recuperación de SWARD, que es la única que actualiza las dos.
 *
 * Y se vacía `auth_instructions`: Moodle lo pinta bajo un título «Registrarse
 * como usuario» que no viene a cuento -el registro propio está apagado- y que
 * sugiere al participante que tiene que crear otra cuenta. Lo que hay que saber
 * ya se lo dice el correo de bienvenida.
 */
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');

$ajustes = [
    'forcelogin' => 1,
    'forgottenpasswordurl' => 'https://sward-upc.github.io/sward-frontend/login',
    'auth_instructions' => '',
];
foreach ($ajustes as $nombre => $valor) {
    $antes = get_config('core', $nombre);
    set_config($nombre, $valor);
    printf("  %-22s = %s%s\n", $nombre, var_export($valor, true),
           ((string) $antes === (string) $valor) ? '' : '   (cambiado)');
}
purge_all_caches();
