"""Imágenes de portada de los cursos de la validación (tarjeta de «Mis cursos»).

    python seed/validacion/imagenes.py

Ilustraciones sin texto (el nombre del curso ya va debajo), en el color de SWARD
sobre un fondo neutro, con un motivo propio de cada curso: la campana de Gauss
sobre un histograma para Estadística y el crecimiento compuesto sobre cuotas
para Matemática Financiera. Se generan con matplotlib para no depender de
imágenes de terceros. cargar_cursos.php las adjunta a cada curso.
"""

from pathlib import Path

import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt  # noqa: E402
import numpy as np  # noqa: E402

AQUI = Path(__file__).resolve().parent / "imagenes"
PRIMARIO = "#4F46E5"
PRIMARIO_SUAVE = "#C7C5F7"
FONDO = "#F1F1F8"
ANCHO, ALTO, DPI = 12, 6, 100  # 1200 × 600 px


def _lienzo():
    fig = plt.figure(figsize=(ANCHO, ALTO), dpi=DPI)
    ax = fig.add_axes([0, 0, 1, 1])
    ax.set_facecolor(FONDO)
    fig.patch.set_facecolor(FONDO)
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 6)
    ax.axis("off")
    return fig, ax


def estadistica(ruta: Path) -> None:
    fig, ax = _lienzo()
    rng = np.random.default_rng(7)
    muestras = rng.normal(6, 1.6, 4000)
    alturas, bordes = np.histogram(muestras, bins=22, range=(1.2, 10.8))
    escala = 3.6 / alturas.max()
    for h, x0, x1 in zip(alturas, bordes[:-1], bordes[1:]):
        ax.add_patch(plt.Rectangle((x0 + 0.04, 1.1), (x1 - x0) - 0.08, h * escala,
                                   color=PRIMARIO_SUAVE, lw=0))
    x = np.linspace(0.6, 11.4, 400)
    y = 1.1 + 3.75 * np.exp(-((x - 6) ** 2) / (2 * 1.6 ** 2))
    ax.fill_between(x, 1.1, y, color=PRIMARIO, alpha=0.10, lw=0)
    ax.plot(x, y, color=PRIMARIO, lw=6, solid_capstyle="round")
    ax.plot([0.6, 11.4], [1.1, 1.1], color=PRIMARIO, lw=3, alpha=0.45)
    ax.plot([6, 6], [1.1, 4.85], color=PRIMARIO, lw=3, ls=(0, (2, 3)), alpha=0.7)
    fig.savefig(ruta, dpi=DPI)
    plt.close(fig)


def financiera(ruta: Path) -> None:
    fig, ax = _lienzo()
    n = 12
    xs = np.linspace(1.2, 10.8, n)
    cuotas = np.full(n, 0.75)
    for x in xs:
        ax.add_patch(plt.Rectangle((x - 0.28, 1.1), 0.56, cuotas[0], color=PRIMARIO_SUAVE, lw=0))
    x = np.linspace(0.8, 11.2, 300)
    y = 1.1 + 0.55 * np.exp(0.19 * (x - 0.8))
    ax.fill_between(x, 1.1, y, color=PRIMARIO, alpha=0.10, lw=0)
    ax.plot(x, y, color=PRIMARIO, lw=6, solid_capstyle="round")
    for xi in (3.4, 6.0, 8.6):
        yi = 1.1 + 0.55 * np.exp(0.19 * (xi - 0.8))
        ax.add_patch(plt.Circle((xi, yi), 0.2, color=FONDO, zorder=3))
        ax.add_patch(plt.Circle((xi, yi), 0.2, fill=False, ec=PRIMARIO, lw=4, zorder=4))
    ax.plot([0.6, 11.4], [1.1, 1.1], color=PRIMARIO, lw=3, alpha=0.45)
    fig.savefig(ruta, dpi=DPI)
    plt.close(fig)


if __name__ == "__main__":
    AQUI.mkdir(exist_ok=True)
    estadistica(AQUI / "SWARD-EST.png")
    financiera(AQUI / "SWARD-MF.png")
    print("Imágenes en", AQUI)
