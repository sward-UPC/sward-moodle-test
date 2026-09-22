<?php
/**
 * SWARD — Ajustes del sitio Moodle para los participantes del estudio.
 *
 * Idempotente: se puede correr las veces que haga falta. Lo aplica el arranque
 * del Moodle de la nube (sward-infra, MoodleStack) y se corre a mano en local:
 *
 *   docker cp seed/validacion/configurar_sitio.php sward-moodle-app:/tmp/
 *   docker exec sward-moodle-app php /tmp/configurar_sitio.php
 *
 * Cada ajuste responde a un problema visto al probar el Moodle local
 * (22 de septiembre de 2026):
 *
 * - Entrada con el correo: el usuario de un participante es la parte local de
 *   su correo y lo natural es escribir el correo completo.
 * - Sin botón de invitado: los cursos no admiten invitados.
 * - Hora de Lima: la imagen trae Europe/London y los plazos se verían corridos.
 * - Correos ocultos entre participantes.
 * - Al entrar, «Mis cursos»; sin Tablero ni mensajería entre participantes:
 *   un participante solo necesita sus dos cursos.
 * - Sin correos por cada quiz: Moodle manda por defecto una confirmación por
 *   cada intento enviado. Con 36 quizzes y 30 estudiantes serían más de mil
 *   correos desde la cuenta de Gmail del proyecto, que corta el envío hacia los
 *   500 diarios y es la misma que manda las contraseñas. Las confirmaciones
 *   quedan dentro de Moodle (campana), no por correo.
 * - Menos ruido para el participante: la lista de cursos sin categorías ni
 *   selector de vistas, y el menú del usuario con solo su perfil y sus notas.
 * - Sin «Modo de edición» en el perfil: los participantes no acomodan bloques
 * - Los estudiantes no ven la lista de participantes (nombres, roles y último
 *   acceso de sus compañeros): no la necesitan y expone datos de los demás.
 * - Sin competencias: el estudio no las usa y agregaban una pestaña al curso.
 * - Tema SWARD (moodle/theme/sward), si está instalado.
 */

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/adminlib.php');

$ajustes = [
    'authloginviaemail' => 1,
    'guestloginbutton' => 0,
    'timezone' => 'America/Lima',
    'defaultpreference_maildisplay' => 0,
    'defaulthomepage' => HOMEPAGE_MYCOURSES,
    'enabledashboard' => 0,
    'messaging' => 0,
];
foreach ($ajustes as $nombre => $valor) {
    $antes = get_config('core', $nombre);
    set_config($nombre, $valor);
    printf("  %-32s %s%s\n", $nombre, $valor, ((string) $antes === (string) $valor) ? '' : "  (antes: $antes)");
}

// Notificaciones que no deben salir por correo: quedan en la campana de Moodle
// y el participante no puede volver a activarlas por correo (bloqueado).
$avisos = [
    'mod_quiz' => ['confirmation', 'submission', 'attempt_grading_complete', 'attempt_overdue', 'quiz_open_soon'],
    'moodle' => ['newlogin'],
];
foreach ($avisos as $componente => $nombres) {
    foreach ($nombres as $nombre) {
        set_config("message_provider_{$componente}_{$nombre}_enabled", 'popup', 'message');
        set_config("email_provider_{$componente}_{$nombre}_locked", 1, 'message');
        echo "  aviso {$componente}/{$nombre}: solo en Moodle\n";
    }
}

// Qué hacer en la pantalla de ingreso (Moodle lo muestra bajo el formulario).
set_config('auth_instructions', '<p>Entra con el correo con el que te inscribiste. Si es tu primer ingreso, '
    . 'usa la contraseña que te llegó por correo: Moodle te pedirá cambiarla.</p>');
echo "  ingreso: instrucciones
";

// «Mis cursos»: tarjetas, sin el nombre de la categoría ni el selector de vistas.
set_config('displaycategories', 0, 'block_myoverview');
set_config('layouts', 'card', 'block_myoverview');
// Menú del usuario: su perfil y sus notas. Sin calendario, archivos ni reportes,
// que no se usan en el estudio.
set_config('customusermenuitems', "profile,moodle|/user/profile.php\ngrades,grades|/grade/report/mygrades.php");
echo "  menús: sin lo que el estudio no usa\n";

$estudiante = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
unassign_capability('moodle/course:viewparticipants', $estudiante->id, context_system::instance()->id);
// El perfil del usuario trae un «Modo de edición» para acomodar los bloques de
// esa página. Es personalización que aquí no aporta y solo invita a desordenar el
// perfil; el permiso cuelga del rol «usuario autenticado», no del de estudiante.
$autenticado = $DB->get_record('role', ['shortname' => 'user'], '*', MUST_EXIST);
unassign_capability('moodle/user:manageownblocks', $autenticado->id, context_system::instance()->id);
echo "  estudiantes: sin lista de participantes ni edición del perfil\n";
set_config('enabled', 0, 'core_competency');
echo "  competencias: desactivadas\n";

if (core_component::get_plugin_directory('theme', 'sward')) {
    set_config('theme', 'sward');
    theme_reset_all_caches();
    echo "  tema: sward\n";
} else {
    echo "  tema: sward no está instalado (moodle/theme/sward); se deja el actual\n";
}

purge_all_caches();
echo "Sitio configurado para los participantes.\n";
