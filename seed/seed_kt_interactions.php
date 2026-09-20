<?php
/**
 * SWARD — Genera calificaciones e interacciones para alimentar el SAKT.
 *
 * El README del repositorio menciona este script, pero nunca llego a
 * commitearse: no aparece en ningun commit de la historia. Sin el, el Moodle de
 * pruebas queda con cursos y actividades pero sin historial de respuestas, que
 * es justo lo que el knowledge tracing necesita para aprender.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * IMPORTANTE — ESTOS DATOS SON SIMULADOS
 *
 * Las notas que escribe este script no provienen de estudiantes reales. Sirven
 * para dos cosas legitimas:
 *   - que el sistema sea demostrable de punta a punta, y
 *   - que el pipeline de entrenamiento e inferencia se pueda ejercitar.
 *
 * NO sirven como validacion empirica. Cualquier metrica calculada sobre estos
 * datos mide la simulacion, no el aprendizaje de personas, y debe reportarse
 * declarando explicitamente su origen. La evidencia real sale de las sesiones
 * con usuarios.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Modelo de simulacion (tipo BKT): cada estudiante tiene una habilidad latente
 * y cada concepto una dificultad; la probabilidad de acierto sube con la
 * practica dentro del mismo concepto (curva de aprendizaje) y lleva ruido. Eso
 * produce secuencias con señal temporal real, que es lo que distingue a un
 * modelo de knowledge tracing de un baseline por dificultad de concepto.
 *
 * Uso:
 *     php /tmp/seed_kt_interactions.php [--semilla=42] [--limpiar]
 */

define('CLI_SCRIPT', true);

$roots = ['/var/www/html', '/bitnami/moodle', '/var/www/moodle', '/app'];
$root  = null;
foreach ($roots as $r) { if (file_exists("$r/config.php")) { $root = $r; break; } }
if (!$root) { fwrite(STDERR, "ERROR: no se encontro config.php de Moodle.\n"); exit(1); }

require("$root/config.php");
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/course/lib.php');

$admin = get_admin();
\core\session\manager::set_user($admin);

// ── Parametros ──────────────────────────────────────────────────────────────
$semilla = 42;
$limpiar = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--semilla=')) { $semilla = (int)substr($arg, 10); }
    if ($arg === '--limpiar') { $limpiar = true; }
}
mt_srand($semilla);

/** Numero aleatorio uniforme en [0,1) con la semilla fijada. */
function u(): float { return mt_rand() / mt_getrandmax(); }

// Modulos que producen nota y que el exportador sabe leer.
$MODULOS_CALIFICABLES = ['assign', 'quiz'];

echo "=== Generacion de interacciones para knowledge tracing ===\n";
echo "Semilla: $semilla" . ($limpiar ? " | modo: limpiar\n" : "\n");
echo str_repeat('-', 62) . "\n";

$cursos = $DB->get_records_select('course', 'id > 1', null, 'id ASC', 'id, fullname, shortname');
if (!$cursos) { echo "No hay cursos.\n"; exit(0); }

$totalNotas = 0;
$totalAciertos = 0;
$totalEstudiantes = 0;

foreach ($cursos as $curso) {
    echo "\nCurso [{$curso->id}] {$curso->shortname} — {$curso->fullname}\n";

    $contexto = context_course::instance($curso->id);
    // Solo estudiantes: quien tenga la capacidad de "ser calificado".
    $estudiantes = get_enrolled_users($contexto, 'mod/assign:submit', 0, 'u.id, u.username', 'u.id ASC');
    if (!$estudiantes) { echo "  (sin estudiantes matriculados)\n"; continue; }

    $modinfo = get_fast_modinfo($curso->id);
    $actividades = [];
    foreach ($modinfo->get_cms() as $cm) {
        if (!in_array($cm->modname, $MODULOS_CALIFICABLES, true)) { continue; }
        // grademax se lee del grade_item que ya creo el modulo. No hay que
        // pasar $itemdetails a grade_update: si se le pasa, Moodle intenta
        // redefinir el item y devuelve GRADE_UPDATE_FAILED.
        $gi = $DB->get_record('grade_items', [
            'courseid'     => $curso->id,
            'itemtype'     => 'mod',
            'itemmodule'   => $cm->modname,
            'iteminstance' => $cm->instance,
            'itemnumber'   => 0,
        ]);
        if (!$gi) { continue; }
        $actividades[] = [
            'modname'  => $cm->modname,
            'instance' => $cm->instance,
            'seccion'  => (int)$cm->sectionnum,
            'nombre'   => $cm->name,
            'grademax' => (float)$gi->grademax ?: 20.0,
        ];
    }
    if (!$actividades) { echo "  (sin actividades calificables)\n"; continue; }

    // Orden temporal: por seccion, y dentro de la seccion por instancia.
    usort($actividades, fn($a, $b) => [$a['seccion'], $a['instance']] <=> [$b['seccion'], $b['instance']]);
    echo '  ' . count($actividades) . ' actividades calificables, '
       . count($estudiantes) . " estudiantes\n";

    // Dificultad por concepto (seccion), estable entre ejecuciones.
    $dificultad = [];
    foreach ($actividades as $a) {
        if (!isset($dificultad[$a['seccion']])) {
            $dificultad[$a['seccion']] = 0.15 + u() * 0.45;   // 0.15 .. 0.60
        }
    }

    foreach ($estudiantes as $est) {
        $totalEstudiantes++;
        $habilidad = 0.30 + u() * 0.55;      // 0.30 .. 0.85
        $practica  = [];                      // veces vistas por concepto

        foreach ($actividades as $i => $a) {
            $sec = $a['seccion'];
            $practica[$sec] = ($practica[$sec] ?? 0) + 1;

            // Curva de aprendizaje: la practica dentro del concepto ayuda,
            // con rendimientos decrecientes.
            $aprendizaje = 0.22 * (1 - exp(-0.6 * ($practica[$sec] - 1)));
            $p = $habilidad - $dificultad[$sec] + $aprendizaje + (u() - 0.5) * 0.18;
            $p = max(0.04, min(0.96, $p + 0.35));   // recentrar y acotar

            $acierto  = u() < $p;
            $grademax = $a['grademax'];
            // Nota coherente con el acierto: aprobado >= 50% (criterio del exportador).
            $nota = $acierto
                ? $grademax * (0.55 + u() * 0.45)
                : $grademax * (0.10 + u() * 0.38);

            $res = grade_update(
                'mod/' . $a['modname'],
                $curso->id,
                'mod',
                $a['modname'],
                $a['instance'],
                0,
                (object)[
                    'userid'   => $est->id,
                    'rawgrade' => $limpiar ? null : round($nota, 2),
                ]
            );

            if ($res === GRADE_UPDATE_OK) {
                $totalNotas++;
                if ($acierto) { $totalAciertos++; }
            }
        }
    }
    echo "  ok\n";
}

echo "\n" . str_repeat('=', 62) . "\n";
if ($limpiar) {
    echo "Notas eliminadas: $totalNotas\n";
} else {
    $pct = $totalNotas ? round(100 * $totalAciertos / $totalNotas) : 0;
    echo "Interacciones generadas: $totalNotas\n";
    echo "Estudiantes con historial: $totalEstudiantes\n";
    echo "Tasa de acierto: $pct%\n";
    echo "\nRecordatorio: son datos SIMULADOS. Declararlo en cualquier reporte.\n";
}
