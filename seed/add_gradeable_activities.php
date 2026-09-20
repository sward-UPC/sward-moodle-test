<?php
/**
 * SWARD — Agrega actividades calificables a cada seccion de curso.
 *
 * Por que hace falta. En SWARD el concepto del knowledge tracing es la seccion
 * del curso, y la secuencia de un estudiante son sus actividades calificables
 * dentro del curso. Con el contenido que genera populate_moodle.php cada alumno
 * produce apenas 7-8 interacciones por curso, y con secuencias tan cortas la
 * auto-atencion no tiene entre que elegir: medido con evaluation/
 * xai_faithfulness.py, la atencion no supera al azar.
 *
 * Alargar la secuencia DENTRO del curso es la correccion limpia: mantiene la
 * coherencia con como infiere el sistema en produccion (GenerarRecomendacion
 * recibe estudiante_id y curso_id, o sea una secuencia por estudiante-curso) y
 * evita mezclar cursos, que introduciria un atajo trivial para el modelo
 * (los conceptos son disjuntos entre cursos, asi que distinguirlos es gratis).
 *
 * Ademas da mas practica repetida por concepto, que es justo la señal que un
 * modelo de KT necesita para aprender una curva de aprendizaje.
 *
 * Uso:  php /tmp/add_gradeable_activities.php [--por-seccion=3]
 */

define('CLI_SCRIPT', true);

$roots = ['/var/www/html', '/bitnami/moodle', '/var/www/moodle', '/app'];
$root  = null;
foreach ($roots as $r) { if (file_exists("$r/config.php")) { $root = $r; break; } }
if (!$root) { fwrite(STDERR, "ERROR: no se encontro config.php\n"); exit(1); }

require("$root/config.php");
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/assign/lib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');

$admin = get_admin();
\core\session\manager::set_user($admin);

$porSeccion = 3;
foreach ($argv as $a) {
    if (str_starts_with($a, '--por-seccion=')) { $porSeccion = max(1, (int)substr($a, 14)); }
}

function base_mod(array $extra): object {
    $intro = $extra['intro'] ?? '';
    $m = (object) array_merge([
        'visible' => 1, 'visibleoncoursepage' => 1,
        'intro' => $intro, 'introformat' => FORMAT_HTML,
        'cmidnumber' => '', 'groupmode' => 0, 'groupingid' => 0,
        'availability' => null, 'completionview' => 0, 'completionexpected' => 0,
        'showdescription' => 0,
    ], $extra);
    $m->introeditor = ['text' => $m->intro, 'format' => FORMAT_HTML, 'itemid' => 0];
    return $m;
}

function existe(int $courseid, string $modname, string $name): bool {
    global $DB;
    $tabla = $DB->get_records($modname, ['course' => $courseid], '', 'id, name');
    foreach ($tabla as $r) { if ($r->name === $name) { return true; } }
    return false;
}

function nueva_tarea(int $courseid, int $section, string $name, string $intro): bool {
    if (existe($courseid, 'assign', $name)) { return false; }
    $m = base_mod([
        'modulename' => 'assign', 'course' => $courseid, 'section' => $section,
        'name' => $name, 'intro' => $intro,
        'alwaysshowdescription' => 1, 'nosubmissions' => 0, 'submissiondrafts' => 0,
        'sendnotifications' => 0, 'sendlatenotifications' => 0, 'sendstudentnotifications' => 0,
        'duedate' => time() + 14 * 86400, 'allowsubmissionsfromdate' => 0, 'cutoffdate' => 0,
        'gradingduedate' => 0, 'grade' => 20, 'teamsubmission' => 0,
        'requireallteammemberssubmit' => 0, 'teamsubmissiongroupingid' => 0,
        'blindmarking' => 0, 'hidegrader' => 0, 'attemptreopenmethod' => 'none',
        'maxattempts' => -1, 'markingworkflow' => 0, 'markingallocation' => 0,
        'requiresubmissionstatement' => 0,
    ]);
    create_module($m);
    return true;
}

function nuevo_quiz(int $courseid, int $section, string $name, string $intro): bool {
    if (existe($courseid, 'quiz', $name)) { return false; }
    $m = base_mod([
        'modulename' => 'quiz', 'course' => $courseid, 'section' => $section,
        'name' => $name, 'intro' => $intro,
        'timeopen' => 0, 'timeclose' => 0, 'timelimit' => 1800,
        'overduehandling' => 'autosubmit', 'graceperiod' => 0,
        'preferredbehaviour' => 'deferredfeedback', 'attempts' => 2, 'attemptonlast' => 0,
        'grademethod' => 1, 'decimalpoints' => 2, 'questiondecimalpoints' => -1,
        'shuffleanswers' => 1, 'grade' => 20, 'sumgrades' => 0,
        'questionsperpage' => 5, 'navmethod' => 'free', 'browsersecurity' => '-',
        // sin esto la columna password (NOT NULL) queda nula y el insert falla
        'quizpassword' => '', 'subnet' => '',
        'delay1' => 0, 'delay2' => 0, 'showuserpicture' => 0, 'showblocks' => 0,
        'completionattemptsexhausted' => 0, 'completionminattempts' => 0,
        'allowofflineattempts' => 0,
    ]);
    create_module($m);
    return true;
}

// Plantillas de nombre: alternan practica y evaluacion sobre el mismo concepto,
// que es lo que produce practica repetida dentro de la seccion.
$PLANTILLAS = [
    ['quiz',   'Autoevaluacion %d — %s',  'Control rapido de %s.'],
    ['assign', 'Ejercicio %d — %s',       'Ejercicio practico sobre %s.'],
    ['quiz',   'Repaso %d — %s',          'Repaso evaluado de %s.'],
    ['assign', 'Practica %d — %s',        'Practica guiada de %s.'],
    ['quiz',   'Control %d — %s',         'Evaluacion corta de %s.'],
];

$cursos = $DB->get_records_select('course', 'id > 1', null, 'id ASC', 'id, shortname');
$creadas = 0;

foreach ($cursos as $curso) {
    echo "Curso [{$curso->id}] {$curso->shortname}\n";
    $secciones = $DB->get_records_select(
        'course_sections', 'course = ? AND section > 0', [$curso->id], 'section ASC'
    );
    foreach ($secciones as $sec) {
        $tema = $sec->name;
        if (empty($tema)) { continue; }           // seccion sin nombre = sin concepto
        $n = 0;
        for ($i = 0; $i < $porSeccion; $i++) {
            [$tipo, $fmt, $introFmt] = $PLANTILLAS[$i % count($PLANTILLAS)];
            $nombre = sprintf($fmt, intdiv($i, count($PLANTILLAS)) + 1, $tema);
            $intro  = sprintf($introFmt, $tema);
            try {
                $ok = $tipo === 'quiz'
                    ? nuevo_quiz($curso->id, (int)$sec->section, $nombre, $intro)
                    : nueva_tarea($curso->id, (int)$sec->section, $nombre, $intro);
                if ($ok) { $n++; $creadas++; }
            } catch (Throwable $e) {
                try { $DB->force_transaction_rollback(); } catch (Throwable $ig) {}
                echo "    ✗ $nombre: " . $e->getMessage() . "\n";
            }
        }
        echo "  sec {$sec->section} ($tema): +$n\n";
    }
    rebuild_course_cache($curso->id, true);
}

echo "\nActividades calificables creadas: $creadas\n";
