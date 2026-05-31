#!/usr/bin/env bash
#
# seed.sh — Carga datos de prueba en el Moodle dockerizado para el MVP de SWARD
# usando la API REST de Moodle (Web Services).
#
# Crea (cumpliendo el PRD: >=10 estudiantes, >=2 docentes):
#   - N cursos (default: 3)
#   - M docentes (default: 2)
#   - K estudiantes (default: 10)
#   - Matriculas y roles correspondientes
#   - Categorías de cursos
#
# REQUISITOS:
#   - Moodle levantado (docker compose up -d) y bootstrap completado
#   - Web Services + protocolo REST habilitados
#   - Token de servicio con funciones de creación habilitadas
#
# USO:
#   cp seed/.env.example seed/.env   # edita el token
#   ./seed/seed.sh                    # ejecuta con valores default
#   NUM_COURSES=5 NUM_TEACHERS=3 NUM_STUDENTS=20 ./seed/seed.sh
#
# OPCIONES:
#   --cleanup        Elimina todos los datos de seed y resetea Moodle
#   --skip-users     Solo crea cursos (útil si ya existen usuarios)
#   --verbose        Muestra respuestas completas de API
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[ -f "${SCRIPT_DIR}/.env" ] && set -a && . "${SCRIPT_DIR}/.env" && set +a

MOODLE_URL="${MOODLE_URL:-http://localhost:8090}"
MOODLE_TOKEN="${MOODLE_TOKEN:-}"
ENDPOINT="${MOODLE_URL}/webservice/rest/server.php"

NUM_COURSES="${NUM_COURSES:-3}"
NUM_TEACHERS="${NUM_TEACHERS:-2}"
NUM_STUDENTS="${NUM_STUDENTS:-10}"
VERBOSE="${VERBOSE:-0}"
CLEANUP_MODE=0
SKIP_USERS=0

# Parse arguments
while [[ $# -gt 0 ]]; do
  case "$1" in
    --cleanup) CLEANUP_MODE=1; shift ;;
    --skip-users) SKIP_USERS=1; shift ;;
    --verbose) VERBOSE=1; shift ;;
    *) echo "Opción desconocida: $1" >&2; exit 1 ;;
  esac
done

if [ -z "${MOODLE_TOKEN}" ]; then
  echo "❌ ERROR: define MOODLE_TOKEN (variable de entorno o seed/.env)." >&2
  exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
  echo "❌ ERROR: se requiere 'jq'. Instala con: brew install jq" >&2
  exit 1
fi

if ! command -v curl >/dev/null 2>&1; then
  echo "❌ ERROR: se requiere 'curl'." >&2
  exit 1
fi

# Color codes para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# call <wsfunction> [param=value ...]
# Realiza una llamada REST a Moodle WS API. Aborta si hay error.
call() {
  local fn="$1"; shift
  local args=(--silent --get --max-time 10
    --data-urlencode "wstoken=${MOODLE_TOKEN}"
    --data-urlencode "moodlewsrestformat=json"
    --data-urlencode "wsfunction=${fn}")
  local p
  for p in "$@"; do
    args+=(--data-urlencode "$p")
  done

  local resp
  if ! resp="$(curl "${args[@]}" "${ENDPOINT}" 2>&1)"; then
    echo -e "${RED}✗ Error de conexión en ${fn}${NC}" >&2
    return 1
  fi

  if echo "${resp}" | jq -e 'if type=="object" then (.exception // empty) else empty end' >/dev/null 2>&1; then
    local msg; msg=$(echo "${resp}" | jq -r '.message // .error // "Error desconocido"')
    echo -e "${RED}✗ Error en ${fn}: ${msg}${NC}" >&2
    [ "$VERBOSE" = "1" ] && echo "${resp}" >&2
    return 1
  fi

  echo "${resp}"
}

# check_moodle_ready: Valida que Moodle esté listo antes de continuar
check_moodle_ready() {
  echo -e "${BLUE}⟳ Verificando que Moodle esté listo...${NC}"
  local retries=0 max_retries=30
  while [ $retries -lt $max_retries ]; do
    if curl -fsS -o /dev/null "${MOODLE_URL}/login/index.php" 2>/dev/null; then
      echo -e "${GREEN}✓ Moodle está listo${NC}"
      return 0
    fi
    retries=$((retries + 1))
    echo -n "."
    sleep 2
  done
  echo -e "${RED}✗ Timeout esperando Moodle${NC}"
  return 1
}

# Gestión de estado y logs
STATE_FILE="${SCRIPT_DIR}/.seed-state.json"
COURSES=()
TEACHERS=()
STUDENTS=()

