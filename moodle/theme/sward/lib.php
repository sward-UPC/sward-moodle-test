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

/**
 * Funciones del tema SWARD.
 *
 * @package    theme_sward
 * @copyright  2026 Proyecto SWARD (UPC)
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/** Color principal de la aplicación SWARD (sward-frontend, --primary). */
const THEME_SWARD_PRIMARIO = '#4F46E5';

/**
 * SCSS principal: el preset por defecto de Boost.
 *
 * @param theme_config $theme
 * @return string
 */
function theme_sward_get_main_scss_content($theme) {
    global $CFG;
    return file_get_contents($CFG->dirroot . '/theme/boost/scss/preset/default.scss');
}

/**
 * Variables que se anteponen al SCSS: el color principal de SWARD.
 *
 * @param theme_config $theme
 * @return string
 */
function theme_sward_get_pre_scss($theme) {
    return '$primary: ' . THEME_SWARD_PRIMARIO . ";\n";
}
