<?php
/**
 * SWARD — Los recursos externos se abren aparte, no encima del curso.
 *
 * Los «Para practicar más» estaban creados con RESOURCELIB_DISPLAY_NEW, que
 * parece lo correcto pero **no es una opción válida de mod_url**: el ajuste del
 * sitio `url_displayoptions` admite 0,1,5,6 (automático, incrustado, abrir,
 * ventana emergente) y el 3 no está. Moodle no protesta: cae al modo automático
 * y abre GeoGebra en la misma pestaña, encima del curso. Como GeoGebra no tiene
 * botón de volver, el estudiante queda atrapado y tiene que rehacer el camino.
 *
 * Se pasan a POPUP, que es la opción admitida que sí abre una ventana aparte.
 *
 * Encontrado el 26 de septiembre de 2026 probando el curso.
 */
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/resourcelib.php');

$opciones = serialize(['printintro' => 1, 'popupwidth' => 1200, 'popupheight' => 800]);
$n = 0;
foreach ($DB->get_records('url', null, 'id') as $u) {
    if ((int) $u->display !== RESOURCELIB_DISPLAY_NEW) {
        continue;
    }
    $u->display = RESOURCELIB_DISPLAY_POPUP;
    $u->displayoptions = $opciones;
    $u->timemodified = time();
    $DB->update_record('url', $u);
    $n++;
    printf("  %s\n", $u->name);
}
printf("\nactualizados: %d\n", $n);
purge_all_caches();

printf("\nComprobacion:\n");
foreach ($DB->get_records('url', null, 'id') as $u) {
    if (str_starts_with($u->name, 'Para practicar')) {
        printf("  %-46s display=%d %s\n", mb_substr($u->name, 0, 45), $u->display,
               $u->display == RESOURCELIB_DISPLAY_POPUP ? '(ventana aparte)' : '(REVISAR)');
    }
}
