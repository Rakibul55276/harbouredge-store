"""Generates the homepage banner images (English + Arabic) into banners/.

Run:  python3 database/data/harbouredge/build_banners.py
Needs Pillow with raqm and the Noto fonts (apt install fonts-noto-core).
"""
import os

from PIL import Image, ImageDraw, ImageFont, ImageOps

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "banners")
os.makedirs(OUT, exist_ok=True)

W, H = 1920, 700
NAVY = (15, 27, 45)
AMBER = (255, 174, 11)
WHITE = (255, 255, 255)
FONTS = "/usr/share/fonts/truetype/noto/"


def font(lang, weight, size):
    if lang == "ar":
        if weight == "bold":
            return ImageFont.truetype(FONTS + "NotoKufiArabic-Bold.ttf", size)
        return ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf", size)
    else:
        name = "NotoSans-Bold.ttf" if weight == "bold" else "NotoSans-Regular.ttf"
    return ImageFont.truetype(FONTS + name, size)


def gradient(rtl):
    g = Image.new("L", (W, 1))
    for x in range(W):
        t = x / W
        t = 1 - t if rtl else t
        g.putpixel((x, 0), int(245 * max(0.0, min(1.0, 1.15 - t * 1.6))))
    return g.resize((W, H))


def cover(img, w, h):
    return ImageOps.fit(img.convert("RGB"), (w, h), Image.LANCZOS)


def text_block(d, lang, x, y, lines, rtl):
    kw = dict(direction="rtl", language="ar") if lang == "ar" else {}
    anchor = "ra" if rtl else "la"
    for txt, f, color, gap in lines:
        d.text((x, y), txt, font=f, fill=color, anchor=anchor, **kw)
        y += f.size + gap
    return y


def button(d, lang, x, y, label, rtl):
    f = font(lang, "bold", 34)
    kw = dict(direction="rtl", language="ar") if lang == "ar" else {}
    tw = d.textlength(label, font=f, **kw)
    bw, bh = int(tw + 80), 76
    x0 = x - bw if rtl else x
    d.rounded_rectangle((x0, y, x0 + bw, y + bh), radius=38, fill=AMBER)
    d.text((x0 + bw / 2, y + bh / 2), label, font=f, fill=NAVY, anchor="mm", **kw)


def product_tiles(canvas, files, rtl):
    size = 330
    gap = 30
    n = len(files)
    total = n * size + (n - 1) * gap
    start = 90 if rtl else W - 90 - total
    for i, fname in enumerate(files):
        tile = Image.new("RGB", (size, size), WHITE)
        p = Image.open(os.path.join(HERE, "images", fname)).convert("RGB")
        p.thumbnail((size - 40, size - 40), Image.LANCZOS)
        tile.paste(p, ((size - p.width) // 2, (size - p.height) // 2))
        mask = Image.new("L", (size, size), 0)
        ImageDraw.Draw(mask).rounded_rectangle((0, 0, size, size), radius=28, fill=255)
        canvas.paste(tile, (start + i * (size + gap), (H - size) // 2), mask)


BANNERS = [
    dict(key="hero", bg="hero", products=None,
         en=["CERTIFIED PPE FOR SAUDI INDUSTRY", "Safety Equipment,", "Delivered Across KSA",
             "Boots, FR workwear, eyewear and more — to EN, ANSI and SASO standards.", "Shop now"],
         ar=["معدات وقاية شخصية معتمدة للصناعة السعودية", "معدات السلامة", "نوصلها لكل مناطق المملكة",
             "أحذية، ملابس مقاومة للهب، نظارات والمزيد — وفق معايير EN وANSI وSASO.", "تسوق الآن"]),
    dict(key="fr", bg=None, products=["ppe-23.webp", "ppe-19.webp"],
         en=["FR & ARC-RATED WORKWEAR", "Save up to 12%", "on FR Coveralls",
             "NFPA 2112 and 8 cal/cm² arc-rated garments for energy sites.", "Shop workwear"],
         ar=["ملابس مقاومة للهب ومصنفة للقوس الكهربائي", "خصم حتى 12٪", "على الأفرولات المقاومة للهب",
             "ملابس مطابقة NFPA 2112 ومصنفة 8 كالوري/سم² لمواقع الطاقة.", "تسوق الملابس"]),
    dict(key="bulk", bg=None, products=["ppe-25.webp", "ppe-10.webp"],
         en=["BULK & PROJECT ORDERS", "Need 500 pairs?", "Request a Quote",
             "Company accounts, custom pricing and credit terms for contractors.", "Request quote"],
         ar=["طلبات الجملة والمشاريع", "تحتاج 500 زوج؟", "اطلب عرض سعر",
             "حسابات للشركات، أسعار خاصة وشروط دفع آجلة للمقاولين.", "اطلب عرض سعر"]),
]

for b in BANNERS:
    for lang in ("en", "ar"):
        rtl = lang == "ar"
        if b["bg"]:
            base = cover(Image.open(os.path.join(HERE, "brand", "hero.webp")), W, H)
            if rtl:
                base = ImageOps.mirror(base)
        else:
            base = Image.new("RGB", (W, H), (232, 236, 242))
            product_tiles(base, b["products"], rtl)
        overlay = Image.new("RGB", (W, H), NAVY)
        base = Image.composite(overlay, base, gradient(rtl))
        d = ImageDraw.Draw(base)
        eyebrow, h1, h2, sub, cta = b[lang]
        x = W - 120 if rtl else 120
        d.rectangle((x - 70 if rtl else x, 150, x if rtl else x + 70, 156), fill=AMBER)
        y = text_block(d, lang, x, 175, [
            (eyebrow, font(lang, "bold", 28), AMBER, 22),
            (h1, font(lang, "bold", 76 if lang == "en" else 64), WHITE, 6 if lang == "en" else 22),
            (h2, font(lang, "bold", 76 if lang == "en" else 64), WHITE, 26 if lang == "en" else 40),
            (sub, font(lang, "regular", 30), (214, 222, 235), 40),
        ], rtl)
        button(d, lang, x, y, cta, rtl)
        base.save(os.path.join(OUT, f"{b['key']}-{lang}.webp"), quality=88)
        print("wrote", f"{b['key']}-{lang}.webp")
