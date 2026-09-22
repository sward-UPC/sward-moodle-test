<?php
/**
 * SWARD — Ensayo de la fase 1 con estudiantes SIMULADOS.
 *
 * Crea estudiantes ficticios (usuario sim.fase1.NN, nombre «Simulado»), los
 * matricula en los cursos de la validación y rinde por ellos los 36 quizzes a lo
 * largo de cuatro días, con fechas realistas. Sirve para probar de punta a punta
 * la sincronización con SWARD y el reentrenamiento antes de que lleguen los
 * estudiantes reales. **Los datos son simulados y no son evidencia de nada.**
 *
 * Cómo acierta cada simulado: la probabilidad depende de su habilidad, de la
 * dificultad del tema (los últimos son más difíciles), del nivel del quiz y de
 * cómo le fue en el tema anterior (los temas se apoyan unos en otros). Es lo
 * mínimo para que el modelo tenga algo que aprender.
 *
 *   docker exec sward-moodle-app php /tmp/simular_fase1.php          # crea y rinde
 *   docker exec sward-moodle-app php /tmp/simular_fase1.php --borrar # borra todo
 *
 * BORRAR ANTES DE QUE EMPIECE LA FASE 1 REAL: los intentos simulados se
 * mezclarían con los reales en el entrenamiento.
 */

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/testing/generator/component_generator_base.php');
require_once($CFG->libdir . '/testing/generator/module_generator.php');
require_once($CFG->libdir . '/testing/generator/data_generator.php');

\core\session\manager::set_user(get_admin());

const CURSOS = ['SWARD-EST', 'SWARD-MF'];
const PREFIJO = 'sim.fase1.';
const N = 30;
mt_srand(20260926);

function simulados(): array {
    global $DB;
    return $DB->get_records_select('user', 'username LIKE ? AND deleted = 0', [PREFIJO . '%']);
}

/**
 * Intentos de los simulados en los cursos de la validación, también los de
 * simulados ya borrados (Moodle los renombra a correo.fecha, que conserva el
 * prefijo) y los que una versión anterior de este script dejó a nombre del admin.
 * delete_user() no borra los intentos, y un quiz con intentos ya no deja cambiar
 * sus preguntas.
 */
function intentos_simulados(): array {
    global $DB;
    [$en, $params] = $DB->get_in_or_equal(CURSOS);
    return $DB->get_records_sql(
        "SELECT qa.* FROM {quiz_attempts} qa JOIN {quiz} q ON q.id = qa.quiz JOIN {course} c ON c.id = q.course
           JOIN {user} u ON u.id = qa.userid
          WHERE c.shortname $en AND (u.username LIKE ? OR u.id = ?)",
        array_merge($params, [PREFIJO . '%', get_admin()->id]));
}

if (in_array('--borrar', $argv, true)) {
    $a = 0;
    foreach (intentos_simulados() as $intento) {
        quiz_delete_attempt($intento, $DB->get_record('quiz', ['id' => $intento->quiz]));
        $a++;
    }
    $n = 0;
    foreach (simulados() as $u) {
        delete_user($u);
        $n++;
    }
    // Notas vacías que el borrado de intentos deja a los simulados ya borrados.
    foreach ($DB->get_fieldset_select('user', 'id', 'username LIKE ? AND deleted = 1', [PREFIJO . '%']) as $id) {
        grade_user_delete($id);
    }
    echo "Borrados $n estudiantes simulados y $a intentos.\n";
    exit(0);
}

if (simulados()) {
    fwrite(STDERR, "Ya hay estudiantes simulados. Bórralos primero con --borrar.\n");
    exit(1);
}

function normal(): float {  // Box-Muller
    $u = max(mt_rand() / mt_getrandmax(), 1e-9);
    $v = mt_rand() / mt_getrandmax();
    return sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
}

function sigmoide(float $x): float {
    return 1 / (1 + exp(-$x));
}

function aleatorio(): float {
    return mt_rand() / mt_getrandmax();
}

$generador = new testing_data_generator();
$gquiz = $generador->get_plugin_generator('mod_quiz');
$plugin = enrol_get_plugin('manual');
$rolestudiante = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

// Cursos, secciones en orden y quizzes por sección.
$plan = [];
foreach (CURSOS as $corto) {
    $curso = $DB->get_record('course', ['shortname' => $corto], '*', MUST_EXIST);
    $instancia = $DB->get_record('enrol', ['courseid' => $curso->id, 'enrol' => 'manual'], '*', MUST_EXIST);
    $modinfo = get_fast_modinfo($curso);
    $temas = [];
    foreach ($modinfo->get_section_info_all() as $sec) {
        if ($sec->section == 0) {
            continue;
        }
        $quizzes = [];
        foreach ($modinfo->sections[$sec->section] ?? [] as $cmid) {
            $cm = $modinfo->cms[$cmid];
            if ($cm->modname === 'quiz') {
                $quizzes[] = $cm->instance;
            }
        }
        $temas[] = ['nombre' => $sec->name, 'quizzes' => $quizzes];
    }
    $plan[] = ['curso' => $curso, 'enrol' => $instancia, 'temas' => $temas];
}

