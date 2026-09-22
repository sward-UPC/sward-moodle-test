"""Genera, desde banco.py, lo que necesitan Moodle y el profesor.

    python seed/validacion/generar.py [carpeta del documento de revisión]

- seed/validacion/salida/cursos.json: cursos, temas y, por tema, sus páginas,
  el video, la práctica guiada con su solución, el recurso externo y los quizzes
  con sus preguntas en formato GIFT; por curso, el foro de dudas. Lo consume
  cargar_cursos.php.
- revision_banco.html: todo el material y las preguntas con sus respuestas,
  para que el profesor lo revise. Se convierte a Word aparte.

Antes de escribir nada valida el banco: 6 temas por curso, 3 quizzes de 4
preguntas por tema, 3 distractores distintos de la respuesta, números finitos y
nombres de tema únicos entre cursos (son las claves del modelo), y una práctica
por tema. Los videos y recursos externos (recursos.py) son opcionales: solo se
cargan los verificados.
"""

import base64
import html
import json
import math
import re
import sys
from pathlib import Path

AQUI = Path(__file__).resolve().parent
sys.path.insert(0, str(AQUI))
from banco import CURSOS, NIVELES  # noqa: E402
from practicas import PRACTICAS  # noqa: E402

try:
    from recursos import RECURSOS  # noqa: E402
except ImportError:  # aún sin videos ni recursos verificados
    RECURSOS = {}

IMAGENES = AQUI / "imagenes"

# Presentación de cada curso (sección General): el recorrido de un tema, qué
# cuenta y a quién preguntar. Es lo primero que ve el estudiante al entrar.
PRESENTACION = """
<p>{intro} Cada tema tiene el mismo recorrido:</p>
<ol>
<li><strong>Lee el resumen</strong> y, si quieres, mira el video.</li>
<li><strong>Estudia el ejemplo resuelto</strong>, paso a paso.</li>
<li><strong>Haz la práctica guiada</strong>: al entregarla se abre la solución para que compares.</li>
<li><strong>Rinde los tres quizzes</strong> (básico, intermedio y aplicado): cuatro preguntas, un solo intento, unos cinco minutos cada uno.</li>
</ol>
<p><strong>Para el estudio cuentan los quizzes</strong>, y te pedimos que los hagas todos: son 18 en este curso, de cinco a ocho minutos cada uno. Puedes repartirlos en varios días. No afectan tu nota del curso: nos interesa cómo aprendes, no cuánto sabes hoy. Resuélvelos por tu cuenta, sin buscar las respuestas.</p>
<p>El video, la práctica y el recurso para practicar más son opcionales: están por si quieres reforzar el tema.</p>
<p>En las preguntas numéricas escribe solo el número, sin símbolos ni separador de miles, con punto decimal (por ejemplo, 1234.56).</p>
<p>¿Dudas? Escríbelas en el foro <em>Dudas del curso</em>.</p>
"""
INTRO_CURSO = {
    "SWARD-EST": "En este curso repasarás seis temas de estadística, de la descripción de datos a las pruebas de hipótesis.",
    "SWARD-MF": "En este curso repasarás seis temas de matemática financiera, del interés simple a la amortización de préstamos.",
}
RESUMEN_CURSO = {
    "SWARD-EST": "Seis temas de estadística descriptiva e inferencial. Cada uno con resumen, video, ejemplo resuelto, práctica guiada y tres quizzes.",
    "SWARD-MF": "Seis temas de matemática financiera: interés, tasas, anualidades, VAN y TIR, y amortización. Cada uno con resumen, video, ejemplo resuelto, práctica guiada y tres quizzes.",
}


def minutos_de_lectura(html_texto: str) -> int:
    """Minutos de lectura a 150 palabras por minuto (texto técnico), mínimo 2."""
    palabras = len(re.sub(r"<[^>]+>", " ", html_texto).split())
    return max(2, round(palabras / 150))


