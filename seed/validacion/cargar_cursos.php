<?php
/**
 * SWARD — Carga en Moodle los cursos de la validación del OE4.
 *
 * Lee el JSON que produce generar.py y crea, en cada curso: una sección por
 * tema (su nombre es el concepto del SAKT), dos páginas por tema (resumen y
 * ejemplo resuelto) y tres quizzes de un solo intento, calificados sobre 10,
 * con sus preguntas importadas desde GIFT.
 *
 * Es idempotente: lo que ya existe no se duplica, así que se puede volver a
 * correr si algo falló a mitad. Nunca borra. Los cursos no fuerzan idioma:
 * siguen el del sitio.
 *
 *   docker cp seed/validacion/salida/cursos.json sward-moodle-app:/tmp/cursos.json
 *   docker cp seed/validacion/cargar_cursos.php sward-moodle-app:/tmp/cargar_cursos.php
 *   docker exec sward-moodle-app php /tmp/cargar_cursos.php /tmp/cursos.json
 */

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/gift/format.php');

\core\session\manager::set_user(get_admin());

if (empty($argv[1]) || !is_readable($argv[1])) {
    fwrite(STDERR, "Uso: php cargar_cursos.php /ruta/cursos.json\n");
    exit(1);
}
$cursos = json_decode(file_get_contents($argv[1]), true);
if (!$cursos) {
    fwrite(STDERR, "El JSON está vacío o no se pudo leer.\n");
    exit(1);
}

function base_modulo(object $curso, int $seccion, string $modulo, string $nombre, array $extra): object {
    return (object) array_merge([
        'modulename' => $modulo, 'course' => $curso->id, 'section' => $seccion, 'name' => $nombre,
        'visible' => 1, 'visibleoncoursepage' => 1, 'intro' => '', 'introformat' => FORMAT_HTML,
        'introeditor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0],
        'cmidnumber' => '', 'groupmode' => 0, 'groupingid' => 0, 'availability' => null,
        'completion' => 0, 'completionview' => 0, 'completionexpected' => 0, 'showdescription' => 0,
    ], $extra);
}

function modulo_existente(int $cursoid, string $modulo, string $nombre): ?object {
    global $DB;
    $fila = $DB->get_record_sql(
        "SELECT cm.id AS cmid, m.id AS instancia
           FROM {course_modules} cm
           JOIN {modules} md ON md.id = cm.module AND md.name = :modulo
           JOIN {{$modulo}} m ON m.id = cm.instance
          WHERE cm.course = :curso AND m.name = :nombre AND cm.deletioninprogress = 0",
        ['modulo' => $modulo, 'curso' => $cursoid, 'nombre' => $nombre], IGNORE_MULTIPLE);
    return $fila ?: null;
}

function crear_pagina(object $curso, int $seccion, array $p): string {
    if (modulo_existente($curso->id, 'page', $p['nombre'])) {
        return 'ya existía';
    }
    create_module(base_modulo($curso, $seccion, 'page', $p['nombre'], [
        'content' => $p['contenido'], 'contentformat' => FORMAT_HTML,
        'display' => 0, 'displayoptions' => serialize(['printintro' => 0, 'printlastmodified' => 0]),
        'printheading' => 1, 'printintro' => 0, 'printlastmodified' => 0, 'revision' => 1,
    ]));
    return 'creada';
}

function crear_quiz(object $curso, int $seccion, array $q): object {
    global $DB;
    $existe = modulo_existente($curso->id, 'quiz', $q['nombre']);
    if ($existe) {
        return $DB->get_record('quiz', ['id' => $existe->instancia], '*', MUST_EXIST);
    }
    // Revisión tras el intento: nota y si cada respuesta fue correcta, sin mostrar
    // la respuesta correcta (los compañeros aún no rinden el mismo quiz).
    $revision = [];
    foreach (['attempt', 'correctness', 'marks', 'specificfeedback', 'generalfeedback', 'rightanswer',
              'overallfeedback'] as $campo) {
        foreach (['during', 'immediately', 'open', 'closed'] as $cuando) {
            $mostrar = in_array($campo, ['attempt', 'correctness', 'marks'], true) && $cuando !== 'during';
            $revision[$campo . $cuando] = $mostrar ? 1 : 0;
        }
    }
    $m = base_modulo($curso, $seccion, 'quiz', $q['nombre'], array_merge([
        'intro' => '<p>Nivel ' . s($q['nivel']) . '. Un solo intento. Resuélvelo por tu cuenta: no cuenta para tu nota.</p>',
        'timeopen' => 0, 'timeclose' => 0, 'timelimit' => 0,
        'overduehandling' => 'autosubmit', 'graceperiod' => 0,
        'preferredbehaviour' => 'deferredfeedback', 'canredoquestions' => 0,
        'attempts' => 1, 'attemptonlast' => 0, 'grademethod' => QUIZ_GRADEHIGHEST,
        'decimalpoints' => 2, 'questiondecimalpoints' => -1, 'grade' => 10, 'sumgrades' => 0,
        'questionsperpage' => 0, 'navmethod' => 'free', 'shuffleanswers' => 1,
        'browsersecurity' => '-', 'quizpassword' => '', 'subnet' => '',
        'delay1' => 0, 'delay2' => 0, 'showuserpicture' => 0, 'showblocks' => 0,
        'completionattemptsexhausted' => 0, 'completionminattempts' => 0, 'allowofflineattempts' => 0,
    ], $revision));
    $m->introeditor = ['text' => $m->intro, 'format' => FORMAT_HTML, 'itemid' => 0];
    $info = create_module($m);
    return $DB->get_record('quiz', ['id' => $info->instance], '*', MUST_EXIST);
}

