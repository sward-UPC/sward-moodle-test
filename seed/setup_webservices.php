<?php
/**
 * SWARD — Habilitar Web Services REST y generar el token, sin pasar por la UI.
 *
 * El README documenta este proceso como una secuencia manual de unos veinte
 * clics (Advanced features, Manage protocols, External services, 13 funciones,
 * Manage tokens). Este script hace lo mismo de forma idempotente y repetible,
 * que es lo que hace falta para reconstruir el entorno en AWS o tras un
 * `make clean`.
 *
 * Uso, dentro del contenedor de Moodle:
 *     php /tmp/setup_webservices.php
 *
 * Imprime el token al final. Ese valor va a MOODLE_TOKEN.
 */

define('CLI_SCRIPT', true);

$moodle_roots = ['/var/www/html', '/bitnami/moodle', '/var/www/moodle', '/app'];
$moodle_root  = null;
foreach ($moodle_roots as $r) {
    if (file_exists("$r/config.php")) { $moodle_root = $r; break; }
}
if (!$moodle_root) {
    fwrite(STDERR, "ERROR: no se encontro config.php de Moodle.\n");
    exit(1);
}

require("$moodle_root/config.php");
require_once($CFG->dirroot . '/lib/externallib.php');
require_once($CFG->dirroot . '/webservice/lib.php');

// Funciones que consume SWARD: las del microservicio de integracion mas las
// que necesitan los seeders de contenido y calificaciones.
$FUNCIONES = [
    // verificacion
    'core_webservice_get_site_info',
    // sincronizacion que hace ms-integracion-lms
    'core_course_get_contents',
    'core_course_get_courses',
    'core_enrol_get_enrolled_users',
    'core_enrol_get_users_courses',
    'core_user_get_users',
    'gradereport_user_get_grade_items',
    'core_completion_get_activities_completion_status',
    // creacion de contenido y datos de prueba
    'core_course_get_courses_by_field',
    'core_course_create_courses',
    'core_course_edit_section',
    'core_user_create_users',
    'core_user_get_users_by_field',
    'core_user_update_users',
    'enrol_manual_enrol_users',
    'mod_assign_save_grade',
    'core_completion_update_activity_completion_status_manually',
    // categorias y gestion de cursos que usan los seeders
    'core_course_create_categories',
    'core_course_get_categories',
    'core_course_delete_courses',
    'core_course_update_courses',
    'core_role_assign_roles',
    'core_role_unassign_roles',
    'core_user_delete_users',
    'core_group_create_groups',
    'core_group_add_group_members',
    'core_files_upload',
    'core_course_get_course_module',
    'mod_quiz_get_quizzes_by_courses',
    'mod_assign_get_assignments',
];

$SHORTNAME = 'sward_integracion_lms';

echo "== 1. Habilitando web services ==\n";
set_config('enablewebservices', 1);

echo "== 2. Habilitando el protocolo REST ==\n";
$protocolos = array_filter(explode(',', (string)get_config('core', 'webserviceprotocols')));
if (!in_array('rest', $protocolos, true)) {
    $protocolos[] = 'rest';
}
set_config('webserviceprotocols', implode(',', $protocolos));

echo "== 3. Servicio externo '$SHORTNAME' ==\n";
$servicio = $DB->get_record('external_services', ['shortname' => $SHORTNAME]);
if (!$servicio) {
    $registro = (object)[
        'name'             => 'SWARD Integracion LMS',
        'shortname'        => $SHORTNAME,
        'enabled'          => 1,
        'restrictedusers'  => 0,
        'downloadfiles'    => 1,
        'uploadfiles'      => 1,
        'timecreated'      => time(),
        'timemodified'     => time(),
    ];
    $registro->id = $DB->insert_record('external_services', $registro);
    $servicio = $registro;
    echo "   creado (id={$servicio->id})\n";
} else {
    $DB->set_field('external_services', 'enabled', 1, ['id' => $servicio->id]);
    echo "   ya existia (id={$servicio->id}), habilitado\n";
}

echo "== 4. Asignando funciones ==\n";
$nuevas = 0;
$faltantes = [];
foreach ($FUNCIONES as $fn) {
    if (!$DB->record_exists('external_functions', ['name' => $fn])) {
        $faltantes[] = $fn;
        continue;
    }
    $existe = $DB->record_exists('external_services_functions', [
        'externalserviceid' => $servicio->id,
        'functionname'      => $fn,
    ]);
    if (!$existe) {
        $DB->insert_record('external_services_functions', (object)[
            'externalserviceid' => $servicio->id,
            'functionname'      => $fn,
        ]);
        $nuevas++;
    }
}
$total = count($FUNCIONES) - count($faltantes);
echo "   $total funciones asignadas ($nuevas nuevas)\n";
if ($faltantes) {
    echo "   AVISO, no existen en esta version de Moodle: " . implode(', ', $faltantes) . "\n";
}

// Sin SMTP, el correo de bienvenida del curso hace fallar
// enrol_manual_enrol_users con "Message was not sent." y no se matricula nadie.
set_config('sendcoursewelcomemessage', 0, 'enrol_manual');

echo "== 5. Permisos del admin ==\n";
$admin = get_admin();
$contexto = context_system::instance();
// Capacidades necesarias para consumir el servicio por REST.
$rolid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
foreach (['webservice/rest:use', 'moodle/webservice:createtoken'] as $cap) {
    if ($rolid) {
        assign_capability($cap, CAP_ALLOW, $rolid, $contexto->id, true);
    }
}
echo "   ok (usuario: {$admin->username})\n";

echo "== 6. Token ==\n";
$token = $DB->get_record_sql(
    "SELECT * FROM {external_tokens}
      WHERE externalserviceid = :sid AND userid = :uid AND tokentype = :tt",
    ['sid' => $servicio->id, 'uid' => $admin->id, 'tt' => EXTERNAL_TOKEN_PERMANENT]
);

if ($token) {
    echo "   ya existia, se reutiliza\n";
    $valor = $token->token;
} else {
    if (class_exists('\core_external\util') && method_exists('\core_external\util', 'generate_token')) {
        $valor = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT, $servicio, $admin->id, $contexto
        );
    } else {
        $valor = external_generate_token(
            EXTERNAL_TOKEN_PERMANENT, $servicio, $admin->id, $contexto
        );
    }
    echo "   generado\n";
}

echo "\n";
echo "MOODLE_TOKEN=$valor\n";
echo "MOODLE_BASE_URL={$CFG->wwwroot}\n";
