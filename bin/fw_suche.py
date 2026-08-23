#!/usr/bin/env python3
"""Funkwacht - die Suchhilfe fuer die Oberflaeche.

Gibt als JSON aus, was auf diesem Geraet zu finden ist: USB-Geraete,
systemd-Dienste, Docker-Container. Die Oberflaeche macht daraus Tabellen zum
Anklicken.

WARUM ES DIESE DATEI GIBT
-------------------------
Bis 0.9.4 waren fuenf der sechs Pflichtfelder je Stick freie Textfelder, und
die Hilfe schickte den Bediener an die Kommandozeile ("die Kennung aus
ls /sys/bus/usb/drivers/usb/"). Das ist die fehlertraechtigste Stelle des
ganzen Plugins - und ein falscher USB-Pfad ist kein Schreibfehler, sondern
ein unbind auf dem falschen Geraet.

WAS SIE NICHT TUT
-----------------
Sie schaltet nichts und sie schreibt nichts. Alles hier ist lesend; die
Ausgabe geht auf die Standardausgabe und nirgendwo sonst.

Aufrufe:
    fw_suche.py --usb          USB-Baum, mit Hersteller, Produkt und Verteiler
    fw_suche.py --dienste      systemd-Einheiten, die nach Funk aussehen
    fw_suche.py --container    Docker-Container
    fw_suche.py --messen <n>   ein einzelnes Geraet aus der Konfiguration messen
    fw_suche.py --alles        alle drei Listen auf einmal
    fw_suche.py --selbsttest   die Rechenteile nachrechnen

Kompatibel mit Python 3.9 und 3.11.
"""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import fw_pruef  # noqa: E402
import funkwacht_dienst as fw  # noqa: E402

FASSUNG = "1.0.0"

# Woran ein Dienst oder ein Container nach Funk aussieht. Die Liste ist ein
# VORSCHLAG, kein Filter: --dienste gibt auf Wunsch alles aus.
FUNKWORTE = ("zigbee", "z2m", "zwave", "z-wave", "deconz", "phoscon", "conbee",
             "bluetooth", "bluez", "ble", "mosquitto", "matter", "thread",
             "homematic", "hm-", "enocean", "rflink", "rtl_433", "tasmota")


def sieht_nach_funk_aus(name: str) -> bool:
    """Enthaelt der Name eines der Funkworte?

    Rein rechnerisch, damit es sich pruefen laesst - und bewusst grosszuegig:
    lieber eine Zeile zu viel in der Vorschlagsliste als eine zu wenig.
    """
    n = str(name or "").lower()
    return any(w in n for w in FUNKWORTE)


def hub_und_port(kennung: str) -> tuple:
    """Aus einer sysfs-Kennung Verteiler und Anschluss machen.

    uhubctl will beides getrennt: -l <Verteiler> -p <Anschluss>.

        1-1.4    haengt am Verteiler 1-1, Anschluss 4
        1-1      haengt am Wurzelverteiler 1, Anschluss 1
        2-3.2.1  haengt am Verteiler 2-3.2, Anschluss 1

    Rein rechnerisch, damit es sich pruefen laesst - und es ist die Angabe,
    die der Bediener sonst von Hand aus zwei Befehlen zusammensuchen muss.
    """
    k = str(kennung or "").strip()
    if not re.match(r"^[0-9]+-[0-9]+(\.[0-9]+)*$", k):
        return "", 0
    if "." in k:
        hub, _, port = k.rpartition(".")
    else:
        hub, _, port = k.partition("-")
    return hub, int(fw_pruef.zahl(port, 0))


def serielle_knoten(geraetepfad: str) -> list:
    """Welche Geraetedateien haengen an diesem USB-Geraet?

    Das ist der Pfad fuer die Art "seriell" - und die haeufigste Rueckfrage
    beim Einrichten. Gesucht wird genau zwei Ebenen tief: die Schnittstellen
    des Geraets und deren tty-Eintraege. Ein os.walk ueber den ganzen Baum
    liefe in die Verweise auf den Rest des Systems.
    """
    aus = []
    try:
        for schnitt in sorted(os.listdir(geraetepfad)):
            unter = os.path.join(geraetepfad, schnitt)
            if not re.match(r"^[0-9]+-[0-9.]+:[0-9]+\.[0-9]+$", schnitt):
                continue
            if not os.path.isdir(unter):
                continue
            for e in sorted(os.listdir(unter)):
                if re.match(r"^tty(USB|ACM)[0-9]+$", e):
                    aus.append("/dev/" + e)
                elif e == "tty" and os.path.isdir(os.path.join(unter, "tty")):
                    for t in sorted(os.listdir(os.path.join(unter, "tty"))):
                        if re.match(r"^tty(USB|ACM|S)[0-9]+$", t):
                            aus.append("/dev/" + t)
    except Exception:
        return sorted(set(aus))
    return sorted(set(aus))


