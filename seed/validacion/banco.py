"""Cursos de la validación del OE4: temas, material y banco de preguntas.

Fuente única de los dos cursos (Estadística y Matemática Financiera). De aquí
salen los archivos GIFT que importa Moodle, el JSON que usa el script de carga y
el documento que revisa el profesor (generar.py).

Cada tema es una sección del curso y un concepto del modelo SAKT: su nombre es
la clave con que el modelo lo reconoce, así que **no se cambia después de
entrenar**. Cada tema tiene dos páginas (resumen y ejemplo resuelto) y tres
quizzes de cuatro preguntas: básico, intermedio y aplicado.

Las respuestas numéricas se calculan aquí, no se escriben a mano.
"""

from math import comb, log, sqrt

# ────────────────────────────────────────────────────────────── utilidades


def num(enunciado, respuesta, tol=None, explicacion=""):
    """Pregunta numérica. `tol` absoluta; por defecto la fija generar.py."""
    return {"tipo": "numero", "enunciado": enunciado, "respuesta": respuesta,
            "tol": tol, "explicacion": explicacion}


def opc(enunciado, correcta, distractores, explicacion=""):
    """Opción múltiple con una sola respuesta correcta."""
    return {"tipo": "opcion", "enunciado": enunciado, "correcta": correcta,
            "distractores": list(distractores), "explicacion": explicacion}


def r2(x):
    return round(x + 1e-12, 2)


def media(xs):
    return sum(xs) / len(xs)


def var_muestral(xs):
    m = media(xs)
    return sum((x - m) ** 2 for x in xs) / (len(xs) - 1)


def fas(i, n):  # factor de valor presente de una anualidad vencida
    return (1 - (1 + i) ** -n) / i


def fsa(i, n):  # factor de valor futuro de una anualidad vencida
    return ((1 + i) ** n - 1) / i


def cuota(p, i, n):
    return p * i / (1 - (1 + i) ** -n)


NOTA_NUM = (" Escribe solo el número, sin símbolos (S/, %) ni separador de miles, y usa punto para "
            "los decimales (por ejemplo, 1234.56).")


# ──────────────────────────────────────────────────────────── ESTADÍSTICA

TENDENCIA = {
    "tema": "Tendencia central",
    "resumen": """
<p>Las medidas de tendencia central resumen con un solo número dónde se ubican los datos.</p>
<ul>
<li><strong>Media</strong> (promedio): la suma de los datos dividida entre cuántos son. Usa todos los datos, por eso un valor muy alto o muy bajo (atípico) la arrastra.</li>
<li><strong>Mediana</strong>: el valor del centro cuando los datos están ordenados. Con una cantidad par de datos, es el promedio de los dos centrales. No la afectan los atípicos.</li>
<li><strong>Moda</strong>: el valor que más se repite. Puede no haber moda o haber varias.</li>
<li><strong>Media ponderada</strong>: cuando cada dato pesa distinto, se multiplica cada uno por su peso y se suma (con pesos que suman 1).</li>
</ul>
<p>Con datos asimétricos (por ejemplo, sueldos), la mediana describe mejor el valor típico que la media.</p>""",
    "ejemplo": """
<p><strong>Notas de 7 estudiantes:</strong> 12, 15, 11, 18, 15, 9, 14.</p>
<ol>
<li>Ordenadas: 9, 11, 12, 14, 15, 15, 18.</li>
<li>Media = (9 + 11 + 12 + 14 + 15 + 15 + 18) / 7 = 94 / 7 = <strong>13.43</strong>.</li>
<li>Mediana: el dato central (el 4.º de 7) = <strong>14</strong>.</li>
<li>Moda: el 15 aparece dos veces = <strong>15</strong>.</li>
</ol>""",
    "quizzes": [
        [
            num("Calcula la media de 8, 12, 10, 14 y 6." + NOTA_NUM, media([8, 12, 10, 14, 6])),
            num("Calcula la mediana de 3, 9, 4, 7 y 10." + NOTA_NUM, 7),
            opc("¿Cuál es la moda de 2, 5, 5, 7, 8, 8, 8, 9?", "8", ["5", "6.5", "7.5"]),
            opc("¿Qué medida es la más afectada por un valor atípico?", "La media",
                ["La mediana", "La moda", "Las tres por igual"]),
        ],
        [
            num("Calcula la mediana de 4, 8, 15, 16, 23 y 42." + NOTA_NUM, (15 + 16) / 2),
            num("Un curso califica así: parcial 30 %, final 40 % y prácticas 30 %. Un estudiante tiene 14 en el parcial, "
                "12 en el final y 16 en prácticas. ¿Cuál es su promedio ponderado?" + NOTA_NUM,
                r2(14 * 0.3 + 12 * 0.4 + 16 * 0.3)),
            opc("Cinco sueldos: 1200, 1300, 1250, 1400 y 9000. ¿Qué medida representa mejor el sueldo típico?",
                "La mediana", ["La media", "El rango", "La suma de los sueldos"]),
            num("La media de 5 números es 12. Si se agrega el número 18, ¿cuál es la nueva media?" + NOTA_NUM,
                (5 * 12 + 18) / 6),
        ],
        [
            num("En una tabla de frecuencias, el valor 1 aparece 4 veces, el 2 aparece 6 veces y el 3 aparece 10 veces. "
                "¿Cuál es la media?" + NOTA_NUM, (1 * 4 + 2 * 6 + 3 * 10) / 20),
            opc("En una distribución con asimetría positiva (cola larga hacia la derecha), lo usual es que:",
                "La media sea mayor que la mediana",
                ["La media sea menor que la mediana", "Media, mediana y moda sean iguales",
                 "La moda sea mayor que la media"]),
            num("Un estudiante tiene 11, 13 y 15 en tres prácticas. ¿Qué nota necesita en la cuarta para que su media "
                "sea 14?" + NOTA_NUM, 4 * 14 - (11 + 13 + 15)),
            opc("Si a cada dato de un conjunto se le suma 5, la media:", "Aumenta en 5",
                ["No cambia", "Se multiplica por 5", "Aumenta en 5 solo si hay más de 5 datos"]),
        ],
    ],
}

