"""Práctica guiada de cada tema: tres ejercicios y su solución paso a paso.

En Moodle cada práctica es una tarea sin nota (el estudiante escribe sus
respuestas) y la solución es una página que se abre al entregarla: primero se
intenta, después se compara. No suma interacciones al modelo SAKT, que solo usa
los quizzes calificados.

Como en el banco de preguntas, los números se calculan aquí, no se escriben a
mano: el texto se arma con los resultados de las mismas funciones.
"""

from math import log
from statistics import NormalDist

from banco import _saldo_frances, _tir_dos_flujos, cuota, fas, fsa, media, var_muestral

PHI = NormalDist().cdf
Z_INV = NormalDist().inv_cdf

INTRO = ("<p><strong>Práctica guiada, sin nota.</strong> Resuelve los tres ejercicios por tu cuenta y "
         "escribe tus respuestas en el cuadro de entrega. Al entregarla se abre la solución, para que "
         "compares tu procedimiento.</p>")


def m(x, d=2):
    """Número con separador de miles (espacio) y punto decimal, como en el banco."""
    texto = f"{x:,.{d}f}".replace(",", " ")
    return texto


def pct(x, d=2):
    return f"{x * 100:.{d}f} %"


def _tir_tres_iguales(i0, f, n):
    """TIR de una inversión con n flujos iguales, por bisección."""
    lo, hi = 0.0, 1.0
    for _ in range(200):
        mid = (lo + hi) / 2
        if -i0 + f * fas(mid, n) > 0:
            lo = mid
        else:
            hi = mid
    return lo


def _tendencia():
    t = [5, 7, 7, 9, 10, 12, 14, 40]
    med = (t[3] + t[4]) / 2
    pond = 0.2 * 14 + 0.3 * 12 + 0.5 * 15
    hijos = {0: 5, 1: 8, 2: 4, 3: 3}
    n = sum(hijos.values())
    mh = sum(k * v for k, v in hijos.items()) / n
    return {
        "enunciado": INTRO + f"""
<ol>
<li>Tiempos de atención (minutos) de 8 clientes: {", ".join(map(str, t))}. Calcula la media, la mediana y la moda. ¿Cuál describe mejor el tiempo típico? ¿Por qué?</li>
<li>La nota final de un curso pondera: práctica calificada 20 % (nota 14), examen parcial 30 % (nota 12) y examen final 50 % (nota 15). Calcula la nota final.</li>
<li>Número de hijos en 20 familias: 0 hijos (5 familias), 1 hijo (8), 2 hijos (4), 3 hijos (3). Calcula la media, la mediana y la moda.</li>
</ol>""",
        "solucion": f"""
<ol>
<li>Media = {sum(t)} / 8 = <strong>{m(media(t))}</strong>. Ordenados, los centrales son 9 y 10: mediana = <strong>{m(med, 1)}</strong>. Moda = <strong>7</strong>. El valor 40 es atípico y sube la media; la <strong>mediana</strong> describe mejor el tiempo típico.</li>
<li>0.20 × 14 + 0.30 × 12 + 0.50 × 15 = 2.8 + 3.6 + 7.5 = <strong>{m(pond, 1)}</strong>.</li>
<li>Media = (0×5 + 1×8 + 2×4 + 3×3) / 20 = 25 / 20 = <strong>{m(mh)}</strong>. Con 20 datos, la mediana es el promedio de los lugares 10 y 11; ambos caen en «1 hijo» (lugares 6 a 13): mediana = <strong>1</strong>. Moda = <strong>1</strong> (8 familias).</li>
</ol>""",
    }


