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
 * Tema SWARD: Boost con la navegación reducida a lo que usa un participante.
 *
 * Un participante del estudio solo necesita sus cursos. La portada del sitio
 * (Home) y el tablero (Dashboard) duplicaban «Mis cursos» y confundían: al
 * probarlo, un estudiante entró por Home y creyó estar en otra cuenta. Se
 * quitan de la barra principal con `removedprimarynavitems`, el mecanismo que
 * Moodle ofrece a los temas, sin tocar el núcleo. El color principal es el de
 * la aplicación SWARD, para que ambas se reconozcan como un mismo sistema.
 *
 * @package    theme_sward
 * @copyright  2026 Proyecto SWARD (UPC)
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/lib.php');

$THEME->name = 'sward';
$THEME->parents = ['boost'];
$THEME->sheets = [];
$THEME->editor_sheets = [];
$THEME->editor_scss = ['editor'];
$THEME->usefallback = true;
$THEME->scss = function($theme) {
    return theme_sward_get_main_scss_content($theme);
};
$THEME->prescsscallback = 'theme_sward_get_pre_scss';
$THEME->extrascsscallback = 'theme_sward_get_extra_scss';
$THEME->enable_dock = false;
$THEME->yuicssmodules = [];
$THEME->rendererfactory = 'theme_overridden_renderer_factory';
$THEME->requiredblocks = '';
$THEME->addblockposition = BLOCK_ADDBLOCK_POSITION_FLATNAV;
$THEME->iconsystem = \core\output\icon_system::FONTAWESOME;
$THEME->haseditswitch = true;
$THEME->usescourseindex = true;
$THEME->activityheaderconfig = ['notitle' => true];
// Home (portada) y Dashboard (tablero) fuera de la barra: queda «Mis cursos».
$THEME->removedprimarynavitems = ['home', 'myhome'];
