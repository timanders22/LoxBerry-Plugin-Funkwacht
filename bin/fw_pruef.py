#!/usr/bin/env python3
"""Funkwacht - der Entscheidungskern.

Reine Rechnung: kein Netz, keine Geraete, keine Uhr ausser der uebergebenen
Zeit. Deshalb laesst sich alles hier durchpruefen - selbsttest() rechnet die
Faelle nach, die im Betrieb weh tun.

WAS DIESES MODUL ENTSCHEIDET, UND WAS NICHT
-------------------------------------------
Es entscheidet: gilt ein Stick als gesund, welche Heilstufe ist als naechstes
dran, und darf jetzt ueberhaupt geheilt werden. Es fuehrt nichts aus - das
tut der Dienst. Diese Trennung ist der Grund, warum sich die heikelste Logik
des Plugins ohne einen einzigen USB-Stick pruefen laesst.

DIE DREI STUFEN
---------------
    1  Dienst      systemctl restart <dienst> / docker restart <container>
    2  sysfs       unbind + bind am USB-Treiber, geht auf jedem Linux
    3  uhubctl     Portstrom aus und wieder an - nur wo die Hardware es kann

Gestuft, weil die Wucht steigt: ein Dienstneustart kostet Sekunden, ein
Portstrom-Reset zieht dem Geraet den Strom weg. Wer gleich mit Stufe 3
anfaengt, riskiert bei einem Stick, der nur gerade beschaeftigt war, einen
Schaden am Dateisystem des Sticks.

WARUM ES EINE SPERRE GIBT
-------------------------
Ein Waechter, der im Minutentakt zuschlaegt, ist schlimmer als keiner. Faellt
ein Stick dauerhaft aus - Kabelbruch, defekte Hardware -, wuerde das Plugin
sonst stuendlich sechzig Resets fahren. Deshalb: Mindestabstand zwischen zwei
Heilversuchen, eine Hoechstzahl je Zeitfenster, und danach Ruhe mit einer
klaren Meldung statt weiterer Versuche.

Kompatibel mit Python 3.9 (LoxBerry 3) und 3.11 (Debian 12/13).
"""

from __future__ import annotations

FASSUNG = "1.0.0"

# Die Stufen, in der Reihenfolge ihrer Wucht.
STUFE_DIENST = 1
STUFE_SYSFS = 2
STUFE_UHUBCTL = 3
STUFEN_NAMEN = {0: "keine", 1: "dienst", 2: "sysfs", 3: "uhubctl"}


def vorgabe_geraet() -> dict:
    """Ein Geraet mit allen Feldern und ihren Vorgaben."""
    return {
        "name": "",
        "aktiv": 1,
        # --- Woran wird Gesundheit erkannt? ---
        "art": "datei",        # datei | mqtt | http | seriell | bluetooth
        "pfad": "",            # Datei, Geraetedatei oder Adresse - je nach Art
        "thema": "",           # nur art=mqtt
        "hoechstalter": 300,   # Sekunden ohne Lebenszeichen, dann gilt es als tot
        # --- Womit wird geheilt? ---
        "heilen": 1,           # 0 = nur melden
        "dienst": "",          # systemd-Einheit, z. B. zigbee2mqtt
        "container": "",       # oder Docker-Container
        "usb_pfad": "",        # sysfs-Kennung, z. B. 1-1.4:1.0
        "hub": "",             # uhubctl: Hub-Kennung, z. B. 1-1
        "port": 0,             # uhubctl: Portnummer
        "hoechststufe": 2,     # weiter als hierhin wird nicht eskaliert
        # --- Bremsen ---
        "ruhe_s": 120,         # Wartezeit nach einem Versuch, bevor neu geurteilt wird
        "abstand_s": 600,      # Mindestabstand zwischen zwei Heilversuchen
        "je_tag": 6,           # Hoechstzahl Heilversuche in 24 Stunden
    }


def zahl(wert, vorgabe=0.0):
    """Eine Zahl aus einer Eingabe - oder die Vorgabe. Nie eine Ausnahme."""
    try:
        if wert is None or wert == "":
            return vorgabe
        return float(str(wert).replace(",", "."))
    except (TypeError, ValueError):
        return vorgabe


