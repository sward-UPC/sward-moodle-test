<?php
/**
 * SWARD — Nombra las secciones de los cursos.
 *
 * En SWARD el "concepto" del knowledge tracing es la seccion del curso:
 * export_moodle_to_pykt.py deriva concept = nombre de la seccion. Si las
 * secciones no tienen nombre (Moodle las deja en NULL y las muestra como
 * "Tema 1", "Tema 2"), el exportador las colapsa en un unico concepto y el
 * dataset queda inservible: con un solo concepto no hay nada que rastrear y
 * cualquier modelo de KT degenera en predecir la media.
 *
 * populate_moodle.php asume secciones con nombre (tiene get_section_number()
 * que las busca por nombre), pero ningun script committeado las nombra: los
 * create_*_course_content.py que el README menciona nunca se subieron.
 *
 * Este script las nombra a partir del contenido real de cada seccion, tomando
 * el primer recurso de lectura como titulo del tema. Es idempotente.
 *
 * Uso:  php /tmp/name_sections.php
 */

define('CLI_SCRIPT', true);

$roots = ['/var/www/html', '/bitnami/moodle', '/var/www/moodle', '/app'];
$root  = null;
foreach ($roots as $r) { if (file_exists("$r/config.php")) { $root = $r; break; } }
if (!$root) { fwrite(STDERR, "ERROR: no se encontro config.php\n"); exit(1); }

require("$root/config.php");
require_once($CFG->dirroot . '/course/lib.php');

$admin = get_admin();
\core\session\manager::set_user($admin);

/** Recorta un titulo largo a algo usable como nombre de concepto. */
function titulo_corto(string $s): string {
    // "Taller 3: ERS para el Sistema SWARD" -> "ERS para el Sistema SWARD"
    if (($pos = strpos($s, ':')) !== false && $pos < 24) {
        $s = trim(substr($s, $pos + 1));
    }
    $s = trim(preg_replace('/\s+/', ' ', $s));
    if (mb_strlen($s) > 48) {
        $s = mb_substr($s, 0, 45) . '...';
    }
    return $s;
}

// Preferencia de modulo para nombrar: primero material de lectura, luego el resto.
$PRIORIDAD = ['page' => 1, 'url' => 2, 'quiz' => 3, 'assign' => 4, 'forum' => 5];

$cursos = $DB->get_records_select('course', 'id > 1', null, 'id ASC', 'id, shortname');
$nombradas = 0;

foreach ($cursos as $curso) {
    echo "Curso [{$curso->id}] {$curso->shortname}\n";
    $modinfo  = get_fast_modinfo($curso->id);
    $porSeccion = [];
    foreach ($modinfo->get_cms() as $cm) {
        if (!$cm->uservisible && !$cm->visible) { continue; }
        $porSeccion[(int)$cm->sectionnum][] = $cm;
    }

    $secciones = $DB->get_records('course_sections', ['course' => $curso->id], 'section ASC');
    foreach ($secciones as $sec) {
        $n = (int)$sec->section;
        if ($n === 0) { continue; }                       // seccion general
        if (!empty($sec->name)) {                          // ya tiene nombre
            echo "  sec $n: (ya nombrada) {$sec->name}\n";
            continue;
        }
        $mods = $porSeccion[$n] ?? [];
        if (!$mods) { continue; }                          // seccion vacia

        usort($mods, fn($a, $b) =>
            ($PRIORIDAD[$a->modname] ?? 9) <=> ($PRIORIDAD[$b->modname] ?? 9));

        $nombre = titulo_corto($mods[0]->name);
        $DB->set_field('course_sections', 'name', $nombre, ['id' => $sec->id]);
        echo "  sec $n: $nombre\n";
        $nombradas++;
    }
    rebuild_course_cache($curso->id, true);
}

echo "\nSecciones nombradas: $nombradas\n";
