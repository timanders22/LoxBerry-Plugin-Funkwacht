#!/usr/bin/env python3
"""Funkwacht - der Entscheidungskern.

Reine Rechnung: kein Netz, keine Geraete, keine Uhr ausser der uebergebenen
Zeit. Deshalb laesst sich alles hier durchpruefen - selbsttest() rechnet die
Faelle nach, die im Betrieb weh tun.

WAS DIESES MODUL ENTSCHEIDET, UND WAS NICHT
-------------------------------------------
Es entscheidet: gilt ein Stick als gesund, welche Heilstufe ist als naechstes
dran, und darf jetzt ueberhaupt geheilt werden. Es fuehrt nichts aus und es
misst nichts - das tut der Dienst. Diese Trennung ist der Grund, warum sich
die heikelste Logik des Plugins ohne einen einzigen USB-Stick pruefen laesst.

DIE DREI STUFEN
---------------
    1  Dienst      systemctl restart <dienst> / docker restart <container>
    2  sysfs       unbind + bind am USB-Treiber, geht auf jedem Linux
    3  uhubctl     Portstrom aus und wieder an - nur wo die Hardware es kann

Gestuft, weil die Wucht steigt: ein Dienstneustart kostet Sekunden, ein
Portstrom-Reset zieht dem Geraet den Strom weg. Wer gleich mit Stufe 3
anfaengt, riskiert bei einem Stick, der nur gerade beschaeftigt war, einen
Schaden am Dateisystem des Sticks.

DIE REIHENFOLGE IST SEIT 1.0.0 EINSTELLBAR. Bei manchen Aufbauten bringt ein
Dienstneustart nichts, solange der Stick selbst festgefahren ist - dort ist
2-1-3 richtig. Die Wucht steigt dann nicht mehr streng, deshalb steht die
Umstellung je Stick und ab Werk auf der alten Folge.

WARUM ES BREMSEN GIBT
---------------------
Ein Waechter, der im Minutentakt zuschlaegt, ist schlimmer als keiner. Faellt
ein Stick dauerhaft aus - Kabelbruch, defekte Hardware -, wuerde das Plugin
sonst stuendlich sechzig Resets fahren. Es gibt deshalb sechs Bremsen, und
jede beantwortet eine andere Frage:

    Erholung      unmittelbar nach einem Versuch ist Stille zu erwarten
    Abstand       Mindestzeit zwischen zwei Versuchen
    Tagesgrenze   Hoechstzahl Versuche in 24 Stunden
    Anlaufzeit    kurz nach dem Systemstart ist alles alt
    Nachtruhe     ein Zeitfenster, in dem nur gemeldet wird
    Wartung       ein Schalter von Hand, der von selbst wieder ausgeht

Dazu kommt: von einem Stick, der noch NIE ein Lebenszeichen gegeben hat, wird
nicht geheilt. Das ist fast immer ein Eintragungsfehler.

Kompatibel mit Python 3.9 (LoxBerry 3) und 3.11 (Debian 12/13).
"""

from __future__ import annotations

FASSUNG = "1.2.0"

# Die Stufen, in der Reihenfolge ihrer Wucht.
STUFE_DIENST = 1
STUFE_SYSFS = 2
STUFE_UHUBCTL = 3
STUFEN_NAMEN = {0: "keine", 1: "dienst", 2: "sysfs", 3: "uhubctl"}

# Die Arten, an denen ein Lebenszeichen abgelesen wird. Gemessen wird in
# funkwacht_dienst.py; hier steht nur, welche es gibt - damit ein gespeicherter
# Eintrag beim Lesen nicht stillschweigend auf "datei" umgebogen wird.
ARTEN = ("datei", "mqtt", "http", "seriell", "bluetooth", "usb", "dienst", "docker")

# Wie zwei Kriterien verknuepft werden, wenn ein Stick zwei traegt.
VERKNUEPFUNGEN = ("", "und", "oder")

