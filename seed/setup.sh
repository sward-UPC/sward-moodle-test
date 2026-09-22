#!/usr/bin/env bash
# SWARD — deja el Moodle de pruebas listo para usarse, de forma reproducible.
#
#   ./seed/setup.sh
#
# Hace tres cosas que hasta ahora eran manuales o estaban rotas:
#   1. Corrige reverseproxy en config.php.
#   2. Habilita web services + REST y crea el servicio con sus funciones.
#   3. Genera (o reutiliza) el token y lo deja en seed/.env.
#
# Sobre el punto 1: el contenedor sirve en el puerto 8080 y se publica en el
# 8090. Con reverseproxy=false Moodle reconstruye la URL con el puerto interno,
# no coincide con su propio wwwroot y responde 303 hacia si mismo en bucle:
# ninguna pagina carga y ninguna llamada REST funciona.

set -euo pipefail

# Git Bash en Windows convierte /rutas/absolutas a rutas Windows al pasarlas
# a docker exec. Esto lo desactiva; en Linux y macOS la variable se ignora.
export MSYS_NO_PATHCONV=1

CONTENEDOR="${MOODLE_CONTAINER:-sward-moodle-app}"
URL="${MOODLE_URL:-http://localhost:8090}"
RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "==> Esperando a que Moodle responda en $URL"
until curl -fsS -m 5 -o /dev/null "$URL/login/index.php" 2>/dev/null; do
  printf '.'
  sleep 5
done
echo " listo"

echo "==> Corrigiendo reverseproxy"
docker exec "$CONTENEDOR" sh -c \
  "sed -i 's/reverseproxy[[:space:]]*=[[:space:]]*false/reverseproxy = true/' /var/www/html/config.php"
docker exec "$CONTENEDOR" php /var/www/html/admin/cli/purge_caches.php >/dev/null

# El correo va a Mailpit (docker-compose.yml, variables SMTP_*; buzón en
# http://localhost:8025, sin salida a internet). Antes se desactivaba con
# noemailever, y las cuentas creadas por crear_participantes.py nunca recibían su
# contraseña: se quita si quedó de una instalación anterior.
echo "==> Correo de prueba: Mailpit"
docker exec "$CONTENEDOR" sh -c "sed -i '/noemailever/d' /var/www/html/config.php"

docker exec "$CONTENEDOR" php /var/www/html/admin/cli/purge_caches.php >/dev/null

echo "==> Configurando web services y generando token"
docker cp "$RAIZ/seed/setup_webservices.php" "$CONTENEDOR:/tmp/setup_webservices.php"
SALIDA="$(docker exec "$CONTENEDOR" php /tmp/setup_webservices.php)"
echo "$SALIDA" | sed 's/^/   /'

TOKEN="$(echo "$SALIDA" | grep '^MOODLE_TOKEN=' | cut -d= -f2)"
if [ -z "$TOKEN" ]; then
  echo "ERROR: no se pudo obtener el token." >&2
  exit 1
fi

echo "==> Verificando el token"
RESP="$(curl -fsS -m 20 \
  "$URL/webservice/rest/server.php?wstoken=$TOKEN&wsfunction=core_webservice_get_site_info&moodlewsrestformat=json")"
case "$RESP" in
  *'"exception"'*) echo "ERROR: el token no funciona: $RESP" >&2; exit 1 ;;
esac
echo "   ok"

cat > "$RAIZ/seed/.env" <<EOF
MOODLE_URL=$URL
MOODLE_TOKEN=$TOKEN
EOF

echo
echo "Token guardado en seed/.env"
echo
echo "Pegalo tambien en sward-local/.env para que el sistema use Moodle real:"
echo "  MOODLE_MOCK=false"
echo "  MOODLE_BASE_URL=http://host.docker.internal:8090"
echo "  MOODLE_TOKEN=$TOKEN"
