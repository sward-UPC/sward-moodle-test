"""Video y recurso externo de cada tema, verificados el 22 de septiembre de 2026.

Videos: cada URL respondió a https://www.youtube.com/oembed con el título y el
canal que figuran aquí (eso también confirma que se pueden embeber). Recursos:
cada página respondió 200 y trata el tema. Se eligieron por título, canal y
descripción; conviene que el profesor los vea antes de la fase 1 (van en el
documento de revisión).

`fuente` es la etiqueta corta que se muestra en la página del curso.
"""


def _v(url, titulo, canal):
    return {"url": url, "titulo": titulo, "canal": canal, "verificado": True}


def _r(url, titulo, fuente, descripcion, idioma="es"):
    return {"url": url, "titulo": titulo, "fuente": fuente, "descripcion": descripcion,
            "idioma": idioma, "verificado": True}


RECURSOS = {
    "Tendencia central": {
        "video": _v("https://www.youtube.com/watch?v=dfxCsCZ1c3A",
                    "Medidas de tendencia central | Media, Mediana y Moda", "Matemáticas profe Alex"),
        "recurso": _r("https://www.geogebra.org/m/DHd6tXbh", "Estadística: Media, Mediana y Moda", "GeoGebra",
                      "Con datos de sueldos, arma la tabla de frecuencias, ubica la moda, la mediana y la media, y "
                      "analiza si el promedio representa bien los datos."),
    },
    "Dispersión": {
        "video": _v("https://www.youtube.com/watch?v=KsVQygSlf4k",
                    "Rango, varianza, desviación estándar, coeficiente de variación, desviación media: datos no "
                    "agrupados", "Matemóvil"),
        "recurso": _r("https://www.geogebra.org/m/wembyyxu", "Medidas de dispersión para variables cuantitativas",
                      "GeoGebra", "Cambia las notas y mira en vivo cómo se mueven el rango, la varianza, la desviación "
                      "estándar y el coeficiente de variación."),
    },
    "Probabilidad": {
        "video": _v("https://www.youtube.com/watch?v=rN6IWbanhy0",
                    "Probabilidad condicional ejercicios resueltos", "Academia Internet"),
        "recurso": _r("https://seeing-theory.brown.edu/compound-probability/es.html",
                      "Viendo la Teoría — Probabilidad Compuesta", "Universidad Brown",
                      "Visualización interactiva: arma uniones, intersecciones y complementos en diagramas de Venn y "
                      "mira cómo se reduce el espacio muestral en la probabilidad condicional."),
    },
    "Distribuciones binomial y normal": {
        "video": _v("https://www.youtube.com/watch?v=mRAUWsrKA5E",
                    "chuleta 9 Distribución binomial y distribución normal", "No todo es matemáticas"),
        "recurso": _r("https://www.geogebra.org/m/d66h6euh", "Cálculo de probabilidades, binomial y normal",
                      "GeoGebra", "Dos calculadoras de probabilidad ya configuradas: fija n y p (binomial) o μ y σ "
                      "(normal) y obtén cada probabilidad con su gráfica."),
    },
    "Intervalos de confianza": {
        "video": _v("https://www.youtube.com/watch?v=VQJpcYPfEI4",
                    "09 Intervalo de confianza para la media poblacional", "Píldoras matemáticas"),
        "recurso": _r("https://seeing-theory.brown.edu/frequentist-inference/es.html",
                      "Viendo la Teoría — Inferencia Frecuentista", "Universidad Brown",
                      "Simulador: elige la distribución, el tamaño de muestra y el nivel de confianza, genera muchos "
                      "intervalos y mira cuántos contienen el parámetro."),
    },
    "Pruebas de hipótesis": {
        "video": _v("https://www.youtube.com/watch?v=SWl-9FzWaLY", "Prueba de hipótesis y valores P",
                    "Khan Academy Español"),
        "recurso": _r("https://www.geogebra.org/m/ERP4hr2c", "Contraste de la media de la normal (σ conocida)",
                      "GeoGebra · Universidad de Murcia",
                      "Ingresa el estadístico, elige las hipótesis y el nivel de significancia, y compara el valor p "
                      "con la región de rechazo para decidir."),
    },
    "Interés simple": {
        "video": _v("https://www.youtube.com/watch?v=b5WPNRTxT1Y", "Comprendiendo las fórmulas de interés simple",
                    "Matemáticas profe Alex"),
        "recurso": _r("https://www.geogebra.org/m/fny4n4x9", "Interés simple y compuesto", "GeoGebra",
                      "Da el capital, la tasa y el tiempo, calcula el monto con interés simple y compáralo en la "
                      "gráfica con el interés compuesto."),
    },
    "Interés compuesto": {
        "video": _v("https://www.youtube.com/watch?v=lEGk3ILeLuQ", "¿Qué es el interés compuesto?",
                    "Matemáticas profe Alex"),
        "recurso": _r("https://www.geogebra.org/m/EtQez32M", "Interés simple vs interés compuesto", "GeoGebra",
                      "Grafica el monto con capitalización mensual frente al interés simple; cambia el capital y la "
                      "tasa y responde preguntas guiadas sobre cuándo conviene cada uno."),
    },
    "Tasas nominales y efectivas": {
        "video": _v("https://www.youtube.com/watch?v=pnTRGrUkmp0", "Conversión de tasas de interés",
                    "CLASE-XPRESS"),
        "recurso": _r("https://clientebancario.bde.es/pcb/es/menu-horizontal/podemosayudarte/simuladores/"
                      "calculo_tipo_interes_efectivo.html", "Cálculo del tipo de interés efectivo",
                      "Banco de España",
                      "Calculadora oficial que pasa de tasa nominal a efectiva, y al revés, según la frecuencia de "
                      "capitalización. Usa los términos de España: TIN es la tasa nominal y TAE la efectiva."),
    },
    "Anualidades": {
        "video": _v("https://www.youtube.com/watch?v=4H1jIaXYe_g",
                    "Anualidad Vencida (cierta, simple, inmediata). Calculo Valor Presente y Monto o Valor Futuro",
                    "Finanzas 24x7"),
        "recurso": _r("https://www.geogebra.org/m/T3Jn4zKa", "Anualidades de capitalización", "GeoGebra",
                      "Ejercicios con datos al azar y puntaje: calcula cuánto se acumula con depósitos constantes, "
                      "en rentas vencidas y anticipadas, con pistas y solución."),
    },
    "VAN y TIR": {
        "video": _v("https://www.youtube.com/watch?v=JANU9YhSN8A", "VAN, TIR y Evaluación de Proyectos | FENVid",
                    "FENVid · Universidad de Chile"),
        "recurso": _r("https://www.geogebra.org/m/pk6DxU6t", "Calculadora VAN y TIR", "GeoGebra",
                      "Ingresa la inversión, hasta 10 flujos de caja y la tasa; obtén el VAN y la TIR y prueba cómo "
                      "cambian al mover cada flujo."),
    },
    "Amortización": {
        "video": _v("https://www.youtube.com/watch?v=BOpGk6t7SDE", "Préstamo francés. Tabla de amortización",
                    "Montero Espinosa"),
        "recurso": _r("https://clientebancario.bde.es/pcb/es/menu-horizontal/podemosayudarte/simuladores/"
                      "simulador_prestamo_hipotecario_personal.html", "Simulador de préstamo (sistema francés)",
                      "Banco de España",
                      "Simulador oficial del sistema francés: con el monto, el plazo y la tasa calcula la cuota fija "
                      "y la tabla de amortización. Usa euros y los términos de España (TIN, TAE)."),
    },
}