# Guía del profesor: una página oculta a los estudiantes, dentro del curso, con
# lo que necesita saber para acompañar la fase 1 sin romper nada.
GUIA_DOCENTE = {
    "nombre": "Guía para el profesor (no la ven los estudiantes)",
    "contenido": """
<p>Esta página solo la ve usted y el equipo del proyecto. Los estudiantes no la ven.</p>

<h4>Qué hacen sus estudiantes</h4>
<p>Cada tema tiene un resumen, un ejemplo resuelto y tres quizzes de cuatro preguntas (básico, intermedio y aplicado), de un solo intento y calificados sobre 20 (cada pregunta vale 5). <strong>Los quizzes no afectan la nota de su curso</strong>: son los datos con que el sistema aprende. El video, la práctica guiada y el recurso externo son opcionales.</p>

<h4>Dónde ver el avance</h4>
<ul>
<li><strong>Informes → Finalización de la actividad</strong>: una tabla de estudiantes por actividad, con una marca por cada cosa completada. Es la vista más rápida para saber quién va al día. Son 36 columnas; en «Incluir» elija <strong>Quizzes</strong> y se queda con las 18 que importan.</li>
<li><strong>Calificaciones</strong>: las notas de los 18 quizzes del curso, con el promedio por quiz al final. Se descarga en Excel.</li>
<li>Dentro de un quiz, <strong>Resultados</strong>: quién lo rindió, con qué nota y cuánto demoró.</li>
</ul>

<h4>Dos cosas que le pedimos</h4>
<ol>
<li><strong>Suscríbase al foro «Dudas del curso»</strong> (entre al foro y pulse «Suscribirse a este foro»), para recibir por correo lo que pregunten sus estudiantes. Está en esta misma sección.</li>
<li><strong>Si encuentra un error en una pregunta, anótelo en el documento de revisión que le enviamos</strong> y nos avisa. Su cuenta ve el curso pero no lo modifica, y es a propósito: el material se genera desde el proyecto, así que una edición hecha aquí se perdería en la siguiente carga, y cambiar una pregunta después de que alguien la rindió alteraría los datos del estudio.</li>
</ol>

<h4>Lo que no hay que cambiar</h4>
<p>Los <strong>nombres de los temas</strong> (las secciones del curso) son la clave con que el sistema identifica cada tema: si se renombran después de que empiecen los estudiantes, el modelo deja de reconocerlos.</p>

<p>Por la misma razón, su cuenta no tiene el modo de edición ni las opciones de reiniciar, restaurar o importar el curso: cualquiera de ellas podría borrar los intentos de sus estudiantes, que son los datos del estudio. Si necesita agregar algo al curso, escríbanos y lo hacemos nosotros.</p>

<p>Cualquier duda, escríbanos: el contacto del proyecto está en el consentimiento informado que firmaron los participantes.</p>
""",
}

FORO = {
    "nombre": "Dudas del curso",
    "intro": ("<p>Escribe aquí tus dudas sobre los temas o los quizzes; el profesor y tus compañeros pueden "
              "responder. Para recibir las respuestas por correo, suscríbete al foro.</p>"),
}

SALIDA = AQUI / "salida"


def validar() -> None:
    errores = []
    temas_vistos = set()
    for curso in CURSOS:
        if len(curso["temas"]) != 6:
            errores.append(f"{curso['nombre']}: {len(curso['temas'])} temas")
        for t in curso["temas"]:
            if t["tema"] in temas_vistos:
                errores.append(f"tema repetido entre cursos: {t['tema']}")
            temas_vistos.add(t["tema"])
            if t["tema"] not in PRACTICAS:
                errores.append(f"{t['tema']}: sin práctica guiada")
            if len(t["quizzes"]) != 3:
                errores.append(f"{t['tema']}: {len(t['quizzes'])} quizzes")
            for k, quiz in enumerate(t["quizzes"], 1):
                if len(quiz) != 4:
                    errores.append(f"{t['tema']} quiz {k}: {len(quiz)} preguntas")
                for j, q in enumerate(quiz, 1):
                    donde = f"{t['tema']} quiz {k} pregunta {j}"
                    if q["tipo"] == "numero":
                        if not math.isfinite(q["respuesta"]):
                            errores.append(f"{donde}: respuesta no finita")
                    else:
                        d = q["distractores"]
                        if len(d) != 3 or q["correcta"] in d or len(set(d)) != 3:
                            errores.append(f"{donde}: distractores inválidos")
    if errores:
        sys.exit("Banco inválido:\n  " + "\n  ".join(errores))


