"""
Build the two fonts the Telegram alert cards are drawn with:

    resources/fonts/NotoSansThai-Regular.ttf
    resources/fonts/NotoSansThai-Bold.ttf

    python resources/fonts/build_thai_font.py path/to/NotoSansThai[wdth,wght].ttf

WHY THIS EXISTS. PHP's GD draws text through FreeType alone — no shaping engine — so every Thai
mark is drawn at the one default position the font gives it. In Noto Sans Thai that default is
"directly above/below the consonant", which is right on its own and wrong in two common cases:

  * a tone mark over an upper vowel (ที่ ชื่อ น้ำ สิ่ง): both are drawn at the same height and
    collide into one blob;
  * a mark on a tall consonant (ป่า ฝั่ง ฟ้า ปิด): it lands on the consonant's ascender.

A browser fixes both with the font's GSUB/GPOS tables; GD ignores those tables. So the fix is
baked in here instead, the way Thai fonts did it for renderers without shaping: for each case we
add a ready-positioned copy of the mark (a composite glyph = the font's own mark, moved by exactly
the offset its GPOS anchors would have applied) and give it a Private Use Area code point.
[App\\Support\\Alerts\\ThaiShaper] swaps those code points in before the text reaches GD.

The PUA layout below and the one in ThaiShaper MUST stay identical — change both or neither.

    U+F700 + i          narrow mark for a tall consonant       i = index in MARKS (12)
    U+F710 + t*8 + v    tone t stacked over upper vowel v       normal consonant
    U+F740 + t*8 + v    tone t stacked over upper vowel v       tall consonant
    U+F770 / U+F771     ญ / ฐ without their descender (before ุ ู)

Offsets come from the font's own anchors, measured on น (whose anchor sits exactly at its
advance, i.e. where a mark lands with no shaping at all) and on ป (the tall-consonant case).
The output is subset to Latin + Thai + the PUA block, and its layout tables dropped: GD cannot
use them, and it keeps each file around 40 KB.

Source: Noto Sans Thai v2.002 variable font (github.com/notofonts/thai), SIL OFL 1.1 — see OFL.txt.
"""

import os
import sys

from fontTools import subset
from fontTools.ttLib import TTFont
from fontTools.ttLib.tables._g_l_y_f import Glyph, GlyphComponent
from fontTools.varLib import instancer

UPPER = [chr(c) for c in (0x0E31, 0x0E34, 0x0E35, 0x0E36, 0x0E37, 0x0E47, 0x0E4D)]  # upper vowels
TONES = [chr(c) for c in (0x0E48, 0x0E49, 0x0E4A, 0x0E4B, 0x0E4C)]                  # tone marks, thanthakhat
MARKS = UPPER + TONES

NARROW_BASE = 0xF700
STACK_BASE = 0xF710
STACK_TALL_BASE = 0xF740
DESCLESS = {chr(0x0E0D): 0xF770, chr(0x0E10): 0xF771}   # yo ying, tho than

NORMAL_REF = chr(0x0E19)   # no nu: its top anchor sits exactly at its advance
TALL_REF = chr(0x0E1B)     # po pla: the tall-consonant case

# What survives the subset. ThaiShaper::fontSafe() keeps exactly these (plus the PUA block);
# anything else — emoji above all — would draw as a hollow box, so it is dropped before drawing.
KEEP = (
    list(range(0x20, 0x7F))
    + list(range(0xA0, 0x100))
    + list(range(0x0E01, 0x0E5C))
    + [0x2013, 0x2014, 0x2018, 0x2019, 0x201C, 0x201D, 0x2022, 0x2026, 0x25CC]
)


def gpos_anchors(font):
    """Collect (base top anchors, mark anchors, mark2 anchors) from the GPOS mark lookups."""
    base, mark, mark2 = {}, {}, {}
    for lookup in font['GPOS'].table.LookupList.Lookup:
        for st in lookup.SubTable:
            kind = lookup.LookupType
            if kind == 9:
                st, kind = st.ExtSubTable, st.ExtSubTable.LookupType
            if kind == 4:
                for i, g in enumerate(st.MarkCoverage.glyphs):
                    r = st.MarkArray.MarkRecord[i]
                    mark.setdefault((g, r.Class), (r.MarkAnchor.XCoordinate, r.MarkAnchor.YCoordinate))
                for i, g in enumerate(st.BaseCoverage.glyphs):
                    for cls, a in enumerate(st.BaseArray.BaseRecord[i].BaseAnchor):
                        if a is not None:
                            base.setdefault((g, cls), (a.XCoordinate, a.YCoordinate))
            elif kind == 6:
                m1 = {g: st.Mark1Array.MarkRecord[i] for i, g in enumerate(st.Mark1Coverage.glyphs)}
                for i, g in enumerate(st.Mark2Coverage.glyphs):
                    for cls, a in enumerate(st.Mark2Array.Mark2Record[i].Mark2Anchor):
                        if a is not None:
                            mark2.setdefault((g, cls), (a.XCoordinate, a.YCoordinate))
                for g, r in m1.items():
                    mark.setdefault((g, 'm1'), (r.MarkAnchor.XCoordinate, r.MarkAnchor.YCoordinate))
    return base, mark, mark2