def _dispersion():
    v = [4, 6, 8, 10, 12]
    s2 = var_muestral(v)
    s = s2 ** 0.5
    return {
        "enunciado": INTRO + f"""
<ol>
<li>Ventas diarias de una tienda (miles de soles): {", ".join(map(str, v))}. Calcula el rango, la varianza muestral, la desviación estándar muestral y el coeficiente de variación.</li>
<li>La máquina A llena bolsas con media 500 g y desviación estándar 5 g; la máquina B, con media 250 g y desviación estándar 4 g. ¿Cuál es relativamente más precisa? Usa el coeficiente de variación.</li>
<li>Los datos 2, 4 y 6 tienen desviación estándar muestral 2. ¿Cuál es la desviación estándar si a cada dato se le suma 3? ¿Y si cada dato se multiplica por 2?</li>
</ol>""",
        "solucion": f"""
<ol>
<li>Rango = 12 − 4 = <strong>8</strong>. Media = {m(media(v), 0)}. Desviaciones al cuadrado: 16, 4, 0, 4, 16; suman 40. Varianza muestral = 40 / (5 − 1) = <strong>{m(s2)}</strong>. Desviación estándar = √{m(s2, 0)} = <strong>{m(s)}</strong> (miles de soles). CV = {m(s)} / {m(media(v), 0)} = <strong>{pct(s / media(v), 1)}</strong>.</li>
<li>CV<sub>A</sub> = 5 / 500 = <strong>1 %</strong>; CV<sub>B</sub> = 4 / 250 = <strong>1.6 %</strong>. Aunque B tiene menor desviación en gramos, <strong>A es relativamente más precisa</strong>.</li>
<li>Sumar 3 desplaza todos los datos igual: la dispersión <strong>no cambia (2)</strong>. Multiplicar por 2 duplica las distancias a la media: la desviación estándar pasa a <strong>4</strong>.</li>
</ol>""",
    }


def _probabilidad():
    p2 = 5 / 10 * 4 / 9
    return {
        "enunciado": INTRO + """
<ol>
<li>Una urna tiene 5 bolas rojas, 3 azules y 2 verdes. Calcula: a) P(roja); b) P(no verde); c) la probabilidad de sacar dos rojas seguidas sin reposición.</li>
<li>De 200 estudiantes, 80 trabajan y 120 no. Aprobaron 60 de los que trabajan y 100 de los que no trabajan. Calcula: a) P(aprobar); b) P(aprobar | trabaja); c) P(trabaja | aprobó).</li>
<li>A y B son independientes, con P(A) = 0.3 y P(B) = 0.5. Calcula P(A y B) y P(A o B).</li>
</ol>""",
        "solucion": f"""
<ol>
<li>a) 5 / 10 = <strong>0.5</strong>. b) 1 − 2 / 10 = <strong>0.8</strong>. c) 5/10 × 4/9 = 20/90 = <strong>{m(p2, 4)}</strong> (al sacar la primera roja quedan 4 rojas de 9).</li>
<li>a) (60 + 100) / 200 = <strong>0.8</strong>. b) 60 / 80 = <strong>0.75</strong>. c) 60 / 160 = <strong>0.375</strong>: la condición cambia el denominador.</li>
<li>Por independencia, P(A y B) = 0.3 × 0.5 = <strong>0.15</strong>. P(A o B) = 0.3 + 0.5 − 0.15 = <strong>0.65</strong>.</li>
</ol>""",
    }


def _distribuciones():
    p0 = 0.8 ** 5
    p1 = 5 * 0.2 * 0.8 ** 4
    return {
        "enunciado": INTRO + """
<ol>
<li>El 20 % de las piezas de un lote es defectuoso. Se toman 5 piezas al azar (binomial, n = 5, p = 0.2). Calcula P(X = 0), P(X = 1), P(X ≥ 2), la media y la varianza.</li>
<li>El tiempo de un trámite sigue una normal con media 30 min y desviación 5 min. Calcula P(X &lt; 35), P(25 &lt; X &lt; 35) y P(X &gt; 40).</li>
<li>Las notas de un examen siguen una normal con media 12 y desviación 2. ¿Qué nota deja por debajo al 90 % de los estudiantes? (z para 0.90 = 1.2816)</li>
</ol>""",
        "solucion": f"""
<ol>
<li>P(X = 0) = 0.8<sup>5</sup> = <strong>{m(p0, 4)}</strong>. P(X = 1) = 5 × 0.2 × 0.8<sup>4</sup> = <strong>{m(p1, 4)}</strong>. P(X ≥ 2) = 1 − {m(p0, 4)} − {m(p1, 4)} = <strong>{m(1 - p0 - p1, 4)}</strong>. Media = n·p = <strong>1</strong>; varianza = n·p·(1 − p) = <strong>0.8</strong>.</li>
<li>z = (35 − 30) / 5 = 1: P(X &lt; 35) = <strong>{m(PHI(1), 4)}</strong>. Entre z = −1 y z = 1: <strong>{m(PHI(1) - PHI(-1), 4)}</strong>. z = (40 − 30) / 5 = 2: P(X &gt; 40) = 1 − {m(PHI(2), 4)} = <strong>{m(1 - PHI(2), 4)}</strong>.</li>
<li>x = μ + z·σ = 12 + 1.2816 × 2 = <strong>{m(12 + 2 * Z_INV(0.9))}</strong>.</li>
</ol>""",
    }