def tolerancia(q) -> float:
    if q["tol"] is not None:
        return q["tol"]
    # Margen para quien redondea factores intermedios: 0.2 % del valor, mínimo 0.01.
    return round(max(0.01, abs(q["respuesta"]) * 0.002), 2)


def formato_num(x: float) -> str:
    texto = f"{x:.4f}".rstrip("0").rstrip(".")
    return texto if texto not in ("-0", "") else "0"


def gift_escape(texto: str) -> str:
    # Las preguntas van en formato HTML: «<» y «>» (como en «μ < μ0») se leerían
    # como etiquetas si no se escapan antes de las reglas propias de GIFT.
    texto = html.escape(texto, quote=False)
    for c in "\\~=#{}:":
        texto = texto.replace(c, "\\" + c)
    return texto.replace("\n", " ")


def gift_quiz(nombre_quiz: str, quiz: list) -> str:
    bloques = []
    for j, q in enumerate(quiz, 1):
        titulo = gift_escape(f"{nombre_quiz} — P{j}")
        enunciado = gift_escape(q["enunciado"])
        if q["tipo"] == "numero":
            cuerpo = f"{{#{formato_num(q['respuesta'])}:{formato_num(tolerancia(q))}}}"
        else:
            opciones = [f"={gift_escape(q['correcta'])}"] + [f"~{gift_escape(d)}" for d in q["distractores"]]
            cuerpo = "{" + " ".join(opciones) + "}"
        bloques.append(f"::{titulo}:: [html]{enunciado} {cuerpo}")
    return "\n\n".join(bloques) + "\n"


def nombre_quiz(tema: str, k: int) -> str:
    return f"Quiz {k} — {tema}"


def verificado(tema: str, clave: str) -> dict | None:
    r = RECURSOS.get(tema, {}).get(clave)
    return r if r and r.get("verificado") and r.get("url") else None


