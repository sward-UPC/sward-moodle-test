<?php
/**
 * SWARD — Poblar Moodle con contenido académico real
 * Ciclo 1 — Ingeniería de Software
 *
 * Uso: php /tmp/populate_moodle.php
 */

define('CLI_SCRIPT', true);

// Auto-detectar raíz de Moodle
$moodle_roots = ['/var/www/html', '/bitnami/moodle', '/var/www/moodle', '/app'];
$moodle_root  = null;
foreach ($moodle_roots as $r) {
    if (file_exists("$r/config.php")) { $moodle_root = $r; break; }
}
if (!$moodle_root) {
    echo "ERROR: No se encontró config.php de Moodle.\n";
    exit(1);
}

require("$moodle_root/config.php");
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/lib/modinfolib.php');

// CLI necesita usuario admin activo para crear módulos
$admin = get_admin();
\core\session\manager::set_user($admin);

echo "\n╔══════════════════════════════════════════════╗\n";
echo "║  SWARD — Carga de contenido académico       ║\n";
echo "╚══════════════════════════════════════════════╝\n\n";
echo "Usuario activo: " . $admin->username . "\n\n";

// ── Helpers ────────────────────────────────────────

/**
 * Retorna el número de sección dentro de un curso por su nombre.
 */
function get_section_number($courseid, $name) {
    global $DB;
    $rec = $DB->get_record_sql(
        "SELECT section FROM {course_sections} WHERE course=? AND name=?",
        [$courseid, $name]
    );
    return $rec ? (int)$rec->section : null;
}

/**
 * Retorna todos los números de sección de un curso ordenados.
 */
function get_sections($courseid) {
    global $DB;
    return $DB->get_records('course_sections', ['course' => $courseid], 'section ASC', 'id,section,name');
}

/**
 * Crea un módulo si no existe ya (idempotente por nombre + curso).
 */
function mod_exists($courseid, $modname, $name) {
    global $DB;
    return $DB->record_exists_sql(
        "SELECT 1 FROM {modules} m
           JOIN {course_modules} cm ON cm.module=m.id
           JOIN {{$modname}} mm ON mm.id=cm.instance
          WHERE cm.course=? AND m.name=? AND mm.name=?",
        [$courseid, $modname, $name]
    );
}

function make_mod($base) {
    $intro = $base['intro'] ?? '';
    $m = (object) array_merge([
        'visible'             => 1,
        'visibleoncoursepage' => 1,
        'intro'               => $intro,
        'introformat'         => FORMAT_HTML,
        'introeditor'         => ['text' => $intro, 'format' => FORMAT_HTML, 'itemid' => 0],
        'cmidnumber'          => '',
        'groupmode'           => 0,
        'groupingid'          => 0,
        'availability'        => null,
        'completionview'      => 0,
        'completionexpected'  => 0,
        'showdescription'     => 0,
    ], (array)$base);
    // Sync introeditor with intro in case base overrides it
    $m->introeditor = ['text' => $m->intro, 'format' => FORMAT_HTML, 'itemid' => 0];
    return $m;
}

function safe_create($m, $label) {
    global $DB;
    try {
        create_module($m);
        echo "    ✓ $label\n";
    } catch (\moodle_exception $e) {
        // Limpiar transacción colgada
        try { $DB->force_transaction_rollback(); } catch (\Exception $ignored) {}
        echo "    ✗ ERROR [$label]: code={$e->errorcode} attr={$e->a} msg={$e->getMessage()}\n";
    } catch (\Exception $e) {
        try { $DB->force_transaction_rollback(); } catch (\Exception $ignored) {}
        echo "    ✗ ERROR [$label]: " . $e->getMessage() . "\n";
    }
}

function add_page($courseid, $section, $name, $content, $video_id = '', $video_title = '') {
    global $CFG;
    require_once($CFG->dirroot . '/mod/page/lib.php');
    if (mod_exists($courseid, 'page', $name)) {
        echo "    ⟳ Ya existe: $name\n"; return;
    }
    if ($video_id) {
        $content = yt($video_id, $video_title) . $content;
    }
    $m = make_mod([
        'modulename'     => 'page',
        'course'         => $courseid,
        'section'        => $section,
        'name'           => $name,
        'intro'          => '',
        'introformat'    => FORMAT_HTML,
        'content'        => $content,
        'contentformat'  => FORMAT_HTML,
        'display'        => 0,
        'displayoptions' => serialize([]),
        'printheading'   => 1,
        'printhastoc'    => 0,
        'legacyfiles'    => 0,
        'revision'       => 1,
        'timemodified'   => time(),
    ]);
    safe_create($m, "Página: $name");
}

function add_url($courseid, $section, $name, $url, $intro = '') {
    global $CFG;
    require_once($CFG->dirroot . '/mod/url/lib.php');
    if (mod_exists($courseid, 'url', $name)) {
        echo "    ⟳ Ya existe URL: $name\n"; return;
    }
    $m = make_mod([
        'modulename'     => 'url',
        'course'         => $courseid,
        'section'        => $section,
        'name'           => $name,
        'intro'          => $intro,
        'introformat'    => FORMAT_HTML,
        'externalurl'    => $url,
        'display'        => 0,
        'displayoptions' => serialize([]),
        'timemodified'   => time(),
    ]);
    safe_create($m, "URL: $name");
}

function add_assign($courseid, $section, $name, $intro, $weeks_due = 2) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/assign/lib.php');
    if (mod_exists($courseid, 'assign', $name)) {
        echo "    ⟳ Ya existe tarea: $name\n"; return;
    }
    $m = make_mod([
        'modulename'                          => 'assign',
        'course'                              => $courseid,
        'section'                             => $section,
        'name'                                => $name,
        'intro'                               => $intro,
        'introformat'                         => FORMAT_HTML,
        'alwaysshowdescription'               => 1,
        'nosubmissions'                       => 0,
        'submissiondrafts'                    => 0,
        'sendnotifications'                   => 0,
        'sendlatenotifications'               => 0,
        'sendstudentnotifications'            => 1,
        'duedate'                             => time() + ($weeks_due * 7 * 24 * 3600),
        'cutoffdate'                          => 0,
        'gradingduedate'                      => 0,
        'allowsubmissionsfromdate'            => 0,
        'grade'                               => 20,
        'timemodified'                        => time(),
        'requiresubmissionstatement'          => 0,
        'completionsubmit'                    => 0,
        'teamsubmission'                      => 0,
        'requireallteammemberssubmit'         => 0,
        'teamsubmissiongroupingid'            => 0,
        'blindmarking'                        => 0,
        'hidegrader'                          => 0,
        'revealidentities'                    => 0,
        'attemptreopenmethod'                 => 'none',
        'maxattempts'                         => -1,
        'markingworkflow'                     => 0,
        'markingallocation'                   => 0,
        'assignsubmission_onlinetext_enabled' => 1,
        'assignsubmission_onlinetext_wordlimit' => 0,
        'assignsubmission_onlinetext_wordlimitenabled' => 0,
        'assignsubmission_file_enabled'       => 1,
        'assignsubmission_file_maxfiles'      => 5,
        'assignsubmission_file_maxsizebytes'  => 10 * 1024 * 1024,
        'assignfeedback_comments_enabled'     => 1,
        'assignfeedback_comments_commentinline' => 0,
        'assignfeedback_editpdf_enabled'      => 0,
        'assignfeedback_file_enabled'         => 0,
    ]);
    safe_create($m, "Tarea: $name");
}

function add_forum($courseid, $section, $name, $intro) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/forum/lib.php');
    if (mod_exists($courseid, 'forum', $name)) {
        echo "    ⟳ Ya existe foro: $name\n"; return;
    }
    $m = make_mod([
        'modulename'      => 'forum',
        'course'          => $courseid,
        'section'         => $section,
        'name'            => $name,
        'intro'           => $intro,
        'introformat'     => FORMAT_HTML,
        'type'            => 'general',
        'forcesubscribe'  => 0,
        'assessed'        => 0,
        'scale'           => 0,
        'maxbytes'        => 0,
        'maxattachments'  => 9,
        'timemodified'    => time(),
    ]);
    safe_create($m, "Foro: $name");
}

function add_quiz($courseid, $section, $name, $intro, $weeks_due = 3) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/quiz/lib.php');
    if (mod_exists($courseid, 'quiz', $name)) {
        echo "    ⟳ Ya existe quiz: $name\n"; return;
    }
    $m = make_mod([
        'modulename'              => 'quiz',
        'course'                  => $courseid,
        'section'                 => $section,
        'name'                    => $name,
        'intro'                   => $intro,
        'introformat'             => FORMAT_HTML,
        'timeopen'                => 0,
        'timeclose'               => time() + ($weeks_due * 7 * 24 * 3600),
        'timelimit'               => 3600,
        'overduehandling'         => 'autosubmit',
        'graceperiod'             => 0,
        'preferredbehaviour'      => 'deferredfeedback',
        'attempts'                => 2,
        'attemptonlast'           => 0,
        'grademethod'             => 1,
        'decimalpoints'           => 2,
        'questiondecimalpoints'   => -1,
        'shuffleanswers'          => 1,
        'grade'                   => 20,
        'sumgrades'               => 0,
        'questionsperpage'        => 5,
        'navmethod'               => 'free',
        'browsersecurity'         => '-',
        'delay1'                  => 0,
        'delay2'                  => 0,
        'showuserpicture'         => 0,
        'showblocks'              => 0,
        'completionattemptsexhausted' => 0,
        'completionminattempts'   => 0,
        'allowofflineattempts'    => 0,
        'timecreated'             => time(),
        'timemodified'            => time(),
    ]);
    safe_create($m, "Quiz: $name");
}

function yt($id, $title = '') {
    return '<div style="position:relative;padding-bottom:56.25%;height:0;margin:16px 0;border-radius:10px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.15);">'
         . '<iframe src="https://www.youtube.com/embed/' . $id . '?rel=0" '
         . 'style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;" '
         . 'allowfullscreen loading="lazy" title="' . htmlspecialchars($title) . '"></iframe>'
         . '</div>'
         . ($title ? '<p style="text-align:center;color:#666;font-size:.9em;">▶ ' . htmlspecialchars($title) . '</p>' : '');
}

// ── Contenido HTML ──────────────────────────────────

