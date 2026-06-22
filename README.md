# sward-moodle-test

Herramientas para **levantar y poblar un Moodle de pruebas** del MVP de SWARD.

Esta carpeta contiene todo lo necesario para tener una instancia **real** de
Moodle (no el mock) cargada con cursos, contenido académico, docentes,
estudiantes, **calificaciones** e **interacciones**, de modo que el microservicio
[`sward-ms-integracion-lms`](https://github.com/sward-UPC/sward-ms-integracion-lms)
pueda sincronizar datos reales y el modelo **SAKT** (Knowledge Tracing) reciba
señal suficiente para reentrenarse.

Incluye:

- Un **entorno dockerizado** (Moodle 4.5 LTS + MariaDB) reproducible.
- **Scripts de creación de cursos y contenido** vía los Web Services REST de Moodle.
- **Seeders de notas e interacciones** (vía REST y vía la API interna de Moodle)
  que generan la señal calificable que consume el SAKT.

> ⚠️ Entorno **no apto para producción**: usa credenciales débiles de prueba,
> sin TLS ni backups. Todas las credenciales viven en el `docker-compose.yml`
> (admin/BD) y en `seed/.env` (token de Web Services); este README **no** incluye
> valores de credenciales.

---

## Estructura de la carpeta

```
.
├── docker-compose.yml                # Moodle 4.5 LTS + MariaDB con healthchecks y volúmenes
├── Makefile                          # make up | down | wait | seed | token | clean
├── coolify.json                      # descriptor de despliegue en Coolify (Moodle de pruebas remoto)
│
├── seed/
│   ├── seed.sh                       # crea cursos/docentes/estudiantes + matrículas (REST)
│   ├── populate_moodle.php           # puebla contenido académico (CLI dentro del contenedor)
│   ├── seed_kt_interactions.php      # crea módulos CALIFICABLES e interacciones para el SAKT (API interna)
│   └── .env.example                  # plantilla con MOODLE_URL y MOODLE_TOKEN
│
├── create_course_content*.py         # creación de contenido del curso AED (varias iteraciones, vía REST)
├── create_bd_course_content.py       # contenido del curso de Bases de Datos (vía REST)
├── create_ingenieria_software.py     # contenido del curso de Ingeniería de Software (vía REST)
├── create_web_development_course.py  # contenido del curso de Desarrollo Web (vía REST)
├── populate_web_dev_content.py       # puebla el curso de Desarrollo Web con recursos/quizzes (vía REST)
│
├── seed_realistic_grades.py          # siembra notas realistas y variadas por estudiante/curso (REST)
├── seed_more_data.py                 # enriquece datos (renombra alumnos, +cursos, re-siembra notas) (REST)
├── seed_programacion.py              # agrega cursos de programación + alumnos + interacciones (REST)
│
├── fix_moodle.py                     # arregla el sitio: nombre "SWARD" y roles globales por prefijo
├── upload_content.sh                 # sube contenido a secciones de curso vía REST (curl)
│
├── WEB_DEVELOPMENT_COURSE.json       # definición del curso de Desarrollo Web (estructura de secciones)
├── WEB_DEVELOPMENT_CONTENT.json      # contenido detallado del curso de Desarrollo Web
├── sql_examples_bd_course.sql        # ejemplos SQL usados como material del curso de Bases de Datos
│
├── GUIA_INTEGRACION_MOODLE.md        # guía de referencia de integración con Moodle
└── 00_LEEME_PRIMERO.txt              # notas de onboarding (histórico)
```

---

## Requisitos

- Docker + Docker Compose v2 (`docker compose version`)
- `curl` y `jq` (para el seed y la verificación)
- Python 3.11+ con `requests` (para los scripts `*.py`)
- Acceso a la instancia de Moodle (local con docker, o la remota en Coolify)

---

## 1. Levantar Moodle

```bash
docker compose up -d     # o:  make up
```

La **primera vez** Moodle tarda **varios minutos** (instala la BD, ejecuta
migraciones y arranca). Espera a que el healthcheck pase:

```bash
make wait                # polling hasta que /login/index.php responda
docker compose ps        # la columna STATUS debe decir (healthy)
docker compose logs -f moodle
```

Cuando esté listo, abre <http://localhost:8090>.

Las credenciales del admin y de la BD están definidas como variables de entorno
en `docker-compose.yml` (sección `environment` de los servicios `moodle` y
`mariadb`). **No las copies a producción.**

---

## 2. Habilitar los Web Services REST y generar un token

Los scripts de creación de contenido y los seeders de notas consumen los
Web Services REST de Moodle (`/webservice/rest/server.php`). Hay que habilitarlos
una sola vez tras el primer arranque, iniciando sesión como `admin`:

1. **Site administration → Advanced features** → marca **Enable web services**.
2. **Server → Web services → Manage protocols** → habilita **REST protocol**.
3. **Server → Web services → External services** → crea un servicio (p. ej.
   `SWARD Integracion LMS`) y agrégale las funciones que usan los scripts:

   | Función REST                       | Uso                                            |
   |------------------------------------|------------------------------------------------|
   | `core_webservice_get_site_info`    | verificar token / sitio                        |
   | `core_course_get_courses`          | sincronizar cursos                             |
   | `core_course_get_courses_by_field` | buscar curso por shortname (idempotencia)      |
   | `core_course_create_courses`       | crear cursos                                   |
   | `core_course_edit_section`         | nombrar/llenar secciones (contenido por WS)    |
   | `core_course_get_contents`         | leer estructura/actividades de un curso        |
   | `core_user_create_users`           | crear docentes y estudiantes                   |
   | `core_user_get_users_by_field`     | buscar usuarios (idempotencia)                 |
   | `core_user_update_users`           | renombrar usuarios (nombres realistas)         |
   | `enrol_manual_enrol_users`         | matricular usuarios en cursos                  |
   | `mod_assign_save_grade`            | escribir calificaciones de tareas              |
   | `gradereport_user_get_grade_items` | leer calificaciones por curso (verificación)   |
   | `core_completion_update_activity_completion_status_manually` | marcar completados |

4. **Server → Web services → Manage tokens** → **Create token** para el usuario
   `admin` y el servicio creado. Copia el token (cadena hex de 32 caracteres).

Verifica el token:

```bash
TOKEN=<tu-token>
curl -s "http://localhost:8090/webservice/rest/server.php?wstoken=$TOKEN&moodlewsrestformat=json&wsfunction=core_webservice_get_site_info" \
  | jq '{sitename, username, functions: (.functions | length)}'
```

> Los scripts `*.py` leen la URL y el token de su configuración interna. Para tu
> instancia, ajusta `MOODLE_URL`/`MOODLE_TOKEN` (o `.env`) antes de ejecutarlos.
> **Nunca subas tokens reales al repositorio.**

---

## 3. Poblar Moodle

El poblado se hace en capas. Según lo que necesites, usa una o varias de estas
herramientas:

### 3.1 Estructura base — usuarios, cursos y matrículas (`seed/seed.sh`)

Crea N cursos, M docentes y K estudiantes (por defecto cumple el PRD del piloto:
≥10 estudiantes, ≥2 docentes) y los matricula. Es **idempotente** (busca por
shortname/username antes de crear).

```bash
cp seed/.env.example seed/.env       # edita y pega tu MOODLE_TOKEN
./seed/seed.sh                        # o:  make seed
NUM_COURSES=5 NUM_TEACHERS=3 NUM_STUDENTS=20 ./seed/seed.sh
./seed/seed.sh --cleanup              # elimina todos los datos del seed
```

Tras el primer seed, `fix_moodle.py` ajusta el **nombre del sitio** y asigna
**roles globales** por prefijo de username (`estudiante*` → Student,
`docente*` → Teacher).

### 3.2 Contenido académico de los cursos (vía REST)

Cada script crea/edita el contenido de un curso (secciones temáticas, recursos,
descripciones, enunciados de práctica y quizzes) usando los Web Services REST:

- `create_course_content.py` / `_v2.py` / `_final.py` — curso **Algoritmos y
  Estructuras de Datos** (varias iteraciones según las funciones WS disponibles).
- `create_bd_course_content.py` — curso **Bases de Datos** (usa
  `sql_examples_bd_course.sql` como material).
- `create_ingenieria_software.py` — curso **Ingeniería de Software**.
- `create_web_development_course.py` + `populate_web_dev_content.py` — curso
  **Desarrollo Web** (la estructura se describe en `WEB_DEVELOPMENT_COURSE.json`
  y el contenido en `WEB_DEVELOPMENT_CONTENT.json`).
- `upload_content.sh` — sube/actualiza el `summary` de secciones de curso por REST
  (alternativa en `curl` puro).

> Limitación: la API WS de Moodle **no** permite crear módulos calificables
> (assign/quiz) ni secciones sueltas. Por eso el contenido por REST se vuelca
> principalmente en el `summary` de cada sección, y la **señal calificable** se
> genera con los scripts del paso 3.3.

### 3.3 Contenido completo desde dentro del contenedor — la API interna (PHP CLI)

Para crear los módulos **calificables** que necesita el SAKT hay que usar la API
**interna** de Moodle (no la WS, que es limitada). Estos scripts se ejecutan
**dentro del contenedor** de Moodle:

```bash
# copiar el script dentro del contenedor y ejecutarlo con el PHP de Moodle
docker compose cp seed/seed_kt_interactions.php moodle:/tmp/seed_kt_interactions.php
docker compose exec moodle php /tmp/seed_kt_interactions.php
```

- **`seed/populate_moodle.php`** — puebla contenido académico real (secciones,
  recursos) de los cursos usando la API interna.
- **`seed/seed_kt_interactions.php`** — el seeder clave para el **SAKT**. En cada
  sección (= un *concepto*) de los cursos objetivo crea:
  - una **Tarea** (`assign`) → señal **calificable** (acierto/error por concepto),
  - una **Lectura** (`page`) y un **Video** (`url`) → señal de engagement/formato.

  Luego asegura un pool de estudiantes con nombres realistas, los matricula y
  **califica cada tarea** con una banda de habilidad por alumno más una
  **tendencia de aprendizaje** a lo largo de las secciones (primeras más bajas,
  últimas más altas), y marca completados. Es **idempotente** y acepta parámetros
  por variable de entorno: `COURSES`, `N_STUDENTS`, `USER_PREFIX`, `DRY_RUN`.

### 3.4 Notas e interacciones adicionales (vía REST)

Sobre cursos que **ya** tienen tareas calificables (creadas en 3.3) se puede
seguir generando señal por REST con `mod_assign_save_grade`:

- `seed_realistic_grades.py` — siembra notas **realistas y variadas**: nivel de
  habilidad determinístico por (estudiante, curso) + ruido por actividad, a ambos
  lados del 50%. Verifica con `gradereport_user_get_grade_items`.
- `seed_more_data.py` — incremental y no destructivo: renombra estudiantes con
  nombres peruanos realistas, crea cursos/secciones (conceptos) nuevos, matricula
  y **re-siembra notas con tendencia de aprendizaje**, y marca completados.
- `seed_programacion.py` — agrega cursos de **programación** + ~40 estudiantes
  nuevos (prefijos propios para no colisionar con otros seeders) + matrículas e
  interacciones a gran escala.

---

## 4. Flujo completo: poblar Moodle → sincronizar LMS → reentrenar SAKT

```
┌──────────────────────┐   ┌───────────────────────────┐   ┌──────────────────────┐
│ 1. POBLAR MOODLE     │   │ 2. SINCRONIZAR LMS        │   │ 3. REENTRENAR SAKT   │
│                      │   │                           │   │                      │
│ seed.sh (usuarios/   │   │ sward-ms-integracion-lms  │   │ pipeline SAKT toma   │
│   cursos)            │──▶│ con MOODLE_MOCK=false lee │──▶│ las interacciones    │
│ create_*/populate_*  │   │ cursos, actividades,      │   │ reales y reentrena   │
│   (contenido)        │   │ calificaciones e          │   │ el modelo de         │
│ seed_kt_interactions │   │ interacciones vía REST y  │   │ Knowledge Tracing    │
│   (módulos+notas)    │   │ las ingiere a SWARD       │   │                      │
└──────────────────────┘   └───────────────────────────┘   └──────────────────────┘
```

### 4.1 Conectar `sward-ms-integracion-lms` a este Moodle

En el `.env` del microservicio:

```dotenv
MOODLE_MOCK=false
MOODLE_BASE_URL=http://localhost:8090
MOODLE_TOKEN=<token-del-paso-2>
```

Con `MOODLE_MOCK=false` el microservicio usa el adaptador real
(`MoodleApiAdapter`) y llama a `{MOODLE_BASE_URL}/webservice/rest/server.php`.

### 4.2 Disparar la sincronización

```bash
curl -X POST http://localhost:8000/lms/sync \
  -H "Authorization: Bearer <JWT-de-acceso>" \
  -H "Content-Type: application/json"
```

### 4.3 Verificación directa contra la API REST de Moodle (sin el microservicio)

```bash
TOKEN=<tu-token>
BASE=http://localhost:8090/webservice/rest/server.php

# Cursos
curl -s "$BASE?wstoken=$TOKEN&moodlewsrestformat=json&wsfunction=core_course_get_courses" \
  | jq '.[] | {id, shortname, fullname}'

# Contenidos/actividades de un curso (sustituye COURSEID)
curl -s "$BASE?wstoken=$TOKEN&moodlewsrestformat=json&wsfunction=core_course_get_contents&courseid=COURSEID" \
  | jq '.[].modules[]? | {id, name, modname}'

# Calificaciones de un curso
curl -s "$BASE?wstoken=$TOKEN&moodlewsrestformat=json&wsfunction=gradereport_user_get_grade_items&courseid=COURSEID" \
  | jq '.usergrades[]? | {userid, gradeitems: (.gradeitems | length)}'
```

Si estos comandos devuelven datos, la sincronización real funcionará y el SAKT
recibirá interacciones para reentrenar.

---

## Operación

```bash
make up        # levantar Moodle + MariaDB
make wait      # esperar bootstrap
make ps        # estado / health
make logs      # ver logs de Moodle
make seed      # cargar datos de prueba (requiere seed/.env con el token)
make token     # recordar cómo generar el token (paso manual en la UI)
make down      # parar (conserva datos/volúmenes)
make clean     # parar y BORRAR volúmenes (reset total)
```

---

## Notas

- Los volúmenes (`mariadb_data`, `moodledata_data`, `moodlehtml_data`) persisten
  los datos entre reinicios. Usa `make clean` para empezar de cero.
- Los scripts de seed son **idempotentes / no destructivos**: re-ejecutarlos no
  duplica usuarios ni módulos.
- Si cambias el puerto publicado, actualiza también `SITE_URL` en
  `docker-compose.yml` para que Moodle genere URLs correctas.
- `seed/.env` está en `.gitignore` porque contiene el token de Web Services.
  **Nunca** subas tokens ni credenciales al repositorio.