def generar_json() -> Path:
    cursos = []
    for curso in CURSOS:
        temas = []
        for t in curso["temas"]:
            tema = t["tema"]
            video = verificado(tema, "video")
            recurso = verificado(tema, "recurso")
            temas.append({
                "tema": tema,
                # Orden de la sección: leer, ver, estudiar un caso, practicar, evaluarse,
                # y material extra al final. cargar_cursos.php lo respeta.
                # «descripcion» es la línea que Moodle muestra bajo cada actividad:
                # formato, duración y si es opcional, como en un curso en línea.
                "paginas": [
                    {"nombre": f"Resumen — {tema}", "contenido": t["resumen"].strip(),
                     "descripcion": f"Lectura · {minutos_de_lectura(t['resumen'])} min"},
                    {"nombre": f"Ejemplo resuelto — {tema}", "contenido": t["ejemplo"].strip(),
                     "descripcion": f"Lectura · {minutos_de_lectura(t['ejemplo'])} min · un problema resuelto paso a paso"},
                ],
                "video": {
                    "nombre": f"Video — {tema}", "url": video["url"],
                    "intro": (f"<p>Opcional, para reforzar el resumen: «{html.escape(video['titulo'])}», "
                              f"de {html.escape(video['canal'])}.</p>"),
                    "descripcion": f"Video · opcional · {html.escape(video['canal'])}",
                } if video else None,
                "practica": {
                    "nombre": f"Práctica guiada — {tema}",
                    "enunciado": PRACTICAS[tema]["enunciado"].strip(),
                    "descripcion": "Práctica · recomendada · sin nota · unos 15 min",
                    "solucion_nombre": f"Solución — Práctica guiada — {tema}",
                    "solucion": PRACTICAS[tema]["solucion"].strip(),
                    "solucion_descripcion": "Se abre al entregar la práctica guiada",
                },
                "quizzes": [
                    {"nombre": nombre_quiz(tema, k), "nivel": NIVELES[k - 1],
                     # El aviso de los decimales va también aquí, corto: el separador de
                     # miles rompe la corrección («1.234,56» se lee como 1.23456). La
                     # regla completa está en cada pregunta y en la presentación.
                     "descripcion": (f"Quiz {NIVELES[k - 1]} · 4 preguntas · 1 intento · unos 5 min · "
                                     "no afecta tu nota · decimales con punto: 1234.56"),
                     "gift": gift_quiz(nombre_quiz(tema, k), quiz)}
                    for k, quiz in enumerate(t["quizzes"], 1)
                ],
                "recurso": {
                    "nombre": f"Para practicar más — {tema}", "url": recurso["url"],
                    "intro": f"<p>{html.escape(recurso['descripcion'])}</p>",
                    "descripcion": ("Opcional · interactivo · " + html.escape(recurso.get("fuente", ""))
                                    + (" · en inglés" if recurso.get("idioma") == "en" else "")),
                } if recurso else None,
            })
        imagen = IMAGENES / f"{curso['corto']}.png"
        cursos.append({
            "nombre": curso["nombre"], "corto": curso["corto"],
            "descripcion": RESUMEN_CURSO.get(curso["corto"], curso["descripcion"]),
            "presentacion": PRESENTACION.format(intro=INTRO_CURSO.get(curso["corto"], "")).strip(),
            "imagen_png": base64.b64encode(imagen.read_bytes()).decode() if imagen.exists() else None,
            "foro": FORO, "guia_docente": GUIA_DOCENTE, "temas": temas,
        })
    SALIDA.mkdir(exist_ok=True)
    ruta = SALIDA / "cursos.json"
    ruta.write_text(json.dumps(cursos, ensure_ascii=False, indent=1), encoding="utf-8")
    return ruta


def respuesta_legible(q) -> str:
    if q["tipo"] == "numero":
        return f"{formato_num(q['respuesta'])} (se acepta ± {formato_num(tolerancia(q))})"
    return q["correcta"]