$aed_s1_page = <<<'HTML'
<h2>Introducción a los Algoritmos</h2>
<h3>¿Qué es un Algoritmo?</h3>
<p>Un <strong>algoritmo</strong> es una secuencia finita, bien definida y efectiva de instrucciones para resolver un problema. Características esenciales:</p>
<ul>
  <li><strong>Entrada:</strong> cero o más valores de entrada</li>
  <li><strong>Salida:</strong> al menos un resultado producido</li>
  <li><strong>Definitud:</strong> cada paso es preciso y sin ambigüedad</li>
  <li><strong>Finitud:</strong> termina en un número finito de pasos</li>
  <li><strong>Efectividad:</strong> cada paso es ejecutable en tiempo finito</li>
</ul>
<h3>Ejemplo — Algoritmo de Euclides (MCD)</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">
Algoritmo MCD(a, b):
  MIENTRAS b ≠ 0 HACER
    temp ← b
    b    ← a mod b
    a    ← temp
  FIN MIENTRAS
  RETORNAR a

Traza para a=48, b=18:
  Paso 1: temp=18, b=48 mod 18=12, a=18
  Paso 2: temp=12, b=18 mod 12=6,  a=12
  Paso 3: temp=6,  b=12 mod 6=0,   a=6
  → MCD(48,18) = 6  ✓
</pre>
<h3>Análisis de Algoritmos</h3>
<p>Evalúa los recursos consumidos: <strong>tiempo</strong> (operaciones elementales) y <strong>espacio</strong> (memoria). Distinguimos tres casos:</p>
<table border="1" cellpadding="8" style="border-collapse:collapse;width:100%;">
  <tr style="background:#e8f4f8;"><th>Caso</th><th>Descripción</th><th>Ejemplo (Búsqueda Lineal)</th></tr>
  <tr><td><strong>Mejor caso</strong></td><td>Mínimo de operaciones</td><td>El elemento está en la primera posición → O(1)</td></tr>
  <tr><td><strong>Caso promedio</strong></td><td>Promedio sobre todas las entradas</td><td>Elemento en posición media → O(n/2)</td></tr>
  <tr><td><strong>Peor caso</strong></td><td>Máximo de operaciones (el más importante)</td><td>Elemento no está → O(n)</td></tr>
</table>
<h3>Pseudocódigo vs Código Real</h3>
<p>El pseudocódigo es independiente del lenguaje y se usa para expresar la lógica antes de implementar:</p>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">
// Pseudocódigo
BúsquedaLineal(A[0..n-1], objetivo):
  PARA i = 0 HASTA n-1 HACER
    SI A[i] = objetivo ENTONCES
      RETORNAR i
  RETORNAR -1

// Python
def busqueda_lineal(arr, objetivo):
    for i, val in enumerate(arr):
        if val == objetivo:
            return i
    return -1
</pre>
<h3>Recursos de la Semana</h3>
<ul>
  <li>📖 Cormen et al., <em>Introduction to Algorithms</em> — Capítulos 1 y 2</li>
  <li>🔗 VisuAlgo: visualizador interactivo de algoritmos</li>
  <li>🎥 MIT 6.006 Lecture 1: Algorithmic Thinking</li>
</ul>
HTML;

$aed_s2_page = <<<'HTML'
<h2>Complejidad Computacional — Notación Big O</h2>
<h3>¿Por qué medir complejidad?</h3>
<p>Para n=1,000,000 elementos, la diferencia entre O(n) y O(n²) es la diferencia entre <em>1 segundo</em> y <em>11 días</em> de ejecución.</p>
<h3>Clases de Complejidad</h3>
<table border="1" cellpadding="8" style="border-collapse:collapse;width:100%;">
  <tr style="background:#e8f4f8;"><th>Notación</th><th>Nombre</th><th>Ejemplo</th><th>n=1,000</th><th>n=1,000,000</th></tr>
  <tr><td>O(1)</td><td>Constante</td><td>Acceso a array por índice</td><td>1</td><td>1</td></tr>
  <tr><td>O(log n)</td><td>Logarítmica</td><td>Búsqueda binaria</td><td>10</td><td>20</td></tr>
  <tr><td>O(n)</td><td>Lineal</td><td>Búsqueda lineal</td><td>1,000</td><td>1,000,000</td></tr>
  <tr><td>O(n log n)</td><td>Lineal-log</td><td>Merge Sort</td><td>10,000</td><td>20,000,000</td></tr>
  <tr><td>O(n²)</td><td>Cuadrática</td><td>Bubble Sort</td><td>1,000,000</td><td>10¹²</td></tr>
  <tr><td>O(2ⁿ)</td><td>Exponencial</td><td>Fibonacci recursivo</td><td>∞</td><td>∞</td></tr>
</table>
<h3>Reglas de Simplificación</h3>
<ol>
  <li><strong>Descartar constantes:</strong> O(3n) = O(n)</li>
  <li><strong>Descartar términos menores:</strong> O(n² + n) = O(n²)</li>
  <li><strong>Bucles anidados:</strong> dos bucles de n = O(n²)</li>
  <li><strong>Código secuencial:</strong> O(f) + O(g) = O(max(f,g))</li>
</ol>
<h3>Análisis Ejemplo</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">
def busqueda_binaria(arr, objetivo):    # arr ordenado
    izq, der = 0, len(arr) - 1          # O(1)
    while izq <= der:                    # O(log n) iteraciones
        mid = (izq + der) // 2           # O(1)
        if arr[mid] == objetivo:
            return mid                   # O(1)
        elif arr[mid] < objetivo:
            izq = mid + 1               # O(1)
        else:
            der = mid - 1               # O(1)
    return -1
# Complejidad total: O(log n) tiempo, O(1) espacio
</pre>
HTML;

$aed_s3_page = <<<'HTML'
<h2>Estructuras de Datos Lineales</h2>
<h3>Arrays vs Listas Enlazadas</h3>
<table border="1" cellpadding="8" style="border-collapse:collapse;width:100%;">
  <tr style="background:#e8f4f8;"><th>Operación</th><th>Array</th><th>Lista Simple</th><th>Lista Doble</th></tr>
  <tr><td>Acceso por índice</td><td>O(1) ✅</td><td>O(n) ❌</td><td>O(n) ❌</td></tr>
  <tr><td>Búsqueda</td><td>O(n)</td><td>O(n)</td><td>O(n)</td></tr>
  <tr><td>Insertar al inicio</td><td>O(n)</td><td>O(1) ✅</td><td>O(1) ✅</td></tr>
  <tr><td>Insertar al final</td><td>O(1) amort.</td><td>O(n) / O(1) con tail</td><td>O(1) ✅</td></tr>
  <tr><td>Eliminar al inicio</td><td>O(n)</td><td>O(1) ✅</td><td>O(1) ✅</td></tr>
  <tr><td>Memoria extra</td><td>O(1)</td><td>O(n) punteros</td><td>O(2n) punteros</td></tr>
</table>
<h3>Pila (Stack) — LIFO</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">
class Pila:
    def __init__(self): self._datos = []
    def push(self, x):  self._datos.append(x)    # O(1)
    def pop(self):      return self._datos.pop()  # O(1)
    def peek(self):     return self._datos[-1]    # O(1)
    def vacia(self):    return len(self._datos)==0

# Aplicación: verificar paréntesis balanceados
def balanceados(s):
    pila, pares = Pila(), {')':'(', ']':'[', '}':'{'}
    for c in s:
        if c in '([{': pila.push(c)
        elif c in ')]}':
            if pila.vacia() or pila.pop() != pares[c]: return False
    return pila.vacia()
</pre>
<h3>Cola (Queue) — FIFO</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">
from collections import deque

class Cola:
    def __init__(self):   self._datos = deque()
    def enqueue(self, x): self._datos.append(x)       # O(1)
    def dequeue(self):    return self._datos.popleft() # O(1)
    def frente(self):     return self._datos[0]        # O(1)
    def vacia(self):      return len(self._datos)==0
</pre>
<h3>Aplicaciones Reales</h3>
<ul>
  <li><strong>Pila:</strong> Call stack del CPU, deshacer/rehacer, navegación back/forward, DFS</li>
  <li><strong>Cola:</strong> Cola de impresión, BFS en grafos, scheduling de procesos del SO</li>
  <li><strong>Deque:</strong> Caché LRU, algoritmo de ventana deslizante</li>
</ul>
HTML;

$aed_s4_page = <<<'HTML'
<h2>Árboles Binarios y BST</h2>
<h3>Árbol Binario de Búsqueda (BST)</h3>
<p>Para cada nodo N: todos los valores en el subárbol izquierdo son <strong>&lt; N</strong>, y todos los del derecho son <strong>&gt; N</strong>.</p>
<h3>Implementación</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">
class Nodo:
    def __init__(self, val): self.val=val; self.izq=self.der=None

class BST:
    def __init__(self): self.raiz = None

    def insertar(self, val):
        self.raiz = self._ins(self.raiz, val)

    def _ins(self, nodo, val):
        if not nodo: return Nodo(val)
        if val < nodo.val: nodo.izq = self._ins(nodo.izq, val)
        elif val > nodo.val: nodo.der = self._ins(nodo.der, val)
        return nodo

    def inorden(self, nodo=None, res=None):  # LNR → ascendente
        if res is None: nodo, res = self.raiz, []
        if nodo:
            self.inorden(nodo.izq, res)
            res.append(nodo.val)
            self.inorden(nodo.der, res)
        return res
</pre>
<h3>Complejidades BST</h3>
<table border="1" cellpadding="8" style="border-collapse:collapse;width:100%;">
  <tr style="background:#e8f4f8;"><th>Operación</th><th>Caso Promedio</th><th>Peor Caso</th><th>Cuándo ocurre el peor caso</th></tr>
  <tr><td>Búsqueda</td><td>O(log n)</td><td>O(n)</td><td>Árbol degenerado (lista enlazada)</td></tr>
  <tr><td>Inserción</td><td>O(log n)</td><td>O(n)</td><td>Insertar elementos ya ordenados</td></tr>
  <tr><td>Eliminación</td><td>O(log n)</td><td>O(n)</td><td>Igual que inserción</td></tr>
</table>
<h3>Los 4 Recorridos</h3>
<ul>
  <li><strong>Inorden (LNR):</strong> visita en orden ascendente — útil para ordenar</li>
  <li><strong>Preorden (NLR):</strong> visita raíz primero — útil para copiar el árbol</li>
  <li><strong>Postorden (LRN):</strong> visita raíz al final — útil para eliminar el árbol</li>
  <li><strong>Por niveles (BFS):</strong> nivel por nivel usando una cola</li>
