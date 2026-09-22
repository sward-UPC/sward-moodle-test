#!/usr/bin/env python3
"""Crea en Moodle las cuentas de los participantes inscritos por formulario.

Lee el CSV que exporta Google Forms, crea a cada persona en Moodle y la matricula
en los cursos de la validación. Pensado para participantes externos: no hace falta
que estén en el Moodle de su universidad, basta con que estén en este.

Por defecto **no cambia nada**: muestra lo que haría. Para aplicarlo, `--aplicar`.

Uso:
    python seed/crear_participantes.py respuestas.csv --curso SWARD-EST,SWARD-MF
    python seed/crear_participantes.py respuestas.csv --curso SWARD-EST,SWARD-MF --aplicar
    python seed/crear_participantes.py respuestas.csv --curso SWARD-EST,SWARD-MF --aplicar \\
        --docente "profesor@universidad.edu.pe;Nombres;Apellidos"

Dos formas de entregar la contraseña:

- **Por defecto, la envía Moodle.** Cada cuenta se crea sin contraseña conocida y
  Moodle genera una y la manda al correo de la persona, que debe cambiarla al
  entrar. Nadie guarda una lista de contraseñas. Requiere que Moodle tenga
  configurado el correo de salida (Administración del sitio → Servidor → Correo
  saliente); si no lo tiene, las personas no reciben nada.
- **`--contrasena-temporal`**, si Moodle no puede enviar correo. Genera una
  contraseña aleatoria por persona, obliga a cambiarla en el primer ingreso y las
  deja en `contrasenas_temporales.csv` para entregarlas una por una. Ese archivo
  se borra en cuanto se hayan entregado.

Lo que se detecta y se informa, sin detenerse:

- Respuestas sin el consentimiento aceptado: se omiten.
- La misma persona inscrita dos veces: se usa su última respuesta.
- Correos que ya tienen cuenta: no se duplican; solo se matriculan si falta.

Necesita `seed/.env` con MOODLE_URL y MOODLE_TOKEN, igual que `seed.sh`. Los CSV
con datos de personas no se suben al repositorio (ver `.gitignore`).
"""

from __future__ import annotations

import argparse
import csv
import json
import re
import secrets
import string
import sys
import unicodedata
import urllib.parse
import urllib.request
from dataclasses import dataclass
from pathlib import Path

# Profesor sin permiso de edición: publica avisos, responde el foro y ve notas,
# reportes y la guía, pero no puede mover, editar ni borrar el contenido. El
# banco de preguntas se genera desde el proyecto (banco.py) y una edición manual
# se perdería en la siguiente carga, además de alterar los datos del estudio.
ROL_DOCENTE = 4  # teacher (sin edición)
ROL_ESTUDIANTE = 5  # student

AQUI = Path(__file__).resolve().parent


# ----------------------------------------------------------------- configuración
def leer_env(ruta: Path) -> dict[str, str]:
    if not ruta.exists():
        sys.exit(f"No encuentro {ruta}. Copia seed/.env.example y completa el token.")
    valores: dict[str, str] = {}
    for linea in ruta.read_text(encoding="utf-8").splitlines():
        linea = linea.strip()
        if linea and not linea.startswith("#") and "=" in linea:
            clave, valor = linea.split("=", 1)
            valores[clave.strip()] = valor.strip().strip('"').strip("'")
    for clave in ("MOODLE_URL", "MOODLE_TOKEN"):
        if not valores.get(clave):
            sys.exit(f"Falta {clave} en {ruta}.")
    return valores


class Moodle:
    def __init__(self, url: str, token: str) -> None:
        self._punto = url.rstrip("/") + "/webservice/rest/server.php"
        self._token = token

    def llamar(self, funcion: str, **parametros: str) -> object:
        datos = {"wstoken": self._token, "wsfunction": funcion,
                 "moodlewsrestformat": "json", **parametros}
        cuerpo = urllib.parse.urlencode(datos).encode()
        with urllib.request.urlopen(self._punto, data=cuerpo, timeout=30) as r:
            respuesta = json.loads(r.read().decode("utf-8"))
        if isinstance(respuesta, dict) and "exception" in respuesta:
            raise RuntimeError(f"{funcion}: {respuesta.get('message')}")
        return respuesta

    def usuario_por_correo(self, correo: str) -> dict | None:
        encontrados = self.llamar("core_user_get_users_by_field",
                                  field="email", **{"values[0]": correo})
        return encontrados[0] if encontrados else None

    def usuario_existe(self, username: str) -> bool:
        return bool(self.llamar("core_user_get_users_by_field",
                                field="username", **{"values[0]": username}))

    def curso(self, nombre_corto: str) -> dict:
        r = self.llamar("core_course_get_courses_by_field",
                        field="shortname", value=nombre_corto)
        cursos = r.get("courses", []) if isinstance(r, dict) else []
        if not cursos:
            sys.exit(f"No existe en Moodle un curso con nombre corto «{nombre_corto}».")
        return cursos[0]

    def matriculados(self, curso_id: int) -> set[int]:
        usuarios = self.llamar("core_enrol_get_enrolled_users", courseid=str(curso_id))
        return {u["id"] for u in usuarios}

    def crear(self, p: "Persona", username: str, contrasena: str | None) -> int:
        campos = {
            "users[0][username]": username,
            "users[0][firstname]": p.nombres,
            "users[0][lastname]": p.apellidos,
            "users[0][email]": p.correo,
            "users[0][auth]": "manual",
            # Que cambie la contraseña al entrar, la haya generado Moodle o este script.
            "users[0][preferences][0][type]": "auth_forcepasswordchange",
            "users[0][preferences][0][value]": "1",
        }
        if contrasena is None:
            campos["users[0][createpassword]"] = "1"
        else:
            campos["users[0][password]"] = contrasena
        creado = self.llamar("core_user_create_users", **campos)
        return creado[0]["id"]

    def matricular(self, usuario_id: int, curso_id: int, rol: int) -> None:
        self.llamar("enrol_manual_enrol_users", **{
            "enrolments[0][roleid]": str(rol),
            "enrolments[0][userid]": str(usuario_id),
            "enrolments[0][courseid]": str(curso_id),
        })