def generar_revision(destino: Path) -> Path:
    e = html.escape
    partes = ["""<html><head><meta charset="utf-8"><title>Banco de preguntas</title>
<style>
body{font-family:Calibri,Arial,sans-serif;font-size:11pt;line-height:1.35}
h1{font-size:18pt} h2{font-size:15pt;margin-top:24pt} h3{font-size:12.5pt;margin-top:14pt}
h4{font-size:11pt;margin:10pt 0 4pt} .caja{border:1px solid #bbb;padding:6pt 9pt;margin:4pt 0 8pt}
table{border-collapse:collapse;width:100%} td,th{border:1px solid #999;padding:3pt 5pt;vertical-align:top}
th{background:#e8e8e8} .ok{color:#1a6b3a;font-weight:bold}
</style></head><body>"""]
    partes.append("<h1>Proyecto SWARD — Material y banco de preguntas para la validación</h1>")
    partes.append(
        "<p>Documento para la revisión del profesor. Contiene, para cada curso, los seis temas con su resumen, su "
        "ejemplo resuelto, el video sugerido, la práctica guiada con su solución, el recurso externo para practicar "
        "más y los tres quizzes (básico, intermedio y aplicado), cada uno de cuatro preguntas autocalificadas, con la "
        "respuesta correcta marcada. Además, cada curso tiene un foro de dudas.</p>"
        "<p><strong>Solo cuentan para el estudio los quizzes.</strong> El video, la práctica y el recurso externo son "
        "opcionales para el estudiante: la práctica no tiene nota y su solución se abre al entregarla.</p>"
        "<p><strong>Qué revisar:</strong> que el enunciado sea claro, que la respuesta sea correcta, que el nivel "
        "corresponda a sus estudiantes y que los distractores sean plausibles. Puede corregir directamente en este "
        "documento. Los <strong>nombres de los temas</strong> no deben cambiar una vez que los estudiantes empiecen, "
        "porque el sistema los usa para identificar cada tema.</p>"
        "<p><strong>Condiciones de los quizzes:</strong> un solo intento, calificación sobre 20, sin límite de tiempo "
        "dentro del plazo de la fase 1. No cuentan para la nota del curso. En las preguntas numéricas se acepta un "
        "pequeño margen por redondeo, indicado junto a cada respuesta. Se usa año comercial de 360 días.</p>")
    for curso in CURSOS:
        partes.append(f"<h2>{e(curso['nombre'])}</h2>")
        for n, t in enumerate(curso["temas"], 1):
            partes.append(f"<h3>Tema {n}. {e(t['tema'])}</h3>")
            partes.append("<h4>Resumen</h4><div class='caja'>" + t["resumen"] + "</div>")
            partes.append("<h4>Ejemplo resuelto</h4><div class='caja'>" + t["ejemplo"] + "</div>")
            video = verificado(t["tema"], "video")
            partes.append("<h4>Video sugerido</h4><div class='caja'>" + (
                f"<p>{e(video['titulo'])} — {e(video['canal'])}<br>{e(video['url'])}</p>" if video
                else "<p>(Sin video verificado todavía.)</p>") + "</div>")
            partes.append("<h4>Práctica guiada</h4><div class='caja'>" + PRACTICAS[t["tema"]]["enunciado"]
                          + "</div><h4>Solución de la práctica</h4><div class='caja'>"
                          + PRACTICAS[t["tema"]]["solucion"] + "</div>")
            recurso = verificado(t["tema"], "recurso")
            partes.append("<h4>Para practicar más (recurso externo)</h4><div class='caja'>" + (
                f"<p>{e(recurso['titulo'])}<br>{e(recurso['url'])}<br>{e(recurso['descripcion'])}</p>" if recurso
                else "<p>(Sin recurso verificado todavía.)</p>") + "</div>")
            for k, quiz in enumerate(t["quizzes"], 1):
                partes.append(f"<h4>{e(nombre_quiz(t['tema'], k))} ({NIVELES[k - 1]})</h4>")
                partes.append("<table><tr><th>#</th><th>Pregunta</th><th>Opciones</th><th>Respuesta correcta</th>"
                              "<th>Observaciones del profesor</th></tr>")
                for j, q in enumerate(quiz, 1):
                    if q["tipo"] == "numero":
                        opciones = "Respuesta numérica"
                    else:
                        todas = [q["correcta"]] + q["distractores"]
                        opciones = "<br>".join(e(o) for o in todas)
                    partes.append(f"<tr><td>{j}</td><td>{e(q['enunciado'])}</td><td>{opciones}</td>"
                                  f"<td class='ok'>{e(respuesta_legible(q))}</td><td>&nbsp;</td></tr>")
                partes.append("</table>")
    partes.append("</body></html>")
    destino.mkdir(parents=True, exist_ok=True)
    ruta = destino / "revision_banco.html"
    ruta.write_text("\n".join(partes), encoding="utf-8")
    return ruta


if __name__ == "__main__":
    validar()
    total = sum(len(q) for c in CURSOS for t in c["temas"] for q in t["quizzes"])
    print(f"Banco válido: {len(CURSOS)} cursos, {sum(len(c['temas']) for c in CURSOS)} temas, "
          f"{sum(len(t['quizzes']) for c in CURSOS for t in c['temas'])} quizzes, {total} preguntas")
    print("JSON:", generar_json())
    destino = Path(sys.argv[1]) if len(sys.argv) > 1 else SALIDA
    print("Revisión:", generar_revision(destino))