DISPERSION = {
    "tema": "Dispersión",
    "resumen": """
<p>Dos grupos pueden tener la misma media y ser muy distintos: las medidas de dispersión dicen cuánto se alejan los datos del centro.</p>
<ul>
<li><strong>Rango</strong>: máximo menos mínimo. Es simple, pero solo mira dos datos.</li>
<li><strong>Varianza muestral</strong>: el promedio de las desviaciones al cuadrado, dividiendo entre <em>n − 1</em>: s² = Σ(x − x̄)² / (n − 1).</li>
<li><strong>Desviación estándar</strong>: la raíz cuadrada de la varianza, en las mismas unidades que los datos.</li>
<li><strong>Coeficiente de variación</strong>: CV = s / x̄ × 100 %. Sirve para comparar la dispersión de grupos con medias distintas.</li>
<li><strong>Rango intercuartílico</strong>: RIC = Q3 − Q1, el ancho del 50 % central de los datos.</li>
</ul>""",
    "ejemplo": """
<p><strong>Datos:</strong> 4, 6, 8, 10, 12.</p>
<ol>
<li>Media: 40 / 5 = 8.</li>
<li>Desviaciones: −4, −2, 0, 2, 4. Al cuadrado: 16, 4, 0, 4, 16. Suma: 40.</li>
<li>Varianza muestral: 40 / (5 − 1) = <strong>10</strong>.</li>
<li>Desviación estándar: √10 = <strong>3.16</strong>.</li>
<li>CV = 3.16 / 8 × 100 % = <strong>39.5 %</strong>.</li>
</ol>""",
    "quizzes": [
        [
            num("Calcula el rango de 7, 3, 12, 9 y 5." + NOTA_NUM, 12 - 3),
            num("Calcula la varianza muestral de 2, 4 y 6." + NOTA_NUM, var_muestral([2, 4, 6])),
            num("Calcula la desviación estándar muestral de 2, 4 y 6." + NOTA_NUM, sqrt(var_muestral([2, 4, 6]))),
            opc("Si todos los datos de un conjunto son iguales, la desviación estándar es:", "0",
                ["1", "Igual a la media", "No se puede calcular"]),
        ],
        [
            num("Calcula la varianza muestral de 5, 7, 9 y 11 (dos decimales)." + NOTA_NUM,
                r2(var_muestral([5, 7, 9, 11]))),
            num("Un grupo tiene media 50 y desviación estándar 5. ¿Cuál es su coeficiente de variación en porcentaje?"
                + NOTA_NUM, 5 / 50 * 100),
            opc("Dos grupos tienen media 14. El grupo A tiene s = 1.5 y el grupo B, s = 4. ¿Cuál es más homogéneo?",
                "El grupo A", ["El grupo B", "Son igual de homogéneos", "No se puede saber sin la mediana"]),
            num("Si la varianza de un conjunto es 16, ¿cuál es su desviación estándar?" + NOTA_NUM, sqrt(16)),
        ],
        [
            num("Los sueldos de una empresa tienen media 2500 y desviación estándar 500. ¿Cuál es el coeficiente de "
                "variación en porcentaje?" + NOTA_NUM, 500 / 2500 * 100),
            num("En un conjunto de datos, Q1 = 12 y Q3 = 20. ¿Cuál es el rango intercuartílico?" + NOTA_NUM, 20 - 12),
            opc("Si cada dato se multiplica por 3, la desviación estándar:", "Se multiplica por 3",
                ["No cambia", "Se multiplica por 9", "Aumenta en 3"]),
            num("Calcula la desviación estándar muestral de 10, 12, 14, 16 y 18 (dos decimales)." + NOTA_NUM,
                r2(sqrt(var_muestral([10, 12, 14, 16, 18])))),
        ],
    ],
}

PROBABILIDAD = {
    "tema": "Probabilidad",
    "resumen": """
<p>La probabilidad mide qué tan posible es un evento, de 0 (imposible) a 1 (seguro).</p>
<ul>
<li>Con resultados igual de posibles: P(A) = casos favorables / casos posibles.</li>
<li><strong>Complemento</strong>: P(no A) = 1 − P(A).</li>
<li><strong>Suma</strong>: P(A o B) = P(A) + P(B) − P(A y B). Si A y B no pueden ocurrir a la vez (excluyentes), P(A y B) = 0.</li>
<li><strong>Independencia</strong>: si uno no afecta al otro, P(A y B) = P(A) · P(B).</li>
<li><strong>Condicional</strong>: la probabilidad de A sabiendo que ocurrió B, P(A | B) = P(A y B) / P(B).</li>
</ul>""",
    "ejemplo": """
<p><strong>Se lanza un dado.</strong> A = sale par (2, 4, 6); B = sale mayor que 4 (5, 6).</p>
<ol>
<li>P(A) = 3/6 = 0.5 y P(B) = 2/6 = 0.33.</li>
<li>A y B a la vez: solo el 6, P(A y B) = 1/6.</li>
<li>P(A o B) = 3/6 + 2/6 − 1/6 = 4/6 = <strong>0.67</strong>.</li>
<li>P(A | B) = (1/6) / (2/6) = <strong>0.5</strong>: sabiendo que salió 5 o 6, la mitad de las veces es par.</li>
</ol>""",
    "quizzes": [
        [
            num("Al lanzar un dado, ¿cuál es la probabilidad de obtener un número mayor que 4? Da el resultado en "
                "decimal con dos decimales." + NOTA_NUM, r2(2 / 6)),
            num("Si P(A) = 0.35, ¿cuánto vale P(no A)?" + NOTA_NUM, 1 - 0.35),
            num("Una urna tiene 3 bolas rojas y 7 azules. ¿Cuál es la probabilidad de sacar una roja?" + NOTA_NUM,
                3 / 10),
            opc("Dos eventos son mutuamente excluyentes cuando:", "No pueden ocurrir a la vez",
                ["Uno no afecta la probabilidad del otro", "Sus probabilidades siempre suman 1",
                 "Siempre ocurren juntos"]),
        ],
        [
            num("Se lanzan dos monedas. ¿Cuál es la probabilidad de obtener dos caras?" + NOTA_NUM, 0.5 * 0.5),
            num("P(A) = 0.5, P(B) = 0.4 y P(A y B) = 0.2. ¿Cuánto vale P(A o B)?" + NOTA_NUM, 0.5 + 0.4 - 0.2),
            num("A y B son independientes, con P(A) = 0.6 y P(B) = 0.5. ¿Cuánto vale P(A y B)?" + NOTA_NUM,
                0.6 * 0.5),
            opc("Si A y B son independientes, P(A | B) es igual a:", "P(A)", ["P(B)", "P(A) · P(B)", "0"]),
        ],
        [
            num("En un salón, el 60 % aprueba Estadística y, de quienes la aprueban, el 50 % también aprueba Cálculo. "
                "¿Cuál es la probabilidad de que un estudiante al azar apruebe ambos cursos?" + NOTA_NUM, 0.6 * 0.5),
            num("P(A y B) = 0.12 y P(B) = 0.4. ¿Cuánto vale P(A | B)?" + NOTA_NUM, 0.12 / 0.4),
            num("Se extraen dos cartas sin reposición de una baraja de 52. ¿Cuál es la probabilidad de que ambas sean "
                "ases? Da el resultado con cuatro decimales." + NOTA_NUM, round(4 / 52 * 3 / 51, 4), tol=0.0001),
            num("El 2 % de las piezas de una fábrica es defectuosa. Una prueba detecta el 90 % de las defectuosas, pero "
                "también marca como defectuosas al 5 % de las buenas. Si una pieza sale marcada, ¿cuál es la "
                "probabilidad de que de verdad sea defectuosa? (dos decimales)" + NOTA_NUM,
                r2(0.02 * 0.9 / (0.02 * 0.9 + 0.98 * 0.05))),
        ],
    ],
}

