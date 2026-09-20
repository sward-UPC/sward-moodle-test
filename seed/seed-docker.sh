#!/usr/bin/env bash
# SWARD — ejecuta seed.sh dentro de un contenedor.
#
#   ./seed/seed-docker.sh                       # 3 cursos, 2 docentes, 10 estudiantes
#   NUM_STUDENTS=20 ./seed/seed-docker.sh       # o con otros valores
#   ./seed/seed-docker.sh --cleanup             # los argumentos se pasan tal cual
#
# seed.sh depende de `jq`, que no viene en Windows ni en muchos equipos. En vez
# de pedir que cada quien lo instale, lo corremos en un contenedor efimero con
# bash, curl y jq. Es ademas lo que hace falta para reproducir el poblado en
# cualquier maquina o en un runner de CI.
#
# Dentro del contenedor, Moodle no esta en localhost: se alcanza por
# host.docker.internal, asi que se sobreescribe MOODLE_URL para esa ejecucion.

set -euo pipefail
export MSYS_NO_PATHCONV=1

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ ! -f "$RAIZ/seed/.env" ]; then
  echo "ERROR: falta seed/.env. Corre primero ./seed/setup.sh" >&2
  exit 1
fi

TOKEN="$(grep '^MOODLE_TOKEN=' "$RAIZ/seed/.env" | cut -d= -f2)"
if [ -z "$TOKEN" ]; then
  echo "ERROR: seed/.env no tiene MOODLE_TOKEN." >&2
  exit 1
fi

docker run --rm \
  --add-host=host.docker.internal:host-gateway \
  -v "$RAIZ:/work" \
  -w /work \
  -e MOODLE_URL="http://host.docker.internal:8090" \
  -e MOODLE_TOKEN="$TOKEN" \
  -e NUM_COURSES="${NUM_COURSES:-3}" \
  -e NUM_TEACHERS="${NUM_TEACHERS:-2}" \
  -e NUM_STUDENTS="${NUM_STUDENTS:-10}" \
  alpine:3.20 sh -c '
    apk add --no-cache bash curl jq >/dev/null 2>&1
    # seed.sh hace `source .env` con set -a, y eso pisaria MOODLE_URL con el
    # valor de localhost. Se escribe un .env valido para dentro del contenedor.
    printf "MOODLE_URL=%s\nMOODLE_TOKEN=%s\n" "$MOODLE_URL" "$MOODLE_TOKEN" > /work/seed/.env.docker
    cp /work/seed/.env /work/seed/.env.host
    cp /work/seed/.env.docker /work/seed/.env
    bash /work/seed/seed.sh "$@"
    estado=$?
    cp /work/seed/.env.host /work/seed/.env
    rm -f /work/seed/.env.docker /work/seed/.env.host
    exit $estado
  ' -- "$@"