# --------------------------------------------------------------- lectura del CSV
@dataclass
class Persona:
    correo: str
    nombres: str
    apellidos: str
    carrera: str = ""
    docente: bool = False


def _normalizar(texto: str) -> str:
    sin_tildes = unicodedata.normalize("NFKD", texto).encode("ascii", "ignore").decode()
    return sin_tildes.lower().strip()


def _columna(encabezados: list[str], *claves: str) -> str | None:
    for encabezado in encabezados:
        if any(clave in _normalizar(encabezado) for clave in claves):
            return encabezado
    return None


ACEPTA = ("si", "acepto", "he leido", "de acuerdo", "true", "x")


def leer_respuestas(ruta: Path) -> tuple[list[Persona], list[str]]:
    """Devuelve las personas con consentimiento y los avisos de lo omitido."""
    # utf-8-sig: Google Forms exporta con BOM.
    with ruta.open(encoding="utf-8-sig", newline="") as f:
        filas = list(csv.DictReader(f))
    if not filas:
        sys.exit(f"{ruta} no tiene respuestas.")

    encabezados = list(filas[0].keys())
    c_correo = _columna(encabezados, "correo", "email", "e-mail")
    c_nombres = _columna(encabezados, "nombre")
    c_apellidos = _columna(encabezados, "apellido")
    c_carrera = _columna(encabezados, "carrera")
    c_consentimiento = _columna(encabezados, "consentimiento", "acepto")

    faltan = [n for n, c in (("correo", c_correo), ("nombres", c_nombres),
                             ("apellidos", c_apellidos),
                             ("consentimiento", c_consentimiento)) if c is None]
    if faltan:
        sys.exit("No encuentro estas columnas en el CSV: " + ", ".join(faltan)
                 + f"\nEncabezados leídos: {encabezados}")

    avisos: list[str] = []
    por_correo: dict[str, Persona] = {}
    for n, fila in enumerate(filas, start=2):  # la fila 1 son los encabezados
        correo = (fila.get(c_correo) or "").strip().lower()
        if not re.fullmatch(r"[^\s@]+@[^\s@]+\.[^\s@]+", correo):
            avisos.append(f"fila {n}: correo inválido «{correo}», se omite")
            continue
        if not any(a in _normalizar(fila.get(c_consentimiento) or "") for a in ACEPTA):
            avisos.append(f"fila {n}: {correo} no aceptó el consentimiento, se omite")
            continue
        if correo in por_correo:
            avisos.append(f"fila {n}: {correo} ya estaba inscrito, se usa esta respuesta")
        por_correo[correo] = Persona(
            correo=correo,
            nombres=(fila.get(c_nombres) or "").strip().title(),
            apellidos=(fila.get(c_apellidos) or "").strip().title(),
            carrera=(fila.get(c_carrera) or "").strip() if c_carrera else "",
        )
    return list(por_correo.values()), avisos


# ------------------------------------------------------------------- utilidades
def nombre_de_usuario(correo: str, ocupado) -> str:
    """Parte local del correo, en minúsculas y sin caracteres que Moodle rechace."""
    base = _normalizar(correo.split("@")[0])
    base = re.sub(r"[^a-z0-9._-]", "", base) or "participante"
    candidato, n = base, 2
    while ocupado(candidato):
        candidato, n = f"{base}{n}", n + 1
    return candidato


def contrasena_aleatoria() -> str:
    # Cumple la política por defecto de Moodle: mayúscula, minúscula, dígito y símbolo.
    alfabeto = string.ascii_letters + string.digits
    cuerpo = "".join(secrets.choice(alfabeto) for _ in range(10))
    return (secrets.choice(string.ascii_uppercase) + secrets.choice(string.ascii_lowercase)
            + secrets.choice(string.digits) + "#" + cuerpo)