DISTRIBUCIONES = {
    "tema": "Distribuciones binomial y normal",
    "resumen": """
<p><strong>Binomial</strong>: cuenta los éxitos en <em>n</em> ensayos independientes, cada uno con probabilidad <em>p</em> de éxito.</p>
<ul>
<li>P(X = k) = C(n, k) · p<sup>k</sup> · (1 − p)<sup>n − k</sup>, donde C(n, k) es el número de combinaciones.</li>
<li>Media: n · p. Varianza: n · p · (1 − p).</li>
</ul>
<p><strong>Normal</strong>: la curva de campana, definida por su media μ y su desviación estándar σ.</p>
<ul>
<li>Se estandariza con z = (x − μ) / σ y se busca la probabilidad en la tabla de la normal estándar.</li>
<li>Regla empírica: cerca del 68 % de los datos cae a menos de 1σ de la media, el 95 % a menos de 2σ y el 99.7 % a menos de 3σ.</li>
</ul>""",
    "ejemplo": """
<p><strong>Binomial.</strong> Se responden 4 preguntas de verdadero o falso al azar (p = 0.5). ¿Probabilidad de acertar exactamente 2?</p>
<p>C(4, 2) · 0.5² · 0.5² = 6 · 0.0625 = <strong>0.375</strong>.</p>
<p><strong>Normal.</strong> Los puntajes tienen μ = 70 y σ = 10. ¿Qué proporción supera 85?</p>
<p>z = (85 − 70) / 10 = 1.5. La tabla da P(Z ≤ 1.5) = 0.9332, así que P(X &gt; 85) = 1 − 0.9332 = <strong>0.0668</strong>.</p>""",
    "quizzes": [
        [
            num("X es binomial con n = 5 y p = 0.5. ¿Cuál es su media?" + NOTA_NUM, 5 * 0.5),
            num("X es binomial con n = 10 y p = 0.2. ¿Cuál es su varianza?" + NOTA_NUM, 10 * 0.2 * 0.8),
            num("Una variable normal tiene μ = 60 y σ = 8. ¿Cuál es el valor z de x = 76?" + NOTA_NUM, (76 - 60) / 8),
            opc("En una distribución normal, ¿qué porcentaje aproximado de los datos cae a menos de una desviación "
                "estándar de la media?", "68 %", ["50 %", "95 %", "99.7 %"]),
        ],
        [
            num("X es binomial con n = 3 y p = 0.5. ¿Cuánto vale P(X = 2)? (tres decimales)" + NOTA_NUM,
                round(comb(3, 2) * 0.5 ** 3, 3), tol=0.001),
            num("X es binomial con n = 4 y p = 0.3. ¿Cuánto vale P(X = 0)? (cuatro decimales)" + NOTA_NUM,
                round(0.7 ** 4, 4), tol=0.0001),
            num("Una variable normal tiene μ = 70 y σ = 10. Sabiendo que P(Z ≤ 1.5) = 0.9332, ¿cuánto vale P(X > 85)? "
                "(cuatro decimales)" + NOTA_NUM, round(1 - 0.9332, 4), tol=0.0005),
            opc("¿Cuál de estas situaciones se modela con una distribución binomial?",
                "El número de aciertos al responder al azar 10 preguntas de opción múltiple",
                ["El tiempo que tarda en llegar un bus", "La estatura de los estudiantes de un salón",
                 "El sueldo mensual de un trabajador"]),
        ],
        [
            num("Un examen de 6 preguntas de verdadero o falso se responde al azar. ¿Cuál es la probabilidad de acertar "
                "exactamente 5? (cuatro decimales)" + NOTA_NUM, round(comb(6, 5) * 0.5 ** 6, 4), tol=0.0001),
            num("Una variable normal tiene μ = 500 y σ = 100. Sabiendo que P(Z ≤ 1) = 0.8413 y P(Z ≤ −1) = 0.1587, "
                "¿cuánto vale P(400 ≤ X ≤ 600)? (cuatro decimales)" + NOTA_NUM, round(0.8413 - 0.1587, 4),
                tol=0.0005),
            num("El 95 % central de una normal está entre μ − 1.96σ y μ + 1.96σ. Si μ = 50 y σ = 5, ¿cuál es el "
                "límite superior?" + NOTA_NUM, 50 + 1.96 * 5),
            opc("En una binomial con n fijo, si p aumenta, la media n · p:", "Aumenta",
                ["Disminuye", "No cambia", "Depende de la varianza"]),
        ],
    ],
}

