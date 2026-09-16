#!/usr/bin/env python3
"""Die Fassungsnummer der Funkwacht - aus EINER Quelle.

WARUM ES DIESE DATEI GIBT
-------------------------
Bis 1.0.2 fuehrte jede Python-Datei ihre eigene Konstante FASSUNG. Am Geraet
gemessen (17.09.2026, installiert war 1.0.2): `--faehigkeit` schrieb
"fassung": "1.0.0" in faehigkeit.json - die Zahl war zwei Veroeffentlichungen
alt, weil keine Nummer, die an zwei Stellen steht, dort gleich bleibt, und
weil `fassung_setzen.py` nur plugin.cfg, release.cfg, prerelease.cfg und die
README kennt (Regeln/03, "Fassungsnummer aus einer Quelle").

WOHER DIE NUMMER KOMMT
----------------------
1. Installiert: die plugin.cfg wird NIRGENDWOHIN installiert. Die einzige
   Quelle ist data/system/plugindatabase.json, gesucht ueber den
   ORDNERNAMEN (folder) - nie ueber den MD5-Schluessel, der entsteht aus
   Autor, E-Mail und Name und aendert sich bei jedem Fork.
2. Entpacktes Archiv (Pruefstand): plugin.cfg liegt neben bin/, ihre
   VERSION-Zeile wird ZEILENWEISE gelesen.
3. Sonst: leer. Eine erfundene Nummer waere von einer echten nicht zu
   unterscheiden.

Keine Abhaengigkeit ausser der Standardbibliothek, keine Seiteneffekte.
"""
from __future__ import annotations

import json
import os
import re


def eigener_ordner() -> str:
    """Der Ordnername, in dem diese Datei liegt; im Archiv heisst er "bin"."""
    return os.path.basename(os.path.dirname(os.path.abspath(__file__)))


def plugin_fassung(ordner: str | None = None, home: str | None = None,
                   hier: str | None = None) -> str:
    hier = hier or os.path.dirname(os.path.abspath(__file__))
    if ordner is None:
        ordner = os.environ.get("LBPPLUGINDIR") or os.path.basename(hier)
    if home is None:
        home = os.environ.get("LBHOMEDIR") or ""

    kandidaten = []
    if home:
        kandidaten.append(os.path.join(home, "data", "system", "plugindatabase.json"))
    # installiert liegt diese Datei unter <home>/bin/plugins/<ordner>/
    kandidaten.append(os.path.normpath(os.path.join(
        hier, "..", "..", "..", "data", "system", "plugindatabase.json")))
    for db in kandidaten:
        try:
            with open(db, encoding="utf-8") as f:
                d = json.load(f)
        except (OSError, ValueError):
            continue
        eintraege = d.get("plugins") if isinstance(d, dict) else None
        for e in (eintraege or {}).values():
            if isinstance(e, dict) and e.get("folder") == ordner and e.get("version"):
                return str(e["version"]).strip()

    try:
        with open(os.path.join(hier, "..", "plugin.cfg"), encoding="utf-8",
                  errors="replace") as f:
            for zeile in f:
                m = re.match(r"\s*VERSION\s*=\s*([0-9][0-9A-Za-z.\-]*)", zeile)
                if m:
                    return m.group(1)
    except OSError:
        pass
    return ""


if __name__ == "__main__":
    print(plugin_fassung() or "")
