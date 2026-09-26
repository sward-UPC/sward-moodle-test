<?php
/**
 * SWARD — Reescribe el correo de bienvenida del aula virtual.
 *
 * La plantilla de fábrica hablaba de un «nombre de usuario» que no hace falta
 * —se entra con el correo—, decía que la cuenta era «en SWARD» sin distinguir la
 * aplicación del aula, no explicaba el orden (primero los cuestionarios, SWARD
 * después) y dejaba los enlaces numerados al pie como [1] y [2], porque
 * `$a->signoff` inserta un enlace de soporte cuyo texto no es su URL y Moodle
 * numera esos. Aquí se escribe la URL a la vista y se firma a mano.
 *
 * Se guarda como paquete de idioma local (`lang/es_local`), que es donde Moodle
 * deja las personalizaciones de idioma: sobrevive a reinicios y a
 * actualizaciones del paquete `es`. Idempotente.
 */
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');

$asunto = 'Tu acceso al aula virtual';

$cuerpo = 'Hola, {$a->firstname}.

Te inscribiste en el estudio de SWARD. Este correo es tu acceso al AULA VIRTUAL,
que es donde vas a resolver los cuestionarios.

Entra con el mismo correo con el que te inscribiste y esta contraseña temporal:

    Contraseña temporal: {$a->newpassword}

La primera vez te pedirá cambiarla. Tiene que ser una distinta: la temporal no
sirve dos veces.

Aula virtual: {$a->link}

Empieza por ahí. Cuando termines los cuestionarios te avisaremos para que uses
SWARD, la aplicación que te recomienda qué repasar y te explica por qué lo
recomienda.

¿Algún problema para entrar? Responde a este correo.

Jorge Labán Hijar
Tesis de Ingeniería de Software — Universidad Peruana de Ciencias Aplicadas
sward.proyecto@gmail.com
';

$dir = $CFG->langlocalroot ?? ($CFG->dataroot . '/lang');
$destino = $dir . '/es_local';
if (!is_dir($destino)) {
    mkdir($destino, $CFG->directorypermissions ?? 0777, true);
}

$php = "<?php\n"
     . "// Personalizacion de SWARD. Generada el 26 de septiembre de 2026.\n"
     . '$string[\'newusernewpasswordsubj\'] = ' . var_export($asunto, true) . ";\n"
     . '$string[\'newusernewpasswordtext\'] = ' . var_export($cuerpo, true) . ";\n";

$archivo = $destino . '/moodle.php';
file_put_contents($archivo, $php);
@chmod($archivo, $CFG->filepermissions ?? 0666);
printf("escrito: %s (%d bytes)\n", $archivo, filesize($archivo));

get_string_manager()->reset_caches();
purge_all_caches();

printf("\nComprobacion:\n");
printf("  sitio  : %s\n", $DB->get_field('course', 'fullname', ['id' => SITEID]));
printf("  asunto : %s\n", get_string('newusernewpasswordsubj'));
$a = (object) ['firstname' => 'Ana', 'newpassword' => 'xxxx', 'link' => $CFG->wwwroot . '/login/?lang=es'];
printf("  cuerpo :\n---\n%s\n---\n", get_string('newusernewpasswordtext', '', $a));
