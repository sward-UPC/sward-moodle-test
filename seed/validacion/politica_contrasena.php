<?php
/**
 * SWARD — Una sola regla de contraseña, la misma que pide SWARD.
 *
 * Desde el 27 de septiembre de 2026 la persona elige **una contraseña** al
 * registrarse en SWARD y esa misma le sirve en el aula virtual. Para que Moodle
 * no rechace lo que SWARD ya aceptó, su política tiene que ser idéntica:
 * **8 caracteres, al menos una mayúscula y al menos un número**.
 *
 * La de fábrica pedía además minúscula y carácter especial, así que el
 * participante se encontraba con dos reglamentos distintos en el mismo trámite.
 * Lo reportó quien probó el recorrido completo: eligió `ABCD1234` en SWARD y
 * Moodle se la rechazó.
 *
 * `passwordreuselimit` se mantiene en 1 —no se puede repetir la anterior—, que
 * es de otro arreglo del 26 y sigue teniendo sentido.
 *
 * **Correrlo después de cada despliegue nuevo**, como `cargar_cursos.php`.
 */
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');

$ajustes = [
    'passwordpolicy' => 1,
    'minpasswordlength' => 8,
    'minpassworddigits' => 1,
    'minpasswordupper' => 1,
    // Ni minúsculas ni signos: SWARD no los pide, y pedirlos aquí obligaría a la
    // persona a tener dos contraseñas distintas otra vez.
    'minpasswordlower' => 0,
    'minpasswordnonalphanum' => 0,
];
foreach ($ajustes as $nombre => $valor) {
    $antes = get_config('core', $nombre);
    set_config($nombre, $valor);
    printf("  %-26s %s%s\n", $nombre, $valor,
           ((string) $antes === (string) $valor) ? '' : "   (antes: $antes)");
}

purge_all_caches();

printf("\nComprobacion, con las mismas que acepta SWARD:\n");
foreach (['ABCD1234', 'Secreta2026', 'abcd1234', 'Abc123'] as $clave) {
    $error = '';
    $vale = check_password_policy($clave, $error);
    printf("  %-14s %s%s\n", $clave, $vale ? 'aceptada' : 'rechazada',
           $vale ? '' : ' — ' . strip_tags($error));
}