def usb_baum(tabu=None) -> list:
    """Alle USB-Geraete aus /sys/bus/usb/devices.

    Zurueck kommt je Geraet: die sysfs-Kennung (das ist der USB-Pfad, den das
    Plugin braucht), Hersteller- und Produktkennung, die Klartextnamen, der
    Verteiler samt Anschlussnummer - und die Angabe, ob es ein Systemgeraet
    ist. Letzteres ist der wichtigste Teil: gesperrt wird SICHTBAR, nicht
    erst spaeter abgelehnt.
    """
    tabu = tabu if tabu is not None else fw.systemgeraete()
    basis = fw.USB_BASIS
    aus = []
    if not os.path.isdir(basis):
        return aus

    def lies(p):
        try:
            with open(p, "r", errors="replace") as f:
                return f.read().strip()
        except Exception:
            return ""

    for name in sorted(os.listdir(basis)):
        # Nur echte Geraete, keine Schnittstellen (1-1.4:1.0) und keine
        # Wurzel-Verteiler (usb1).
        if not re.match(r"^[0-9]+-[0-9]+(\.[0-9]+)*$", name):
            continue
        d = os.path.join(basis, name)
        vid = lies(os.path.join(d, "idVendor"))
        pid = lies(os.path.join(d, "idProduct"))
        if not vid:
            continue
        hub, port = hub_und_port(name)
        aus.append({
            "pfad": name,
            "kennung": "%s:%s" % (vid, pid) if vid and pid else "",
            "hersteller": lies(os.path.join(d, "manufacturer")),
            "produkt": lies(os.path.join(d, "product")),
            "seriennummer": lies(os.path.join(d, "serial")),
            "hub": hub,
            "port": port,
            "system": 1 if fw_pruef.ist_systemgeraet(name, tabu) else 0,
            "knoten": serielle_knoten(d),
        })
    return aus


def dienste(nur_funk=True) -> list:
    """systemd-Einheiten. Rueckgabe je Eintrag: Name, Zustand, Neustarts."""
    rc, text = fw.befehl(["systemctl", "list-units", "--type=service",
                          "--all", "--no-legend", "--no-pager", "--plain"], 20)
    if rc != 0:
        return []
    aus = []
    for zeile in text.splitlines():
        t = zeile.split()
        if len(t) < 4 or not t[0].endswith(".service"):
            continue
        name = t[0][:-len(".service")]
        if nur_funk and not sieht_nach_funk_aus(name):
            continue
        aus.append({"name": name, "geladen": t[1], "aktiv": t[2], "zustand": t[3],
                    "funk": 1 if sieht_nach_funk_aus(name) else 0})
    return aus


def container(nur_funk=False) -> list:
    """Docker-Container samt Zustand, Healthcheck und Neustartzaehler."""
    rc, text = fw.befehl(["docker", "ps", "-a", "--format",
                          "{{.Names}}|{{.State}}|{{.Status}}|{{.Image}}"], 20)
    if rc != 0:
        return []
    aus = []
    for zeile in text.splitlines():
        t = zeile.split("|")
        if len(t) < 4:
            continue
        name = t[0].strip()
        if nur_funk and not sieht_nach_funk_aus(name + " " + t[3]):
            continue
        aus.append({"name": name, "zustand": t[1].strip(), "text": t[2].strip(),
                    "abbild": t[3].strip(),
                    "funk": 1 if sieht_nach_funk_aus(name + " " + t[3]) else 0})
    return aus


