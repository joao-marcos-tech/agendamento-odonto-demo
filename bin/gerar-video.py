# Gera o vídeo do topo. Uso: python3 bin/gerar-video.py PASTA_DOS_QUADROS
# Depois: ffmpeg -framerate 24 -i PASTA/f%04d.png -vf scale=1280:720,format=yuv420p -c:v libvpx-vp9 -b:v 0 -crf 40 -an public/assets/video/hero.webm
#        (e o mesmo com -c:v libx264 -crf 28 -movflags +faststart para hero.mp4)
# Gera o vídeo abstrato do hero (luz atravessando água/porcelana).
# Feito do zero para esta demo: sem direitos de terceiros.
import sys, os, numpy as np
from PIL import Image
out = sys.argv[1]; os.makedirs(out, exist_ok=True)
W, H, FPS, SEC = 640, 360, 24, 10
N = FPS * SEC
y, x = np.mgrid[0:H, 0:W].astype(np.float32)
x = (x / W - .5) * 3.2; y = (y / H - .5) * 1.8
ink = np.array([14, 26, 23], np.float32)
deep = np.array([22, 52, 45], np.float32)
mint = np.array([150, 214, 190], np.float32)
lime = np.array([214, 240, 90], np.float32)
for f in range(N):
    t = 2 * np.pi * f / N  # período exato -> loop sem emenda
    u = x + .35 * np.sin(1.3 * y + t) + .2 * np.sin(2.1 * y - 2 * t)
    v = y + .30 * np.cos(1.1 * x - t) + .15 * np.cos(2.7 * x + t)
    c = np.zeros_like(x)
    for k, (a, b, s) in enumerate([(3.1, 1.7, 1), (-2.3, 3.3, 2), (1.2, -4.1, -1), (4.4, 2.2, 1)]):
        c += np.sin(a * u + b * v + s * t + k)
    c = np.abs(c) / 4
    caus = np.clip(1 - c * 3.2, 0, 1) ** 3          # filetes de luz
    glow = np.exp(-((x - .9 * np.cos(t)) ** 2 * .5 + (y - .25 * np.sin(t)) ** 2 * 1.4))
    vig = np.clip(1 - (x ** 2 * .12 + y ** 2 * .5), 0, 1)
    base = ink + (deep - ink) * (glow * vig)[..., None]
    col = base + (mint - base) * (caus * .55 * vig)[..., None]
    col = col + (lime - col) * (caus ** 2 * glow * .5)[..., None]
    Image.fromarray(np.clip(col, 0, 255).astype(np.uint8)).save(f'{out}/f{f:04d}.png')