def _intervalos():
    e95 = 1.96 * 12 / 6
    e99 = 2.576 * 12 / 6
    n = (1.96 * 12 / 2) ** 2
    return {
        "enunciado": INTRO + """
<ol>
<li>En una muestra de 36 clientes, el gasto medio fue S/ 150; la desviación estándar poblacional es S/ 12. Construye el intervalo de confianza del 95 % para el gasto medio (z = 1.96).</li>
<li>Repite el intervalo con 99 % de confianza (z = 2.576). ¿Qué pasa con su ancho y por qué?</li>
<li>¿Qué tamaño de muestra se necesita para estimar el gasto medio con un margen de error de S/ 2 al 95 % de confianza (σ = 12)?</li>
</ol>""",
        "solucion": f"""
<ol>
<li>Error estándar = 12 / √36 = 2. Margen = 1.96 × 2 = {m(e95)}. Intervalo: <strong>[{m(150 - e95)} ; {m(150 + e95)}]</strong>.</li>
<li>Margen = 2.576 × 2 = {m(e99, 3)}. Intervalo: <strong>[{m(150 - e99)} ; {m(150 + e99)}]</strong>. Es <strong>más ancho</strong>: para tener más confianza hay que cubrir un rango mayor.</li>
<li>n = (z·σ / E)² = (1.96 × 12 / 2)² = {m(n)}. Se redondea hacia arriba: <strong>{int(n) + 1} clientes</strong>.</li>
</ol>""",
    }


def _hipotesis():
    return {
        "enunciado": INTRO + """
<ol>
<li>Un fabricante afirma que sus paquetes pesan en promedio 500 g. En 49 paquetes, el peso medio fue 497 g; σ = 7 g. Con α = 0.05, prueba H<sub>0</sub>: μ = 500 contra H<sub>1</sub>: μ ≠ 500. Calcula el estadístico z y el valor p, y decide.</li>
<li>Una empresa dice que entrega en menos de 30 min en promedio. En 25 pedidos, la media fue 28.5 min; σ = 5 min. Con α = 0.05, prueba H<sub>0</sub>: μ = 30 contra H<sub>1</sub>: μ &lt; 30 (z crítico = −1.645).</li>
<li>Una prueba da valor p = 0.03. ¿Se rechaza H<sub>0</sub> con α = 0.05? ¿Y con α = 0.01? Explica qué significa rechazar.</li>
</ol>""",
        "solucion": f"""
<ol>
<li>Error estándar = 7 / √49 = 1. z = (497 − 500) / 1 = <strong>−3</strong>. Valor p = 2 × P(Z &lt; −3) = <strong>{m(2 * (1 - PHI(3)), 4)}</strong>. Como |z| = 3 &gt; 1.96 (o p &lt; 0.05), <strong>se rechaza H<sub>0</sub></strong>: el peso medio difiere de 500 g.</li>
<li>Error estándar = 5 / √25 = 1. z = (28.5 − 30) / 1 = <strong>−1.5</strong>. Valor p = P(Z &lt; −1.5) = <strong>{m(PHI(-1.5), 4)}</strong>. Como −1.5 &gt; −1.645 (o p &gt; 0.05), <strong>no se rechaza H<sub>0</sub></strong>: la muestra no basta para afirmar que entregan en menos de 30 min.</li>
<li>Con α = 0.05: <strong>se rechaza</strong> (0.03 &lt; 0.05). Con α = 0.01: <strong>no se rechaza</strong> (0.03 &gt; 0.01). Rechazar significa que, si H<sub>0</sub> fuera cierta, un resultado tan extremo sería poco probable; no demuestra que H<sub>1</sub> sea cierta con seguridad.</li>
</ol>""",
    }


def _interes_simple():
    return {
        "enunciado": INTRO + """
<ol>
<li>Se invierten S/ 5 000 al 1.5 % mensual de interés simple durante 8 meses. Calcula el interés y el monto final.</li>
<li>¿Qué capital genera S/ 450 de interés en 6 meses al 18 % anual simple?</li>
<li>¿En cuántos meses S/ 2 000 se convierten en S/ 2 300 al 2 % mensual simple?</li>
</ol>""",
        "solucion": f"""
<ol>
<li>I = C·i·t = 5 000 × 0.015 × 8 = <strong>S/ {m(5000 * 0.015 * 8)}</strong>. Monto = 5 000 + 600 = <strong>S/ {m(5600)}</strong>.</li>
<li>Seis meses son 0.5 años: C = I / (i·t) = 450 / (0.18 × 0.5) = <strong>S/ {m(450 / (0.18 * 0.5))}</strong>.</li>
<li>El interés es 300: t = I / (C·i) = 300 / (2 000 × 0.02) = <strong>{m(300 / (2000 * 0.02), 1)} meses</strong>.</li>
</ol>""",
    }