def einzeln_messen(nummer: int) -> dict:
    """Ein Geraet aus der Konfiguration messen - ohne zu heilen.

    Das ist der Knopf "Jetzt messen" neben einer Zeile: speichern, warten,
    in einem anderen Reiter nachsehen entfaellt.
    """
    cfg = fw.config()
    liste = list(enumerate(cfg["geraete"], start=1))
    treffer = [(n, g) for n, g in liste if n == int(nummer) and g["name"]]
    if not treffer:
        return {"ok": 0, "fehler": "kein Stick mit dieser Nummer"}
    n, g = treffer[0]
    hist = fw.historie_lesen()
    he = fw.historie_eintrag(hist, n)
    alter, groesse, neustarts, bemerkung, a1, a2 = fw.messen(g, he.get("letzte_groesse"))
    gesund, grund = fw_pruef.gesund(g, alter, __import__("time").time())
    return {"ok": 1, "nr": n, "name": g["name"], "art": g["art"], "art2": g["art2"],
            "alter": (-1 if alter is None else int(alter)),
            "alter1": (-1 if a1 is None else int(a1)),
            "alter2": (-1 if a2 is None else int(a2)),
            "hoechstalter": g["hoechstalter"], "gesund": gesund, "grund": grund,
            "neustarts": neustarts, "bemerkung": bemerkung}


# ======================================================================
# Selbsttest - nur die Rechenteile, denn Geraete gibt es hier keine
# ======================================================================

def selbsttest() -> tuple:
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

    pr("zigbee2mqtt sieht nach Funk aus", sieht_nach_funk_aus("zigbee2mqtt"), True)
    pr("Grossschreibung stoert nicht", sieht_nach_funk_aus("Zigbee2MQTT"), True)
    pr("zwave-js-ui ebenso", sieht_nach_funk_aus("zwave-js-ui"), True)
    pr("deconz ebenso", sieht_nach_funk_aus("deconz"), True)
    pr("bluetooth ebenso", sieht_nach_funk_aus("bluetooth"), True)
    pr("mosquitto ebenso", sieht_nach_funk_aus("mosquitto"), True)
    pr("nginx nicht", sieht_nach_funk_aus("nginx"), False)
    pr("cron nicht", sieht_nach_funk_aus("cron"), False)
    pr("leer nicht", sieht_nach_funk_aus(""), False)
    pr("None nicht", sieht_nach_funk_aus(None), False)

    pr("1-1.4 haengt am Verteiler 1-1, Anschluss 4", hub_und_port("1-1.4"), ("1-1", 4))
    pr("1-1 haengt am Wurzelverteiler 1, Anschluss 1", hub_und_port("1-1"), ("1", 1))
    pr("2-3.2.1 haengt am Verteiler 2-3.2", hub_und_port("2-3.2.1"), ("2-3.2", 1))
    pr("1-1.10 wird nicht mit 1-1.1 verwechselt", hub_und_port("1-1.10"), ("1-1", 10))
    pr("eine Schnittstelle ist kein Geraet", hub_und_port("1-1.4:1.0"), ("", 0))
    pr("ein Wurzelverteiler ist kein Geraet", hub_und_port("usb1"), ("", 0))
    pr("leer ergibt nichts", hub_und_port(""), ("", 0))

    kopf = "Funkwacht-Suchhilfe %s: %d Faelle geprueft, %d Fehlschlaege." % (
        FASSUNG, stand["n"], stand["f"])
    return stand["n"], stand["f"], kopf + "\n\n" + "\n".join(zeilen)


def main():
    argv = sys.argv[1:]
    if "--selbsttest" in argv:
        n, f, t = selbsttest()
        print(t)
        return 1 if f else 0
    alles = "--alles" in argv
    aus = {}
    if alles or "--usb" in argv:
        aus["usb"] = usb_baum()
    if alles or "--dienste" in argv:
        aus["dienste"] = dienste(nur_funk="--alle" not in argv)
    if alles or "--container" in argv:
        aus["container"] = container()
    if "--messen" in argv:
        i = argv.index("--messen")
        aus["messung"] = einzeln_messen(
            int(fw_pruef.zahl(argv[i + 1] if len(argv) > i + 1 else 0, 0)))
    if not aus:
        print(json.dumps({"fehler": "Nichts angefragt. Siehe --usb, --dienste, "
                                    "--container, --messen, --alles."},
                         ensure_ascii=False))
        return 2
    print(json.dumps(aus, ensure_ascii=False, indent=1))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
