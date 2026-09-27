<?php
/**
 * SWARD — Los textos propios del aula virtual.
 *
 * Un solo archivo porque Moodle guarda todas las personalizaciones de idioma en
 * `lang/es_local/moodle.php`: dos guiones escribiéndolo se pisarían.
 *
 * Qué cambia y por qué:
 *
 * - `newusernewpasswordsubj` / `newusernewpasswordtext`: el correo de alta. El de
 *   fábrica confundía la aplicación con el aula, anunciaba un nombre de usuario
 *   que no hace falta y numeraba los enlaces al pie como [1] y [2].
 * - `loginto`: el recuadro de acceso se titulaba «Entrar a SWARD — Aula virtual».
 *   Pasa a ser sólo el nombre del sitio, que es lo que se espera arriba de una
 *   pantalla de ingreso.
 *
 * **Correrlo después de cada despliegue nuevo**, como `cargar_cursos.php`.
 */
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');

$soporte = $CFG->wwwroot . '/user/contactsitesupport.php';

$cadenas = [
    'newusernewpasswordsubj' => 'Tu acceso al aula virtual',
    'newusernewpasswordtext' => 'Hola, {$a->firstname}.

Te inscribiste en el estudio de SWARD. Este correo es tu acceso al aula virtual, que es donde vas a resolver los cuestionarios.

Entra con el mismo correo con el que te inscribiste y esta contraseña temporal:

    Contraseña temporal: {$a->newpassword}

La primera vez el sistema te pedirá cambiarla. Tiene que ser una distinta: la temporal no sirve dos veces.

Aula virtual: {$a->link}

Empieza por ahí. Cuando termines los cuestionarios te avisaremos para que uses SWARD, la aplicación que te recomienda qué repasar y te explica por qué lo recomienda.

¿Algún problema para entrar? Escríbenos: ' . $soporte . '
',
    // Era «Entrar a {$a}». El nombre del sitio solo, sin el verbo.
    'loginto' => '{$a}',
];

$dir = ($CFG->langlocalroot ?? ($CFG->dataroot . '/lang')) . '/es_local';
if (!is_dir($dir)) {
    mkdir($dir, $CFG->directorypermissions ?? 0777, true);
}
$php = "<?php\n// Personalizacion de SWARD. La genera seed/validacion/textos_aula.php\n";
foreach ($cadenas as $id => $valor) {
    $php .= '$string[' . var_export($id, true) . '] = ' . var_export($valor, true) . ";\n";
}
file_put_contents($dir . '/moodle.php', $php);
@chmod($dir . '/moodle.php', $CFG->filepermissions ?? 0666);

get_string_manager()->reset_caches();
purge_all_caches();

printf("escrito: %s/moodle.php\n\nComprobacion:\n", $dir);
printf("  titulo del acceso : %s\n", get_string('loginto', '', format_string($SITE->fullname)));
printf("  asunto del correo : %s\n", get_string('newusernewpasswordsubj'));
