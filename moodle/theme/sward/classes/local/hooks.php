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