def _interes_compuesto():
    return {
        "enunciado": INTRO + """
<ol>
<li>Se depositan S/ 3 000 al 2 % mensual compuesto durante 12 meses. Calcula el monto final.</li>
<li>¿Cuánto hay que depositar hoy para tener S/ 10 000 dentro de 3 años al 8 % anual compuesto?</li>
<li>Un capital de S/ 4 000 se convirtió en S/ 5 000 en 4 años. ¿Qué tasa anual compuesta ganó? ¿En cuántos años se duplica un capital al 10 % anual?</li>
</ol>""",
        "solucion": f"""
<ol>
<li>S = P·(1 + i)<sup>n</sup> = 3 000 × 1.02<sup>12</sup> = <strong>S/ {m(3000 * 1.02 ** 12)}</strong>.</li>
<li>P = S / (1 + i)<sup>n</sup> = 10 000 / 1.08<sup>3</sup> = <strong>S/ {m(10000 / 1.08 ** 3)}</strong>.</li>
<li>i = (5 000 / 4 000)<sup>1/4</sup> − 1 = <strong>{pct((5000 / 4000) ** 0.25 - 1)}</strong> anual. Para duplicar: n = ln 2 / ln 1.10 = <strong>{m(log(2) / log(1.1))} años</strong>.</li>
</ol>""",
    }


def _tasas():
    tea_b = 1.0105 ** 12 - 1
    return {
        "enunciado": INTRO + """
<ol>
<li>Un banco ofrece una TNA de 24 % capitalizable mensualmente. ¿Cuál es la TEA?</li>
<li>Una TEA de 12 %, ¿a qué tasa efectiva mensual (TEM) equivale?</li>
<li>Para ahorrar, el banco A ofrece TEA 13 % y el banco B una TNA de 12.6 % capitalizable mensualmente. ¿Cuál conviene?</li>
</ol>""",
        "solucion": f"""
<ol>
<li>Tasa mensual = 24 % / 12 = 2 %. TEA = (1 + 0.02)<sup>12</sup> − 1 = <strong>{pct(1.02 ** 12 - 1)}</strong>.</li>
<li>TEM = (1 + 0.12)<sup>1/12</sup> − 1 = <strong>{pct(1.12 ** (1 / 12) - 1, 4)}</strong>.</li>
<li>Banco B: tasa mensual = 12.6 % / 12 = 1.05 %; TEA = 1.0105<sup>12</sup> − 1 = {pct(tea_b)}. Como {pct(tea_b)} &gt; 13 %, <strong>conviene el banco B</strong>: hay que comparar tasas efectivas, no nominales.</li>
</ol>""",
    }


def _anualidades():
    return {
        "enunciado": INTRO + """
<ol>
<li>Depositas S/ 300 al final de cada mes durante 24 meses, al 1 % mensual. ¿Cuánto acumulas?</li>
<li>¿Cuál es el valor presente de una pensión de S/ 1 500 mensuales durante 5 años, al 0.8 % mensual?</li>
<li>¿Cuánto debes depositar al final de cada mes para juntar S/ 20 000 en 3 años, al 1 % mensual?</li>
</ol>""",
        "solucion": f"""
<ol>
<li>VF = R·[(1 + i)<sup>n</sup> − 1] / i = 300 × {m(fsa(0.01, 24), 4)} = <strong>S/ {m(300 * fsa(0.01, 24))}</strong>.</li>
<li>n = 60 meses. VP = R·[1 − (1 + i)<sup>−n</sup>] / i = 1 500 × {m(fas(0.008, 60), 4)} = <strong>S/ {m(1500 * fas(0.008, 60))}</strong>.</li>
<li>n = 36 meses. R = VF / {{[(1 + i)<sup>n</sup> − 1] / i}} = 20 000 / {m(fsa(0.01, 36), 4)} = <strong>S/ {m(20000 / fsa(0.01, 36))}</strong>.</li>
</ol>""",
    }