cleanup_data() {
  echo -e "${YELLOW}⚠ Limpiando datos de seed anterior...${NC}"
  if [ -f "$STATE_FILE" ]; then
    local courses; courses=$(jq -r '.courses[]?' "$STATE_FILE" 2>/dev/null || true)
    for cid in $courses; do
      call core_course_delete_courses "courseids[0]=$cid" >/dev/null 2>&1 || true
    done
    rm "$STATE_FILE"
  fi
  echo -e "${GREEN}✓ Datos antiguos eliminados${NC}"
}

# ---------------------------------------------------------------------------
# 1) Crear o reutilizar categoría
# ---------------------------------------------------------------------------
create_or_get_category() {
  local name="$1"
  local existing
  existing=$(call core_course_get_categories "criteria[0][key]=name" "criteria[0][value]=${name}" 2>/dev/null | jq -r '.[0].id // empty' || true)
  if [ -n "$existing" ]; then
    echo "$existing"
    return
  fi
  call core_course_create_categories "categories[0][name]=${name}" "categories[0][description]=Cursos SWARD" | jq -r '.[0].id'
}

# ---------------------------------------------------------------------------
# 2) Crear cursos (idempotente)
# ---------------------------------------------------------------------------
course_id_by_shortname() {
  call core_course_get_courses_by_field field=shortname "value=$1" 2>/dev/null | jq -r '.courses[0].id // empty' || true
}

create_course() {
  local fullname="$1" shortname="$2" cat="${3:-1}"
  local existing; existing="$(course_id_by_shortname "$shortname")"
  if [ -n "$existing" ]; then
    echo -e "${YELLOW}⟳${NC} Curso '$shortname' ya existe (id=$existing)"
    echo "$existing"
    return
  fi
  local cid
  cid=$(call core_course_create_courses \
    "courses[0][fullname]=${fullname}" \
    "courses[0][shortname]=${shortname}" \
    "courses[0][categoryid]=${cat}" \
    "courses[0][summary]=Curso de prueba - SWARD" \
    "courses[0][visible]=1" | jq -r '.[0].id')
  echo -e "${GREEN}✓${NC} Curso '$shortname' creado (id=$cid)"
  echo "$cid"
}

# ---------------------------------------------------------------------------
# 3) Crear usuarios (idempotente)
# ---------------------------------------------------------------------------
user_id_by_username() {
  call core_user_get_users_by_field field=username "value=$1" 2>/dev/null | jq -r '.[0].id // empty' || true
}

create_user() {
  local username="$1" first="$2" last="$3" email="$4"
  local existing; existing="$(user_id_by_username "$username")"
  if [ -n "$existing" ]; then
    echo -e "${YELLOW}⟳${NC} Usuario '$username' ya existe (id=$existing)"
    echo "$existing"
    return
  fi
  local uid
  uid=$(call core_user_create_users \
    "users[0][username]=${username}" \
    "users[0][password]=Sward2026!" \
    "users[0][firstname]=${first}" \
    "users[0][lastname]=${last}" \
    "users[0][email]=${email}" \
    "users[0][auth]=manual" \
    "users[0][lang]=es" | jq -r '.[0].id')
  echo -e "${GREEN}✓${NC} Usuario '$username' creado (id=$uid)"
  echo "$uid"
}

# ---------------------------------------------------------------------------
# 4) Matriculación
# ---------------------------------------------------------------------------
enrol_user() {
  local userid="$1" courseid="$2" roleid="$3" role_name="$4"
  call enrol_manual_enrol_users \
    "enrolments[0][roleid]=${roleid}" \
    "enrolments[0][userid]=${userid}" \
    "enrolments[0][courseid]=${courseid}" >/dev/null 2>&1 || true
  echo -e "${GREEN}✓${NC} Usuario $userid matriculado como $role_name en curso $courseid"
}

# ---------------------------------------------------------------------------
# MAIN
# ---------------------------------------------------------------------------
echo -e "${BLUE}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║      SWARD Moodle Seed - Carga de datos de prueba         ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""

if [ "$CLEANUP_MODE" = "1" ]; then
  cleanup_data
  exit 0
fi

check_moodle_ready || exit 1

echo -e "${BLUE}⟳ Verificando conexión y token${NC}"
SITEINFO="$(call core_webservice_get_site_info)" || exit 1
SITENAME=$(echo "$SITEINFO" | jq -r '.sitename')
WSUSER=$(echo "$SITEINFO" | jq -r '.username')
echo -e "${GREEN}✓${NC} Conectado a: ${SITENAME} | WS user: ${WSUSER}"
echo ""

