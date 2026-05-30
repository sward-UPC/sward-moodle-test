#!/usr/bin/env bash
#
# seed.sh — Carga datos minimos de prueba en el Moodle dockerizado para el
# MVP de SWARD, usando la API REST de Moodle (Web Services).
#
# Crea (cumpliendo el PRD: >=10 estudiantes, >=2 docentes):
#   - 3 cursos
#   - 2 docentes (rol editingteacher)
#   - 10 estudiantes (rol student)
#   - Matricula de los estudiantes y docentes en los cursos
#
# Las actividades (quiz/assign) y las calificaciones se crean a mano desde la
# UI, porque las funciones REST para crear modulos de curso no estan expuestas
# por defecto en Moodle (ver README, seccion "Pasos manuales").
#
# REQUISITOS:
#   - Moodle levantado (docker compose up -d) y bootstrap terminado.
#   - Web Services + protocolo REST habilitados.
#   - Un token de servicio con las funciones de creacion habilitadas
#     (ver README). Exporta MOODLE_URL y MOODLE_TOKEN o usa seed/.env.
#
# USO:
#   cp seed/.env.example seed/.env   # y edita el token
#   ./seed/seed.sh
#   # o:  MOODLE_URL=http://localhost:8090 MOODLE_TOKEN=xxxx ./seed/seed.sh
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[ -f "${SCRIPT_DIR}/.env" ] && set -a && . "${SCRIPT_DIR}/.env" && set +a

MOODLE_URL="${MOODLE_URL:-http://localhost:8090}"
MOODLE_TOKEN="${MOODLE_TOKEN:-}"
ENDPOINT="${MOODLE_URL}/webservice/rest/server.php"

if [ -z "${MOODLE_TOKEN}" ]; then
  echo "ERROR: define MOODLE_TOKEN (variable de entorno o seed/.env)." >&2
  exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
  echo "ERROR: se requiere 'jq'. Instala con: brew install jq" >&2
  exit 1
fi

# call <wsfunction> [param=value ...]
# Hace una llamada REST y devuelve el JSON. Aborta si Moodle devuelve un error.
call() {
  local fn="$1"; shift
  local args=(--silent --get
    --data-urlencode "wstoken=${MOODLE_TOKEN}"
    --data-urlencode "moodlewsrestformat=json"
    --data-urlencode "wsfunction=${fn}")
  local p
  for p in "$@"; do
    args+=(--data-urlencode "$p")
  done
  local resp
  resp="$(curl "${args[@]}" "${ENDPOINT}")"
  if echo "${resp}" | jq -e 'if type=="object" then (.exception // empty) else empty end' >/dev/null 2>&1; then
    echo "  ! Error en ${fn}: $(echo "${resp}" | jq -r '.message')" >&2
    echo "${resp}"
    return 1
  fi
  echo "${resp}"
}

echo "==> Verificando conexion y token ($ENDPOINT)"
SITEINFO="$(call core_webservice_get_site_info)"
echo "    Sitio: $(echo "$SITEINFO" | jq -r '.sitename')  |  usuario WS: $(echo "$SITEINFO" | jq -r '.username')"

# ---------------------------------------------------------------------------
# 1) Crear cursos (idempotente por shortname)
# ---------------------------------------------------------------------------
# Devuelve el id del curso con el shortname dado, o vacio si no existe.
course_id_by_shortname() {
  call core_course_get_courses_by_field field=shortname "value=$1" \
    | jq -r '.courses[0].id // empty'
}

create_course() {
  local fullname="$1" shortname="$2" cat="${3:-1}"
  local existing; existing="$(course_id_by_shortname "$shortname")"
  if [ -n "$existing" ]; then
    echo "    curso '$shortname' ya existe (id=$existing)"; echo "$existing"; return
  fi
  call core_course_create_courses \
    "courses[0][fullname]=${fullname}" \
    "courses[0][shortname]=${shortname}" \
    "courses[0][categoryid]=${cat}" \
    "courses[0][summary]=Curso de prueba SWARD" \
    | jq -r '.[0].id'
}

echo "==> Creando cursos"
CID1="$(create_course 'Algoritmos y Estructuras de Datos' 'SWARD-AED' 1)"
CID2="$(create_course 'Bases de Datos' 'SWARD-BD' 1)"
CID3="$(create_course 'Ingenieria de Software' 'SWARD-IS' 1)"
echo "    cursos: AED=$CID1  BD=$CID2  IS=$CID3"

# ---------------------------------------------------------------------------
# 2) Crear usuarios (idempotente por username)
# ---------------------------------------------------------------------------
user_id_by_username() {
  call core_user_get_users_by_field field=username "value=$1" | jq -r '.[0].id // empty'
}

create_user() {
  local username="$1" first="$2" last="$3" email="$4"
  local existing; existing="$(user_id_by_username "$username")"
  if [ -n "$existing" ]; then echo "$existing"; return; fi
  call core_user_create_users \
    "users[0][username]=${username}" \
    "users[0][password]=Sward2026!" \
    "users[0][firstname]=${first}" \
    "users[0][lastname]=${last}" \
    "users[0][email]=${email}" \
    "users[0][auth]=manual" \
    | jq -r '.[0].id'
}

echo "==> Creando 2 docentes"
T1="$(create_user docente1 Ana   Torres   docente1@sward.test)"
T2="$(create_user docente2 Luis  Mendoza  docente2@sward.test)"
echo "    docentes: $T1 $T2"

echo "==> Creando 10 estudiantes"
STUDENT_IDS=()
for i in $(seq 1 10); do
  num=$(printf "%02d" "$i")
  sid="$(create_user "estudiante${num}" "Estudiante${num}" "Apellido${num}" "estudiante${num}@sward.test")"
  STUDENT_IDS+=("$sid")
done
echo "    estudiantes: ${STUDENT_IDS[*]}"

# ---------------------------------------------------------------------------
# 3) Matricular usuarios en cursos
#    roleid: 3 = editingteacher (docente con edicion), 5 = student
# ---------------------------------------------------------------------------
enrol() {
  local userid="$1" courseid="$2" roleid="$3"
  call enrol_manual_enrol_users \
    "enrolments[0][roleid]=${roleid}" \
    "enrolments[0][userid]=${userid}" \
    "enrolments[0][courseid]=${courseid}" >/dev/null || true
}

echo "==> Matriculando docentes (rol editingteacher) en los 3 cursos"
for cid in "$CID1" "$CID2" "$CID3"; do
  enrol "$T1" "$cid" 3
  enrol "$T2" "$cid" 3
done

echo "==> Matriculando los 10 estudiantes (rol student) en los 3 cursos"
for cid in "$CID1" "$CID2" "$CID3"; do
  for sid in "${STUDENT_IDS[@]}"; do
    enrol "$sid" "$cid" 5
  done
done

echo ""
echo "============================================================"
echo " Seed completado."
echo "   Cursos:      3  (AED=$CID1, BD=$CID2, IS=$CID3)"
echo "   Docentes:    2  (editingteacher)"
echo "   Estudiantes: 10 (student)"
echo ""
echo " Verifica via REST:"
echo "   curl -s \"$ENDPOINT?wstoken=\$MOODLE_TOKEN&moodlewsrestformat=json&wsfunction=core_course_get_courses\" | jq '.[].shortname'"
echo ""
echo " Las actividades (quiz/assign) y calificaciones se crean desde la UI"
echo " (Moodle no expone su creacion via REST por defecto). Ver README."
echo "============================================================"