INTERVALOS = {
    "tema": "Intervalos de confianza",
    "resumen": """
<p>Un intervalo de confianza da un rango de valores plausibles para un parámetro de la población, a partir de una muestra.</p>
<ul>
<li><strong>Media, con σ conocida</strong>: x̄ ± z · σ / √n.</li>
<li>Valores de z: 1.645 para 90 %, 1.96 para 95 % y 2.576 para 99 %.</li>
<li>El <strong>margen de error</strong> es E = z · σ / √n. Más confianza o más dispersión lo agrandan; más muestra lo achica.</li>
<li><strong>Tamaño de muestra</strong> para un margen E: n = (z · σ / E)², redondeado hacia arriba.</li>
<li><strong>Proporción</strong>: p̂ ± z · √(p̂ (1 − p̂) / n).</li>
</ul>
<p>Un intervalo del 95 % no dice que la media «tiene 95 % de probabilidad» de estar ahí: dice que el procedimiento acierta en el 95 % de las muestras.</p>""",
    "ejemplo": """
<p><strong>Se midió a 36 estudiantes:</strong> x̄ = 80, con σ = 12 conocida. Intervalo del 95 %.</p>
<ol>
<li>Error estándar: σ / √n = 12 / 6 = 2.</li>
<li>Margen de error: 1.96 · 2 = 3.92.</li>
<li>Intervalo: 80 ± 3.92 = <strong>[76.08; 83.92]</strong>.</li>
</ol>""",
    "quizzes": [
        [
            num("x̄ = 50, σ = 10 y n = 25. Con 95 % de confianza (z = 1.96), ¿cuál es el margen de error?" + NOTA_NUM,
                r2(1.96 * 10 / sqrt(25))),
            num("Con los datos anteriores (x̄ = 50, margen de error 3.92), ¿cuál es el límite inferior del "
                "intervalo?" + NOTA_NUM, r2(50 - 1.96 * 10 / sqrt(25))),
            opc("Si con la misma muestra se sube el nivel de confianza de 95 % a 99 %, el intervalo:",
                "Se hace más ancho", ["Se hace más angosto", "No cambia", "Se desplaza hacia la derecha"]),
            opc("Un intervalo de confianza del 95 % para la media significa que:",
                "El procedimiento contiene a la media de la población en el 95 % de las muestras posibles",
                ["El 95 % de los datos está dentro del intervalo",
                 "La media de la muestra está en el intervalo con 95 % de probabilidad",
                 "Los datos tienen un 5 % de error"]),
        ],
        [
            num("x̄ = 120, σ = 15 y n = 100. Con 90 % de confianza (z = 1.645), ¿cuál es el límite superior del "
                "intervalo?" + NOTA_NUM, r2(120 + 1.645 * 15 / sqrt(100))),
            num("En una muestra de 100 personas, p̂ = 0.4. Con 95 % de confianza, ¿cuál es el margen de error? "
                "(tres decimales)" + NOTA_NUM, round(1.96 * sqrt(0.4 * 0.6 / 100), 3), tol=0.001),
            opc("Si se cuadruplica el tamaño de la muestra, el margen de error:", "Se reduce a la mitad",
                ["Se reduce a la cuarta parte", "Se duplica", "No cambia"]),
            opc("¿Qué valor de z corresponde a un nivel de confianza del 99 %?", "2.576", ["1.645", "1.96", "3.00"]),
        ],
        [
            num("Con σ = 20 y 95 % de confianza, ¿qué tamaño de muestra se necesita para un margen de error de 4? "
                "Redondea hacia arriba." + NOTA_NUM, 97, tol=0),
            num("Se encuestó a 400 estudiantes y 240 usan Moodle a diario. ¿Cuál es el límite inferior del intervalo "
                "del 95 % para la proporción? (tres decimales)" + NOTA_NUM,
                round(0.6 - 1.96 * sqrt(0.6 * 0.4 / 400), 3), tol=0.001),
            opc("Para reducir el margen de error sin bajar el nivel de confianza, conviene:",
                "Aumentar el tamaño de la muestra",
                ["Reducir el tamaño de la muestra", "Usar un valor de z más grande", "Cambiar la media muestral"]),
            num("Un intervalo del 95 % para la media es [72; 78]. ¿Cuál fue la media de la muestra?" + NOTA_NUM,
                (72 + 78) / 2),
        ],
    ],
}

HIPOTESIS = {
    "tema": "Pruebas de hipótesis",
    "resumen": """
<p>Una prueba de hipótesis decide, con datos de una muestra, si hay evidencia contra una afirmación sobre la población.</p>
<ul>
<li><strong>H0</strong> (hipótesis nula): no hay efecto o diferencia, por ejemplo μ = μ0. <strong>H1</strong>: lo que se quiere mostrar (μ ≠ μ0, μ &gt; μ0 o μ &lt; μ0).</li>
<li><strong>Estadístico</strong>: z = (x̄ − μ0) / (σ / √n).</li>
<li><strong>Valor p</strong>: qué tan raros serían los datos si H0 fuera cierta. Si p &lt; α (por ejemplo 0.05), se rechaza H0.</li>
<li>En una prueba bilateral con α = 0.05, se rechaza H0 si |z| &gt; 1.96.</li>
<li><strong>Error tipo I</strong>: rechazar H0 siendo verdadera (su probabilidad es α). <strong>Error tipo II</strong>: no rechazarla siendo falsa.</li>
</ul>""",
    "ejemplo": """
<p><strong>¿El tiempo medio de estudio es distinto de 100 minutos?</strong> H0: μ = 100; H1: μ ≠ 100; α = 0.05.</p>
<ol>
<li>Muestra de 36 estudiantes: x̄ = 104, con σ = 12.</li>
<li>z = (104 − 100) / (12 / 6) = 4 / 2 = 2.</li>
<li>|2| &gt; 1.96, así que se <strong>rechaza H0</strong>: hay evidencia de que la media es distinta de 100.</li>
</ol>""",
    "quizzes": [
        [
            opc("La hipótesis nula (H0) suele afirmar que:", "No hay efecto o diferencia",
                ["Lo que el investigador quiere demostrar es cierto", "La muestra es grande",
                 "El valor p es menor que 0.05"]),
            opc("Rechazar H0 cuando en realidad es verdadera es un error:", "Tipo I",
                ["Tipo II", "De muestreo", "De medición"]),
            opc("Con α = 0.05 y un valor p de 0.03, la decisión es:", "Rechazar H0",
                ["No rechazar H0", "Aceptar H1 con total certeza", "Repetir la prueba con otra muestra"]),
            num("H0: μ = 50. En una muestra de 36, x̄ = 53, con σ = 6. Calcula el estadístico z." + NOTA_NUM,
                (53 - 50) / (6 / sqrt(36))),
        ],
        [
            num("H0: μ = 200. En una muestra de 64, x̄ = 195, con σ = 20. Calcula el estadístico z." + NOTA_NUM,
                (195 - 200) / (20 / sqrt(64))),
            opc("En una prueba bilateral con α = 0.05, se rechaza H0 cuando |z| es mayor que:", "1.96",
                ["1.645", "2.576", "0.05"]),
            opc("Un valor p de 0.20 indica que:", "Los datos no dan evidencia suficiente contra H0",
                ["H0 es verdadera con 80 % de probabilidad", "H1 es verdadera", "Se cometió un error tipo I"]),
            opc("Se quiere probar que un nuevo método aumenta el rendimiento medio respecto de μ0. La hipótesis "
                "alternativa es:", "μ > μ0", ["μ < μ0", "μ ≠ μ0", "μ = μ0"]),
        ],
        [
            num("Una empresa afirma que el tiempo medio de atención es 10 minutos. En 49 casos, x̄ = 11, con σ = 3.5. "
                "Calcula el estadístico z." + NOTA_NUM, (11 - 10) / (3.5 / sqrt(49))),
            opc("Con el estadístico del caso anterior (z = 2) y α = 0.05 en una prueba bilateral, la decisión es:",
                "Rechazar H0", ["No rechazar H0", "No se puede decidir", "Aceptar H0 con certeza"]),
            opc("Si se aumenta el tamaño de la muestra y se mantiene α, en general:",
                "Disminuye la probabilidad de error tipo II",
                ["Aumenta la probabilidad de error tipo I", "No cambia nada", "Aumenta α"]),
            opc("En una prueba con α = 0.01 se obtuvo un valor p de 0.04. La decisión es:", "No rechazar H0",
                ["Rechazar H0", "Rechazar H0 solo si la prueba es bilateral", "Subir α a 0.05 y rechazar H0"]),
        ],
    ],
}

