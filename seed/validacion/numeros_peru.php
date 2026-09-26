<?php
/**
 * SWARD — Los números, como se escriben en Perú.
 *
 * El paquete de idioma `es` es el de España: separador decimal la coma y
 * separador de miles el punto. Con eso, un estudiante que escribe `15.5` en una
 * pregunta numérica recibe «escribe tu respuesta sin usar el separador de miles
 * (.)» y no puede responder — aunque el enunciado le pide justamente el punto,
 * porque en Perú el decimal es el punto y los miles van con coma (1,234.56).
 *
 * Se detectó el 26 de septiembre de 2026, probando el primer quiz. Sin esto, los
 * 30 participantes se habrían trabado en la primera pregunta de la fase 1.
 *
 * Se corrige con un paquete de idioma local, que es donde Moodle guarda las
 * personalizaciones de idioma: sobrevive a reinicios y a actualizaciones del
 * paquete `es`. Afecta también a cómo se muestran las notas, que pasan a verse
 * 10.8 en vez de 10,8: es lo que espera quien las lee aquí.
 *
 * **Correrlo después de cada despliegue nuevo**, como `cargar_cursos.php`.
 */
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');

$dir = ($CFG->langlocalroot ?? ($CFG->dataroot . '/lang')) . '/es_local';
if (!is_dir($dir)) {
    mkdir($dir, $CFG->directorypermissions ?? 0777, true);
}

$php = "<?php\n"
     . "// Personalizacion de SWARD: numeros al uso peruano (1,234.56).\n"
     . "// La genera seed/validacion/numeros_peru.php\n"
     . '$string[\'decsep\'] = \'.\';' . "\n"
     . '$string[\'thousandssep\'] = \',\';' . "\n";

$archivo = $dir . '/langconfig.php';
file_put_contents($archivo, $php);
@chmod($archivo, $CFG->filepermissions ?? 0666);
printf("escrito: %s\n", $archivo);

get_string_manager()->reset_caches();
purge_all_caches();

printf("\nComprobacion:\n");
printf("  decsep       : «%s»\n", get_string('decsep', 'langconfig'));
printf("  thousandssep : «%s»\n", get_string('thousandssep', 'langconfig'));
require_once($CFG->dirroot . '/question/type/numerical/questiontype.php');
$qt = new qtype_numerical();
foreach (['15.5', '15,5', '1234.56', '1,234.56', '15'] as $v) {
    $error = $qt->validate_form_field('', $v);
    printf("  «%-9s» -> %s\n", $v, $error === '' ? 'aceptado' : $error);
}