function categoria_preguntas(object $curso, string $nombre): object {
    global $DB;
    $contexto = context_course::instance($curso->id);
    $padre = question_get_default_category($contexto->id) ?: question_make_default_categories([$contexto]);
    $cat = $DB->get_record('question_categories', ['contextid' => $contexto->id, 'name' => $nombre]);
    if ($cat) {
        return $cat;
    }
    $cat = (object) ['name' => $nombre, 'contextid' => $contexto->id, 'info' => '', 'infoformat' => FORMAT_HTML,
                     'parent' => $padre->id, 'sortorder' => 999, 'stamp' => make_unique_id_code()];
    $cat->id = $DB->insert_record('question_categories', $cat);
    return $cat;
}

function importar_y_asignar(object $curso, object $quiz, array $q): int {
    global $DB;
    if ($DB->count_records('quiz_slots', ['quizid' => $quiz->id]) > 0) {
        return 0;  // ya tiene sus preguntas
    }
    $cat = categoria_preguntas($curso, $q['nombre']);
    $archivo = tempnam(sys_get_temp_dir(), 'gift');
    file_put_contents($archivo, $q['gift']);

    $formato = new qformat_gift();
    $formato->setCategory($cat);
    $formato->setContexts([context_course::instance($curso->id)]);
    $formato->setCourse($curso);
    $formato->setFilename($archivo);
    $formato->setRealfilename('preguntas.gift');
    $formato->setMatchgrades('error');
    $formato->setCatfromfile(false);
    $formato->setContextfromfile(false);
    $formato->setStoponerror(true);
    ob_start();
    $ok = $formato->importpreprocess() && $formato->importprocess() && $formato->importpostprocess();
    $salida = ob_get_clean();
    unlink($archivo);
    if (!$ok || empty($formato->questionids)) {
        throw new moodle_exception('generalexceptionmessage', 'error', '', "no se importaron las preguntas de «{$q['nombre']}»: " . strip_tags($salida));
    }
    foreach ($formato->questionids as $qid) {
        quiz_add_quiz_question($qid, $quiz, 0, 1);
    }
    $ajustes = \mod_quiz\quiz_settings::create($quiz->id);
    \mod_quiz\grade_calculator::create($ajustes)->recompute_quiz_sumgrades();
    return count($formato->questionids);
}

$total = ['cursos' => 0, 'paginas' => 0, 'quizzes' => 0, 'preguntas' => 0];
foreach ($cursos as $c) {
    $curso = $DB->get_record('course', ['shortname' => $c['corto']]);
    if (!$curso) {
        $curso = create_course((object) [
            'fullname' => $c['nombre'], 'shortname' => $c['corto'], 'category' => 1,
            'summary' => '<p>' . s($c['descripcion']) . '</p>', 'summaryformat' => FORMAT_HTML,
            'format' => 'topics', 'numsections' => count($c['temas']), 'visible' => 1,
            'enablecompletion' => 1, 'showgrades' => 1,
        ]);
        $total['cursos']++;
        echo "Curso creado: {$c['nombre']} (id {$curso->id})\n";
    } else {
        echo "Curso existente: {$c['nombre']} (id {$curso->id})\n";
        // Versiones anteriores forzaban 'es'; sin ese paquete, el índice del curso
        // no carga. El curso sigue el idioma del sitio.
        if ($curso->lang !== '') {
            $DB->set_field('course', 'lang', '', ['id' => $curso->id]);
            echo "  idioma forzado ({$curso->lang}) quitado: sigue el del sitio\n";
        }
    }
    course_create_sections_if_missing($curso, range(0, count($c['temas'])));

    foreach ($c['temas'] as $i => $t) {
        $n = $i + 1;
        $seccion = $DB->get_record('course_sections', ['course' => $curso->id, 'section' => $n], '*', MUST_EXIST);
        if ($seccion->name !== $t['tema']) {
            course_update_section($curso, $seccion, ['name' => $t['tema']]);
        }
        echo "  Sección $n: {$t['tema']}\n";
        foreach ($t['paginas'] as $p) {
            $estado = crear_pagina($curso, $n, $p);
            $total['paginas'] += $estado === 'creada';
            echo "    página «{$p['nombre']}»: $estado\n";
        }
        foreach ($t['quizzes'] as $q) {
            $quiz = crear_quiz($curso, $n, $q);
            $nuevas = importar_y_asignar($curso, $quiz, $q);
            $total['quizzes'] += $nuevas > 0;
            $total['preguntas'] += $nuevas;
            $slots = $DB->count_records('quiz_slots', ['quizid' => $quiz->id]);
            echo "    quiz «{$q['nombre']}»: $slots preguntas" . ($nuevas ? " (importadas ahora)" : "") . "\n";
        }
    }
    rebuild_course_cache($curso->id, true);
}

echo "\nNuevos: {$total['cursos']} cursos, {$total['paginas']} páginas, {$total['quizzes']} quizzes, {$total['preguntas']} preguntas.\n";