# ──────────────────────────────────────────────────── MATEMÁTICA FINANCIERA
# Año comercial de 360 días. Resultados en soles, con dos decimales.

INTERES_SIMPLE = {
    "tema": "Interés simple",
    "resumen": """
<p>En el interés simple, el interés se calcula siempre sobre el capital inicial: cada periodo gana lo mismo.</p>
<ul>
<li>Interés: I = P · i · t, donde P es el capital, i la tasa y t el tiempo.</li>
<li>Monto: S = P · (1 + i · t).</li>
<li>La tasa y el tiempo deben estar en la misma unidad: con tasa anual y tiempo en días, t = días / 360 (año comercial).</li>
<li>Despejes útiles: P = I / (i · t), i = I / (P · t), t = I / (P · i).</li>
</ul>""",
    "ejemplo": """
<p><strong>Se prestan S/ 5000 al 12 % anual simple por 9 meses.</strong></p>
<ol>
<li>Tiempo en años: t = 9 / 12 = 0.75.</li>
<li>Interés: I = 5000 · 0.12 · 0.75 = <strong>S/ 450</strong>.</li>
<li>Monto: S = 5000 + 450 = <strong>S/ 5450</strong>.</li>
</ol>""",
    "quizzes": [
        [
            num("Un capital de S/ 2000 se invierte al 10 % anual simple durante 3 años. ¿Cuánto interés gana?"
                + NOTA_NUM, 2000 * 0.10 * 3),
            num("En el caso anterior, ¿cuál es el monto al final de los 3 años?" + NOTA_NUM, 2000 * (1 + 0.10 * 3)),
            num("Un capital de S/ 1500 se invierte al 2 % mensual simple durante 6 meses. ¿Cuánto interés gana?"
                + NOTA_NUM, 1500 * 0.02 * 6),
            opc("En el interés simple, el interés de cada periodo:", "Es el mismo en todos los periodos",
                ["Crece periodo a periodo", "Disminuye periodo a periodo", "Depende del monto acumulado"]),
        ],
        [
            num("Un préstamo de S/ 8000 al 18 % anual simple por 120 días (año de 360 días). ¿Cuánto interés se "
                "paga?" + NOTA_NUM, r2(8000 * 0.18 * 120 / 360)),
            num("¿Qué capital genera S/ 300 de interés en 2 años al 5 % anual simple?" + NOTA_NUM, 300 / (0.05 * 2)),
            num("Un capital de S/ 4000 se convirtió en S/ 4600 en 1.5 años. ¿Cuál fue la tasa de interés simple "
                "anual, en porcentaje?" + NOTA_NUM, (4600 - 4000) / (4000 * 1.5) * 100),
            num("¿En cuántos meses un capital de S/ 1000 al 3 % mensual simple genera S/ 240 de interés?" + NOTA_NUM,
                240 / (1000 * 0.03)),
        ],
        [
            num("¿Cuánto hay que depositar hoy al 12 % anual simple para tener S/ 5600 dentro de 1 año?" + NOTA_NUM,
                r2(5600 / (1 + 0.12 * 1))),
            num("Un préstamo de S/ 10 000 al 24 % anual simple por 45 días (año de 360). ¿Cuál es el monto a pagar?"
                + NOTA_NUM, r2(10000 * (1 + 0.24 * 45 / 360))),
            opc("En el interés simple, si se duplica el tiempo, el interés:", "Se duplica",
                ["Se cuadruplica", "No cambia", "Aumenta más que el doble"]),
            num("¿En cuántos años se duplica un capital al 8 % anual simple?" + NOTA_NUM, 1 / 0.08),
        ],
    ],
}