# Zahlencodes fuer Loxone. Ein Text laesst sich in einem virtuellen Eingang
# nicht auswerten; diese beiden Tabellen sind die einzige Stelle, an der die
# Zuordnung steht, und der Reiter Test haelt sie gegen die Sprachdatei.
GRUND_NR = {"frisch": 0, "veraltet": 1, "nie_gesehen": 2, "zeitsprung": 3,
            "aus": 4, "erholung": 5}
WARUM_NR = {"": 0, "frei": 0, "heilen_aus": 1, "abstand": 2, "tagesgrenze": 3,
            "keine_stufe_mehr": 4, "nie_gesehen": 5, "anlaufzeit": 6,
            "nachtruhe": 7, "wartung": 8, "global_aus": 9}

# Sperren, die von aussen kommen (der Dienst rechnet sie aus, weil dafuer eine
# Uhr und /proc/uptime noetig sind).
SPERREN = ("", "anlaufzeit", "nachtruhe", "wartung", "global_aus")

# Ab so vielen erfolglosen Versuchen gilt eine Stufe als taub - aber nur, wenn
# der Haken "lernen" gesetzt ist UND danach noch eine andere Stufe kommt.
TAUB_AB = 10


def vorgabe_geraet() -> dict:
    """Ein Geraet mit allen Feldern und ihren Vorgaben."""
    return {
        "name": "",
        "aktiv": 1,
        # --- Woran wird Gesundheit erkannt? ---
        "art": "datei",        # siehe ARTEN
        "pfad": "",            # Datei, Geraetedatei, Adresse, sysfs-Kennung ...
        "thema": "",           # nur art=mqtt
        "kennung": "",         # nur art=usb, z. B. 1a86:55d4 - Gegenprobe
        "wachstum": 0,         # nur art=datei: waechst sie, statt nur beruehrt zu werden?
        # --- Zweites Kriterium (leer = keines) ---
        "art2": "",
        "pfad2": "",
        "thema2": "",
        "verkn": "oder",       # und | oder - wie die beiden zusammengehen
        "hoechstalter": 300,   # Sekunden ohne Lebenszeichen, dann gilt es als tot
        # --- Womit wird geheilt? ---
        "heilen": 1,           # 0 = nur melden
        "dienst": "",          # systemd-Einheit, z. B. zigbee2mqtt
        "container": "",       # oder Docker-Container
        "usb_pfad": "",        # sysfs-Kennung, z. B. 1-1.4:1.0
        "hub": "",             # uhubctl: Hub-Kennung, z. B. 1-1
        "port": 0,             # uhubctl: Portnummer
        "hoechststufe": 2,     # weiter als hierhin wird nicht eskaliert
        "reihenfolge": "normal",   # normal (1-2-3) | usb_zuerst (2-1-3)
        "dienst_nach": 1,      # nach Stufe 2/3 den Dienst gleich mitnehmen
        "lernen": 0,           # eine dauerhaft erfolglose Stufe ueberspringen
        # --- Bremsen ---
        "ruhe_s": 120,         # Erholungszeit nach einem Versuch
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
    g["art"] = g["art"] if g["art"] in ARTEN else "datei"
    g["art2"] = g["art2"] if g["art2"] in ARTEN else ""
    g["verkn"] = g["verkn"] if g["verkn"] in ("und", "oder") else "oder"
    g["reihenfolge"] = "usb_zuerst" if g["reihenfolge"] == "usb_zuerst" else "normal"
    for k in ("aktiv", "heilen", "wachstum", "dienst_nach", "lernen"):
        g[k] = 0 if not g[k] else 1
    g["hoechstalter"] = int(max(10, min(86400, zahl(g["hoechstalter"], 300))))
    g["hoechststufe"] = int(max(0, min(3, zahl(g["hoechststufe"], 2))))
    g["ruhe_s"] = int(max(10, min(3600, zahl(g["ruhe_s"], 120))))
    g["abstand_s"] = int(max(30, min(86400, zahl(g["abstand_s"], 600))))
    g["je_tag"] = int(max(0, min(50, zahl(g["je_tag"], 6))))
    g["port"] = int(max(0, min(99, zahl(g["port"], 0))))
    return g


# ======================================================================
# Zwei Kriterien
# ======================================================================

def alter_verbinden(a1, a2, verkn: str):
    """Aus zwei gemessenen Altern eines machen.

    None heisst "kein Lebenszeichen". Bei ODER genuegt eines, also zaehlt das
    JUENGERE; bei UND muessen beide frisch sein, also zaehlt das AELTERE - und
    ein fehlendes reisst das Ergebnis auf None.

    Ohne zweites Kriterium bleibt es beim ersten. Genau das ist der Grund,
    warum diese Rechnung hier steht und nicht beim Messen: sie ist eine
    Entscheidung, keine Messung.
    """
    if a2 is None and verkn == "oder":
        return a1
    if a1 is None and verkn == "oder":
        return a2
    if a1 is None or a2 is None:
        return None
    return min(a1, a2) if verkn == "oder" else max(a1, a2)


# ======================================================================
# Gesundheit
# ======================================================================

def gesund(g: dict, alter_s, jetzt: float) -> tuple:
    """Gilt das Geraet als gesund?

    Rueckgabe: (1/0, Grund)

    alter_s ist die Zeit seit dem letzten Lebenszeichen - None heisst: es gab
    noch nie eines. Der Unterschied ist wichtig. Ein Geraet, von dem noch nie
    etwas kam, ist nicht "seit langem tot", sondern moeglicherweise falsch
    eingetragen; dafuer waere ein USB-Reset die falsche Antwort. Was daraus
    folgt, entscheidet entscheiden() - hier wird nur gemeldet.
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


def in_erholung(g: dict, verlauf: list, jetzt: float) -> bool:
    """Laeuft nach dem letzten Heilversuch noch die Erholungszeit?

    Ein Dienstneustart oder ein USB-Reset macht das Funknetz fuer ein bis zwei
    Minuten stumm. Wer in dieser Zeit misst, misst die Heilung, nicht den
    Stick - und meldete an Loxone bei JEDER Heilung eine Stoerung.
    """
    if not verlauf:
        return False
    return (jetzt - verlauf[-1]) < g["ruhe_s"]


# ======================================================================
# Zeitfenster
# ======================================================================

def fenster_offen(minute: int, von: str, bis: str) -> bool:
    """Liegt die Tagesminute im Fenster von..bis?

    von und bis stehen als HH:MM. Ist eines leer oder unlesbar, gibt es kein
    Fenster - dann ist die Antwort False, und nichts wird gesperrt. Ein
    Fenster, das ueber Mitternacht geht (22:00 bis 06:00), wird richtig
    behandelt; von == bis heisst "kein Fenster", nicht "der ganze Tag".
    """
    a = _hhmm(von)
    b = _hhmm(bis)
    if a is None or b is None or a == b:
        return False
    if a < b:
        return a <= minute < b
    return minute >= a or minute < b


def _hhmm(text):
    t = str(text or "").strip()
    if len(t) != 5 or t[2] != ":":
        return None
    try:
        std = int(t[0:2])
        mn = int(t[3:5])
    except ValueError:
        return None
    if not (0 <= std <= 23 and 0 <= mn <= 59):
        return None
    return std * 60 + mn


# ======================================================================
# Eskalation
# ======================================================================

def stufen_folge(g: dict) -> tuple:
    """Die Reihenfolge, in der die Stufen versucht werden."""
    if g.get("reihenfolge") == "usb_zuerst":
        return (STUFE_SYSFS, STUFE_DIENST, STUFE_UHUBCTL)
    return (STUFE_DIENST, STUFE_SYSFS, STUFE_UHUBCTL)


def stufe_belegt(g: dict, stufe: int) -> bool:
    """Ist fuer diese Stufe ueberhaupt etwas eingetragen?"""
    if stufe == STUFE_DIENST:
        return bool(g["dienst"] or g["container"])
    if stufe == STUFE_SYSFS:
        return bool(g["usb_pfad"])
    if stufe == STUFE_UHUBCTL:
        return bool(g["hub"]) and g["port"] > 0
    return False


def stufe_taub(statistik, stufe: int) -> bool:
    """Hat diese Stufe oft genug nichts gebracht?

    statistik ist {stufe: (versuche, erfolge)}. Taub heisst NICHT "nie wieder":
    uebersprungen wird nur, solange eine andere Stufe uebrig ist - siehe
    naechste_stufe(). Ein Waechter, der am Ende gar nichts mehr tut, waere
    schlechter als einer, der es noch einmal versucht.
    """
    if not statistik:
        return False
    v, e = statistik.get(stufe, (0, 0))
    return v >= TAUB_AB and e == 0


def naechste_stufe(g: dict, bisher: int, statistik=None) -> int:
    """Welche Heilstufe ist als naechstes dran - und ist sie ueberhaupt belegt?

    Uebersprungen wird, wofuer nichts eingetragen ist: wer keinen Dienst
    nennt, faengt bei sysfs an. Ohne diese Pruefung liefe die Eskalation ins
    Leere und meldete "geheilt", ohne etwas getan zu haben.

    Mit gesetztem Haken "lernen" wird zusaetzlich uebersprungen, was
    nachweislich nichts bringt - aber nur, wenn danach noch etwas kommt.
    """
    folge = stufen_folge(g)
    start = folge.index(bisher) + 1 if bisher in folge else 0
    # Erst die Stufen sammeln, die ueberhaupt in Frage kommen. Ein
    # "return 0" beim ersten zu hohen Wert waere falsch, sobald die
    # Reihenfolge nicht mehr aufsteigt (2-1-3).
    moeglich = [s for s in folge[start:]
                if s <= g["hoechststufe"] and stufe_belegt(g, s)]
    if not moeglich:
        return 0
    if g.get("lernen"):
        wach = [s for s in moeglich if not stufe_taub(statistik, s)]
        if wach:
            return wach[0]
        # Alle uebrigen sind taub: dann doch die erste versuchen, statt gar
        # nichts zu tun.
    return moeglich[0]


def stufe_danach(g: dict, stufe: int) -> int:
    """Welche Stufe wird unmittelbar angehaengt, wenn diese gelungen ist?

    Ein unbind/bind oder ein Stromzyklus laesst den Dienst mit einem toten
    Dateideskriptor zurueck: der Stick ist da, aber Zigbee2MQTT redet nicht
    mehr mit ihm. Deshalb kommt der Dienstneustart gleich hinterher, statt
    erst beim naechsten Durchlauf - und nur dann, wenn ueberhaupt einer
    eingetragen ist.
    """
    if not g.get("dienst_nach"):
        return 0
    if stufe not in (STUFE_SYSFS, STUFE_UHUBCTL):
        return 0
    if not stufe_belegt(g, STUFE_DIENST):
        return 0
    return STUFE_DIENST


# ======================================================================
# Bremsen
# ======================================================================

def darf_heilen(g: dict, verlauf: list, jetzt: float) -> tuple:
    """Darf jetzt geheilt werden? Rueckgabe: (1/0, Grund)

    verlauf ist eine Liste von Zeitstempeln vergangener Heilversuche,
    aufsteigend.

    Die Ruhezeit steht hier NICHT: sie ist seit 0.9.4 eine Erholungszeit und
    wird in entscheiden() vor dem Urteil abgefragt, nicht danach.
    """
    if not g.get("heilen"):
        return 0, "heilen_aus"
    if not verlauf:
        return 1, "frei"
    letzter = verlauf[-1]
    if jetzt - letzter < g["abstand_s"]:
        return 0, "abstand"
    im_fenster = [t for t in verlauf if jetzt - t < 86400]
    if g["je_tag"] > 0 and len(im_fenster) >= g["je_tag"]:
        return 0, "tagesgrenze"
    return 1, "frei"


def entscheiden(g: dict, alter_s, verlauf: list, bisher: int, jetzt: float,
                schonzeit: bool = False, sperre: str = "",
                statistik=None) -> dict:
    """Der ganze Entschluss in einem Aufruf.

    schonzeit  kurz nach dem Systemstart ist jede Datei alt und jedes Thema
               leer - dann wird gemeldet und nicht geheilt.
    sperre     Nachtruhe, Wartung oder der globale Aus-Schalter. Der Dienst
               rechnet sie aus, weil dafuer eine Uhr noetig ist.

    Rueckgabe:
        ok       1 = gesund
        grund    warum
        aktion   0 = nichts tun, sonst die Stufe
        danach   Stufe, die unmittelbar angehaengt wird, wenn aktion gelingt
        warum    warum nicht geheilt wird, wenn aktion 0 ist
    """
    leer = {"ok": 1, "grund": "frisch", "aktion": 0, "danach": 0, "warum": ""}

    ok, grund = gesund(g, alter_s, jetzt)
    if ok:
        return dict(leer, grund=grund)

    # Erholung geht dem Urteil vor: unmittelbar nach einem Heilversuch ist
    # Stille zu erwarten und kein Befund.
    if in_erholung(g, verlauf, jetzt):
        return dict(leer, grund="erholung")

    krank = {"ok": 0, "grund": grund, "aktion": 0, "danach": 0, "warum": ""}

    # Noch nie ein Lebenszeichen heisst fast immer: falsch eingetragen.
    # Melden ja, heilen nein - ein Waechter, der im Zweifel zuschlaegt, ist
    # schlimmer als keiner.
    if grund == "nie_gesehen":
        return dict(krank, warum="nie_gesehen")

    if schonzeit:
        return dict(krank, warum="anlaufzeit")

    if sperre in SPERREN and sperre != "":
        return dict(krank, warum=sperre)

    erlaubt, warum = darf_heilen(g, verlauf, jetzt)
    if not erlaubt:
        return dict(krank, warum=warum)

    stufe = naechste_stufe(g, bisher, statistik)
    if stufe == 0:
        # Nichts mehr uebrig. Das ist eine Aussage, kein Fehler: entweder ist
        # nichts eingetragen, oder alle Stufen sind durch.
        return dict(krank, warum="keine_stufe_mehr")
    return dict(krank, aktion=stufe, danach=stufe_danach(g, stufe))


def alarm_wert(eintraege) -> int:
    """1, sobald ein Stick gestoert ist und fuer ihn KEINE Heilung mehr aussteht.

    Genau das sagt die Themenliste zu, und genau das war bis 0.9.3 nicht der
    Fall: dort ging auf demselben Thema ein Satz hinaus, sobald geheilt wurde,
    und er wurde nie zurueckgenommen.
    """
    for e in eintraege or []:
        if not e.get("ok") and not e.get("aktion"):
            return 1
    return 0


def im_fenster_zaehlen(verlauf, jetzt: float, sekunden: float) -> int:
    """Wie viele Heilungen liegen in den letzten <sekunden>?

    Das ist die Zahl, aus der die Verschleisskurve entsteht: steigt sie ueber
    Wochen langsam an, ist ein Stick am Ende seiner Kraefte, lange bevor er
    ganz ausfaellt.
    """
    return len([t for t in (verlauf or []) if 0 <= (jetzt - float(t)) < sekunden])


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
    g = geraet_geradebiegen({"name": "Zigbee", "art": "http",
                             "pfad": "http://localhost:8080/",
                             "hoechstalter": 300, "dienst": "zigbee2mqtt",
                             "usb_pfad": "1-1.4:1.0", "hub": "1-1", "port": 4,
                             "hoechststufe": 3, "dienst_nach": 0})

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

    # ---------- Zwei Kriterien ----------
    pr("ODER nimmt das juengere Lebenszeichen", alter_verbinden(500, 10, "oder"), 10)
    pr("UND nimmt das aeltere", alter_verbinden(500, 10, "und"), 500)
    pr("ODER traegt auch, wenn eines fehlt", alter_verbinden(None, 10, "oder"), 10)
    pr("ODER traegt auch andersherum", alter_verbinden(10, None, "oder"), 10)
    pr("UND faellt aus, wenn eines fehlt", alter_verbinden(10, None, "und"), None)
    pr("beide fehlen heisst nichts gehoert", alter_verbinden(None, None, "oder"), None)
    pr("ohne zweites Kriterium bleibt es beim ersten",
       alter_verbinden(42, None, "oder"), 42)

    # ---------- Zeitfenster ----------
    pr("Fenster am Tag: mittendrin", fenster_offen(13 * 60, "12:00", "14:00"), True)
    pr("Fenster am Tag: davor", fenster_offen(11 * 60, "12:00", "14:00"), False)
    pr("Fenster am Tag: der Endpunkt zaehlt nicht mehr dazu",
       fenster_offen(14 * 60, "12:00", "14:00"), False)
    pr("Fenster ueber Mitternacht: spaet abends",
       fenster_offen(23 * 60, "22:00", "06:00"), True)
    pr("Fenster ueber Mitternacht: frueh morgens",
       fenster_offen(2 * 60, "22:00", "06:00"), True)
    pr("Fenster ueber Mitternacht: mittags nicht",
       fenster_offen(12 * 60, "22:00", "06:00"), False)
    pr("leere Angabe ergibt kein Fenster", fenster_offen(12 * 60, "", "06:00"), False)
    pr("unlesbare Angabe ergibt kein Fenster",
       fenster_offen(12 * 60, "24:00", "06:00"), False)
    pr("von gleich bis heisst KEIN Fenster, nicht der ganze Tag",
       fenster_offen(12 * 60, "06:00", "06:00"), False)

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

    # ---------- Umgekehrte Reihenfolge ----------
    u = geraet_geradebiegen(dict(g, reihenfolge="usb_zuerst"))
    pr("umgekehrt faengt es bei sysfs an", naechste_stufe(u, 0), STUFE_SYSFS)
    pr("danach kommt der Dienst", naechste_stufe(u, STUFE_SYSFS), STUFE_DIENST)
    pr("und zuletzt uhubctl", naechste_stufe(u, STUFE_DIENST), STUFE_UHUBCTL)
    pr("dann ist auch dort Schluss", naechste_stufe(u, STUFE_UHUBCTL), 0)
    u2 = geraet_geradebiegen(dict(g, reihenfolge="usb_zuerst", hoechststufe=2))
    pr("die Hoechststufe verschluckt bei 2-1-3 nicht den Dienst",
       (naechste_stufe(u2, 0), naechste_stufe(u2, STUFE_SYSFS)),
       (STUFE_SYSFS, STUFE_DIENST))

    # ---------- Den Dienst hinterherschicken ----------
    d = geraet_geradebiegen(dict(g, dienst_nach=1))
    pr("nach sysfs kommt der Dienst gleich mit", stufe_danach(d, STUFE_SYSFS), STUFE_DIENST)
    pr("nach uhubctl ebenso", stufe_danach(d, STUFE_UHUBCTL), STUFE_DIENST)
    pr("nach dem Dienst selbst nicht", stufe_danach(d, STUFE_DIENST), 0)
    pr("ohne Haken gar nicht", stufe_danach(g, STUFE_SYSFS), 0)
    pr("und nicht, wenn kein Dienst eingetragen ist",
       stufe_danach(geraet_geradebiegen(dict(d, dienst="", container="")), STUFE_SYSFS), 0)

    # ---------- Lernen ----------
    taub = {STUFE_DIENST: (12, 0), STUFE_SYSFS: (3, 2)}
    pr("eine Stufe mit 12 Versuchen ohne Erfolg gilt als taub",
       stufe_taub(taub, STUFE_DIENST), True)
    pr("eine mit Erfolgen nicht", stufe_taub(taub, STUFE_SYSFS), False)
    pr("eine unbekannte nicht", stufe_taub(taub, STUFE_UHUBCTL), False)
    pr("knapp unter der Grenze noch nicht",
       stufe_taub({STUFE_DIENST: (TAUB_AB - 1, 0)}, STUFE_DIENST), False)
    l = geraet_geradebiegen(dict(g, lernen=1))
    pr("mit Haken wird die taube Stufe uebersprungen",
       naechste_stufe(l, 0, taub), STUFE_SYSFS)
    pr("ohne Haken nicht", naechste_stufe(g, 0, taub), STUFE_DIENST)
    alle_taub = {STUFE_DIENST: (12, 0), STUFE_SYSFS: (12, 0), STUFE_UHUBCTL: (12, 0)}
    pr("sind alle taub, wird trotzdem die erste versucht",
       naechste_stufe(l, 0, alle_taub), STUFE_DIENST)

    # ---------- Bremsen ----------
    pr("ohne Verlauf ist frei", darf_heilen(g, [], jetzt), (1, "frei"))
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
    pr("die Ruhezeit steht nicht mehr in darf_heilen",
       darf_heilen(g, [jetzt - 60], jetzt), (0, "abstand"))

    # ---------- Erholung ----------
    pr("unmittelbar nach einem Versuch laeuft die Erholung",
       in_erholung(g, [jetzt - 60], jetzt), True)
    pr("nach ruhe_s ist die Erholung vorbei",
       in_erholung(g, [jetzt - 121], jetzt), False)
    pr("ohne Verlauf gibt es keine Erholung", in_erholung(g, [], jetzt), False)
    pr("in der Erholung gilt ein stummer Stick als gesund",
       entscheiden(g, 900, [jetzt - 60], 1, jetzt),
       {"ok": 1, "grund": "erholung", "aktion": 0, "danach": 0, "warum": ""})
    pr("nach der Erholung wird wieder geurteilt (hier bremst der Abstand)",
       entscheiden(g, 900, [jetzt - 300], 1, jetzt),
       {"ok": 0, "grund": "veraltet", "aktion": 0, "danach": 0, "warum": "abstand"})

    # ---------- Der ganze Entschluss ----------
    pr("gesund -> nichts tun",
       entscheiden(g, 10, [], 0, jetzt),
       {"ok": 1, "grund": "frisch", "aktion": 0, "danach": 0, "warum": ""})
    pr("tot und frei -> Stufe 1",
       entscheiden(g, 900, [], 0, jetzt),
       {"ok": 0, "grund": "veraltet", "aktion": 1, "danach": 0, "warum": ""})
    pr("tot, Stufe 1 schon versucht -> Stufe 2",
       entscheiden(g, 900, [jetzt - 700], 1, jetzt),
       {"ok": 0, "grund": "veraltet", "aktion": 2, "danach": 0, "warum": ""})
    pr("mit dienst_nach kommt der Dienst hinterher",
       entscheiden(d, 900, [jetzt - 700], 1, jetzt)["danach"], STUFE_DIENST)
    pr("tot, alle Stufen durch -> nichts, und das wird gesagt",
       entscheiden(g, 900, [jetzt - 700], 3, jetzt),
       {"ok": 0, "grund": "veraltet", "aktion": 0, "danach": 0,
        "warum": "keine_stufe_mehr"})

    # ---------- "Noch nie gesehen" heilt nicht ----------
    pr("noch nie ein Lebenszeichen loest KEINE Heilung aus",
       entscheiden(g, None, [], 0, jetzt),
       {"ok": 0, "grund": "nie_gesehen", "aktion": 0, "danach": 0,
        "warum": "nie_gesehen"})
    pr("und auch nicht nach Tagen ohne Lebenszeichen",
       entscheiden(g, None, [jetzt - 90000], 0, jetzt)["aktion"], 0)
    pr("es wird aber weiter als gestoert gemeldet",
       entscheiden(g, None, [], 0, jetzt)["ok"], 0)

    # ---------- Schonzeit und Sperren ----------
    pr("in der Anlaufzeit wird gemeldet, nicht geheilt",
       entscheiden(g, 900, [], 0, jetzt, schonzeit=True),
       {"ok": 0, "grund": "veraltet", "aktion": 0, "danach": 0,
        "warum": "anlaufzeit"})
    pr("die Anlaufzeit macht einen gesunden Stick nicht krank",
       entscheiden(g, 10, [], 0, jetzt, schonzeit=True)["ok"], 1)
    for s in ("nachtruhe", "wartung", "global_aus"):
        pr("Sperre %s: melden ja, heilen nein" % s,
           entscheiden(g, 900, [], 0, jetzt, sperre=s),
           {"ok": 0, "grund": "veraltet", "aktion": 0, "danach": 0, "warum": s})
    pr("eine unbekannte Sperre wird nicht beachtet",
       entscheiden(g, 900, [], 0, jetzt, sperre="unfug")["aktion"], 1)
    pr("die Schonzeit geht der Sperre vor",
       entscheiden(g, 900, [], 0, jetzt, schonzeit=True, sperre="wartung")["warum"],
       "anlaufzeit")

    # ---------- Sammelalarm ----------
    pr("Alarm, wenn ein gestoerter Stick keine Heilung mehr aussteht",
       alarm_wert([{"ok": 1, "aktion": 0}, {"ok": 0, "aktion": 0}]), 1)
    pr("kein Alarm, solange noch eine Stufe aussteht",
       alarm_wert([{"ok": 0, "aktion": 2}]), 0)
    pr("kein Alarm, wenn alles gesund ist", alarm_wert([{"ok": 1, "aktion": 0}]), 0)
    pr("kein Alarm ohne Geraete", alarm_wert([]), 0)

    # ---------- Verschleisskurve ----------
    reihe = [jetzt - 100, jetzt - 3600, jetzt - 90000, jetzt - 700000]
    pr("Heilungen der letzten 24 Stunden", im_fenster_zaehlen(reihe, jetzt, 86400), 2)
    pr("Heilungen der letzten sieben Tage",
       im_fenster_zaehlen(reihe, jetzt, 7 * 86400), 3)
    pr("ohne Verlauf keine", im_fenster_zaehlen([], jetzt, 86400), 0)
    pr("ein Zeitstempel aus der Zukunft zaehlt nicht mit",
       im_fenster_zaehlen([jetzt + 100], jetzt, 86400), 0)

    # ---------- Zahlencodes fuer Loxone ----------
    pr("jeder Grund hat eine Nummer",
       sorted(GRUND_NR), ["aus", "erholung", "frisch", "nie_gesehen", "veraltet",
                          "zeitsprung"])
    pr("die Nummern der Gruende sind eindeutig",
       len(set(GRUND_NR.values())), len(GRUND_NR))
    pr("jede Sperre hat eine Nummer",
       all(s in WARUM_NR for s in SPERREN if s), True)
    pr("leer und frei bedeuten dasselbe", WARUM_NR[""], WARUM_NR["frei"])

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
                             "je_tag": 999, "art": "unfug", "port": "7",
                             "verkn": "vielleicht", "reihenfolge": "quer"})
    pr("unlesbares Hoechstalter faellt auf die Vorgabe zurueck", b["hoechstalter"], 300)
    pr("negative Ruhezeit wird begrenzt", b["ruhe_s"], 10)
    pr("unsinnige Tagesgrenze wird begrenzt", b["je_tag"], 50)
    pr("unbekannte Art wird auf datei gesetzt", b["art"], "datei")
    pr("unbekannte Verknuepfung wird zu oder", b["verkn"], "oder")
    pr("unbekannte Reihenfolge wird zu normal", b["reihenfolge"], "normal")
    pr("Name wird beschnitten", b["name"], "Stick")
    pr("Port als Text wird zur Zahl", b["port"], 7)
    pr("die Art mqtt ist wieder waehlbar und bleibt stehen",
       geraet_geradebiegen({"name": "Z", "art": "mqtt"})["art"], "mqtt")
    for a in ("usb", "dienst", "docker"):
        pr("die neue Art %s bleibt stehen" % a,
           geraet_geradebiegen({"name": "Z", "art": a})["art"], a)

    kopf = "Funkwacht-Kern %s: %d Faelle geprueft, %d Fehlschlaege." % (
        FASSUNG, stand["n"], stand["f"])
    return stand["n"], stand["f"], kopf + "\n\n" + "\n".join(zeilen)


if __name__ == "__main__":
    n, f, text = selbsttest()
    print(text)
    raise SystemExit(1 if f else 0)