def build(src, weight, style, out):
    font = instancer.instantiateVariableFont(TTFont(src), {'wght': weight, 'wdth': 100})
    # A static cut of a variable font keeps the default instance's names; say which weight this is.
    name = font['name']
    for nid, value in ((2, style), (4, f'Noto Sans Thai {style}'), (6, f'NotoSansThai-{style}')):
        name.setName(value, nid, 3, 1, 0x409)
        name.setName(value, nid, 1, 0, 0)
    cmap = font.getBestCmap()
    glyf, hmtx = font['glyf'], font['hmtx']
    order = font.getGlyphOrder()
    base, mark, mark2 = gpos_anchors(font)

    def g(ch):
        return cmap[ord(ch)]

    def variant(name, suffix):
        return name + suffix if name + suffix in glyf.glyphs else name

    def top_mark_anchor(name):
        # Top marks attach through class 1 of the mark-to-base lookup in this font.
        for key in ((name, 1), (name, 0)):
            if key in mark:
                return mark[key]
        raise SystemExit(f'no mark anchor for {name}')

    def m1_anchor(name):
        return mark.get((name, 'm1')) or top_mark_anchor(name)

    def m2_anchor(name, fallback):
        return mark2.get((name, 0)) or mark2.get((fallback, 0))

    def add(code, source, dx, dy, advance=0):
        name = f'uni{code:04X}'
        comp = GlyphComponent()
        comp.glyphName, comp.x, comp.y, comp.flags = source, int(round(dx)), int(round(dy)), 0x4
        glyph = Glyph()
        glyph.numberOfContours, glyph.components = -1, [comp]
        glyf.glyphs[name] = glyph
        order.append(name)
        glyph.recalcBounds(glyf)
        # lsb MUST equal xMin — FreeType places the outline by it, so a stale lsb shifts the mark.
        hmtx.metrics[name] = (advance, getattr(glyph, 'xMin', 0))
        for table in font['cmap'].tables:
            if table.isUnicode():
                table.cmap[code] = name

    tall, normal = g(TALL_REF), g(NORMAL_REF)
    tall_top = base[(tall, 1)]
    tall_adv = hmtx[tall][0]
    normal_top = base[(normal, 1)]
    normal_adv = hmtx[normal][0]

    # Narrow marks on a tall consonant: the offset its anchor would apply, relative to where the
    # mark falls with no shaping at all (the consonant's advance).
    narrow_dx = {}
    for i, ch in enumerate(MARKS):
        src_name = variant(g(ch), '.narrow')
        ax, ay = top_mark_anchor(src_name)
        dx = tall_top[0] - ax - tall_adv
        dy = tall_top[1] - ay
        narrow_dx[ch] = (src_name, dx, dy)
        add(NARROW_BASE + i, src_name, dx, dy)

    # On a normal consonant the unshaped position is already the anchored one (น: anchor == advance),
    # so the upper vowel stays where it is and the tone is moved onto the vowel's own mark-2 anchor.
    drift = normal_top[0] - normal_adv  # 0 for น; kept so a different reference still adds up
    for t, tone in enumerate(TONES):
        tone_src = variant(g(tone), '.small')
        tx, ty = m1_anchor(tone_src)
        for v, vowel in enumerate(UPPER):
            vx, vy = m2_anchor(g(vowel), g(vowel))
            add(STACK_BASE + t * 8 + v, tone_src, vx - tx + drift, vy - ty)

            nsrc, ndx, ndy = narrow_dx[vowel]
            nvx, nvy = m2_anchor(nsrc, g(vowel))
            add(STACK_TALL_BASE + t * 8 + v, tone_src, ndx + nvx - tx, ndy + nvy - ty)

    for ch, code in DESCLESS.items():
        less = variant(g(ch), '.less')
        add(code, less, 0, 0, advance=hmtx[less][0])

    font.setGlyphOrder(order)

    opts = subset.Options()
    opts.drop_tables += ['GSUB', 'GPOS', 'GDEF', 'STAT', 'MVAR', 'HVAR']
    opts.layout_features = []
    opts.name_IDs = ['*']
    opts.notdef_outline = True
    opts.glyph_names = False
    pua = [NARROW_BASE + i for i in range(len(MARKS))]
    pua += [b + t * 8 + v for b in (STACK_BASE, STACK_TALL_BASE) for t in range(len(TONES)) for v in range(len(UPPER))]
    pua += list(DESCLESS.values())
    sub = subset.Subsetter(opts)
    sub.populate(unicodes=KEEP + pua)
    sub.subset(font)

    missing = [hex(c) for c in pua if c not in font.getBestCmap()]
    if missing:
        raise SystemExit(f'{out}: PUA code points missing after subset: {missing}')

    font.save(out)
    latin1 = [c for c in range(0xA0, 0x100) if c in font.getBestCmap()]
    print(f'{out}: {os.path.getsize(out)} bytes, {len(pua)} PUA glyphs, Latin-1 kept: {len(latin1)}/96')
    print('  Latin-1 missing:', ' '.join(f'{c:04X}' for c in range(0xA0, 0x100) if c not in latin1))


if __name__ == '__main__':
    if len(sys.argv) != 2:
        raise SystemExit(__doc__)
    here = os.path.dirname(os.path.abspath(__file__))
    for weight, style in ((400, 'Regular'), (700, 'Bold')):
        build(sys.argv[1], weight, style, os.path.join(here, f'NotoSansThai-{style}.ttf'))