INTERES_COMPUESTO = {
    "tema": "Interés compuesto",
    "resumen": """
<p>En el interés compuesto, el interés de cada periodo se suma al capital y también gana intereses (se <em>capitaliza</em>).</p>
<ul>
<li>Monto: S = P · (1 + i)<sup>n</sup>, con i la tasa por periodo y n el número de periodos.</li>
<li>Valor presente: P = S / (1 + i)<sup>n</sup>.</li>
<li>Número de periodos: n = ln(S / P) / ln(1 + i).</li>
<li>Tasa: i = (S / P)<sup>1/n</sup> − 1.</li>
</ul>
<p>A igual tasa y plazo mayor que un periodo, el compuesto da más interés que el simple.</p>""",
    "ejemplo": """
<p><strong>Se depositan S/ 5000 al 10 % anual compuesto por 3 años.</strong></p>
<ol>
<li>Factor: (1.10)³ = 1.331.</li>
<li>Monto: S = 5000 · 1.331 = <strong>S/ 6655</strong>.</li>
<li>Interés: 6655 − 5000 = S/ 1655. Con interés simple habría sido S/ 1500.</li>
</ol>""",
    "quizzes": [
        [
            num("Se depositan S/ 1000 al 10 % anual compuesto por 2 años. ¿Cuál es el monto?" + NOTA_NUM,
                r2(1000 * 1.10 ** 2)),
            num("Se depositan S/ 2000 al 5 % anual compuesto por 3 años. ¿Cuál es el monto?" + NOTA_NUM,
                r2(2000 * 1.05 ** 3)),
            opc("Frente al interés simple, con la misma tasa y un plazo mayor que un periodo, el interés compuesto "
                "produce:", "Más interés", ["Menos interés", "El mismo interés", "Depende del capital"]),
            num("En el depósito de S/ 1000 al 10 % anual por 2 años, ¿cuánto interés se ganó?" + NOTA_NUM,
                r2(1000 * 1.10 ** 2 - 1000)),
        ],
        [
            num("¿Cuál es el valor presente de S/ 5000 que se recibirán en 2 años, al 8 % anual compuesto?"
                + NOTA_NUM, r2(5000 / 1.08 ** 2)),
            num("Se depositan S/ 3000 al 2 % mensual compuesto durante 12 meses. ¿Cuál es el monto?" + NOTA_NUM,
                r2(3000 * 1.02 ** 12)),
            num("¿Qué tasa anual compuesta convierte S/ 1000 en S/ 1210 en 2 años? Responde en porcentaje." + NOTA_NUM,
                r2(((1210 / 1000) ** (1 / 2) - 1) * 100)),
            opc("Capitalizar los intereses significa:", "Sumarlos al capital para que también generen interés",
                ["Retirarlos cada periodo", "Usarlos para pagar la deuda", "Cambiar la tasa de interés"]),
        ],
        [
            num("¿En cuántos años S/ 1000 se convierten en S/ 2000 al 10 % anual compuesto? (dos decimales)"
                + NOTA_NUM, r2(log(2) / log(1.10))),
            num("Se depositan S/ 4000 hoy y S/ 3000 dentro de un año, al 6 % anual compuesto. ¿Cuál es el monto al "
                "final del año 3?" + NOTA_NUM, r2(4000 * 1.06 ** 3 + 3000 * 1.06 ** 2)),
            opc("Si la tasa de interés sube, el valor presente de un pago futuro fijo:", "Disminuye",
                ["Aumenta", "No cambia", "Se duplica"]),
            num("¿Cuál es el valor presente de S/ 10 000 que se recibirán en 5 años, al 12 % anual compuesto?"
                + NOTA_NUM, r2(10000 / 1.12 ** 5)),
        ],
    ],
}

TASAS = {
    "tema": "Tasas nominales y efectivas",
    "resumen": """
<p>Una misma tasa anual rinde distinto según cuántas veces al año se capitalice.</p>
<ul>
<li><strong>Tasa nominal anual</strong> (TNA) capitalizable <em>m</em> veces al año: la tasa por periodo es j / m.</li>
<li><strong>Tasa efectiva anual</strong> (TEA): lo que de verdad se gana en un año, TEA = (1 + j/m)<sup>m</sup> − 1.</li>
<li><strong>Tasas equivalentes</strong>: rinden lo mismo en el mismo plazo. Por ejemplo, la mensual equivalente a una TEA es (1 + TEA)<sup>1/12</sup> − 1.</li>
</ul>
<p>Para comparar préstamos o ahorros con distinta capitalización se comparan sus tasas efectivas.</p>""",
    "ejemplo": """
<p><strong>Un banco ofrece TNA de 24 % capitalizable mensualmente.</strong></p>
<ol>
<li>Tasa mensual: 24 % / 12 = 2 %.</li>
<li>TEA = (1.02)¹² − 1 = 0.2682 = <strong>26.82 %</strong>.</li>
<li>El 24 % «nominal» rinde, en la práctica, 26.82 % al año.</li>
</ol>""",
    "quizzes": [
        [
            num("Una TNA de 12 % es capitalizable mensualmente. ¿Cuál es la tasa mensual, en porcentaje?" + NOTA_NUM,
                12 / 12),
            num("Una TNA de 18 % es capitalizable trimestralmente. ¿Cuál es la tasa trimestral, en porcentaje?"
                + NOTA_NUM, 18 / 4),
            opc("La tasa efectiva anual es mayor que la tasa nominal cuando:",
                "Hay más de una capitalización al año",
                ["Hay una sola capitalización al año", "La tasa es negativa", "Nunca: siempre son iguales"]),
            num("Una TNA de 10 % es capitalizable semestralmente. ¿Cuál es la TEA, en porcentaje? (dos decimales)"
                + NOTA_NUM, r2((1.05 ** 2 - 1) * 100)),
        ],
        [
            num("Una TNA de 24 % es capitalizable mensualmente. ¿Cuál es la TEA, en porcentaje? (dos decimales)"
                + NOTA_NUM, r2((1.02 ** 12 - 1) * 100)),
            num("La TEA es 12.36 %. ¿Cuál es la tasa efectiva semestral equivalente, en porcentaje?" + NOTA_NUM,
                r2((1.1236 ** 0.5 - 1) * 100)),
            num("La tasa efectiva mensual es 1.5 %. ¿Cuál es la TEA, en porcentaje? (dos decimales)" + NOTA_NUM,
                r2((1.015 ** 12 - 1) * 100)),
            opc("Para comparar dos préstamos con distinta capitalización se debe usar:", "La tasa efectiva anual",
                ["La tasa nominal anual", "La tasa con más capitalizaciones", "El monto del préstamo"]),
        ],
        [
            num("La TEA es 20 %. ¿Cuál es la tasa efectiva mensual equivalente, en porcentaje? (dos decimales)"
                + NOTA_NUM, r2((1.20 ** (1 / 12) - 1) * 100)),
            num("Un banco ofrece 1.2 % efectivo mensual y otro, 14.8 % efectivo anual. ¿Cuál es la TEA del primero, "
                "en porcentaje? (dos decimales)" + NOTA_NUM, r2((1.012 ** 12 - 1) * 100)),
            opc("Con los datos anteriores, ¿qué opción le conviene a un ahorrista?", "La de 1.2 % efectivo mensual",
                ["La de 14.8 % efectivo anual", "Son equivalentes", "No se pueden comparar"]),
            num("Una TNA de 36 % es capitalizable diariamente (año de 360 días). ¿Cuál es la TEA, en porcentaje? "
                "(dos decimales)" + NOTA_NUM, r2(((1 + 0.36 / 360) ** 360 - 1) * 100)),
        ],
    ],
}