def _van_tir():
    van10 = -10000 + 4000 * fas(0.10, 3)
    van9 = -10000 + 4000 * fas(0.09, 3)
    tir = _tir_tres_iguales(10000, 4000, 3)
    tir2 = _tir_dos_flujos(5000, 2000, 4000)
    van2 = -5000 + 2000 / 1.1 + 4000 / 1.1 ** 2
    return {
        "enunciado": INTRO + """
<ol>
<li>Un proyecto requiere S/ 10 000 y genera S/ 4 000 al final de cada uno de los próximos 3 años. Con un costo de capital de 10 %, calcula el VAN. ¿Conviene?</li>
<li>Recalcula el VAN del mismo proyecto con 9 %. Con los dos resultados, ¿entre qué tasas está la TIR?</li>
<li>Otro proyecto requiere S/ 5 000 y genera S/ 2 000 en el año 1 y S/ 4 000 en el año 2. Calcula su VAN al 10 % y su TIR.</li>
</ol>""",
        "solucion": f"""
<ol>
<li>VAN = −10 000 + 4 000 × {m(fas(0.10, 3), 4)} = <strong>S/ {m(van10)}</strong>. Es negativo por poco: al 10 % <strong>no conviene</strong>.</li>
<li>VAN al 9 % = −10 000 + 4 000 × {m(fas(0.09, 3), 4)} = <strong>S/ {m(van9)}</strong>. Como el VAN cambia de signo entre 9 % y 10 %, la TIR está entre ambas (≈ <strong>{pct(tir)}</strong>).</li>
<li>VAN = −5 000 + 2 000 / 1.1 + 4 000 / 1.1<sup>2</sup> = <strong>S/ {m(van2)}</strong>. La TIR resuelve −5 000 + 2 000·x + 4 000·x² = 0 con x = 1 / (1 + r): <strong>{pct(tir2)}</strong>, mayor que 10 %: conviene.</li>
</ol>""",
    }


def _amortizacion():
    r = cuota(6000, 0.02, 6)
    a1 = r - 120
    s1 = 6000 - a1
    i2 = s1 * 0.02
    a2 = r - i2
    s2 = s1 - a2
    return {
        "enunciado": INTRO + """
<ol>
<li>Un préstamo de S/ 6 000 se paga en 6 cuotas mensuales al 2 % mensual con el sistema francés. Calcula la cuota y las dos primeras filas de la tabla (interés, amortización y saldo).</li>
<li>El mismo préstamo con el sistema alemán: calcula la amortización, las dos primeras cuotas y el total de intereses.</li>
<li>En el sistema francés, ¿cuánto se debe después de pagar 3 cuotas? ¿Cuánto interés se paga en total? Compáralo con el sistema alemán.</li>
</ol>""",
        "solucion": f"""
<ol>
<li>Cuota = 6 000 × 0.02 / [1 − 1.02<sup>−6</sup>] = <strong>S/ {m(r)}</strong>. Fila 1: interés = 6 000 × 0.02 = 120; amortización = {m(r)} − 120 = {m(a1)}; saldo = <strong>{m(s1)}</strong>. Fila 2: interés = {m(s1)} × 0.02 = {m(i2)}; amortización = {m(a2)}; saldo = <strong>{m(s2)}</strong>.</li>
<li>Amortización constante = 6 000 / 6 = <strong>S/ 1 000</strong>. Cuota 1 = 1 000 + 120 = <strong>S/ 1 120</strong>; cuota 2 = 1 000 + 5 000 × 0.02 = <strong>S/ 1 100</strong>. Intereses totales = 0.02 × (6 000 + 5 000 + 4 000 + 3 000 + 2 000 + 1 000) = <strong>S/ {m(0.02 * 21000)}</strong>.</li>
<li>Saldo tras 3 cuotas = 6 000 × 1.02<sup>3</sup> − {m(r)} × {m(fsa(0.02, 3), 4)} = <strong>S/ {m(_saldo_frances(6000, 0.02, 6, 3))}</strong>. Intereses totales (francés) = 6 × {m(r)} − 6 000 = <strong>S/ {m(6 * r - 6000)}</strong>, algo más que en el alemán (S/ 420), porque el saldo baja más lento.</li>
</ol>""",
    }


PRACTICAS = {
    "Tendencia central": _tendencia(),
    "Dispersión": _dispersion(),
    "Probabilidad": _probabilidad(),
    "Distribuciones binomial y normal": _distribuciones(),
    "Intervalos de confianza": _intervalos(),
    "Pruebas de hipótesis": _hipotesis(),
    "Interés simple": _interes_simple(),
    "Interés compuesto": _interes_compuesto(),
    "Tasas nominales y efectivas": _tasas(),
    "Anualidades": _anualidades(),
    "VAN y TIR": _van_tir(),
    "Amortización": _amortizacion(),
}