</ul>
<h3>Árbol AVL — Balance Garantizado</h3>
<p>BST auto-balanceado. <strong>Factor de balance</strong> = altura(izq) − altura(der) ∈ {−1, 0, 1}. Garantiza O(log n) siempre, incluso en el peor caso. Usa 4 tipos de rotaciones para mantener el balance tras cada inserción o eliminación.</p>
HTML;

$aed_s5_page = <<<'HTML'
<h2>Algoritmos de Ordenamiento Comparados</h2>
<table border="1" cellpadding="8" style="border-collapse:collapse;width:100%;">
  <tr style="background:#e8f4f8;"><th>Algoritmo</th><th>Mejor</th><th>Promedio</th><th>Peor</th><th>Espacio</th><th>Estable</th></tr>
  <tr><td>Bubble Sort</td><td>O(n)</td><td>O(n²)</td><td>O(n²)</td><td>O(1)</td><td>✅</td></tr>
  <tr><td>Insertion Sort</td><td>O(n)</td><td>O(n²)</td><td>O(n²)</td><td>O(1)</td><td>✅</td></tr>
  <tr><td>Selection Sort</td><td>O(n²)</td><td>O(n²)</td><td>O(n²)</td><td>O(1)</td><td>❌</td></tr>
  <tr><td>Merge Sort</td><td>O(n log n)</td><td>O(n log n)</td><td>O(n log n)</td><td>O(n)</td><td>✅</td></tr>
  <tr><td>Quick Sort</td><td>O(n log n)</td><td>O(n log n)</td><td>O(n²)</td><td>O(log n)</td><td>❌</td></tr>
  <tr><td>Heap Sort</td><td>O(n log n)</td><td>O(n log n)</td><td>O(n log n)</td><td>O(1)</td><td>❌</td></tr>
</table>
<h3>Merge Sort — Implementación</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">
def merge_sort(arr):
    if len(arr) <= 1: return arr
    mid = len(arr) // 2
    izq = merge_sort(arr[:mid])
    der = merge_sort(arr[mid:])
    return merge(izq, der)

def merge(izq, der):
    res, i, j = [], 0, 0
    while i < len(izq) and j < len(der):
        if izq[i] <= der[j]: res.append(izq[i]); i+=1
        else:                 res.append(der[j]); j+=1
    return res + izq[i:] + der[j:]
</pre>
<h3>¿Cuándo usar cada uno?</h3>
<ul>
  <li><strong>Insertion Sort:</strong> arreglos pequeños (n &lt; 20) o casi ordenados</li>
  <li><strong>Merge Sort:</strong> cuando necesitas estabilidad o trabajas con listas enlazadas</li>
  <li><strong>Quick Sort:</strong> caso general (más rápido en la práctica)</li>
  <li><strong>Heap Sort:</strong> cuando necesitas O(n log n) garantizado con O(1) espacio</li>
</ul>
HTML;

$bd_s1_page = <<<'HTML'
<h2>Fundamentos de Bases de Datos</h2>
<h3>¿Qué es una Base de Datos?</h3>
<p>Una <strong>base de datos</strong> es una colección organizada de datos estructurados, almacenada y accedida electrónicamente. Un <strong>SGBD</strong> (Sistema Gestor de Bases de Datos) gestiona: almacenamiento, recuperación, concurrencia, seguridad y recuperación ante fallos.</p>
<h3>Propiedades ACID</h3>
<table border="1" cellpadding="8" style="border-collapse:collapse;width:100%;">
  <tr style="background:#e8f4f8;"><th>Propiedad</th><th>Descripción</th><th>Ejemplo</th></tr>
  <tr><td><strong>Atomicidad</strong></td><td>La transacción ocurre completa o no ocurre</td><td>Transferencia bancaria: débito Y crédito, o ninguno</td></tr>
  <tr><td><strong>Consistencia</strong></td><td>De estado válido a estado válido</td><td>Saldo nunca puede ser negativo si hay restricción</td></tr>
  <tr><td><strong>Aislamiento</strong></td><td>Transacciones no se interfieren entre sí</td><td>Dos usuarios comprando el último item en stock</td></tr>
  <tr><td><strong>Durabilidad</strong></td><td>Cambios confirmados persisten ante fallos</td><td>Datos sobreviven un corte eléctrico</td></tr>
</table>
<h3>Tipos de SGBD</h3>
<table border="1" cellpadding="8" style="border-collapse:collapse;width:100%;">
  <tr style="background:#e8f4f8;"><th>Tipo</th><th>Ejemplo</th><th>Casos de uso</th></tr>
  <tr><td>Relacional (SQL)</td><td>PostgreSQL, MySQL, Oracle</td><td>ERP, e-commerce, sistemas transaccionales</td></tr>
  <tr><td>Documental (NoSQL)</td><td>MongoDB, CouchDB</td><td>CMS, catálogos, datos semi-estructurados</td></tr>
  <tr><td>Clave-Valor</td><td>Redis, DynamoDB</td><td>Caché, sesiones, rankings en tiempo real</td></tr>
  <tr><td>Grafos</td><td>Neo4j, ArangoDB</td><td>Redes sociales, recomendaciones, rutas</td></tr>
  <tr><td>Columnar</td><td>Cassandra, HBase</td><td>Big Data, series temporales, analytics</td></tr>
</table>
HTML;

$bd_s3_page = <<<'HTML'
<h2>SQL Básico — DDL y DML</h2>
<h3>DDL — Data Definition Language</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">
-- Crear tablas con restricciones
CREATE TABLE estudiante (
    id_est    SERIAL         PRIMARY KEY,
    dni       CHAR(8)        NOT NULL UNIQUE,
    nombre    VARCHAR(50)    NOT NULL,
    apellido  VARCHAR(50)    NOT NULL,
    email     VARCHAR(100)   NOT NULL UNIQUE,
    fecha_nac DATE,
    activo    BOOLEAN        DEFAULT TRUE,
    creado_en TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_email CHECK (email LIKE '%@%')
);

CREATE TABLE curso (
    id_cur    SERIAL         PRIMARY KEY,
    codigo    VARCHAR(10)    NOT NULL UNIQUE,
    nombre    VARCHAR(100)   NOT NULL,
    creditos  SMALLINT       NOT NULL CHECK (creditos BETWEEN 1 AND 6),
    id_doc    INT            REFERENCES docente(id_doc) ON DELETE SET NULL
);

CREATE TABLE matricula (
    id_est    INT  REFERENCES estudiante(id_est) ON DELETE CASCADE,
    id_cur    INT  REFERENCES curso(id_cur)      ON DELETE RESTRICT,
    fecha     DATE DEFAULT CURRENT_DATE,
    nota      NUMERIC(4,2)   CHECK (nota BETWEEN 0 AND 20),
    PRIMARY KEY (id_est, id_cur)
);
</pre>
<h3>DML — Data Manipulation Language</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">
-- INSERT múltiple
INSERT INTO estudiante (dni, nombre, apellido, email, fecha_nac) VALUES
    ('12345678', 'Ana',   'Torres',  'ana@uni.edu',  '2003-05-14'),
    ('87654321', 'Luis',  'Mendoza', 'luis@uni.edu', '2002-11-30'),
    ('11223344', 'María', 'García',  'maria@uni.edu','2003-08-22');

-- SELECT con filtros y orden
SELECT nombre, apellido, email
FROM   estudiante
WHERE  activo = TRUE AND fecha_nac > '2000-01-01'
ORDER  BY apellido ASC, nombre ASC
LIMIT  10 OFFSET 0;

-- UPDATE con subconsulta
UPDATE matricula SET nota = 16.5
WHERE id_est = 1 AND id_cur = 2;

-- DELETE seguro
DELETE FROM estudiante WHERE id_est = 5 AND activo = FALSE;
</pre>
<h3>JOINs más usados</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">
-- INNER JOIN: solo coincidencias
SELECT e.nombre, e.apellido, c.nombre AS curso, m.nota
FROM   estudiante e
JOIN   matricula  m ON e.id_est = m.id_est
JOIN   curso      c ON m.id_cur = c.id_cur
ORDER  BY m.nota DESC;

-- LEFT JOIN: todos los estudiantes, hayan o no matriculado
SELECT e.nombre, COUNT(m.id_cur) AS cursos_matriculados
FROM   estudiante e
LEFT   JOIN matricula m ON e.id_est = m.id_est
GROUP  BY e.id_est, e.nombre
HAVING COUNT(m.id_cur) >= 2
ORDER  BY cursos_matriculados DESC;
</pre>
HTML;

$is_s1_page = <<<'HTML'
<h2>Introducción a la Ingeniería de Software</h2>
<h3>¿Qué es la Ingeniería de Software?</h3>
<p>La <strong>Ingeniería de Software</strong> es la aplicación de principios de ingeniería para desarrollar software de manera <em>sistemática</em>, <em>disciplinada</em> y <em>cuantificable</em> (IEEE 610.12). Su objetivo: producir software de alta calidad, a tiempo y dentro del presupuesto.</p>
<h3>La Crisis del Software</h3>
<p>Según el <strong>CHAOS Report 2023</strong> (Standish Group):</p>
<ul>
  <li>Solo el <strong>31%</strong> de los proyectos se entregan a tiempo, dentro del presupuesto y con todas las funcionalidades</li>
  <li>El <strong>19%</strong> falla completamente</li>
  <li>El <strong>50%</strong> tiene sobrecostos, retrasos o funcionalidades reducidas</li>
</ul>
<h3>Causas principales de fracaso</h3>
<table border="1" cellpadding="8" style="border-collapse:collapse;width:100%;">
  <tr style="background:#e8f4f8;"><th>Causa</th><th>Frecuencia</th><th>Solución IS</th></tr>
  <tr><td>Requerimientos incompletos o cambiantes</td><td>39%</td><td>Elicitación sistemática, metodologías ágiles</td></tr>
  <tr><td>Falta de participación del usuario</td><td>13%</td><td>Scrum, user stories, demos frecuentes</td></tr>
  <tr><td>Falta de soporte gerencial</td><td>7%</td><td>Gestión de stakeholders, KPIs claros</td></tr>
  <tr><td>Estimaciones incorrectas</td><td>6%</td><td>Planning Poker, datos históricos</td></tr>