ANUALIDADES = {
    "tema": "Anualidades",
    "resumen": """
<p>Una anualidad es una serie de pagos iguales hechos a intervalos iguales, con la misma tasa por periodo.</p>
<ul>
<li><strong>Vencida</strong> (pagos al final de cada periodo): VP = R · [1 − (1 + i)<sup>−n</sup>] / i y VF = R · [(1 + i)<sup>n</sup> − 1] / i.</li>
<li><strong>Cuota</strong> para pagar un préstamo P: R = P · i / [1 − (1 + i)<sup>−n</sup>].</li>
<li><strong>Anticipada</strong> (pagos al inicio): se multiplica el valor de la vencida por (1 + i).</li>
</ul>""",
    "ejemplo": """
<p><strong>Se depositan S/ 500 al final de cada mes, durante 12 meses, al 1 % mensual.</strong></p>
<ol>
<li>Factor de valor futuro: [(1.01)¹² − 1] / 0.01 = 12.6825.</li>
<li>Monto acumulado: 500 · 12.6825 = <strong>S/ 6341.25</strong>.</li>
<li>Valor presente de esos pagos: 500 · [1 − (1.01)<sup>−12</sup>] / 0.01 = <strong>S/ 5627.54</strong>.</li>
</ol>""",
    "quizzes": [
        [
            num("Se depositan S/ 100 al final de cada mes, durante 12 meses, al 1 % mensual. ¿Cuánto se acumula?"
                + NOTA_NUM, r2(100 * fsa(0.01, 12))),
            num("¿Cuál es el valor presente de 3 pagos anuales vencidos de S/ 1000, al 10 % anual?" + NOTA_NUM,
                r2(1000 * fas(0.10, 3))),
            opc("En una anualidad vencida, los pagos se hacen:", "Al final de cada periodo",
                ["Al inicio de cada periodo", "A mitad de cada periodo", "Todos al final del plazo"]),
            num("Se depositan S/ 2000 al final de cada año, durante 4 años, al 5 % anual. ¿Cuánto se acumula?"
                + NOTA_NUM, r2(2000 * fsa(0.05, 4))),
        ],
        [
            num("¿Cuál es la cuota mensual vencida para pagar un préstamo de S/ 10 000 en 12 meses, al 2 % mensual?"
                + NOTA_NUM, r2(cuota(10000, 0.02, 12))),
            num("¿Cuál es el valor presente de 24 pagos mensuales vencidos de S/ 300, al 1.5 % mensual?" + NOTA_NUM,
                r2(300 * fas(0.015, 24))),
            num("¿Cuánto hay que depositar al final de cada mes, durante 10 meses, al 1 % mensual, para reunir "
                "S/ 5000?" + NOTA_NUM, r2(5000 / fsa(0.01, 10))),
            opc("Con los mismos pagos y la misma tasa, una anualidad anticipada tiene, frente a una vencida, un valor "
                "presente:", "Mayor", ["Menor", "Igual", "Igual a cero"]),
        ],
        [
            num("¿Cuál es el valor presente de 6 pagos mensuales anticipados de S/ 500, al 1 % mensual?" + NOTA_NUM,
                r2(500 * fas(0.01, 6) * 1.01)),
            num("Un auto cuesta S/ 30 000. Se paga 20 % de inicial y el resto en 36 cuotas mensuales vencidas al 1 % "
                "mensual. ¿Cuál es la cuota?" + NOTA_NUM, r2(cuota(30000 * 0.8, 0.01, 36))),
            num("Se ahorran S/ 200 al final de cada mes, durante 2 años, al 0.5 % mensual. ¿Cuánto se acumula?"
                + NOTA_NUM, r2(200 * fsa(0.005, 24))),
            opc("Si la tasa de interés sube, la cuota para pagar el mismo préstamo en el mismo plazo:", "Aumenta",
                ["Disminuye", "No cambia", "Se vuelve cero"]),
        ],
    ],
}


def _tir_dos_flujos(i0, f1, f2):
    """Raíz positiva de −I0 + F1·x + F2·x² = 0, con x = 1/(1 + r)."""
    x = (-f1 + sqrt(f1 ** 2 + 4 * f2 * i0)) / (2 * f2)
    return 1 / x - 1


VAN_TIR = {
    "tema": "VAN y TIR",
    "resumen": """
<p>El <strong>VAN</strong> (valor actual neto) trae al presente todos los flujos de un proyecto con la tasa de descuento <em>k</em> (costo de capital) y les resta la inversión:</p>
<p>VAN = −I<sub>0</sub> + FC<sub>1</sub> / (1 + k) + FC<sub>2</sub> / (1 + k)² + … + FC<sub>n</sub> / (1 + k)<sup>n</sup>.</p>
<ul>
<li>VAN &gt; 0: el proyecto rinde más que el costo de capital, conviene. VAN &lt; 0: no conviene.</li>
<li>La <strong>TIR</strong> (tasa interna de retorno) es la tasa que hace VAN = 0. Conviene si la TIR es mayor que el costo de capital.</li>
<li>Entre proyectos excluyentes, se elige el de mayor VAN.</li>
</ul>""",
    "ejemplo": """
<p><strong>Inversión de S/ 1000; flujos de S/ 600 al final de los años 1 y 2; k = 10 %.</strong></p>
<ol>
<li>600 / 1.10 = 545.45 y 600 / 1.10² = 495.87.</li>
<li>VAN = −1000 + 545.45 + 495.87 = <strong>S/ 41.32</strong>: el proyecto conviene.</li>
<li>Como el VAN es positivo con k = 10 %, la TIR es mayor que 10 %.</li>
</ol>""",
    "quizzes": [
        [
            num("Un proyecto requiere S/ 1000 hoy y devuelve S/ 1210 al final del año 1. Con k = 10 %, ¿cuál es el "
                "VAN?" + NOTA_NUM, r2(-1000 + 1210 / 1.10)),
            opc("Un proyecto conviene cuando su VAN es:", "Mayor que cero",
                ["Menor que cero", "Igual a la inversión", "Mayor que la TIR"]),
            opc("La TIR es la tasa de descuento que:", "Hace que el VAN sea cero",
                ["Hace máximo el VAN", "Iguala la inversión a los flujos sin descontar", "Es igual al costo de capital"]),
            num("Una inversión de S/ 500 devuelve S/ 550 al final del año 1. ¿Cuál es su TIR, en porcentaje?"
                + NOTA_NUM, (550 / 500 - 1) * 100),
        ],
        [
            num("Inversión de S/ 2000 y flujos de S/ 1200 al final de los años 1 y 2. Con k = 8 %, ¿cuál es el VAN?"
                + NOTA_NUM, r2(-2000 + 1200 / 1.08 + 1200 / 1.08 ** 2)),
            opc("Si la TIR de un proyecto es 15 % y el costo de capital es 12 %, el proyecto:", "Conviene",
                ["No conviene", "Es indiferente", "No se puede decidir sin el VAN"]),
            num("Una inversión de S/ 1000 devuelve S/ 1210 al final del año 2, sin flujo en el año 1. ¿Cuál es su "
                "TIR, en porcentaje?" + NOTA_NUM, r2(((1210 / 1000) ** 0.5 - 1) * 100)),
            opc("En un proyecto convencional, si la tasa de descuento aumenta, el VAN:", "Disminuye",
                ["Aumenta", "No cambia", "Se vuelve igual a la TIR"]),
        ],
        [
            num("Inversión de S/ 5000 y flujos de S/ 2000 al final de cada uno de los años 1, 2 y 3. Con k = 10 %, "
                "¿cuál es el VAN? (puede ser negativo)" + NOTA_NUM, r2(-5000 + 2000 * fas(0.10, 3))),
            opc("Con el VAN del caso anterior, la decisión es:", "Rechazar el proyecto",
                ["Aceptar el proyecto", "Es indiferente", "Falta calcular la TIR para decidir"]),
            num("Inversión de S/ 1000, flujo de S/ 500 en el año 1 y de S/ 720 en el año 2. ¿Cuál es la TIR, en "
                "porcentaje? (dos decimales)" + NOTA_NUM, r2(_tir_dos_flujos(1000, 500, 720) * 100), tol=0.05),
            opc("Entre dos proyectos excluyentes, ambos con VAN positivo, se elige el de:", "Mayor VAN",
                ["Mayor inversión", "Menor TIR", "Mayor plazo"]),
        ],
    ],
}


