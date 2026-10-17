"""Compare two site builds page by page (e.g. old Python build vs PHP build). Prints the first difference per file.
Known, harmless differences are normalised first: HTML comments, a bare "& " vs "&amp; ", sitemap dates, the footer year.
Usage: parity.py DIR_A DIR_B [--show N]"""
import difflib, glob, os, re, sys

A, B = sys.argv[1].rstrip("/"), sys.argv[2].rstrip("/")
SHOW = int(sys.argv[sys.argv.index("--show") + 1]) if "--show" in sys.argv else 1


def norm(s):
    s = re.sub(r"<!--.*?-->", "", s, flags=re.S)
    s = re.sub(r"&(?=\s)", "&amp;", s)
    s = re.sub(r"<lastmod>\d{4}-\d\d-\d\d</lastmod>", "<lastmod>DATE</lastmod>", s)
    s = re.sub(r'<span id="yr">\d{4}</span>', '<span id="yr">YEAR</span>', s)
    return s


names = sorted({os.path.basename(p) for p in glob.glob(A + "/*.html") + glob.glob(A + "/*.xml") + glob.glob(A + "/*.txt")})
bad = 0
for n in names:
    pa, pb = f"{A}/{n}", f"{B}/{n}"
    if not os.path.exists(pb): print("MISSING in B:", n); bad += 1; continue
    a, b = norm(open(pa, encoding="utf-8").read()), norm(open(pb, encoding="utf-8").read())
    if a == b: continue
    bad += 1
    sm = difflib.SequenceMatcher(None, a, b, autojunk=False)
    shown = 0
    for op, i1, i2, j1, j2 in sm.get_opcodes():
        if op == "equal": continue
        print(f"--- {n} [{op}] at {i1}:")
        print("   A:", repr(a[max(0, i1 - 80):i2 + 80]))
        print("   B:", repr(b[max(0, j1 - 80):j2 + 80]))
        shown += 1
        if shown >= SHOW: break
extra = sorted({os.path.basename(p) for p in glob.glob(B + "/*")} - set(names) - {"404.html"})
print(f"files compared: {len(names)}, different: {bad}" + (f", only in B: {extra}" if extra else ""))