$dificultad = [-0.8, -0.4, 0.0, 0.3, 0.5, 0.7];
$inicio = time() - 4 * 86400;
$intentos = 0;
$aciertos = 0;
$preguntas = 0;

for ($s = 1; $s <= N; $s++) {
    $num = str_pad((string) $s, 2, '0', STR_PAD_LEFT);
    $usuario = (object) [
        'username' => PREFIJO . $num, 'firstname' => 'Simulado', 'lastname' => "Fase1 $num",
        'email' => PREFIJO . $num . '@sward-prueba.com', 'auth' => 'manual', 'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id, 'password' => 'Simulado2026!',
    ];
    $usuario->id = user_create_user($usuario, true, false);
    $habilidad = normal();
    $aprende = 0.2 + 0.6 * aleatorio();
    $reloj = $inicio + mt_rand(0, 6 * 3600);

    foreach ($plan as $c => $p) {
        \core\session\manager::set_user(get_admin());
        $plugin->enrol_user($p['enrol'], $usuario->id, $rolestudiante);
        // El intento es de quien tiene la sesión: el simulado rinde con la suya.
        \core\session\manager::set_user($DB->get_record('user', ['id' => $usuario->id], '*', MUST_EXIST));
        $reloj = $inicio + $c * 2 * 86400 + mt_rand(8, 20) * 3600;  // un curso cada dos días
        $previo = 0.5;
        foreach ($p['temas'] as $t => $tema) {
            $notas = [];
            foreach ($tema['quizzes'] as $k => $quizid) {
                if (aleatorio() < 0.08) {
                    continue;  // quiz que el estudiante no rindió
                }
                $logit = $habilidad - $dificultad[$t] - 0.25 * $k + 0.6 * $k * $aprende + 1.2 * ($previo - 0.5);
                $p_acierto = sigmoide($logit);
                $intento = $gquiz->create_attempt($quizid, $usuario->id);
                if ((int) $intento->userid !== (int) $usuario->id) {
                    throw new coding_exception("el intento quedó a nombre de {$intento->userid}, no del simulado");
                }
                $objeto = \mod_quiz\quiz_attempt::create($intento->id);
                $respuestas = [];
                $correctas = 0;
                foreach ($objeto->get_slots() as $slot) {
                    $pregunta = $objeto->get_question_attempt($slot)->get_question();
                    $acierta = aleatorio() < $p_acierto;
                    $correctas += $acierta;
                    if ($pregunta->get_type_name() === 'numerical') {
                        $ok = (float) reset($pregunta->answers)->answer;
                        $respuestas[$slot] = ['answer' => (string) ($acierta ? $ok : round($ok * 1.37 + 3, 2))];
                    } else {
                        $opciones = array_values($pregunta->answers);
                        $buenas = array_filter($opciones, fn($a) => (float) $a->fraction > 0.99);
                        $malas = array_filter($opciones, fn($a) => (float) $a->fraction < 0.01);
                        $elegida = $acierta ? reset($buenas) : $malas[array_rand($malas)];
                        $respuestas[$slot] = ['answer' => clean_param($elegida->answer, PARAM_NOTAGS)];
                    }
                }
                $reloj += mt_rand(6, 25) * 60;
                // Mismos datos que enviaría el formulario del quiz (sin la ayuda de
                // pruebas unitarias, que Moodle solo permite dentro de PHPUnit).
                $post = ['slots' => implode(',', array_keys($respuestas))];
                foreach ($respuestas as $slot => $respuesta) {
                    $qa = $objeto->get_question_attempt($slot);
                    $post[$qa->get_control_field_name('sequencecheck')] = $qa->get_sequence_check_count();
                    foreach ($qa->get_question()->prepare_simulated_post_data($respuesta) as $campo => $valor) {
                        $post[$qa->get_qt_field_name($campo)] = $valor;
                    }
                }
                $objeto->process_submitted_actions($reloj, false, $post);
                $objeto->process_finish($reloj, false);
                $intentos++;
                $preguntas += count($respuestas);
                $aciertos += $correctas;
                $notas[] = $correctas / max(1, count($respuestas));
            }
            $previo = $notas ? array_sum($notas) / count($notas) : 0.5;
            $reloj += mt_rand(20, 90) * 60;
        }
    }
    echo "  " . PREFIJO . "$num listo\n";
}

printf("\n%d estudiantes simulados, %d intentos, %.0f %% de aciertos.\n", N, $intentos, 100 * $aciertos / max(1, $preguntas));
echo "Recuerda: php simular_fase1.php --borrar antes de la fase 1 real.\n";
