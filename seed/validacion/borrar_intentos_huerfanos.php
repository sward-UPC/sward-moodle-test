<?php
/**
 * SWARD — Borra los intentos de quiz de cuentas ya eliminadas.
 *
 * Moodle no borra al usuario: lo anonimiza (`deleted=1`, correo convertido en un
 * hash) y **conserva sus intentos**. Esos intentos son justo lo que la
 * sincronización lleva a SWARD y con lo que el modelo aprende, así que una
 * cuenta de prueba borrada seguiría pesando en los datos del estudio.
 *
 * Encontrado el 27 de septiembre de 2026, al limpiar las cuentas de prueba.
 */
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

$borrados = 0;
foreach ($DB->get_records('quiz_attempts') as $intento) {
    $usuario = $DB->get_record('user', ['id' => $intento->userid], 'id,deleted');
    if (!$usuario || !$usuario->deleted) {
        continue;
    }
    $quiz = $DB->get_record('quiz', ['id' => $intento->quiz], '*', MUST_EXIST);
    quiz_delete_attempt($intento, $quiz);
    $borrados++;
    printf("  borrado intento %d (usuario %d, ya eliminado)\n", $intento->id, $intento->userid);
}
printf("\nborrados: %d | quedan: %d\n", $borrados, $DB->count_records('quiz_attempts'));