def leer_docente(valor: str) -> Persona:
    partes = [p.strip() for p in valor.split(";")]
    if len(partes) != 3 or "@" not in partes[0]:
        sys.exit('--docente va como "correo;Nombres;Apellidos"')
    return Persona(correo=partes[0].lower(), nombres=partes[1], apellidos=partes[2],
                   docente=True)


# ------------------------------------------------------------------------ main
def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("csv", type=Path, help="respuestas exportadas de Google Forms")
    ap.add_argument("--curso", required=True,
                    help="nombres cortos de los cursos en Moodle, separados por coma")
    ap.add_argument("--docente", help='"correo;Nombres;Apellidos" del profesor')
    ap.add_argument("--aplicar", action="store_true", help="crear y matricular de verdad")
    ap.add_argument("--contrasena-temporal", action="store_true",
                    help="generar contraseñas en vez de que Moodle las envíe")
    ap.add_argument("--env", type=Path, default=AQUI / ".env")
    args = ap.parse_args()

    env = leer_env(args.env)
    moodle = Moodle(env["MOODLE_URL"], env["MOODLE_TOKEN"])

    personas, avisos = leer_respuestas(args.csv)
    if args.docente:
        personas.insert(0, leer_docente(args.docente))

    cortos = [c.strip() for c in args.curso.split(",") if c.strip()]
    cursos = [moodle.curso(c) for c in cortos]
    ya_matriculados = {c["id"]: moodle.matriculados(c["id"]) for c in cursos}

    modo = "APLICANDO" if args.aplicar else "SIMULACIÓN (nada se cambia; usa --aplicar)"
    print(f"\n{modo}")
    for c, corto in zip(cursos, cortos):
        print(f"Curso: {c['fullname']} ({corto})")
    print(f"Personas a procesar: {len(personas)}\n")
    for aviso in avisos:
        print(f"  aviso  {aviso}")
    if avisos:
        print()

    resultado: list[dict[str, str]] = []
    temporales: list[dict[str, str]] = []
    nuevos_usernames: set[str] = set()

    for p in personas:
        rol = ROL_DOCENTE if p.docente else ROL_ESTUDIANTE
        etiqueta = "docente" if p.docente else "estudiante"
        existente = moodle.usuario_por_correo(p.correo)

        if existente:
            usuario_id, username, estado = existente["id"], existente["username"], "ya existía"
        else:
            username = nombre_de_usuario(
                p.correo, lambda u: u in nuevos_usernames or moodle.usuario_existe(u))
            nuevos_usernames.add(username)
            estado, usuario_id = "se crea", None
            if args.aplicar:
                clave = contrasena_aleatoria() if args.contrasena_temporal else None
                usuario_id = moodle.crear(p, username, clave)
                estado = "creado"
                if clave:
                    temporales.append({"correo": p.correo, "usuario": username,
                                       "contrasena_temporal": clave})

        estados = []
        for c, corto in zip(cursos, cortos):
            if usuario_id is not None and usuario_id in ya_matriculados[c["id"]]:
                estados.append(f"{corto}: ya matriculado")
            elif args.aplicar and usuario_id is not None:
                moodle.matricular(usuario_id, c["id"], rol)
                estados.append(f"{corto}: matriculado")
            else:
                estados.append(f"{corto}: se matricula")
        matricula = f"{etiqueta} · " + ", ".join(estados)

        print(f"  {p.correo:<38} {username:<22} {estado:<11} {matricula}")
        resultado.append({"correo": p.correo, "usuario": username, "rol": etiqueta,
                          "carrera": p.carrera, "cuenta": estado, "matricula": matricula})

    if not args.aplicar:
        print("\nNo se cambió nada. Revisa la lista y repite con --aplicar.")
        return

    salida = args.csv.with_name("resultado_inscripcion.csv")
    with salida.open("w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=list(resultado[0].keys()))
        w.writeheader()
        w.writerows(resultado)
    print(f"\nResultado en {salida}")

    if temporales:
        archivo = args.csv.with_name("contrasenas_temporales.csv")
        with archivo.open("w", encoding="utf-8", newline="") as f:
            w = csv.DictWriter(f, fieldnames=list(temporales[0].keys()))
            w.writeheader()
            w.writerows(temporales)
        print(f"Contraseñas temporales en {archivo}: entrégalas una por una y "
              "borra el archivo al terminar.")
    elif any(r["cuenta"] == "creado" for r in resultado):
        print("Moodle enviará a cada persona nueva su contraseña por correo, en los "
              "próximos minutos (lo hace su tarea programada).")
    else:
        print("No había cuentas nuevas: nadie recibe correo.")


if __name__ == "__main__":
    main()
