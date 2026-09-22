<?php
/**
 * SWARD — Carga en Moodle los cursos de la validación del OE4.
 *
 * Lee el JSON que produce generar.py y crea, en cada curso: un foro de dudas y
 * una sección por tema (su nombre es el concepto del SAKT) con, en este orden:
 * resumen, video (embebido), ejemplo resuelto, práctica guiada (tarea sin nota)
 * y su solución (se abre al entregar la práctica), tres quizzes de un solo
 * intento calificados sobre 10 con sus preguntas importadas desde GIFT, y un
 * recurso externo para practicar más. Solo los quizzes generan datos para el
 * modelo: son lo único calificado.
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
require_once($CFG->libdir . '/resourcelib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/mod/forum/lib.php');

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

/** Enlace (mod_url): el video se embebe; el recurso externo se abre aparte. */
function crear_enlace(object $curso, int $seccion, array $e, int $display): string {
    if (modulo_existente($curso->id, 'url', $e['nombre'])) {
        return 'ya existía';
    }
    create_module(base_modulo($curso, $seccion, 'url', $e['nombre'], [
        'intro' => $e['intro'], 'externalurl' => $e['url'], 'display' => $display, 'printintro' => 1,
        'introeditor' => ['text' => $e['intro'], 'format' => FORMAT_HTML, 'itemid' => 0],
    ]));
    return 'creado';
}

/**
 * Práctica guiada: tarea sin nota con entrega en texto. Se entrega de una vez
 * (sin borrador) y se da por completada al entregar, que es lo que abre la
 * solución. Sin avisos por correo al profesor ni al estudiante.
 */
