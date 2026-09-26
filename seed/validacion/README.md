# Cursos de la validación del OE4

Estadística (`SWARD-EST`) y Matemática Financiera (`SWARD-MF`): seis temas cada
uno, dos páginas por tema (resumen y ejemplo resuelto) y tres quizzes de cuatro
preguntas, de un solo intento y calificados sobre 20 (escala vigesimal).

- `banco.py`: el contenido. Las respuestas numéricas se calculan aquí, no se
  escriben a mano. Es lo único que se edita cuando el profesor corrige.
- `generar.py`: valida el banco y escribe `salida/cursos.json` (para Moodle) y
  `salida/revision_banco.html` (para que el profesor lo revise).
- `cargar_cursos.php`: crea los cursos en Moodle a partir del JSON. Idempotente.
- `correo_bienvenida.php`: el texto del correo que Moodle manda al crear una
  cuenta. El de fábrica confundía la aplicación con el aula, pedía un nombre de
  usuario que no hace falta y numeraba los enlaces al pie como [1] y [2].
  **Correrlo después de cada despliegue nuevo**, como `cargar_cursos.php`.
- `numeros_peru.php`: pone el separador decimal en punto y el de miles en coma.
  El paquete `es` es el de España y hace lo contrario, así que Moodle rechazaba
  `15.5` en las preguntas numéricas pidiendo «sin separador de miles», que es
  justo lo que el enunciado manda usar. **Correrlo después de cada despliegue
  nuevo**, como `cargar_cursos.php`.
- `simular_fase1.php`: estudiantes ficticios que rinden los quizzes, para ensayar
  la sincronización y el reentrenamiento. **Borrarlos antes de la fase 1 real.**

```bash
python seed/validacion/generar.py
docker cp seed/validacion/salida/cursos.json sward-moodle-app:/tmp/cursos.json
docker cp seed/validacion/cargar_cursos.php sward-moodle-app:/tmp/cargar_cursos.php
docker exec sward-moodle-app php /tmp/cargar_cursos.php /tmp/cursos.json

# ensayo
docker cp seed/validacion/simular_fase1.php sward-moodle-app:/tmp/simular_fase1.php
docker exec sward-moodle-app php /tmp/simular_fase1.php
docker exec sward-moodle-app php /tmp/simular_fase1.php --borrar
```

Los nombres de los temas son las secciones del curso y, a la vez, los conceptos
del SAKT: no se renombran después de entrenar, y no se repiten entre cursos.