def geraet_geradebiegen(roh: dict) -> dict:
    """Ein Geraet aus der Konfiguration auf gueltige Grenzen bringen."""
    g = vorgabe_geraet()
    g.update({k: v for k, v in (roh or {}).items() if k in g})
    g["name"] = str(g["name"]).strip()
    g["art"] = g["art"] if g["art"] in ("datei", "mqtt", "http", "seriell", "bluetooth") else "datei"
    g["aktiv"] = 0 if not g["aktiv"] else 1
    g["heilen"] = 0 if not g["heilen"] else 1
    g["hoechstalter"] = int(max(10, min(86400, zahl(g["hoechstalter"], 300))))
    g["hoechststufe"] = int(max(0, min(3, zahl(g["hoechststufe"], 2))))
    g["ruhe_s"] = int(max(10, min(3600, zahl(g["ruhe_s"], 120))))
    g["abstand_s"] = int(max(30, min(86400, zahl(g["abstand_s"], 600))))
    g["je_tag"] = int(max(0, min(50, zahl(g["je_tag"], 6))))
    g["port"] = int(max(0, min(99, zahl(g["port"], 0))))
    return g


def gesund(g: dict, alter_s, jetzt: float) -> tuple:
    """Gilt das Geraet als gesund?

    Rueckgabe: (1/0, Grund)

    alter_s ist die Zeit seit dem letzten Lebenszeichen - None heisst: es gab
    noch nie eines. Der Unterschied ist wichtig. Ein Geraet, von dem noch nie
    etwas kam, ist nicht "seit langem tot", sondern moeglicherweise falsch
    eingetragen; dafuer waere ein USB-Reset die falsche Antwort.
    """
    if not g.get("aktiv"):
        return 1, "aus"
    if alter_s is None:
        return 0, "nie_gesehen"
    if alter_s < 0:
        # Kann vorkommen, wenn die Systemuhr springt (NTP nach dem Start).
        # Dann ist nichts bewiesen - also nicht als krank werten.
        return 1, "zeitsprung"
    if alter_s <= g["hoechstalter"]:
        return 1, "frisch"
    return 0, "veraltet"


def naechste_stufe(g: dict, bisher: int) -> int:
    """Welche Heilstufe ist als naechstes dran - und ist sie ueberhaupt belegt?

    Uebersprungen wird, wofuer nichts eingetragen ist: wer keinen Dienst
    nennt, faengt bei sysfs an. Ohne diese Pruefung liefe die Eskalation ins
    Leere und meldete "geheilt", ohne etwas getan zu haben.
    """
    for stufe in (STUFE_DIENST, STUFE_SYSFS, STUFE_UHUBCTL):
        if stufe <= bisher:
            continue
        if stufe > g["hoechststufe"]:
            return 0
        if stufe == STUFE_DIENST and (g["dienst"] or g["container"]):
            return stufe
        if stufe == STUFE_SYSFS and g["usb_pfad"]:
            return stufe
        if stufe == STUFE_UHUBCTL and g["hub"] and g["port"] > 0:
            return stufe
    return 0


def darf_heilen(g: dict, verlauf: list, jetzt: float) -> tuple:
    """Darf jetzt geheilt werden? Rueckgabe: (1/0, Grund)

    verlauf ist eine Liste von Zeitstempeln vergangener Heilversuche,
    aufsteigend.
    """
    if not g.get("heilen"):
        return 0, "heilen_aus"
    if not verlauf:
        return 1, "frei"
    letzter = verlauf[-1]
    if jetzt - letzter < g["ruhe_s"]:
        return 0, "ruhezeit"
    if jetzt - letzter < g["abstand_s"]:
        return 0, "abstand"
    im_fenster = [t for t in verlauf if jetzt - t < 86400]
    if g["je_tag"] > 0 and len(im_fenster) >= g["je_tag"]:
        return 0, "tagesgrenze"
    return 1, "frei"


def entscheiden(g: dict, alter_s, verlauf: list, bisher: int, jetzt: float) -> dict:
    """Der ganze Entschluss in einem Aufruf.

    Rueckgabe:
        ok       1 = gesund
        grund    warum
        aktion   0 = nichts tun, sonst die Stufe
        warum    warum nicht geheilt wird, wenn aktion 0 ist
    """
    ok, grund = gesund(g, alter_s, jetzt)
    if ok:
        return {"ok": 1, "grund": grund, "aktion": 0, "warum": ""}

    erlaubt, warum = darf_heilen(g, verlauf, jetzt)
    if not erlaubt:
        return {"ok": 0, "grund": grund, "aktion": 0, "warum": warum}

    stufe = naechste_stufe(g, bisher)
    if stufe == 0:
        # Nichts mehr uebrig. Das ist eine Aussage, kein Fehler: entweder ist
        # nichts eingetragen, oder alle Stufen sind durch. Der Dienst meldet
        # das nach Loxone, statt es zu verschweigen.
        return {"ok": 0, "grund": grund, "aktion": 0, "warum": "keine_stufe_mehr"}
    return {"ok": 0, "grund": grund, "aktion": stufe, "warum": ""}