function crear_practica(object $curso, int $seccion, array $p): int {
    $existe = modulo_existente($curso->id, 'assign', $p['nombre']);
    if ($existe) {
        return (int) $existe->cmid;
    }
    $info = create_module(base_modulo($curso, $seccion, 'assign', $p['nombre'], [
        'intro' => '<p>' . $p['descripcion'] . '</p>',
        'introeditor' => ['text' => '<p>' . $p['descripcion'] . '</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
        'activityeditor' => ['text' => $p['enunciado'], 'format' => FORMAT_HTML, 'itemid' => 0],
        'alwaysshowdescription' => 1, 'submissiondrafts' => 0, 'requiresubmissionstatement' => 0,
        'sendnotifications' => 0, 'sendlatenotifications' => 0, 'sendstudentnotifications' => 0,
        'duedate' => 0, 'allowsubmissionsfromdate' => 0, 'cutoffdate' => 0, 'gradingduedate' => 0,
        'grade' => 0, 'teamsubmission' => 0, 'requireallteammemberssubmit' => 0,
        'teamsubmissiongroupingid' => 0, 'blindmarking' => 0, 'attemptreopenmethod' => 'untilpass',
        'maxattempts' => -1, 'markingworkflow' => 0, 'markingallocation' => 0, 'markinganonymous' => 0,
        'activityformat' => 0, 'timelimit' => 0, 'submissionattachments' => 0,
        'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_onlinetext_wordlimit' => 0,
        'assignsubmission_onlinetext_wordlimit_enabled' => 0,
        'assignsubmission_file_enabled' => 0, 'assignfeedback_comments_enabled' => 0,
        'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionsubmit' => 1,
    ]));
    return (int) $info->coursemodule;
}

/** La solución se muestra (atenuada) desde el inicio y se abre al entregar la práctica. */
function crear_solucion(object $curso, int $seccion, array $p, int $cmpractica): string {
    if (modulo_existente($curso->id, 'page', $p['solucion_nombre'])) {
        return 'ya existía';
    }
    $condicion = json_encode(['op' => '&', 'showc' => [true],
        'c' => [['type' => 'completion', 'cm' => $cmpractica, 'e' => COMPLETION_COMPLETE]]]);
    create_module(base_modulo($curso, $seccion, 'page', $p['solucion_nombre'], [
        'content' => $p['solucion'], 'contentformat' => FORMAT_HTML,
        'display' => 0, 'displayoptions' => serialize(['printintro' => 0, 'printlastmodified' => 0]),
        'printheading' => 1, 'printintro' => 0, 'printlastmodified' => 0, 'revision' => 1,
        'availability' => $condicion,
    ]));
    return 'creada';
}

/** Foro de dudas en la sección general. Suscripción opcional: sin avalancha de correos. */
function crear_foro(object $curso, array $f): string {
    if (modulo_existente($curso->id, 'forum', $f['nombre'])) {
        return 'ya existía';
    }
    create_module(base_modulo($curso, 0, 'forum', $f['nombre'], [
        'intro' => $f['intro'], 'introeditor' => ['text' => $f['intro'], 'format' => FORMAT_HTML, 'itemid' => 0],
        'type' => 'general', 'forcesubscribe' => FORUM_CHOOSESUBSCRIBE, 'assessed' => 0, 'scale' => 0,
        'grade_forum' => 0, 'trackingtype' => FORUM_TRACKING_OPTIONAL, 'maxbytes' => 0, 'maxattachments' => 1,
        'displaywordcount' => 0, 'lockdiscussionafter' => 0, 'blockperiod' => 0, 'blockafter' => 0,
        'warnafter' => 0,
    ]));
    return 'creado';
}

/**
 * Cómo se presenta una actividad en la página del curso: la línea que se ve
 * debajo del nombre (formato, duración, si es opcional) y si cuenta para el
 * progreso. Se aplica también a lo ya creado, así que es idempotente.
 *
 * $progreso: 'ver' (páginas: al abrirlas), 'nota' (quizzes: al recibir nota),
 * 'entrega' (práctica: al entregarla; abre la solución) o 'no' (opcional).
 */
function presentar(object $curso, string $modulo, string $nombre, string $descripcion, string $progreso): void {
    global $DB;
    $m = modulo_existente($curso->id, $modulo, $nombre);
    if (!$m) {
        return;
    }
    if ($modulo === 'assign') {
        // Versiones anteriores dejaban los ejercicios en la descripción.
        $a = $DB->get_record('assign', ['id' => $m->instancia], 'id, intro, activity', MUST_EXIST);
        if (trim((string) $a->activity) === '') {
            $DB->update_record('assign', (object) ['id' => $a->id, 'activity' => $a->intro, 'activityformat' => FORMAT_HTML]);
        }
    }
    $DB->update_record($modulo, (object) ['id' => $m->instancia, 'intro' => "<p>$descripcion</p>", 'introformat' => FORMAT_HTML]);
    $cm = ['id' => $m->cmid, 'showdescription' => 1, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
           'completionview' => 0, 'completiongradeitemnumber' => null, 'completionpassgrade' => 0];
    if ($progreso === 'ver') {
        $cm['completionview'] = 1;
    } else if ($progreso === 'nota') {
        $cm['completiongradeitemnumber'] = 0;
    } else if ($progreso === 'no') {
        $cm['completion'] = COMPLETION_TRACKING_NONE;
    }
    $DB->update_record('course_modules', (object) $cm);
}

/** Imagen de la tarjeta del curso en «Mis cursos», si aún no tiene. */
function portada(object $curso, ?string $png): string {
    if (!$png) {
        return 'sin imagen';
    }
    $contexto = context_course::instance($curso->id);
    $fs = get_file_storage();
    if (!$fs->is_area_empty($contexto->id, 'course', 'overviewfiles', 0)) {
        return 'ya tenía';
    }
    $fs->create_file_from_string(['contextid' => $contexto->id, 'component' => 'course', 'filearea' => 'overviewfiles',
        'itemid' => 0, 'filepath' => '/', 'filename' => $curso->shortname . '.png'], base64_decode($png));
    return 'agregada';
}

/** Deja los módulos de la sección en el orden dado (los que no figuran quedan al final). */
function ordenar_seccion(object $curso, int $n, array $nombres): void {
    global $DB;
    $modinfo = get_fast_modinfo($curso);
    $seccion = $DB->get_record('course_sections', ['course' => $curso->id, 'section' => $n], '*', MUST_EXIST);
    $pornombre = [];
    foreach ($modinfo->sections[$n] ?? [] as $cmid) {
        $pornombre[$modinfo->cms[$cmid]->name] = $cmid;
    }
    $antes = null;
    foreach (array_reverse($nombres) as $nombre) {
        if (!isset($pornombre[$nombre])) {
            continue;
        }
        $cm = $DB->get_record('course_modules', ['id' => $pornombre[$nombre]], '*', MUST_EXIST);
        moveto_module($cm, $seccion, $antes);
        $antes = $cm;
    }
    rebuild_course_cache($curso->id, true);
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
    // Presentación: resumen del curso (tarjeta), sección inicial con el recorrido
    // y la imagen de portada.
    $DB->set_field('course', 'summary', '<p>' . s($c['descripcion']) . '</p>', ['id' => $curso->id]);
    if (!empty($c['presentacion'])) {
        $cero = $DB->get_record('course_sections', ['course' => $curso->id, 'section' => 0], '*', MUST_EXIST);
        course_update_section($curso, $cero, ['name' => 'Presentación del curso',
            'summary' => $c['presentacion'], 'summaryformat' => FORMAT_HTML]);
    }
    echo "  Portada: " . portada($curso, $c['imagen_png'] ?? null) . "\n";
    if (!empty($c['foro'])) {
        echo "  Foro «{$c['foro']['nombre']}»: " . crear_foro($curso, $c['foro']) . "\n";
    }

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
        if (!empty($t['video'])) {
            echo "    video: " . crear_enlace($curso, $n, $t['video'], RESOURCELIB_DISPLAY_EMBED) . "\n";
        }
        if (!empty($t['practica'])) {
            $cmpractica = crear_practica($curso, $n, $t['practica']);
            echo "    práctica guiada (cm $cmpractica); solución: "
                . crear_solucion($curso, $n, $t['practica'], $cmpractica) . "\n";
        }
        foreach ($t['quizzes'] as $q) {
            $quiz = crear_quiz($curso, $n, $q);
            $nuevas = importar_y_asignar($curso, $quiz, $q);
            $total['quizzes'] += $nuevas > 0;
            $total['preguntas'] += $nuevas;
            $slots = $DB->count_records('quiz_slots', ['quizid' => $quiz->id]);
            echo "    quiz «{$q['nombre']}»: $slots preguntas" . ($nuevas ? " (importadas ahora)" : "") . "\n";
        }
        if (!empty($t['recurso'])) {
            echo "    recurso externo: " . crear_enlace($curso, $n, $t['recurso'], RESOURCELIB_DISPLAY_NEW) . "\n";
        }
        foreach ($t['paginas'] as $p) {
            presentar($curso, 'page', $p['nombre'], $p['descripcion'] ?? '', 'ver');
        }
        if (!empty($t['video'])) {
            presentar($curso, 'url', $t['video']['nombre'], $t['video']['descripcion'], 'no');
        }
        if (!empty($t['practica'])) {
            presentar($curso, 'assign', $t['practica']['nombre'], $t['practica']['descripcion'], 'entrega');
            presentar($curso, 'page', $t['practica']['solucion_nombre'], $t['practica']['solucion_descripcion'], 'no');
        }
        foreach ($t['quizzes'] as $q) {
            presentar($curso, 'quiz', $q['nombre'], $q['descripcion'], 'nota');
        }
        if (!empty($t['recurso'])) {
            presentar($curso, 'url', $t['recurso']['nombre'], $t['recurso']['descripcion'], 'no');
        }
        // Leer, ver, estudiar un caso, practicar, evaluarse; lo extra al final.
        $orden = [$t['paginas'][0]['nombre']];
        if (!empty($t['video'])) {
            $orden[] = $t['video']['nombre'];
        }
        $orden[] = $t['paginas'][1]['nombre'];
        if (!empty($t['practica'])) {
            $orden[] = $t['practica']['nombre'];
            $orden[] = $t['practica']['solucion_nombre'];
        }
        foreach ($t['quizzes'] as $q) {
            $orden[] = $q['nombre'];
        }
        if (!empty($t['recurso'])) {
            $orden[] = $t['recurso']['nombre'];
        }
        ordenar_seccion($curso, $n, $orden);
    }
    rebuild_course_cache($curso->id, true);
}

echo "\nNuevos: {$total['cursos']} cursos, {$total['paginas']} páginas, {$total['quizzes']} quizzes, {$total['preguntas']} preguntas.\n";
