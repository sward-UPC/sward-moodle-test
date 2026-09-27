<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace theme_sward\local;

/**
 * Callbacks del tema SWARD.
 *
 * @package    theme_sward
 * @copyright  2026 Proyecto SWARD (UPC)
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hooks {
    /**
     * Explica, arriba del formulario de acceso, con qué se entra.
     *
     * Moodle sólo ofrece un sitio para este texto —«instrucciones de la página de
     * ingreso»— y lo pinta **debajo** del formulario, bajo un título que habla de
     * registrarse aunque el registro propio esté apagado. Ahí el participante lo
     * lee tarde o lo entiende al revés: cree que tiene que crear otra cuenta.
     *
     * Va justo bajo el encabezado, que es donde se mira primero.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook
     */
    public static function con_que_se_entra(
        \core\hook\output\before_standard_head_html_generation $hook,
    ): void {
        global $PAGE;
        if ($PAGE->pagetype !== 'login-index') {
            return;
        }
        $texto = get_string('sward_con_que_se_entra', 'theme_sward');
        $json = json_encode($texto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hook->add_html(<<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
    var titulo = document.querySelector('.loginform .login-heading');
    if (!titulo || document.querySelector('.sward-con-que-se-entra')) { return; }
    var p = document.createElement('p');
    p.className = 'sward-con-que-se-entra text-muted mb-4';
    p.textContent = {$json};
    titulo.insertAdjacentElement('afterend', p);
});
</script>
HTML);
    }

    /**
     * Pliega los temas que el estudiante ya terminó.
     *
     * La página del curso los muestra todos abiertos, así que después de un par
     * de temas hay que desplazarse por material ya visto para llegar al que toca.
     * Aquí se pliegan los que tienen **todas** sus actividades con seguimiento
     * completadas; el primero que quede a medias sigue abierto, que es donde el
     * estudiante tiene que continuar.
     *
     * La finalización se calcula en el servidor, con `completion_info`, y no
     * leyendo el HTML: lo único que se toca del maquetado es el identificador
     * `coursecontentcollapseid{seccion}`, que es el que usa Moodle para plegar.
     *
     * Si el estudiante abre a mano un tema plegado, se respeta mientras dure la
     * pestaña: sin eso, recargar volvería a cerrárselo en la cara.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook
     */
    public static function plegar_temas_terminados(
        \core\hook\output\before_standard_head_html_generation $hook,
    ): void {
        global $COURSE, $PAGE, $USER;

        if ($PAGE->pagetype !== 'course-view-topics' && $PAGE->pagetype !== 'course-view-weeks') {
            return;
        }
        if (empty($COURSE->id) || (int) $COURSE->id === SITEID || empty($COURSE->enablecompletion)) {
            return;
        }
        if (!isloggedin() || isguestuser()) {
            return;
        }

        $completion = new \completion_info($COURSE);
        if (!$completion->is_enabled()) {
            return;
        }

        $modinfo = get_fast_modinfo($COURSE, $USER->id);
        $terminadas = [];
        foreach ($modinfo->get_section_info_all() as $seccion) {
            if ($seccion->section == 0) {
                continue;
            }
            $conSeguimiento = 0;
            $completas = 0;
            foreach ($modinfo->sections[$seccion->section] ?? [] as $cmid) {
                $cm = $modinfo->cms[$cmid];
                if (!$cm->uservisible || !$completion->is_enabled($cm)) {
                    continue;
                }
                $conSeguimiento++;
                $datos = $completion->get_data($cm, true, $USER->id, $modinfo);
                if (in_array((int) $datos->completionstate,
                             [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
                    $completas++;
                }
            }
            // Un tema sin nada que completar no se pliega: no hay nada terminado.
            if ($conSeguimiento > 0 && $conSeguimiento === $completas) {
                $terminadas[] = (int) $seccion->id;
            }
        }

        if (!$terminadas) {
            return;
        }

        $ids = json_encode($terminadas);
        $curso = (int) $COURSE->id;
        $hook->add_html(<<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
    var abiertos = [];
    try {
        abiertos = JSON.parse(sessionStorage.getItem('sward_temas_abiertos_{$curso}') || '[]');
    } catch (e) { abiertos = []; }
    {$ids}.forEach(function (id) {
        if (abiertos.indexOf(id) !== -1) { return; }
        var caja = document.getElementById('coursecontentcollapseid' + id);
        if (!caja || !caja.classList.contains('show')) { return; }
        caja.classList.remove('show');
        var boton = document.querySelector('[href="#coursecontentcollapseid' + id + '"]');
        if (boton) {
            boton.setAttribute('aria-expanded', 'false');
            boton.classList.add('collapsed');
            boton.addEventListener('click', function () {
                abiertos.push(id);
                try {
                    sessionStorage.setItem('sward_temas_abiertos_{$curso}', JSON.stringify(abiertos));
                } catch (e) { /* sin sessionStorage se pliega en cada carga, y ya */ }
            }, { once: true });
        }
    });
});
</script>
HTML);
    }

    /**
     * Escribe el nombre del curso en la cabecera del índice lateral, que Moodle
     * deja vacía: dentro de una actividad no quedaba a la vista en qué curso se
     * está. La plantilla de esa cabecera no recibe datos, así que el nombre va
     * como contenido de un ::before.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook
     */
    public static function nombre_del_curso_en_el_indice(
        \core\hook\output\before_standard_head_html_generation $hook,
    ): void {
        global $COURSE;
        if (empty($COURSE->id) || (int) $COURSE->id === SITEID) {
            return;
        }
        $nombre = format_string($COURSE->fullname, true, ['context' => \context_course::instance($COURSE->id)]);
        $nombre = html_entity_decode($nombre, ENT_QUOTES, 'UTF-8');
        // El texto va dentro de <style>: sin < ni > no puede cerrar la etiqueta.
        $nombre = str_replace(['<', '>'], '', $nombre);
        // json_encode entrega el texto ya entrecomillado y con las comillas y
        // contrabarras escapadas, que es también lo que espera una cadena CSS.
        $cadena = json_encode($nombre, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hook->add_html('<style>#theme_boost-drawers-courseindex .drawerheader::before{content:'
            . $cadena . ';}</style>');
    }
}