# ======================================================================
# Systemgeraete schuetzen
#
# DER GEFAEHRLICHSTE FEHLER, DEN DIESES PLUGIN MACHEN KOENNTE, waere ein
# unbind auf dem USB-Geraet, an dem das Wurzeldateisystem haengt. Auf einem
# Raspberry Pi, der von USB bootet, ist das der sofortige Stillstand - und
# zwar einer, der sich nicht von selbst loest.
#
# Deshalb wird jede sysfs-Kennung vorher gegen die Liste der Geraete
# geprueft, auf denen / und /boot liegen. Passt sie, wird ABGELEHNT, nicht
# gewarnt.
# ======================================================================

def ist_systemgeraet(usb_pfad: str, system_pfade: list) -> bool:
    """Gehoert diese USB-Kennung zu einem Geraet, das das System traegt?

    Verglichen wird auf Praefix-Ebene: 1-1.4 schuetzt auch 1-1.4:1.0, denn
    ein unbind am Elternknoten nimmt die Kinder mit.
    """
    u = str(usb_pfad or "").strip()
    if not u:
        return False
    for s in system_pfade or []:
        s = str(s or "").strip()
        if not s:
            continue
        if u == s or u.startswith(s + ":") or u.startswith(s + ".") or s.startswith(u + ":"):
            return True
    return False


# ======================================================================
# Selbsttest
# ======================================================================

