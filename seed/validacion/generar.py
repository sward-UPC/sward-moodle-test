"""Genera, desde banco.py, lo que necesitan Moodle y el profesor.

    python seed/validacion/generar.py [carpeta del documento de revisión]

- seed/validacion/salida/cursos.json: cursos, temas, páginas y, por quiz, sus
  preguntas en formato GIFT. Lo consume cargar_cursos.php.
- revision_banco.html: todo el material y las preguntas con sus respuestas,
  para que el profesor lo revise. Se convierte a Word aparte.

Antes de escribir nada valida el banco: 6 temas por curso, 3 quizzes de 4
preguntas por tema, 3 distractores distintos de la respuesta, números finitos y
nombres de tema únicos entre cursos (son las claves del modelo).
"""

import html
import json
import math
import sys
from pathlib import Path

AQUI = Path(__file__).resolve().parent
sys.path.insert(0, str(AQUI))
from banco import CURSOS, NIVELES  # noqa: E402

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


def generar_json() -> Path:
    cursos = []
    for curso in CURSOS:
        temas = []
        for t in curso["temas"]:
            temas.append({
                "tema": t["tema"],
                "paginas": [
                    {"nombre": f"Resumen — {t['tema']}", "contenido": t["resumen"].strip()},
                    {"nombre": f"Ejemplo resuelto — {t['tema']}", "contenido": t["ejemplo"].strip()},
                ],
                "quizzes": [
                    {"nombre": nombre_quiz(t["tema"], k), "nivel": NIVELES[k - 1],
                     "gift": gift_quiz(nombre_quiz(t["tema"], k), quiz)}
                    for k, quiz in enumerate(t["quizzes"], 1)
                ],
            })
        cursos.append({"nombre": curso["nombre"], "corto": curso["corto"],
                       "descripcion": curso["descripcion"], "temas": temas})
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
        "ejemplo resuelto y los tres quizzes (básico, intermedio y aplicado), cada uno de cuatro preguntas "
        "autocalificadas, con la respuesta correcta marcada.</p>"
        "<p><strong>Qué revisar:</strong> que el enunciado sea claro, que la respuesta sea correcta, que el nivel "
        "corresponda a sus estudiantes y que los distractores sean plausibles. Puede corregir directamente en este "
        "documento. Los <strong>nombres de los temas</strong> no deben cambiar una vez que los estudiantes empiecen, "
        "porque el sistema los usa para identificar cada tema.</p>"
        "<p><strong>Condiciones de los quizzes:</strong> un solo intento, calificación sobre 10, sin límite de tiempo "
        "dentro del plazo de la fase 1. No cuentan para la nota del curso. En las preguntas numéricas se acepta un "
        "pequeño margen por redondeo, indicado junto a cada respuesta. Se usa año comercial de 360 días.</p>")
    for curso in CURSOS:
        partes.append(f"<h2>{e(curso['nombre'])}</h2>")
        for n, t in enumerate(curso["temas"], 1):
            partes.append(f"<h3>Tema {n}. {e(t['tema'])}</h3>")
            partes.append("<h4>Resumen</h4><div class='caja'>" + t["resumen"] + "</div>")
            partes.append("<h4>Ejemplo resuelto</h4><div class='caja'>" + t["ejemplo"] + "</div>")
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