</table>
<h3>Principios de Software de Calidad (ISO 25010)</h3>
<ul>
  <li><strong>Funcionalidad:</strong> cumple los requerimientos especificados</li>
  <li><strong>Fiabilidad:</strong> funciona bajo condiciones y tiempo especificados</li>
  <li><strong>Usabilidad:</strong> el usuario aprende y usa el sistema eficientemente</li>
  <li><strong>Eficiencia:</strong> rendimiento adecuado con los recursos usados</li>
  <li><strong>Mantenibilidad:</strong> fácil de modificar, extender y corregir</li>
  <li><strong>Portabilidad:</strong> puede transferirse a otros entornos</li>
  <li><strong>Seguridad:</strong> protege datos e información adecuadamente</li>
</ul>
HTML;

$is_s3_page = <<<'HTML'
<h2>Requerimientos de Software</h2>
<h3>Tipos de Requerimientos</h3>
<p><strong>Requerimientos Funcionales (RF):</strong> Describen QUÉ debe hacer el sistema.</p>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">
RF-01: El sistema debe permitir al estudiante registrarse con nombre, email y contraseña.
RF-02: El sistema debe autenticar usuarios mediante email y contraseña.
RF-03: El sistema debe generar recomendaciones personalizadas basadas en el historial del estudiante.
RF-04: El sistema debe permitir al docente publicar recursos educativos.
RF-05: El sistema debe notificar al estudiante cuando hay nuevas recomendaciones.
</pre>
<p><strong>Requerimientos No Funcionales (RNF):</strong> Describen CÓMO debe comportarse el sistema.</p>
<table border="1" cellpadding="8" style="border-collapse:collapse;width:100%;">
  <tr style="background:#e8f4f8;"><th>Tipo</th><th>Requerimiento</th><th>Métrica</th></tr>
  <tr><td>Rendimiento</td><td>Las recomendaciones deben generarse rápidamente</td><td>&lt; 2 segundos para el 95% de las solicitudes</td></tr>
  <tr><td>Disponibilidad</td><td>El sistema debe estar disponible</td><td>Uptime ≥ 99.5% mensual</td></tr>
  <tr><td>Seguridad</td><td>Contraseñas cifradas</td><td>bcrypt con factor de coste ≥ 12</td></tr>
  <tr><td>Escalabilidad</td><td>Soportar carga concurrente</td><td>1,000 usuarios simultáneos sin degradación</td></tr>
  <tr><td>Usabilidad</td><td>Registro completable sin ayuda</td><td>90% de usuarios nuevos lo completan en &lt; 3 min</td></tr>
</table>
<h3>Historia de Usuario — Formato</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">
Como [ROL] quiero [ACCIÓN] para [BENEFICIO]

Ejemplo:
Como ESTUDIANTE
quiero recibir recomendaciones de recursos basadas en mis notas
para aprovechar mejor mi tiempo de estudio en las áreas donde tengo dificultades.

Criterios de Aceptación (Given-When-Then):
  Given: el estudiante tiene notas registradas en al menos 2 cursos
  When:  accede a la sección "Mis Recomendaciones"
  Then:  ve al menos 5 recursos ordenados por relevancia a su perfil
  And:   puede filtrar por tipo (video, artículo, ejercicio)
  And:   puede marcar recursos como "ya estudiado"

Estimación: 5 puntos (Fibonacci)  |  Prioridad: Alta (MoSCoW: Must Have)
Definition of Done:
  ✓ Código en PR aprobado  ✓ Tests unitarios con 80% cobertura
  ✓ Documentado en Swagger  ✓ Validado con 3 usuarios reales
</pre>
HTML;

$dw_s1_page = <<<'HTML'
<h2>HTML5 Semántico — Fundamentos</h2>
<h3>¿Por qué semántica?</h3>
<p>El HTML semántico mejora: accesibilidad (lectores de pantalla), SEO (motores de búsqueda), mantenibilidad del código y la colaboración en equipos.</p>
<h3>Etiquetas semánticas principales</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">
&lt;!DOCTYPE html&gt;
&lt;html lang="es"&gt;
&lt;head&gt;
    &lt;meta charset="UTF-8"&gt;
    &lt;meta name="viewport" content="width=device-width, initial-scale=1.0"&gt;
    &lt;meta name="description" content="Plataforma SWARD de recomendación educativa"&gt;
    &lt;title&gt;SWARD — Aprendizaje Inteligente&lt;/title&gt;
    &lt;link rel="stylesheet" href="styles.css"&gt;
&lt;/head&gt;
&lt;body&gt;
    &lt;header&gt;              &lt;!-- Cabecera del sitio o sección --&gt;
        &lt;nav&gt;             &lt;!-- Menú de navegación --&gt;
            &lt;a href="/"&gt;SWARD&lt;/a&gt;
            &lt;ul&gt;
                &lt;li&gt;&lt;a href="/cursos"&gt;Mis Cursos&lt;/a&gt;&lt;/li&gt;
                &lt;li&gt;&lt;a href="/recursos"&gt;Recursos&lt;/a&gt;&lt;/li&gt;
            &lt;/ul&gt;
        &lt;/nav&gt;
    &lt;/header&gt;

    &lt;main&gt;                &lt;!-- Contenido principal (uno por página) --&gt;
        &lt;article&gt;         &lt;!-- Contenido independiente y reutilizable --&gt;
            &lt;h1&gt;Dashboard del Estudiante&lt;/h1&gt;
            &lt;section&gt;     &lt;!-- Agrupación temática --&gt;
                &lt;h2&gt;Mis Recomendaciones&lt;/h2&gt;
                &lt;!-- contenido --&gt;
            &lt;/section&gt;
        &lt;/article&gt;
        &lt;aside&gt;           &lt;!-- Contenido relacionado / sidebar --&gt;
            &lt;h2&gt;Mi Progreso&lt;/h2&gt;
        &lt;/aside&gt;
    &lt;/main&gt;

    &lt;footer&gt;              &lt;!-- Pie de página --&gt;
        &lt;p&gt;&amp;copy; 2026 SWARD — Universidad&lt;/p&gt;
    &lt;/footer&gt;
&lt;/body&gt;
&lt;/html&gt;
</pre>
<h3>Formularios con validación HTML5</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">
&lt;form action="/api/auth/register" method="POST" novalidate&gt;
    &lt;div class="campo"&gt;
        &lt;label for="nombre"&gt;Nombre completo &lt;span aria-hidden&gt;*&lt;/span&gt;&lt;/label&gt;
        &lt;input type="text" id="nombre" name="nombre"
               required minlength="3" maxlength="100"
               autocomplete="name"
               aria-required="true"&gt;
    &lt;/div&gt;
    &lt;div class="campo"&gt;
        &lt;label for="email"&gt;Correo electrónico&lt;/label&gt;
        &lt;input type="email" id="email" name="email" required
               autocomplete="email"&gt;
    &lt;/div&gt;
    &lt;div class="campo"&gt;
        &lt;label for="pass"&gt;Contraseña&lt;/label&gt;
        &lt;input type="password" id="pass" name="password"
               required minlength="8"
               pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}"
               title="Mínimo 8 caracteres: mayúscula, minúscula y número"&gt;
    &lt;/div&gt;
    &lt;button type="submit"&gt;Registrarme&lt;/button&gt;
&lt;/form&gt;
</pre>
HTML;

$dw_s3_page = <<<'HTML'
<h2>JavaScript ES6+ — Sintaxis Moderna</h2>
<h3>Variables y Destructuring</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">
// const/let (nunca var)
const MAX_INTENTOS = 3;     // inmutable
let  intentos     = 0;      // mutable

// Destructuring de objetos
const { nombre, email, rol = 'estudiante' } = usuario;

// Destructuring de arrays
const [primero, segundo, ...resto] = calificaciones;

// Spread operator
const usuarioActualizado = { ...usuario, activo: true };
const nuevaLista = [...lista1, ...lista2, nuevoItem];
</pre>
<h3>Funciones Modernas</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">
// Arrow functions
const sumar  = (a, b) => a + b;
const doble  = n => n * 2;
const saludar = nombre => `¡Hola, ${nombre}!`;

// Default params + rest
const crear = (nombre, rol = 'estudiante', ...permisos) => ({
    nombre, rol, permisos, creadoEn: new Date()
});

// Array methods (inmutables, encadenables)
const aprobados = estudiantes
    .filter(e => e.promedio >= 14)
    .map(e => ({ ...e, estado: 'Aprobado' }))
    .sort((a, b) => b.promedio - a.promedio)
    .slice(0, 10);   // top 10
</pre>
<h3>Async/Await — Peticiones a APIs</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">
// Función asíncrona con manejo de errores robusto
async function cargarRecomendaciones(usuarioId) {
    try {
        const token = localStorage.getItem('token');
        const res   = await fetch(`/api/usuarios/${usuarioId}/recomendaciones`, {
            headers: { 'Authorization': `Bearer ${token}` }
        });

        if (res.status === 401) { redirigirLogin(); return; }
        if (!res.ok) throw new Error(`Error del servidor: ${res.status}`);

        const { data, total, pagina } = await res.json();
        renderizarTarjetas(data);
        actualizarPaginacion(total, pagina);

    } catch (error) {
        if (error.name === 'TypeError') {
            mostrarError('Sin conexión a internet');
        } else {
            mostrarError(error.message);
        }
        console.error('[cargarRecomendaciones]', error);
    }
}
</pre>
HTML;

// ── Crear contenido en los 4 cursos ───────────────────

$courses = [
    2 => 'Algoritmos y Estructuras de Datos',
    3 => 'Bases de Datos',
    4 => 'Ingeniería de Software',
    5 => 'Desarrollo Web',
];

