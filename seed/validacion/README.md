# Cursos de la validación del OE4

Estadística (`SWARD-EST`) y Matemática Financiera (`SWARD-MF`): seis temas cada
uno, dos páginas por tema (resumen y ejemplo resuelto) y tres quizzes de cuatro
preguntas, de un solo intento y calificados sobre 20 (escala vigesimal).

- `banco.py`: el contenido. Las respuestas numéricas se calculan aquí, no se
  escriben a mano. Es lo único que se edita cuando el profesor corrige.
- `generar.py`: valida el banco y escribe `salida/cursos.json` (para Moodle) y
  `salida/revision_banco.html` (para que el profesor lo revise).
- `cargar_cursos.php`: crea los cursos en Moodle a partir del JSON. Idempotente.
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