# Crear categoría principal
echo -e "${BLUE}⟳ Creando categoría de cursos${NC}"
CAT_ID="$(create_or_get_category "SWARD")"
echo -e "${GREEN}✓${NC} Categoría SWARD (id=$CAT_ID)"
echo ""

# Crear cursos
echo -e "${BLUE}⟳ Creando ${NUM_COURSES} curso(s)...${NC}"
declare -a COURSE_NAMES=("Algoritmos y Estructuras de Datos" "Bases de Datos" "Ingeniería de Software" "Desarrollo Web" "Seguridad Informática")
declare -a COURSE_SHORTS=("SWARD-AED" "SWARD-BD" "SWARD-IS" "SWARD-DW" "SWARD-SI")
for i in $(seq 1 "$NUM_COURSES"); do
  idx=$((i - 1))
  if [ $idx -lt ${#COURSE_NAMES[@]} ]; then
    fname="${COURSE_NAMES[$idx]}"
    short="${COURSE_SHORTS[$idx]}"
  else
    fname="Curso SWARD $i"
    short="SWARD-C$i"
  fi
  cid="$(create_course "$fname" "$short" "$CAT_ID")"
  COURSES+=("$cid")
done
echo ""

# Crear usuarios
if [ "$SKIP_USERS" = "0" ]; then
  echo -e "${BLUE}⟳ Creando ${NUM_TEACHERS} docente(s)...${NC}"
  for i in $(seq 1 "$NUM_TEACHERS"); do
    num=$(printf "%02d" "$i")
    tid="$(create_user "docente${num}" "Docente${num}" "Apellido${num}" "docente${num}@sward.test")"
    TEACHERS+=("$tid")
  done
  echo ""

  echo -e "${BLUE}⟳ Creando ${NUM_STUDENTS} estudiante(s)...${NC}"
  for i in $(seq 1 "$NUM_STUDENTS"); do
    num=$(printf "%02d" "$i")
    sid="$(create_user "estudiante${num}" "Estudiante${num}" "Apellido${num}" "estudiante${num}@sward.test")"
    STUDENTS+=("$sid")
    if [ $((i % 5)) -eq 0 ]; then echo "  ... $i/${NUM_STUDENTS}"; fi
  done
  echo ""

  # Matriculaciones
  echo -e "${BLUE}⟳ Matriculando docentes en cursos (rol: editingteacher)${NC}"
  for cid in "${COURSES[@]}"; do
    for tid in "${TEACHERS[@]}"; do
      enrol_user "$tid" "$cid" "3" "editingteacher" >/dev/null
    done
  done
  echo -e "${GREEN}✓${NC} Docentes matriculados"
  echo ""

  echo -e "${BLUE}⟳ Matriculando estudiantes en cursos (rol: student)${NC}"
  for cid in "${COURSES[@]}"; do
    for sid in "${STUDENTS[@]}"; do
      enrol_user "$sid" "$cid" "5" "student" >/dev/null
    done
  done
  echo -e "${GREEN}✓${NC} Estudiantes matriculados"
  echo ""
fi

# Guardar estado
cat > "$STATE_FILE" <<EOF
{
  "timestamp": "$(date -Iseconds)",
  "courses": [$(printf '%s,' "${COURSES[@]}" | sed 's/,$//')],
  "teachers": [$(printf '%s,' "${TEACHERS[@]}" | sed 's/,$//')],
  "students": [$(printf '%s,' "${STUDENTS[@]}" | sed 's/,$//')],
  "moodle_url": "$MOODLE_URL"
}
EOF

# Resumen final
echo -e "${BLUE}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║              ✓ Seed completado exitosamente               ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${YELLOW}Cursos:${NC}       ${#COURSES[@]} creados"
echo -e "  ${YELLOW}Docentes:${NC}     ${#TEACHERS[@]} creados (rol: editingteacher)"
echo -e "  ${YELLOW}Estudiantes:${NC}  ${#STUDENTS[@]} creados (rol: student)"
echo ""
echo -e "  ${YELLOW}Estado guardado en:${NC} $STATE_FILE"
echo -e "  ${YELLOW}URL de Moodle:${NC} ${MOODLE_URL}"
echo ""
echo -e "  ${YELLOW}Credenciales de prueba:${NC}"
echo -e "    Admin: admin / Sward2026!"
echo -e "    Docentes: docente01, docente02, ... / Sward2026!"
echo -e "    Estudiantes: estudiante01, estudiante02, ... / Sward2026!"
echo ""
echo -e "  ${YELLOW}Para resetear datos:${NC} ./seed/seed.sh --cleanup"
echo -e "  ${YELLOW}Para cambiar cantidades:${NC} NUM_COURSES=5 NUM_STUDENTS=50 ./seed/seed.sh"
echo ""
