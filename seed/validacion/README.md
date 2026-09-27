# Cursos de la validación del OE4

Estadística (`SWARD-EST`) y Matemática Financiera (`SWARD-MF`): seis temas cada
uno, dos páginas por tema (resumen y ejemplo resuelto) y tres quizzes de cuatro
preguntas, de un solo intento y calificados sobre 20 (escala vigesimal).

- `banco.py`: el contenido. Las respuestas numéricas se calculan aquí, no se
  escriben a mano. Es lo único que se edita cuando el profesor corrige.
- `generar.py`: valida el banco y escribe `salida/cursos.json` (para Moodle) y
  `salida/revision_banco.html` (para que el profesor lo revise).
- `cargar_cursos.php`: crea los cursos en Moodle a partir del JSON. Idempotente.
- `textos_aula.php`: los textos propios del aula —el correo de alta y el título
  del recuadro de acceso—. Van juntos porque Moodle guarda todas las
  personalizaciones de idioma en un mismo archivo: dos guiones se pisarían.
  **Correrlo después de cada despliegue nuevo**, como `cargar_cursos.php`.
- `acceso.php`: obliga a identificarse para ver cualquier cosa y manda la
  recuperación de contraseña a SWARD. Sin lo primero, la portada enseña el
  catálogo del estudio a cualquiera con el enlace; sin lo segundo, recuperarla
  cambiaría sólo la del aula y volverían a ser dos distintas.
  **Correrlo después de cada despliegue.**
- `politica_contrasena.php`: deja la regla de contraseña igual a la de SWARD
  —8 caracteres, una mayúscula y un número—. La de fábrica pedía además
  minúscula y carácter especial, así que Moodle rechazaba la que SWARD acababa
  de aceptar. **Correrlo después de cada despliegue nuevo.**
- `numeros_peru.php`: pone el separador decimal en punto y el de miles en coma.
  El paquete `es` es el de España y hace lo contrario, así que Moodle rechazaba
  `15.5` en las preguntas numéricas pidiendo «sin separador de miles», que es
  justo lo que el enunciado manda usar. **Correrlo después de cada despliegue
  nuevo**, como `cargar_cursos.php`.
- `borrar_intentos_huerfanos.php`: borra los intentos de quiz de cuentas ya
  eliminadas. Moodle no borra al usuario, lo anonimiza, y **conserva sus
  intentos**: son justo lo que la sincronización lleva a SWARD y con lo que el
  modelo aprende. Correrlo junto con la purga de trazabilidad, antes de que
  entre el primer participante real.
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