def _saldo_frances(p, i, n, k):
    """Saldo después de pagar k cuotas del sistema francés."""
    c = cuota(p, i, n)
    return p * (1 + i) ** k - c * fsa(i, k)


AMORTIZACION = {
    "tema": "Amortización",
    "resumen": """
<p>Amortizar una deuda es pagarla por partes. Cada cuota tiene dos componentes: el <strong>interés</strong> del periodo (sobre el saldo pendiente) y la <strong>amortización</strong>, que reduce el saldo.</p>
<ul>
<li><strong>Sistema francés</strong>: cuota constante, R = P · i / [1 − (1 + i)<sup>−n</sup>]. Al principio la cuota es casi todo interés; con el tiempo, casi todo amortización.</li>
<li><strong>Sistema alemán</strong>: amortización constante (P / n); como el saldo baja, el interés y la cuota disminuyen.</li>
<li>En cualquier sistema: interés del periodo = saldo anterior × i; saldo nuevo = saldo anterior − amortización.</li>
</ul>""",
    "ejemplo": """
<p><strong>Préstamo de S/ 10 000 en 12 cuotas mensuales, al 1 % mensual, sistema francés.</strong></p>
<ol>
<li>Cuota: 10 000 · 0.01 / [1 − (1.01)<sup>−12</sup>] = <strong>S/ 888.49</strong>.</li>
<li>Mes 1: interés = 10 000 · 0.01 = S/ 100; amortización = 888.49 − 100 = S/ 788.49.</li>
<li>Saldo después del mes 1: 10 000 − 788.49 = <strong>S/ 9211.51</strong>.</li>
</ol>""",
    "quizzes": [
        [
            num("Sistema alemán: un préstamo de S/ 12 000 se paga en 12 cuotas mensuales. ¿Cuánto se amortiza cada "
                "mes?" + NOTA_NUM, 12000 / 12),
            num("En el caso anterior, con una tasa de 2 % mensual, ¿cuánto interés tiene la primera cuota?"
                + NOTA_NUM, 12000 * 0.02),
            opc("En el sistema francés, la cuota es:", "Constante",
                ["Decreciente", "Creciente", "Solo de intereses"]),
            num("En el mismo préstamo (alemán, S/ 12 000, 12 cuotas, 2 % mensual), ¿cuál es la primera cuota?"
                + NOTA_NUM, 12000 / 12 + 12000 * 0.02),
        ],
        [
            num("Sistema francés: préstamo de S/ 10 000 en 12 cuotas mensuales al 1 % mensual. ¿Cuál es la cuota?"
                + NOTA_NUM, r2(cuota(10000, 0.01, 12))),
            num("En el caso anterior, ¿cuánto interés tiene la primera cuota?" + NOTA_NUM, 10000 * 0.01),
            num("En el caso anterior, ¿cuánto se amortiza con la primera cuota?" + NOTA_NUM,
                r2(cuota(10000, 0.01, 12) - 10000 * 0.01)),
            num("En el caso anterior, ¿cuál es el saldo después de pagar la primera cuota?" + NOTA_NUM,
                r2(_saldo_frances(10000, 0.01, 12, 1))),
        ],
        [
            num("Sistema alemán: préstamo de S/ 6000 en 6 cuotas mensuales al 1.5 % mensual. ¿Cuál es la cuota del "
                "mes 2?" + NOTA_NUM, r2(6000 / 6 + (6000 - 6000 / 6) * 0.015)),
            opc("En el sistema francés, a medida que se pagan las cuotas, la parte de interés:", "Disminuye",
                ["Aumenta", "Se mantiene igual", "Desaparece desde la primera cuota"]),
            num("Sistema francés: préstamo de S/ 5000 en 3 cuotas anuales al 10 % anual. ¿Cuál es la cuota?"
                + NOTA_NUM, r2(cuota(5000, 0.10, 3))),
            num("En el caso anterior, ¿cuánto interés se paga en total?" + NOTA_NUM,
                r2(3 * cuota(5000, 0.10, 3) - 5000)),
        ],
    ],
}

# ───────────────────────────────────────────────────────────────── cursos

CURSOS = [
    {
        "nombre": "Estadística",
        "corto": "SWARD-EST",
        "descripcion": "Curso de la validación de SWARD: estadística descriptiva e inferencial básica.",
        "temas": [TENDENCIA, DISPERSION, PROBABILIDAD, DISTRIBUCIONES, INTERVALOS, HIPOTESIS],
    },
    {
        "nombre": "Matemática Financiera",
        "corto": "SWARD-MF",
        "descripcion": "Curso de la validación de SWARD: interés, tasas, anualidades, evaluación de proyectos y amortización.",
        "temas": [INTERES_SIMPLE, INTERES_COMPUESTO, TASAS, ANUALIDADES, VAN_TIR, AMORTIZACION],
    },
]

NIVELES = ["básico", "intermedio", "aplicado"]