foreach ($courses as $courseid => $coursename) {
    echo "\n📚 [$courseid] $coursename\n";
    echo str_repeat('─', 50) . "\n";

    // Obtener secciones
    $sections = get_sections($courseid);
    $sec = [];
    foreach ($sections as $s) {
        $sec[$s->section] = (int)$s->section;
    }
    $max_sec = max(array_keys($sec));

    if ($courseid === 2) { // AED
        echo "  [Semana 1-2]\n";
        add_page($courseid, 1, "Introducción a los Algoritmos", $aed_s1_page, 'HtSuA80QTyo', 'What is an Algorithm? — CS Dojo');
        add_url($courseid,  1, "VisuAlgo — Visualizador de Algoritmos",
            "https://visualgo.net/en", "Visualizador interactivo de estructuras de datos y algoritmos");
        add_url($courseid,  1, "MIT 6.006 — Introducción a Algoritmos",
            "https://ocw.mit.edu/courses/6-006-introduction-to-algorithms-fall-2011/",
            "Curso completo del MIT con videos, apuntes y ejercicios");
        add_assign($courseid, 1, "Práctica 1: Diseño de Algoritmos con Pseudocódigo",
            "<p>Diseña en pseudocódigo los siguientes algoritmos:</p><ol>
            <li>Determinar si un número es primo (trial division)</li>
            <li>Calcular el MCD de dos enteros usando el Algoritmo de Euclides</li>
            <li>Encontrar el máximo y mínimo de un arreglo en una sola pasada</li>
            </ol>
            <p>Para cada algoritmo indica: entradas, salidas, pasos y número de operaciones en el peor caso.</p>
            <p><strong>Entrega:</strong> PDF con pseudocódigo, trazas de ejecución y análisis.</p>", 2);
        add_forum($courseid, 1, "Foro Semana 1-2: Fundamentos",
            "<p>Espacio para dudas sobre fundamentos de algoritmos. Incluye el código o pseudocódigo cuando hagas una pregunta.</p>");

        echo "  [Semana 3-4]\n";
        add_page($courseid, 2, "Complejidad Computacional — Big O", $aed_s2_page, 'v4cd1O4zkGw', 'Big O Notation — CS Dojo');
        add_url($courseid,  2, "Big-O Cheat Sheet",
            "https://www.bigocheatsheet.com/", "Referencia rápida de complejidades por algoritmo");
        add_assign($courseid, 2, "Práctica 2: Análisis de Complejidad",
            "<p>Para cada uno de los 8 fragmentos de código dados, determina:</p>
            <ol>
            <li>Complejidad temporal en Big O</li>
            <li>Complejidad espacial en Big O</li>
            <li>Justificación paso a paso</li>
            </ol>
            <p>Incluye una tabla comparativa final con todas las complejidades.</p>", 3);
        add_quiz($courseid, 2, "Quiz 1: Notación Big O",
            "<p>Evaluación de 10 preguntas sobre complejidad computacional. Tiempo límite: 30 minutos. Se permite 1 intento.</p>", 3);

        echo "  [Semana 5-7]\n";
        add_page($courseid, 3, "Estructuras de Datos Lineales", $aed_s3_page, 'RBSGKlAvoiM', 'Data Structures — freeCodeCamp');
        add_url($courseid,  3, "VisuAlgo — Listas, Pilas y Colas",
            "https://visualgo.net/en/list", "Animaciones interactivas de estructuras lineales");
        add_assign($courseid, 3, "Práctica 3: Implementación de Estructuras Lineales",
            "<p>Implementa en Python, Java o C++:</p>
            <ol>
            <li>Lista doblemente enlazada con: insertar inicio/final, eliminar por valor, buscar, imprimir</li>
            <li>Pila usando array con límite de capacidad</li>
            <li>Cola circular usando array de tamaño fijo</li>
            <li>Verificador de paréntesis balanceados usando pila: <code>{[()]} → válido</code></li>
            </ol>
            <p>Para cada estructura: 5 casos de prueba incluyendo casos límite.</p>", 4);

        echo "  [Semana 8-9]\n";
        add_page($courseid, 4, "Árboles Binarios y AVL", $aed_s4_page, 'oSWTXtMglKE', 'Binary Search Trees — Bro Code');
        add_url($courseid,  4, "VisuAlgo — BST y AVL",
            "https://visualgo.net/en/bst", "Visualización de rotaciones AVL");
        add_assign($courseid, 4, "Práctica 4: Árbol Binario de Búsqueda",
            "<p>Implementa un BST completo con:</p>
            <ul>
            <li>Inserción y búsqueda</li>
            <li>Los 4 tipos de recorrido (inorden, preorden, postorden, por niveles)</li>
            <li>Eliminación de nodos (caso hoja, un hijo, dos hijos)</li>
            <li>Método que calcule la altura del árbol</li>
            <li>Método que verifique si el árbol es balanceado</li>
            </ul>
            <p>Prueba con la secuencia: 50, 30, 70, 20, 40, 60, 80, 10, 35</p>
            <p>Muestra el árbol resultante y todos los recorridos.</p>", 5);
        add_quiz($courseid, 4, "Quiz 2: Árboles Binarios",
            "<p>15 preguntas sobre BST y AVL. Tiempo: 40 minutos.</p>", 5);

        echo "  [Semana 12-13]\n";
        add_page($courseid, 6, "Algoritmos de Ordenamiento", $aed_s5_page, 'kgBjXUE_Kkw', 'Sorting Algorithms — freeCodeCamp');
        add_url($courseid,  6, "Sorting Algorithms Visualized",
            "https://www.toptal.com/developers/sorting-algorithms",
            "Comparación visual y animada de algoritmos de ordenamiento");
        add_assign($courseid, 6, "Práctica 6: Comparación Experimental de Ordenamientos",
            "<p>Implementa Bubble Sort, Merge Sort y Quick Sort. Luego:</p>
            <ol>
            <li>Genera arreglos aleatorios de tamaño 1,000 / 10,000 / 100,000</li>
            <li>Mide el tiempo de ejecución de cada algoritmo</li>
            <li>Construye una tabla comparativa con los resultados</li>
            <li>Grafica los tiempos (usa matplotlib o similar)</li>
            <li>¿Coinciden los tiempos con la complejidad teórica? Analiza.</li>
            </ol>", 6);

        echo "  [Proyecto Final]\n";
        add_assign($courseid, 7, "Proyecto Final: Sistema de Gestión de Biblioteca",
            "<h3>Descripción</h3>
            <p>Diseña e implementa un sistema de gestión de biblioteca digital que integre múltiples estructuras de datos del curso.</p>
            <h3>Requerimientos</h3>
            <ul>
            <li>Tabla Hash para búsqueda O(1) por ISBN</li>
            <li>BST para búsqueda eficiente por título o autor</li>
            <li>Cola de prioridad (min-heap) para gestionar lista de espera de préstamos</li>
            <li>Lista enlazada para historial de préstamos por usuario</li>
            </ul>
            <h3>Criterios de Evaluación</h3>
            <ul>
            <li>Corrección funcional (30%)</li>
            <li>Eficiencia y justificación de estructuras (25%)</li>
            <li>Análisis de complejidad documentado (20%)</li>
            <li>Código limpio y mantenible (15%)</li>
            <li>Informe técnico (10%)</li>
            </ul>
            <p><strong>Entregables:</strong> código fuente + informe PDF + diagrama de clases UML</p>
            <p><strong>Grupos:</strong> individual o pareja</p>", 15);
        add_forum($courseid, 7, "Foro: Proyecto Final — Consultas",
            "<p>Espacio oficial para consultas sobre el proyecto final. El docente responde en 48h.</p>");

    } elseif ($courseid === 3) { // BD
        echo "  [Semana 1-2]\n";
        add_page($courseid, 1, "Fundamentos de Bases de Datos", $bd_s1_page, 'OqjJjpjDRLc', 'Introduction to Databases — mycodeschool');
        add_url($courseid,  1, "PostgreSQL Tutorial Oficial",
            "https://www.postgresql.org/docs/current/tutorial.html", "Documentación oficial de PostgreSQL");
        add_url($courseid,  1, "SQLZoo — Práctica SQL interactiva",
            "https://sqlzoo.net/", "Ejercicios SQL interactivos por nivel");
        add_assign($courseid, 1, "Práctica 1: Instalación y Configuración de PostgreSQL",
            "<p>Instala PostgreSQL 15 en tu equipo y realiza:</p>
            <ol>
            <li>Crea la base de datos <code>universidad_db</code></li>
            <li>Crea el usuario <code>admin_uni</code> con contraseña segura</li>
            <li>Conéctate con pgAdmin 4 o DBeaver</li>
            <li>Documenta el proceso con capturas de pantalla</li>
            <li>Ejecuta y muestra: versión de PostgreSQL, lista de bases de datos, lista de usuarios</li>
            </ol>
            <p>Entrega: PDF con capturas y todos los comandos utilizados.</p>", 2);

        echo "  [Semana 3-4]\n";
        add_url($courseid,  2, "dbdiagram.io — Diseño ER Online",
            "https://dbdiagram.io/", "Herramienta gratuita para crear diagramas ER");
        add_assign($courseid, 2, "Práctica 2: Diseño ER — Sistema de Ventas Online",
            "<p>Diseña el diagrama ER completo para un sistema de ventas online con:</p>
            <ul>
            <li>Cliente (datos personales, dirección, historial)</li>
            <li>Producto (código, nombre, precio, stock, categoría)</li>
            <li>Pedido (fecha, estado, total, dirección de envío)</li>
            <li>DetallePedido (cantidad, precio unitario al momento de compra)</li>
            <li>Categoría (jerarquía con sub-categorías)</li>
            <li>Proveedor (puede proveer múltiples productos)</li>
            </ul>
            <p>Entrega: diagrama ER + script SQL de creación con todas las restricciones.</p>", 3);

        echo "  [Semana 5-6]\n";
        add_page($courseid, 3, "SQL Básico — DDL y DML", $bd_s3_page, 'HXV3zeQKqGY', 'MySQL Tutorial for Beginners — Programming with Mosh');
        add_assign($courseid, 3, "Práctica 3: CRUD Completo — Sistema Universitario",
            "<p>Crea las tablas del sistema universitario (Estudiante, Docente, Curso, Matrícula) y luego:</p>
            <ol>
            <li>Inserta: 15 estudiantes, 4 docentes, 5 cursos con datos realistas</li>
            <li>Matricula estudiantes (mínimo 2 cursos por estudiante)</li>
            <li>Escribe 8 consultas SELECT con diferentes condiciones WHERE y ORDER BY</li>
            <li>Actualiza el email de 3 estudiantes</li>
            <li>Prueba la integridad referencial eliminando un estudiante con matrículas</li>
            </ol>
            <p>Entrega: script SQL completo + capturas de resultados.</p>", 4);
        add_quiz($courseid, 3, "Quiz 1: DDL y DML Básico",
            "<p>20 preguntas sobre SQL básico. Tiempo: 45 minutos. 2 intentos permitidos.</p>", 4);

        echo "  [Semana 7-8]\n";
        add_page($courseid, 4, "SQL Avanzado — JOINs y Subconsultas",
            '<h2>SQL Avanzado</h2>
<h3>Tipos de JOIN</h3>
<table border="1" cellpadding="8" style="border-collapse:collapse;width:100%;">
<tr style="background:#e8f4f8;"><th>JOIN</th><th>Retorna</th><th>Caso de uso</th></tr>
<tr><td>INNER JOIN</td><td>Solo filas con coincidencia en ambas tablas</td><td>Estudiantes matriculados (excluye sin matrícula)</td></tr>
<tr><td>LEFT JOIN</td><td>Todas las filas izquierda + coincidencias</td><td>Todos los estudiantes, con o sin matrícula</td></tr>
<tr><td>RIGHT JOIN</td><td>Todas las filas derecha + coincidencias</td><td>Todos los cursos, con o sin estudiantes</td></tr>
<tr><td>FULL OUTER JOIN</td><td>Todas las filas de ambas tablas</td><td>Auditoría completa de relaciones</td></tr>
<tr><td>CROSS JOIN</td><td>Producto cartesiano</td><td>Combinaciones de opciones/variantes</td></tr>
</table>
<h3>Ejemplos Prácticos</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">-- Estudiantes con sus cursos y notas
SELECT e.nombre, e.apellido, c.nombre AS curso, m.nota,
       CASE WHEN m.nota >= 14 THEN "Aprobado"
            WHEN m.nota >= 11 THEN "En proceso"
            ELSE "Desaprobado" END AS estado
FROM estudiante e
JOIN matricula  m ON e.id_est = m.id_est
JOIN curso      c ON m.id_cur = c.id_cur
ORDER BY e.apellido, m.nota DESC;

-- Docente con el mayor promedio (subconsulta)
SELECT d.nombre, AVG(m.nota) AS promedio
FROM docente d
JOIN curso c ON c.id_doc = d.id_doc
JOIN matricula m ON m.id_cur = c.id_cur
WHERE m.nota IS NOT NULL
GROUP BY d.id_doc, d.nombre
ORDER BY promedio DESC
LIMIT 1;

-- Funciones de ventana (Window Functions) - PostgreSQL
SELECT e.nombre, c.nombre AS curso, m.nota,
       RANK() OVER (PARTITION BY m.id_cur ORDER BY m.nota DESC) AS posicion,
       AVG(m.nota) OVER (PARTITION BY m.id_cur) AS promedio_curso
FROM estudiante e
JOIN matricula m ON e.id_est = m.id_est
JOIN curso c ON m.id_cur = c.id_cur;</pre>',
            '9yeOJ0ZMUYw', 'SQL Joins — Programming with Mosh');
        add_assign($courseid, 4, "Práctica 4: SQL Avanzado — JOINs y Funciones de Ventana",
            "<p>Usando la BD universitaria, resuelve:</p><ol>
            <li>INNER JOIN: lista completa de notas por estudiante y curso</li>
            <li>LEFT JOIN: estudiantes SIN ninguna matrícula</li>
            <li>Docente con mayor promedio de notas en sus cursos</li>
            <li>Top 3 cursos por número de matriculados</li>
            <li>Ranking de estudiantes por promedio usando RANK() OVER</li>
            <li>VIEW que muestre reporte académico completo</li>
            <li>CTE (WITH) que calcule estadísticas por departamento</li>
            </ol>", 5);

        echo "  [Semana 9-10]\n";
        add_page($courseid, 5, "Normalización — 1FN, 2FN, 3FN y BCNF",
            '<h2>Normalización de Bases de Datos</h2>
<h3>¿Por qué normalizar?</h3>
<p>La normalización elimina <strong>redundancia</strong> y evita <strong>anomalías de actualización</strong>, inserción y eliminación. Un esquema bien normalizado es más consistente y fácil de mantener.</p>
<h3>Dependencias Funcionales</h3>
<p>A → B significa "A determina B" (conocer A implica conocer B exactamente). Ejemplo: <code>id_est → nombre_est</code>.</p>
<h3>Primera Forma Normal (1FN)</h3>
<p>❌ <strong>Violación:</strong> columna <code>telefonos = "999-111, 888-222"</code> (valor no atómico)</p>
<p>✅ <strong>Solución:</strong> tabla separada <code>TELEFONO(id_est FK, numero, tipo)</code></p>
<h3>Segunda Forma Normal (2FN)</h3>
<p>❌ <strong>Violación (PK compuesta id_est+id_cur):</strong> <code>nombre_cur</code> depende solo de <code>id_cur</code></p>
<p>✅ <strong>Solución:</strong> mover <code>nombre_cur</code> a tabla CURSO</p>
<h3>Tercera Forma Normal (3FN)</h3>
<p>❌ <strong>Violación:</strong> <code>EMPLEADO(id, nombre, id_dept, nombre_dept)</code> → <code>id→id_dept→nombre_dept</code> (dependencia transitiva)</p>
<p>✅ <strong>Solución:</strong> tabla <code>DEPARTAMENTO(id_dept PK, nombre_dept)</code></p>
<h3>Ejemplo Completo de Normalización</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">-- ANTES (sin normalizar):
PEDIDO(id_ped, fecha, id_cli, nom_cli, email_cli,
       cod_prod, nom_prod, precio_unit, cantidad, total_linea)

-- DESPUÉS (3FN):
CLIENTE(id_cli PK, nombre, email UNIQUE)
PRODUCTO(cod_prod PK, nombre, precio_unitario)
PEDIDO(id_ped PK, fecha, id_cli FK, subtotal, igv, total)
DETALLE(id_ped FK, cod_prod FK, cantidad, precio_al_vender)
  PK: (id_ped, cod_prod)</pre>',
            'ABwD8IYByfk', 'Database Normalization — 1NF 2NF 3NF');
        add_assign($courseid, 5, "Práctica 5: Normalización hasta 3FN",
            "<p>Normaliza la siguiente tabla hasta la Tercera Forma Normal (3FN):</p>
            <pre>INSCRIPCION(id_ins, fecha_ins, id_est, nom_est, email_est, ciudad_est,
id_cur, nom_cur, creditos, id_doc, nom_doc, dept_doc, nota, fecha_exam)</pre>
            <p>Para cada forma normal:</p>
            <ol>
            <li>Identifica la violación con ejemplo concreto</li>
            <li>Muestra el esquema resultante</li>
            <li>Justifica tu decisión</li>
            </ol>
            <p>Entrega el esquema final en SQL (CREATE TABLE) con todas las restricciones de integridad.</p>", 5);

        echo "  [Proyecto Final]\n";
        add_assign($courseid, 7, "Proyecto Final: Base de Datos para Biblioteca Digital",
            "<h3>Diseña e implementa la BD completa para un sistema de biblioteca digital</h3>
            <h4>Entidades requeridas</h4>
            <ul>
            <li>Libro (isbn, título, año, editorial, ejemplares_disponibles)</li>
            <li>Autor — relación M:N con Libro</li>
            <li>Usuario (carnet, nombre, email, tipo: estudiante|docente|externo)</li>
            <li>Prestamo (fecha_inicio, fecha_limite, fecha_devolucion, multa)</li>
            <li>Reserva (lista de espera FIFO cuando no hay ejemplares)</li>
            <li>Categoria (con sub-categorías)</li>
            </ul>
            <h4>Entregables</h4>
            <ol>
            <li>Diagrama ER completo</li>
            <li>Script DDL con PK, FK, CHECK, UNIQUE, DEFAULT</li>
            <li>Datos de prueba: 50 libros, 30 usuarios, 100 préstamos</li>
            <li>10 consultas avanzadas (JOINs, subconsultas, funciones de ventana)</li>
            <li>3 procedimientos almacenados: registrar préstamo, devolver libro, reporte mensual</li>
            <li>Informe técnico con decisiones de diseño justificadas</li>
            </ol>
            <p><strong>Grupos:</strong> 2-3 personas</p>", 15);

    } elseif ($courseid === 4) { // IS
        echo "  [Semana 1-2]\n";
        add_page($courseid, 1, "Introducción a la Ingeniería de Software", $is_s1_page, 'O753uuutqH8', 'Software Engineering: Introduction — MIT OpenCourseWare');
        add_url($courseid,  1, "SWEBOK v3 — Guía de Conocimientos en IS",
            "https://www.computer.org/education/bodies-of-knowledge/software-engineering",
            "Estándar IEEE de conocimientos en Ingeniería de Software");
        add_assign($courseid, 1, "Taller 1: Análisis de Fracaso de Proyecto de Software",
            "<p>Investiga y analiza UN caso real de fracaso de proyecto de software.</p>
            <p>Casos sugeridos: Healthcare.gov (2013), Denver Baggage System (1994), London Ambulance Service (1992), Boeing 737 MAX MCAS.</p>
            <p>Tu informe debe incluir:</p>
            <ol>
            <li>Descripción del proyecto y sus objetivos originales</li>
            <li>¿Qué salió mal? (causas técnicas y no técnicas)</li>
            <li>Impacto económico y humano</li>
            <li>¿Qué principios de IS se violaron?</li>
            <li>¿Qué habrías hecho diferente? (propuesta concreta)</li>
            </ol>
            <p>Extensión: 4-6 páginas. Requiere fuentes académicas.</p>", 2);
        add_forum($courseid, 1, "Foro: Debates sobre Ingeniería de Software",
            "<p>Espacio para discutir casos reales, noticias de la industria y reflexiones sobre IS.</p>");

        echo "  [Semana 3-4]\n";
        add_page($courseid, 2, "Metodologías Ágiles — Scrum y Kanban",
            '<h2>Metodologías Ágiles</h2>
<h3>Manifiesto Ágil (2001)</h3>
<p>12 principios sobre 4 valores: <strong>individuos sobre procesos</strong>, <strong>software funcional sobre documentación</strong>, <strong>colaboración con el cliente sobre negociación</strong>, <strong>respuesta al cambio sobre seguir un plan</strong>.</p>
<h3>Scrum — Marco de Trabajo</h3>
<table border="1" cellpadding="8" style="border-collapse:collapse;width:100%;">
<tr style="background:#e8f4f8;"><th>Elemento</th><th>Descripción</th><th>Duración/Frecuencia</th></tr>
<tr><td>Sprint</td><td>Iteración con entregable funcional</td><td>2-4 semanas</td></tr>
<tr><td>Daily Scrum</td><td>¿Qué hice? ¿Qué haré? ¿Impedimentos?</td><td>15 min diarios</td></tr>
<tr><td>Sprint Review</td><td>Demo al Product Owner</td><td>Al final del Sprint</td></tr>
<tr><td>Retrospectiva</td><td>¿Qué mejorar en el proceso?</td><td>Al final del Sprint</td></tr>
<tr><td>Sprint Planning</td><td>Seleccionar items del backlog</td><td>Al inicio del Sprint</td></tr>
</table>
<h3>Roles en Scrum</h3>
<ul>
<li><strong>Product Owner:</strong> prioriza el backlog, representa al cliente</li>
<li><strong>Scrum Master:</strong> facilita el proceso, elimina impedimentos</li>
<li><strong>Development Team:</strong> auto-organizado, entrega el incremento</li>
</ul>
<h3>Kanban</h3>
<p>Sistema visual de gestión de flujo de trabajo. Columnas típicas: <em>Por Hacer → En Progreso → Revisión → Hecho</em>. Principio clave: limitar el WIP (Work In Progress) para aumentar el flujo.</p>',
            '9TycLR0TqFA', 'Scrum en menos de 10 minutos — Atlassian');
        add_url($courseid,  2, "Scrum Guide 2020 en Español",
            "https://scrumguides.org/docs/scrumguide/v2020/2020-Scrum-Guide-Spanish-European.pdf",
            "Guía oficial de Scrum 2020 en español");
        add_url($courseid,  2, "Agile Manifesto en Español",
            "https://agilemanifesto.org/iso/es/manifesto.html",
            "Los 4 valores y 12 principios del Manifiesto Ágil");
        add_assign($courseid, 2, "Taller 2: Planificación con Waterfall vs Scrum",
            "<p>Selecciona un proyecto de software realista (puede ser ficticio). Planifícalo de dos formas:</p>
            <ol>
            <li><strong>Waterfall:</strong> fases secuenciales, entregables, diagrama de Gantt de 4 meses</li>
            <li><strong>Scrum:</strong> Product Backlog con 15+ historias de usuario priorizadas + Sprint Planning de 3 sprints</li>
            </ol>
            <p>Finalmente: compara las dos metodologías para ESE proyecto específico y recomienda cuál usarías con justificación.</p>", 3);

        echo "  [Semana 5-6]\n";
        add_page($courseid, 3, "Requerimientos de Software", $is_s3_page, '4O5GIjy5bk8', 'Agile User Stories — Mountain Goat Software');
        add_assign($courseid, 3, "Taller 3: ERS para el Sistema SWARD",
            "<p>Redacta el Documento de Especificación de Requerimientos (ERS) para el sistema SWARD siguiendo IEEE 830:</p>
            <ol>
            <li>Introducción (propósito, alcance, definiciones, referencias)</li>
            <li>Descripción general (perspectiva, funciones principales, tipos de usuario)</li>
            <li>10 Requerimientos Funcionales detallados con criterios de aceptación</li>
            <li>5 Requerimientos No Funcionales medibles (con métricas concretas)</li>
            <li>10 Historias de Usuario en formato estándar con Definition of Done</li>
            <li>Diagrama de Casos de Uso UML con al menos 3 actores</li>
            </ol>", 4);
        add_quiz($courseid, 3, "Quiz 1: Requerimientos y Metodologías",
            "<p>15 preguntas sobre tipos de requerimientos, metodologías ágiles e historias de usuario. 30 minutos.</p>", 4);

        echo "  [Semana 7-8]\n";
        add_url($courseid,  4, "Refactoring Guru — Patrones de Diseño",
            "https://refactoring.guru/es/design-patterns",
            "Catálogo completo de patrones GoF con ejemplos en múltiples lenguajes");
        add_assign($courseid, 4, "Taller 4: Aplicación de Principios SOLID",
            "<p>Analiza el siguiente código legado que viola SOLID y refactorízalo:</p>
            <p>Se proporciona una clase <code>GestorUsuario</code> que valida, guarda en BD, envía emails y genera reportes PDF.</p>
            <ol>
            <li>Identifica qué principios SOLID viola y por qué</li>
            <li>Rediseña aplicando todos los principios SOLID</li>
            <li>Diagrama de clases UML antes y después</li>
            <li>Implementa el diseño refactorizado con pruebas unitarias</li>
            </ol>", 5);

        echo "  [Semana 11-13]\n";
        add_url($courseid,  6, "pytest — Framework de Testing Python",
            "https://docs.pytest.org/en/stable/", "Documentación oficial de pytest");
        add_assign($courseid, 6, "Taller 5: TDD — Módulo de Autenticación",
            "<p>Usando TDD (Red-Green-Refactor), implementa el módulo de autenticación de SWARD:</p>
            <ol>
            <li>Registro: validar email único, contraseña segura, datos completos</li>
            <li>Login: verificar credenciales, generar JWT, manejar bloqueo por intentos</li>
            <li>Refresh Token: renovar JWT sin re-login</li>
            <li>Cambio de contraseña: verificar contraseña actual, validar nueva</li>
            </ol>
            <p>Mínimo 20 casos de prueba. Cobertura ≥ 80%. Entrega con reporte de coverage.</p>", 7);

        echo "  [Proyecto Final]\n";
        add_assign($courseid, 7, "Proyecto Final Sprint 1: Planificación y Diseño",
            "<h3>Primer entregable del Proyecto Final</h3>
            <p>Equipos de 3-4 personas. Entrega al final de la semana 8.</p>
            <ol>
            <li>Documento de visión del producto (1 página: problema, solución, usuarios objetivo)</li>
            <li>Product Backlog con 20+ historias de usuario priorizadas (MoSCoW)</li>
            <li>Arquitectura del sistema: diagrama de componentes</li>
            <li>Modelo de datos: diagrama ER</li>
            <li>Wireframes de las 5 pantallas principales</li>
            <li>Plan de sprints con cronograma</li>
            <li>Repositorio Git configurado con rama main protegida + README</li>
            </ol>", 8);
        add_assign($courseid, 7, "Proyecto Final Sprint 3: Entrega Final",
            "<h3>Demo y entrega final del sistema</h3>
            <p>Semana 15 — Demo en vivo ante el docente y compañeros.</p>
            <h4>Criterios de Evaluación</h4>
            <ul>
            <li>Funcionalidad completa (35%)</li>
            <li>Código limpio + SOLID + patrones (25%)</li>
            <li>Tests y cobertura ≥ 70% (20%)</li>
            <li>CI/CD + despliegue funcional (10%)</li>
            <li>Presentación y demo en vivo (10%)</li>
            </ul>
            <p><strong>Entregables:</strong> URL pública del sistema, repositorio GitHub, informe técnico, video demo (5 min)</p>", 15);

    } elseif ($courseid === 5) { // DW
        echo "  [Semana 1-2]\n";
        add_page($courseid, 1, "HTML5 Semántico — Fundamentos", $dw_s1_page, 'UB1O30fR-EE', 'HTML Crash Course — Traversy Media');
        add_url($courseid,  1, "MDN Web Docs — HTML en Español",
            "https://developer.mozilla.org/es/docs/Web/HTML",
            "Referencia completa de HTML5 en español");
        add_url($courseid,  1, "W3C HTML Validator",
            "https://validator.w3.org/", "Valida tu HTML en línea según los estándares W3C");
        add_assign($courseid, 1, "Proyecto 1: Portafolio Personal con HTML5",
            "<p>Crea tu página de portafolio personal usando <strong>solo HTML5</strong> (sin CSS todavía):</p>
            <ol>
            <li>Estructura semántica completa: header, nav, main, sections, article, aside, footer</li>
            <li>Sección: sobre mí (foto, descripción, habilidades)</li>
            <li>Sección: proyectos (mínimo 3 con imagen, título, descripción, tecnologías)</li>
            <li>Sección: experiencia o educación</li>
            <li>Formulario de contacto con validación HTML5 (required, type, pattern, minlength)</li>
            <li>El HTML debe pasar el validador W3C <strong>sin errores</strong></li>
            </ol>
            <p>Entrega: archivo .html + captura del validador W3C en verde.</p>", 2);

        echo "  [Semana 3-4]\n";
        add_page($courseid, 2, "CSS3 — Flexbox, Grid y Diseño Responsivo",
            '<h2>CSS3 Moderno</h2>
<h3>Flexbox — Layout Unidimensional</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">.container {
    display: flex;
    justify-content: space-between; /* horizontal */
    align-items: center;            /* vertical */
    flex-wrap: wrap;
    gap: 1rem;
}
.item { flex: 1 1 250px; } /* grow shrink basis */</pre>
<h3>CSS Grid — Layout Bidimensional</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">.layout {
    display: grid;
    grid-template-columns: 260px 1fr;
    grid-template-rows: 64px 1fr 48px;
    grid-template-areas:
        "header  header"
        "sidebar main"
        "footer  footer";
    min-height: 100vh;
    gap: 0;
}
header { grid-area: header; }
aside  { grid-area: sidebar; }
main   { grid-area: main; }
footer { grid-area: footer; }</pre>
<h3>Variables CSS y Temas</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">:root {
    --primary:    #2563eb;
    --secondary:  #1e1b4b;
    --success:    #10b981;
    --danger:     #ef4444;
    --text:       #1f2937;
    --bg:         #f9fafb;
    --radius:     8px;
    --shadow:     0 4px 6px rgba(0,0,0,.07);
    --transition: all .2s ease;
}
.btn {
    background: var(--primary);
    border-radius: var(--radius);
    transition: var(--transition);
}
.btn:hover { filter: brightness(1.1); }</pre>
<h3>Media Queries — Mobile First</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">/* Base: mobile */
.cards { display: grid; grid-template-columns: 1fr; gap: 1rem; }

@media (min-width: 640px)  { .cards { grid-template-columns: 1fr 1fr; } }
@media (min-width: 1024px) { .cards { grid-template-columns: repeat(3, 1fr); } }
@media (min-width: 1280px) { .cards { grid-template-columns: repeat(4, 1fr); } }</pre>',
            'JJSoEo8JSnc', 'Flexbox CSS — Traversy Media Crash Course');
        add_url($courseid,  2, "CSS Tricks — Guía Completa de Flexbox",
            "https://css-tricks.com/snippets/css/a-guide-to-flexbox/",
            "Referencia visual exhaustiva de Flexbox");
        add_url($courseid,  2, "CSS Grid Generator",
            "https://cssgrid-generator.netlify.app/", "Generador visual de layouts con CSS Grid");
        add_assign($courseid, 2, "Proyecto 2: Portafolio Responsivo con CSS3",
            "<p>Aplica CSS3 profesional al portafolio del Proyecto 1:</p>
            <ol>
            <li>Variables CSS (<code>--primary</code>, <code>--secondary</code>, <code>--text</code>, <code>--bg</code>, <code>--radius</code>)</li>
            <li>Navbar fija con menú hamburguesa en mobile</li>
            <li>Grilla de proyectos con CSS Grid (<code>auto-fit</code>, <code>minmax</code>)</li>
            <li>Cards con Flexbox, sombras, bordes redondeados y <code>hover</code> effects</li>
            <li>Formulario de contacto estilizado</li>
            <li>Completamente responsivo: mobile (320px), tablet (768px), desktop (1200px)</li>
            <li>Mínimo 3 animaciones CSS (<code>@keyframes</code> o <code>transition</code>)</li>
            </ol>", 3);

        echo "  [Semana 5-7]\n";
        add_page($courseid, 3, "JavaScript ES6+ — Sintaxis Moderna", $dw_s3_page, 'hdI2bqOjy3c', 'JavaScript Crash Course — Traversy Media');
        add_url($courseid,  3, "JavaScript.info — Guía Completa en Español",
            "https://es.javascript.info/", "La mejor guía de JavaScript moderno en español");
        add_assign($courseid, 3, "Proyecto 3: App To-Do con JavaScript Puro",
            "<p>Construye una SPA de lista de tareas con JavaScript puro (sin frameworks):</p>
            <ol>
            <li>Agregar tareas con título, descripción, prioridad y fecha límite</li>
            <li>Marcar como completada / pendiente (toggle)</li>
            <li>Eliminar tarea con confirmación</li>
            <li>Filtrar por estado (todas, activas, completadas) y búsqueda por texto</li>
            <li>Ordenar por prioridad o fecha límite</li>
            <li>Persistencia en <code>localStorage</code></li>
            <li>Drag and drop para reordenar (sin librerías)</li>
            </ol>
            <p><strong>Requisitos técnicos:</strong> ES6+, módulos JS, async/await, sin jQuery.</p>", 4);
        add_quiz($courseid, 3, "Quiz 1: HTML5, CSS3 y JavaScript",
            "<p>25 preguntas sobre los fundamentos web vistos hasta ahora. Tiempo: 45 minutos.</p>", 4);

        echo "  [Semana 8-10]\n";
        add_page($courseid, 4, "React 18 — Componentes, Hooks y Estado",
            '<h2>React 18 — Fundamentos</h2>
<h3>¿Por qué React?</h3>
<p>React es la librería de UI más usada en la industria (más del 40% de los proyectos web según Stack Overflow Survey 2023). Permite construir interfaces declarativas y reutilizables basadas en componentes.</p>
<h3>Componente Funcional Moderno</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">import { useState, useEffect } from "react";

function TarjetaCurso({ id, nombre, creditos, docente }) {
    const [matriculado, setMatriculado] = useState(false);
    const [loading, setLoading] = useState(false);

    const matricularse = async () => {
        setLoading(true);
        try {
            await fetch("/api/matriculas", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ cursoId: id }),
            });
            setMatriculado(true);
        } finally {
            setLoading(false);
        }
    };

    return (
        &lt;article className="card"&gt;
            &lt;h3&gt;{nombre}&lt;/h3&gt;
            &lt;p&gt;{creditos} créditos · {docente}&lt;/p&gt;
            &lt;button onClick={matricularse} disabled={matriculado || loading}&gt;
                {loading ? "Procesando..." : matriculado ? "✓ Matriculado" : "Matricularme"}
            &lt;/button&gt;
        &lt;/article&gt;
    );
}</pre>
<h3>Hooks Esenciales</h3>
<table border="1" cellpadding="8" style="border-collapse:collapse;width:100%;">
<tr style="background:#e8f4f8;"><th>Hook</th><th>Para qué sirve</th><th>Cuándo usarlo</th></tr>
<tr><td>useState</td><td>Estado local del componente</td><td>Datos que cambian y causan re-render</td></tr>
<tr><td>useEffect</td><td>Efectos secundarios</td><td>Fetch de datos, suscripciones, timers</td></tr>
<tr><td>useContext</td><td>Estado global sin props drilling</td><td>Usuario autenticado, tema, idioma</td></tr>
<tr><td>useReducer</td><td>Estado complejo con acciones</td><td>Formularios complejos, carrito de compras</td></tr>
<tr><td>useMemo</td><td>Memoizar valores costosos</td><td>Cálculos pesados que dependen de props</td></tr>
<tr><td>useCallback</td><td>Memoizar funciones</td><td>Funciones pasadas como props a hijos</td></tr>
</table>',
            'w7ejDZ8SWv8', 'React Crash Course 2024 — Traversy Media');
        add_url($courseid,  4, "React.dev — Documentación Oficial",
            "https://es.react.dev/", "Documentación oficial de React en español");
        add_url($courseid,  4, "Vite — Build Tool Moderno",
            "https://vitejs.dev/", "Herramienta de desarrollo ultra-rápida para React/Vue");
        add_assign($courseid, 4, "Proyecto 4: Dashboard Educativo con React",
            "<p>Construye un dashboard para estudiantes con React + Vite:</p>
            <ol>
            <li>Página de login con validación de formulario</li>
            <li>Dashboard principal: estadísticas (cursos, promedio, progreso), lista de cursos, próximas entregas</li>
            <li>Página de detalle de curso con módulos y recursos</li>
            <li>Navegación con React Router v6</li>
            <li>Estado global con Context API + useReducer</li>
            <li>Fetch a una API simulada (json-server o datos mock)</li>
            <li>Loading states y manejo de errores</li>
            </ol>", 5);

        echo "  [Semana 11-12]\n";
        add_page($courseid, 5, "Node.js, Express y API REST",
            '<h2>Backend con Node.js y Express</h2>
<h3>¿Por qué Node.js?</h3>
<p>Node.js permite usar JavaScript en el servidor. Es <strong>asíncrono y no bloqueante</strong>, ideal para APIs con muchas conexiones concurrentes. Es el runtime más usado en backend según Stack Overflow Survey 2023.</p>
<h3>API REST con Express — Estructura Profesional</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">// server.js
import express from "express";
import cors    from "cors";
import helmet  from "helmet";
import { cursosRouter } from "./routes/cursos.js";
import { authRouter }   from "./routes/auth.js";
import { errorHandler } from "./middleware/error.js";

const app = express();

app.use(helmet());
app.use(cors({ origin: process.env.CLIENT_URL }));
app.use(express.json());

app.use("/api/auth",   authRouter);
app.use("/api/cursos", cursosRouter);
app.use(errorHandler); // siempre al final

app.listen(3000, () => console.log("API en :3000"));</pre>
<h3>Autenticación JWT</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">// middleware/auth.js
import jwt from "jsonwebtoken";

export const requireAuth = (req, res, next) => {
    const token = req.headers.authorization?.split(" ")[1];
    if (!token) return res.status(401).json({ error: "No autenticado" });
    try {
        req.user = jwt.verify(token, process.env.JWT_SECRET);
        next();
    } catch {
        res.status(401).json({ error: "Token inválido o expirado" });
    }
};</pre>
<h3>Validación con Zod</h3>
<pre style="background:#f4f4f4;padding:12px;border-radius:4px;">import { z } from "zod";

const CursoSchema = z.object({
    nombre:   z.string().min(3).max(100),
    creditos: z.number().int().min(1).max(6),
    docenteId: z.number().int().positive(),
});

// En el router:
app.post("/api/cursos", requireAuth, async (req, res) => {
    const parsed = CursoSchema.safeParse(req.body);
    if (!parsed.success) return res.status(400).json(parsed.error.flatten());
    const curso = await prisma.curso.create({ data: parsed.data });
    res.status(201).json(curso);
});</pre>',
            'fBNz5xF-Kx4', 'Node.js Crash Course — Traversy Media');
        add_url($courseid,  5, "Express.js — Documentación en Español",
            "https://expressjs.com/es/", "Framework minimalista para Node.js");
        add_url($courseid,  5, "JWT.io — Debugger de Tokens",
            "https://jwt.io/", "Decodifica y verifica JSON Web Tokens");
        add_assign($courseid, 5, "Proyecto 5: API REST con Node.js y Express",
            "<p>Desarrolla una API REST completa para el sistema SWARD:</p>
            <pre>POST /api/auth/register  — registrar usuario
POST /api/auth/login     — login, retorna JWT
GET  /api/cursos         — listar cursos (público)
GET  /api/cursos/:id     — detalle de curso
POST /api/cursos         — crear curso (requiere auth admin)
GET  /api/me/cursos      — mis cursos (requiere auth)
POST /api/me/matricula   — matricularse en curso</pre>
            <p><strong>Requisitos:</strong> validación con Joi/Zod, JWT auth, manejo de errores centralizado, variables de entorno (.env), tests con Supertest, documentación con Swagger.</p>", 6);

        echo "  [Proyecto Final]\n";
        add_assign($courseid, 7, "Proyecto Final: Aplicación Web Full-Stack Desplegada",
            "<h3>Construye y despliega una aplicación web completa</h3>
            <h4>Requisitos técnicos</h4>
            <ul>
            <li>Frontend React desplegado en Vercel</li>
            <li>API Express desplegada en Render</li>
            <li>Base de datos PostgreSQL en Neon.tech (gratuito)</li>
            <li>Autenticación JWT con refresh tokens</li>
            <li>Diseño responsivo y accesible (WCAG AA básico)</li>
            <li>CI/CD con GitHub Actions</li>
            </ul>
            <h4>Ideas de proyecto</h4>
            <ul>
            <li>Plataforma de gestión de aprendizaje (tu propio SWARD)</li>
            <li>Sistema de reservas (salas, tutorías, recursos)</li>
            <li>Red social académica para compartir apuntes</li>
            <li>Marketplace de servicios universitarios</li>
            </ul>
            <h4>Criterios (100 puntos)</h4>
            <ul>
            <li>Funcionalidad completa y desplegada (35)</li>
            <li>Código limpio y buenas prácticas (25)</li>
            <li>UX/UI responsivo (20)</li>
            <li>CI/CD y despliegue (10)</li>
            <li>Presentación y demo en vivo (10)</li>
            </ul>", 15);
        add_forum($courseid, 7, "Foro: Proyecto Final — Anuncios y Equipos",
            "<p>Forma tu equipo (2-3 personas) y publica aquí los integrantes y la idea de proyecto para aprobación del docente.</p>");
    }
}

echo "\n╔══════════════════════════════════════════════╗\n";
echo "║  ✅ Campus Virtual SWARD — COMPLETADO        ║\n";
echo "╠══════════════════════════════════════════════╣\n";
echo "║  4 cursos con contenido real y completo     ║\n";
echo "║  28+ actividades y recursos creados         ║\n";
echo "║  Páginas HTML, URLs, Tareas, Foros, Quizzes ║\n";
echo "╚══════════════════════════════════════════════╝\n\n";