def selbsttest() -> tuple:
    """Rueckgabe: (Anzahl, Fehlschlaege, Text)"""
    zeilen = []
    stand = {"n": 0, "f": 0}

    def pr(name, ist, soll):
        stand["n"] += 1
        ok = ist == soll
        if not ok:
            stand["f"] += 1
        zeilen.append(("[ OK ] " if ok else "[FEHL] ") + name)
        if not ok:
            zeilen.append("       erzeugt : %r" % (ist,))
            zeilen.append("       erwartet: %r" % (soll,))

    jetzt = 1_000_000.0
    g = geraet_geradebiegen({"name": "Zigbee", "art": "mqtt", "thema": "zigbee2mqtt/bridge/state",
                             "hoechstalter": 300, "dienst": "zigbee2mqtt",
                             "usb_pfad": "1-1.4:1.0", "hub": "1-1", "port": 4,
                             "hoechststufe": 3})

    # ---------- Gesundheit ----------
    pr("frisch gemeldet gilt als gesund", gesund(g, 10, jetzt), (1, "frisch"))
    pr("genau auf der Grenze gilt noch als gesund", gesund(g, 300, jetzt), (1, "frisch"))
    pr("eine Sekunde darueber gilt als tot", gesund(g, 301, jetzt), (0, "veraltet"))
    pr("noch nie gesehen ist NICHT dasselbe wie tot",
       gesund(g, None, jetzt), (0, "nie_gesehen"))
    pr("negatives Alter (Zeitsprung) wird nicht als krank gewertet",
       gesund(g, -50, jetzt), (1, "zeitsprung"))
    pr("abgeschaltetes Geraet gilt immer als gesund",
       gesund(geraet_geradebiegen(dict(g, aktiv=0)), 99999, jetzt), (1, "aus"))

    # ---------- Eskalation ----------
    pr("erste Stufe ist der Dienst", naechste_stufe(g, 0), STUFE_DIENST)
    pr("danach sysfs", naechste_stufe(g, 1), STUFE_SYSFS)
    pr("danach uhubctl", naechste_stufe(g, 2), STUFE_UHUBCTL)
    pr("danach nichts mehr", naechste_stufe(g, 3), 0)

    ohne_dienst = geraet_geradebiegen(dict(g, dienst="", container=""))
    pr("ohne Dienst wird Stufe 1 uebersprungen",
       naechste_stufe(ohne_dienst, 0), STUFE_SYSFS)
    nur_dienst = geraet_geradebiegen(dict(g, usb_pfad="", hub="", port=0))
    pr("ohne USB-Angaben bleibt es bei Stufe 1",
       (naechste_stufe(nur_dienst, 0), naechste_stufe(nur_dienst, 1)), (STUFE_DIENST, 0))
    gedeckelt = geraet_geradebiegen(dict(g, hoechststufe=1))
    pr("Hoechststufe 1 laesst nicht auf sysfs eskalieren",
       naechste_stufe(gedeckelt, 1), 0)
    pr("Hoechststufe 0 heisst: gar nicht heilen",
       naechste_stufe(geraet_geradebiegen(dict(g, hoechststufe=0)), 0), 0)

    # ---------- Bremsen ----------
    pr("ohne Verlauf ist frei", darf_heilen(g, [], jetzt), (1, "frei"))
    pr("innerhalb der Ruhezeit nicht", darf_heilen(g, [jetzt - 60], jetzt), (0, "ruhezeit"))
    pr("innerhalb des Mindestabstands nicht",
       darf_heilen(g, [jetzt - 300], jetzt), (0, "abstand"))
    pr("nach dem Abstand wieder frei", darf_heilen(g, [jetzt - 700], jetzt), (1, "frei"))
    viele = [jetzt - 86000 + i * 100 for i in range(6)]
    pr("Tagesgrenze greift", darf_heilen(g, viele, jetzt), (0, "tagesgrenze"))
    alt = [jetzt - 90000 - i * 100 for i in range(9)]
    pr("Versuche aelter als 24 h zaehlen nicht mit",
       darf_heilen(g, sorted(alt), jetzt), (1, "frei"))
    pr("heilen=0 heisst nur melden",
       darf_heilen(geraet_geradebiegen(dict(g, heilen=0)), [], jetzt), (0, "heilen_aus"))

    # ---------- Der ganze Entschluss ----------
    pr("gesund -> nichts tun",
       entscheiden(g, 10, [], 0, jetzt),
       {"ok": 1, "grund": "frisch", "aktion": 0, "warum": ""})
    pr("tot und frei -> Stufe 1",
       entscheiden(g, 900, [], 0, jetzt),
       {"ok": 0, "grund": "veraltet", "aktion": 1, "warum": ""})
    pr("tot, Stufe 1 schon versucht -> Stufe 2",
       entscheiden(g, 900, [jetzt - 700], 1, jetzt),
       {"ok": 0, "grund": "veraltet", "aktion": 2, "warum": ""})
    pr("tot, aber in der Ruhezeit -> nichts, mit Grund",
       entscheiden(g, 900, [jetzt - 30], 1, jetzt),
       {"ok": 0, "grund": "veraltet", "aktion": 0, "warum": "ruhezeit"})
    pr("tot, alle Stufen durch -> nichts, und das wird gesagt",
       entscheiden(g, 900, [jetzt - 700], 3, jetzt),
       {"ok": 0, "grund": "veraltet", "aktion": 0, "warum": "keine_stufe_mehr"})

    # ---------- Systemgeraete ----------
    sys_pfade = ["1-1.1", "2-2"]
    pr("das Systemgeraet selbst wird erkannt", ist_systemgeraet("1-1.1", sys_pfade), True)
    pr("die Schnittstelle darunter ebenso", ist_systemgeraet("1-1.1:1.0", sys_pfade), True)
    pr("ein Kindknoten ebenso", ist_systemgeraet("1-1.1.3", sys_pfade), True)
    pr("ein Nachbarport ist frei", ist_systemgeraet("1-1.4", sys_pfade), False)
    pr("aehnlicher Anfang ist kein Treffer", ist_systemgeraet("1-1.10", sys_pfade), False)
    pr("leere Angabe ist kein Treffer", ist_systemgeraet("", sys_pfade), False)

    # ---------- Eingaben geradebiegen ----------
    b = geraet_geradebiegen({"name": " Stick ", "hoechstalter": "abc", "ruhe_s": -5,
                             "je_tag": 999, "art": "unfug", "port": "7"})
    pr("unlesbares Hoechstalter faellt auf die Vorgabe zurueck", b["hoechstalter"], 300)
    pr("negative Ruhezeit wird begrenzt", b["ruhe_s"], 10)
    pr("unsinnige Tagesgrenze wird begrenzt", b["je_tag"], 50)
    pr("unbekannte Art wird auf datei gesetzt", b["art"], "datei")
    pr("Name wird beschnitten", b["name"], "Stick")
    pr("Port als Text wird zur Zahl", b["port"], 7)

    kopf = "Funkwacht-Kern %s: %d Faelle geprueft, %d Fehlschlaege." % (
        FASSUNG, stand["n"], stand["f"])
    return stand["n"], stand["f"], kopf + "\n\n" + "\n".join(zeilen)


if __name__ == "__main__":
    n, f, text = selbsttest()
    print(text)
    raise SystemExit(1 if f else 0)
