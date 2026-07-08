# Spec technique — UC83 (Icône du plugin)

## Architecture
Feature **asset uniquement** — aucun code applicatif, aucun appel API/MQTT, aucun i18n.

- **Fichier livré (versionné)** : `plugin_info/stellantis_icon.png` — **écrasement** du placeholder du
  template (carré vert + bulles `? … ! ok`) par une icône « véhicule connecté » générique.
- **Contrat Market Jeedom** (source : doc.jeedom.com « Icone_de_plugin », consultée 2026-07-08) :
  **PNG 309×348 px**, nom `<plugin-id>_icon.png` dans `plugin_info/` (**reconnaissance automatique par
  le nom, pas de déclaration `info.json`**), bords arrondis, fond coloré, **transparence autour**, **pas
  de texte** sous l'image (reco post-2020), éviter le **code couleur des icônes officielles Jeedom**.
- **Générateur (non livré dans le plugin)** : le script Python Pillow ci-dessous est **versionné ici**
  (dans la spec) pour reproductibilité — il ne fait pas partie du runtime du plugin et n'est PAS commité
  dans l'arbre du plugin.

## Server vs Client
Sans objet (asset statique). L'icône est servie telle quelle par Jeedom ; aucune exécution.

## Validation
Contrôles **assertés dans le script** :
- taille == `(309, 348)`, mode == `RGBA` ;
- **4 coins à alpha == 0** (transparence hors du rectangle arrondi — pas juste « canal alpha présent »).

Contrôles **hors script**, au moment du dev :
- « pas de texte » : garanti par l'**absence de tout `ImageDraw.text` / `ImageFont`** (aucun import de
  police) — non testé programmatiquement ;
- lisibilité en vignette : aperçus 80/64/32 px générés séparément et contrôlés visuellement (Jeedom
  affiche l'icône ~64-80 px dans la liste des plugins).

## Server Actions / API
Aucune.

## Dépendances
Aucune dépendance runtime ajoutée. La **génération** requiert Python + **Pillow ≥ 8.2** (poste de dev
uniquement ; `rounded_rectangle` introduit en 8.2 — vérifié sur Pillow 12.2). On utilise `Image.LANCZOS`
(alias **toujours présent en Pillow 12**, et compatible Pillow ancien) plutôt que
`Image.Resampling.LANCZOS` (qui, lui, exige ≥ 9.1) ; ne pas confondre avec `Image.ANTIALIAS`, réellement
supprimé en Pillow 10. Rendu en **supersampling ×4** (dessin 1236×1392 puis réduction LANCZOS → 309×348)
pour l'antialiasing (ImageDraw ne lisse pas nativement). Poids obtenu ~18,7 Ko (`optimize=True`).

## Design retenu
- Fond : dégradé vertical bleu ardoise `#31465C → #1E2A3A` (distinct du vert du template et du bleu vif
  Jeedom — choix esthétique, non vérifiable statiquement contre les autres icônes `devicecommunication`).
- Sujet : silhouette de voiture de profil blanc cassé `#EEF2F6`, vitres `#2C3E52`, pneus `#161E29`,
  moyeux `#5A6B7D`.
- Motif « connecté » : ondes de signal cyan `#4DD0E1` (3 arcs + point) émanant du toit.
- Pas de logo de marque (Peugeot/Citroën/DS/Opel/Vauxhall = marques déposées → exclus).

## Script générateur (référence reproductible)
```python
from PIL import Image, ImageDraw

W, H = 309, 348
SS = 4               # supersampling (antialiasing)
OFFY = -12           # centrage vertical du sujet
MARGIN, RADIUS = 8, 46

BG_TOP, BG_BOT = (49, 70, 92), (30, 42, 58)   # #31465C -> #1E2A3A
CAR, GLASS = (238, 242, 246), (44, 62, 82)
TIRE, HUB = (22, 30, 41), (90, 107, 125)
SIGNAL = (77, 208, 225)                        # #4DD0E1

def T(x, y): return (x * SS, (y + OFFY) * SS)
def poly(pts): return [T(x, y) for (x, y) in pts]
def circle(d, cx, cy, r, fill): d.ellipse([T(cx - r, cy - r), T(cx + r, cy + r)], fill=fill)
def arc(d, cx, cy, r, a0, a1, fill, w): d.arc([T(cx - r, cy - r), T(cx + r, cy + r)], a0, a1, fill=fill, width=w * SS)

# Fond dégradé
grad = Image.new('RGB', (W * SS, H * SS)); gd = ImageDraw.Draw(grad)
for y in range(H * SS):
    t = y / (H * SS - 1)
    gd.line([(0, y), (W * SS, y)], fill=tuple(int(a + (b - a) * t) for a, b in zip(BG_TOP, BG_BOT)))

# Masque coins arrondis (transparence autour)
mask = Image.new('L', (W * SS, H * SS), 0)
ImageDraw.Draw(mask).rounded_rectangle(
    [MARGIN * SS, MARGIN * SS, (W - MARGIN) * SS - 1, (H - MARGIN) * SS - 1], radius=RADIUS * SS, fill=255)

icon = Image.new('RGBA', (W * SS, H * SS), (0, 0, 0, 0))
icon.paste(grad, (0, 0), mask)
d = ImageDraw.Draw(icon, 'RGBA')

# Corps
d.polygon(poly([(72,258),(72,232),(78,220),(112,214),(130,182),(150,176),(188,176),(204,214),(232,218),(238,232),(238,258)]), fill=CAR)
# Vitres (avant + arrière, pilier B blanc)
d.polygon(poly([(120,210),(136,184),(150,184),(150,210)]), fill=GLASS)
d.polygon(poly([(158,184),(184,184),(196,210),(158,210)]), fill=GLASS)
# Roues
for cx in (110, 198):
    circle(d, cx, 258, 27, TIRE); circle(d, cx, 258, 11, HUB)
# Ondes « connecté »
for r in (18, 32, 46): arc(d, 154, 150, r, 200, 340, SIGNAL, 6)
circle(d, 154, 150, 5, SIGNAL)

out = icon.resize((W, H), Image.LANCZOS)
out.save('plugin_info/stellantis_icon.png', optimize=True)

# Vérifications
px = out.load()
assert out.size == (W, H) and out.mode == 'RGBA'
assert [px[0,0][3], px[W-1,0][3], px[0,H-1][3], px[W-1,H-1][3]] == [0, 0, 0, 0]
```
