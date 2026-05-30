# sward-moodle-test

Entorno **Moodle real dockerizado** con datos de prueba, para validar la
integracion del microservicio
[`sward-ms-integracion-lms`](https://github.com/sward-UPC/sward-ms-integracion-lms)
contra una instancia **real** de Moodle (no contra el mock).

Sirve como banco de pruebas del MVP de SWARD: levanta Moodle + MariaDB con un
solo comando, se cargan cursos/docentes/estudiantes de prueba, se habilitan los
Web Services REST de Moodle y se apunta `sward-ms-integracion-lms` a esta
instancia (`MOODLE_MOCK=false`) para verificar la sincronizacion real con
`POST /lms/sync`.

> Stack: imagenes oficiales de **Bitnami** (`bitnami/moodle:4.5` LTS + `bitnami/mariadb:11.4`),
> que automatizan el bootstrap (instalacion de Moodle, BD y usuario admin).

---

## Contenido del repo

```
.
├── docker-compose.yml      # Moodle 4.5 LTS + MariaDB con healthchecks y volumenes
├── Makefile                # make up | down | wait | seed | token | clean
├── seed/
│   ├── seed.sh             # crea 3 cursos, 2 docentes y 10 estudiantes via REST
│   └── .env.example        # plantilla con MOODLE_URL y MOODLE_TOKEN
├── .gitignore
└── README.md
```

---

## Requisitos

- Docker + Docker Compose v2 (`docker compose version`)
- `curl` y `jq` (para el seed y los comandos de verificacion)
- Conexion a internet (la primera vez descarga las imagenes de Bitnami)

---

## 1. Levantar Moodle

```bash
docker compose up -d
# o:  make up
```

La **primera vez** Moodle tarda **varios minutos** (instala la BD, ejecuta
migraciones y arranca Apache). Espera a que el healthcheck pase antes de
continuar:

```bash
# Opcion A: con el target del Makefile (hace polling hasta que responda)
make wait

# Opcion B: revisar el estado de salud del contenedor
docker compose ps           # la columna STATUS debe decir (healthy)

# Opcion C: seguir los logs hasta ver "moodle: ... ready to handle connections"
docker compose logs -f moodle
```

Cuando este listo, abre: <http://localhost:8090>

### Credenciales por defecto (SOLO entorno de prueba)

> No usar estos valores en produccion. Estan documentados aqui porque este
> repo es un banco de pruebas desechable.

| Dato            | Valor                |
|-----------------|----------------------|
| URL             | http://localhost:8090 |
| Usuario admin   | `admin`              |
| Password admin  | `Sward2026!`         |
| Email admin     | `admin@sward.test`   |
| Nombre del sitio| `SWARD Moodle Test`  |

Base de datos MariaDB (interna): db `bitnami_moodle`, usuario `bn_moodle`,
password `bn_moodle_pass`, root `root_pass`.

Los usuarios de prueba creados por el seed (docentes y estudiantes) usan la
misma password: `Sward2026!`.

---

## 2. Habilitar los Web Services REST y generar un token

`sward-ms-integracion-lms` consume los Web Services REST de Moodle
(`/webservice/rest/server.php`). Hay que habilitarlos una sola vez tras el
primer arranque. Inicia sesion como `admin` y sigue estos pasos:

### 2.1 Habilitar Web Services

1. **Site administration → Advanced features**
   (`/admin/search.php` → "Advanced features").
2. Marca **Enable web services** (`enablewebservices`) y guarda.

### 2.2 Habilitar el protocolo REST

1. **Site administration → Server → Web services → Manage protocols**
   (`/admin/settings.php?section=webserviceprotocols`).
2. Habilita **REST protocol**.

### 2.3 Crear un "external service" con las funciones que usa SWARD

1. **Site administration → Server → Web services → External services**
   (`/admin/settings.php?section=externalservices`).
2. **Add** un servicio nuevo, p. ej. nombre `SWARD Integracion LMS`,
   shortname `sward_lms`, marca **Enabled** y **Authorised users only**
   (o desmarcalo para permitir cualquier usuario autorizado). Guarda.
3. En ese servicio, click en **Functions → Add functions** y agrega como
   minimo las funciones que consume el microservicio:

   | Funcion REST                         | Uso en SWARD                                  |
   |--------------------------------------|-----------------------------------------------|
   | `core_webservice_get_site_info`      | (recomendada) verificar token / sitio         |
   | `core_course_get_courses`            | **sincronizar cursos** (`get_courses`)        |
   | `core_course_get_courses_by_field`   | buscar curso por shortname (seed idempotente) |
   | `core_course_get_contents`           | **sincronizar actividades** (`get_activities`)|
   | `gradereport_user_get_grade_items`   | calificaciones por curso (`get_grades`)       |
   | `core_grades_get_grades`             | calificaciones (alternativa)                  |

   Funciones adicionales que necesita el **seed** para crear datos via REST:

   | Funcion REST                       | Uso                                  |
   |------------------------------------|--------------------------------------|
   | `core_course_create_courses`       | crear cursos                         |
   | `core_user_create_users`           | crear docentes y estudiantes         |
   | `core_user_get_users_by_field`     | buscar usuarios (idempotencia)       |
   | `enrol_manual_enrol_users`         | matricular usuarios en cursos        |

   > Para **logs/interacciones** (cuando se implementen en el microservicio)
   > se suelen usar funciones de reportes como
   > `core_completion_get_activities_completion_status` o consultas al
   > `logstore`. Hoy `get_grades`/`get_events` del adaptador devuelven vacio,
   > asi que las dos primeras tablas bastan para el MVP de sincronizacion.

### 2.4 Generar el token

Opcion rapida (usar el admin como usuario del servicio):

1. **Site administration → Server → Web services → Manage tokens**
   (`/admin/settings.php?section=webservicetokens`).
2. **Create token**: selecciona el usuario `admin`, el servicio
   `SWARD Integracion LMS` y guarda.
3. Copia el token (cadena hex de 32 caracteres).

> Para un piloto mas realista, crea un **usuario de servicio** dedicado con un
> rol que tenga la capability `webservice/rest:use` y los permisos de lectura
> de cursos, y genera el token para ese usuario en vez del admin.

Verifica el token:

```bash
TOKEN=<tu-token>
curl -s "http://localhost:8090/webservice/rest/server.php?wstoken=$TOKEN&moodlewsrestformat=json&wsfunction=core_webservice_get_site_info" | jq '{sitename, username, functions: (.functions | length)}'
```

---

## 3. Cargar datos de prueba (seed)

El PRD del piloto pide **>=10 estudiantes** y **>=2 docentes**. El seed crea:

- **3 cursos**: `SWARD-AED`, `SWARD-BD`, `SWARD-IS`
- **2 docentes** (rol `editingteacher`)
- **10 estudiantes** (rol `student`)
- **Matricula** de los 2 docentes y los 10 estudiantes en los 3 cursos

```bash
cp seed/.env.example seed/.env
# edita seed/.env y pega el TOKEN del paso 2.4
./seed/seed.sh
# o:  make seed
```

El script es idempotente (busca por shortname/username antes de crear), asi que
puedes re-ejecutarlo sin duplicar datos.

### Actividades (quiz/assign) y calificaciones — paso manual

Moodle **no expone via REST por defecto** la creacion de modulos de curso
(quiz/assign). Por eso se crean desde la UI (son pocos clicks):

1. Entra a un curso (p. ej. `SWARD-AED`) → **activa la edicion**.
2. **Add an activity or resource** → elige **Quiz** o **Assignment**, ponle
   nombre y guarda. Repite para 1-2 actividades por curso.
3. Para tener **calificaciones**: entra al **Grader report** del curso
   (`Grades`) e introduce notas manuales para algunos estudiantes, o califica
   un envio del Assignment. Asi `gradereport_user_get_grade_items` devolvera
   datos reales.

> Alternativa avanzada (opcional): la herramienta CLI `moosh`
> (`moosh activity-add`, `moosh user-mod`) permite scriptar tambien las
> actividades, pero requiere instalarla dentro del contenedor; para el MVP el
> camino manual es mas rapido.

---

## 4. Conectar `sward-ms-integracion-lms` a este Moodle

En el repo del microservicio, edita su `.env` para apuntar al Moodle real:

```dotenv
# sward-ms-integracion-lms/.env
MOODLE_MOCK=false
MOODLE_BASE_URL=http://localhost:8090
MOODLE_TOKEN=<token-generado-en-el-paso-2.4>
```

> El microservicio llama a `{MOODLE_BASE_URL}/webservice/rest/server.php` con
> `wstoken`, `moodlewsrestformat=json` y `wsfunction`. Con `MOODLE_MOCK=false`
> usa el adaptador real (`MoodleApiAdapter`) en vez del mock.

Levanta el microservicio (segun su README; normalmente):

```bash
cd ../sward-ms-integracion-lms
uvicorn src.infrastructure.adapters.in_.main:app --reload --port 8000
```

Dispara la sincronizacion real. El endpoint `POST /lms/sync` exige un JWT de
acceso (emitido por `sward-ms-usuarios`):

```bash
curl -X POST http://localhost:8000/lms/sync \
  -H "Authorization: Bearer <JWT-de-acceso>" \
  -H "Content-Type: application/json"
```

Deberia traer los **3 cursos reales** (`SWARD-AED`, `SWARD-BD`, `SWARD-IS`) y
sus actividades, en vez de los datos del mock.

### Verificacion directa contra la API REST de Moodle (sin el microservicio)

Replica exactamente las llamadas que hace el adaptador real:

```bash
TOKEN=<tu-token>
BASE=http://localhost:8090/webservice/rest/server.php

# 1) Cursos (lo que usa get_courses -> core_course_get_courses)
curl -s "$BASE?wstoken=$TOKEN&moodlewsrestformat=json&wsfunction=core_course_get_courses" \
  | jq '.[] | {id, shortname, fullname}'

# 2) Contenidos/actividades de un curso (get_activities -> core_course_get_contents)
#    Sustituye COURSEID por el id de SWARD-AED que devolvio el seed.
curl -s "$BASE?wstoken=$TOKEN&moodlewsrestformat=json&wsfunction=core_course_get_contents&courseid=COURSEID" \
  | jq '.[].modules[]? | {id, name, modname}'

# 3) Calificaciones de un curso (get_grades -> gradereport_user_get_grade_items)
curl -s "$BASE?wstoken=$TOKEN&moodlewsrestformat=json&wsfunction=gradereport_user_get_grade_items&courseid=COURSEID" \
  | jq '.usergrades[]? | {userid, gradeitems: (.gradeitems | length)}'
```

Si estos comandos devuelven datos, `sward-ms-integracion-lms` con
`MOODLE_MOCK=false` sincronizara correctamente.

---

## Operacion

```bash
make up        # levantar
make wait      # esperar bootstrap
make ps        # estado / health
make logs      # ver logs de Moodle
make seed      # cargar datos de prueba
make down      # parar (conserva datos)
make clean     # parar y BORRAR volumenes (reset total)
```

---

## Notas

- Los volumenes `moodle_data`, `moodledata_data` y `mariadb_data` persisten los
  datos entre reinicios. Usa `make clean` para empezar de cero.
- Si cambias el puerto publicado, actualiza tambien `MOODLE_HOST` en el
  `docker-compose.yml` para que Moodle genere URLs correctas.
- Entorno **no apto para produccion**: credenciales debiles documentadas, sin
  TLS, sin backups.
