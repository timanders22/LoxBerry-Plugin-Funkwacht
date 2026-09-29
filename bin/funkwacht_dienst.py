#!/usr/bin/env python3
"""Funkwacht - der Waechter.

Prueft in einem einstellbaren Takt, ob die eingerichteten Funksticks noch
leben, und heilt sie gestuft, wenn nicht. Der Entschluss selbst faellt in
fw_pruef.py - hier steht nur, wie gemessen und ausgefuehrt wird.

WAS HIER BEWUSST NICHT GESCHIEHT
--------------------------------
Die serielle Schnittstelle eines laufenden Zigbee2MQTT wird NICHT geoeffnet.
Ein Oeffnen von /dev/ttyACM0 nimmt dem Dienst die Schnittstelle weg - der
Waechter wuerde also genau den Ausfall erzeugen, den er verhindern soll.
Gemessen wird deshalb an dem, was der Dienst hinterlaesst.

DIE ACHT ARTEN, EIN LEBENSZEICHEN ABZULESEN
-------------------------------------------
    datei      Aenderungsdatum einer Protokolldatei (wahlweise: waechst sie?)
    mqtt       Zeitpunkt der letzten Nachricht, aus bin/fw_mqtt.py
    http       antwortet eine Adresse (ohne Weiterleitungen zu folgen)
    seriell    ist der Geraeteknoten da (NICHT geoeffnet)
    bluetooth  ist der Adapter angemeldet
    usb        haengt der Stick ueberhaupt noch am Bus - mit Gegenprobe auf
               Hersteller- und Produktkennung, falls jemand umgesteckt hat
    dienst     laeuft die systemd-Einheit, und wie oft ist sie neu gestartet
    docker     laeuft der Container, was sagt sein Healthcheck

Je Stick lassen sich ZWEI davon verknuepfen. "Geraeteknoten da UND MQTT
frisch" trennt sauber zwischen "Stick weg" und "Dienst haengt" - und erlaubt,
gleich die richtige Stufe zu ziehen.

WAS 1.0.0 GEGENUEBER 0.9.4 DAZUBEKOMMEN HAT
-------------------------------------------
* Erfolgskontrolle: nach der Erholungszeit wird nachgemessen, ob die Heilung
  gewirkt hat, und das Ergebnis je Stufe gezaehlt. Damit beantwortet das
  Plugin die Frage, die es vorher offenliess: wirkt das Heilen ueberhaupt?
* Nach Stufe 2 oder 3 kommt der Dienstneustart gleich hinterher - ein
  unbind/bind laesst ihn sonst mit einem toten Dateideskriptor zurueck.
* Anlaufschonzeit nach dem Systemstart, Nachtruhe, Wartungsschalter mit
  Ablauf und ein globaler Aus-Schalter.
* Ereignisliste und Tages-CSV - die Grundlage der Verschleisskurve.
* Meldung ins LoxBerry-Benachrichtigungszentrum und an SignalBot, bei
  WECHSEL des Befundes und mit Entwarnung.

Aufrufe:
    funkwacht_dienst.py               laeuft als Dienst
    funkwacht_dienst.py --einmal      ein Durchlauf, dann Schluss
    funkwacht_dienst.py --selbsttest  nur rechnen, nichts anfassen
    funkwacht_dienst.py --faehigkeit  was kann dieses Geraet wirklich?
    funkwacht_dienst.py --trocken     was WUERDE jetzt geschehen? (schaltet nichts)
    funkwacht_dienst.py --heile N[:S] Stick N von Hand heilen, wahlweise Stufe S
    funkwacht_dienst.py --mqtt-leeren eigene behaltene Themen am Broker loeschen
                                      (fuer uninstall/uninstall)

Kompatibel mit Python 3.9 und 3.11.
"""

from __future__ import annotations

import json
import os
import re
import signal
import socket
import subprocess
import sys
import time

# Kein Bytecode in bin/plugins/<ordner>/. Am Geraet gemessen (17.09.2026):
# der Selbsttestaufruf aus postinstall.sh legte __pycache__/fw_pruef.*.pyc im
# installierten Ordner an - ein Rest, den kein Archiv kennt und den
# geraetestand_vergleichen.py als "am Geraet, nicht im Tag" meldet.
# PYTHONDONTWRITEBYTECODE vererbt sich nur, wenn jeder Aufrufer es setzt;
# diese Zeile gilt fuer jeden Weg, auf dem die Datei startet.
sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import fw_pruef  # noqa: E402
import fw_fassung  # noqa: E402

laeuft = True

# Wo der USB-Baum liegt. Als Konstante und nicht fest im Text, damit
# sich die Auswertung gegen einen nachgebauten Baum messen laesst -
# was hier geprueft wird, ist das Lesen und Zerlegen, nicht der Kernel.
USB_BASIS = "/sys/bus/usb/devices"


# ======================================================================
# Pfade
# ======================================================================

def wurzel() -> str:
    """Der LoxBerry-Wurzelordner - ohne festen Systempfad."""
    d = os.path.dirname(os.path.abspath(__file__))
    for _ in range(8):
        # config/system/general.json ist die dritte Bedingung (Regeln/06,
        # Raumklima-Vorfall): config/plugins und webfrontend allein traegt
        # auch ein Pruefstandsrest - in einem solchen fremden Baum lief der
        # Durchgang bis 1.0.6 (Fall Y8).
        if os.path.isdir(os.path.join(d, "config", "plugins")) \
                and os.path.isdir(os.path.join(d, "data", "plugins")) \
                and os.path.isfile(os.path.join(d, "config", "system", "general.json")):
            return d
        eltern = os.path.dirname(d)
        if eltern == d:
            break
        d = eltern
    return ""


def eigener_ordner() -> str:
    """Der Ordnername, in dem dieses Skript wirklich liegt.

    Installiert steht es in bin/plugins/<ordner>/ - damit stimmt der Name auch
    dann, wenn LoxBerry wegen eines zweiten Plugins mit demselben FOLDER nach
    "funkwacht01" installiert hat. Im entpackten Archiv heisst der Ordner
    schlicht "bin"; dann gibt es KEINEN Namen ('').

    Erst LBPPLUGINDIR, dann der Ablageort. Den festen Namen "funkwacht" als
    letzte Stufe gibt es seit 1.0.6 nicht mehr: aus einem Pruefarchiv
    <Wurzel>/pruefung/x/bin lief sonst der Durchgang bzw. der Mithoerer auf
    dem Ordner der Anlage (Pruefung-Funkwacht-1.0.6, Faelle Y1, Y2).
    """
    o = (os.environ.get("LBPPLUGINDIR") or "").strip("/").split("/")[-1]
    if o not in ("", ".", ".."):
        return o
    name = os.path.basename(os.path.dirname(os.path.abspath(__file__)))
    return "" if name in ("bin", "plugins", "", ".", "/") else name


def lb_home() -> str:
    """Die Wurzel: GELESEN, nicht geraten. '' heisst: es gibt keine.

    Ein gesetztes LBHOMEDIR gilt, wenn darunter config/plugins und
    data/plugins liegen - sonst gilt GAR NICHTS; es wird nie durch eine
    gesuchte Wurzel ersetzt (Regeln/06). Bis 1.0.6 wurde es ungeprueft
    genommen und darunter angelegt (Fall Y12). Nur ohne LBHOMEDIR wird
    gesucht.
    """
    h = os.environ.get("LBHOMEDIR") or ""
    if h:
        if os.path.isdir(os.path.join(h, "config", "plugins")) \
                and os.path.isdir(os.path.join(h, "data", "plugins")):
            return h
        return ""
    return wurzel()


def pfade() -> dict:
    home = lb_home()
    ordner = eigener_ordner()
    if not home or not ordner:
        # Ohne gelesene Wurzel oder ohne Ordnernamen gibt es KEINE Pfade.
        # Bis 1.0.6 entstanden sie dann relativ zum Arbeitsverzeichnis und
        # wurden dort angelegt (Fall Y10). json_schreiben() und log()
        # scheitern an einem leeren Pfad still; main() bricht vorher ab.
        return {k: "" for k in ("home", "plugin", "config", "data", "stand",
                                "historie", "auftraege", "mqtt_stand",
                                "verlauf", "log", "general", "altpraefix")}
    data = os.path.join(home, "data", "plugins", ordner)
    return {
        "home": home,
        "plugin": ordner,
        "config": os.path.join(home, "config", "plugins", ordner, "funkwacht.json"),
        "data": data,
        "stand": os.path.join(data, "stand.json"),
        # Zaehler, Verlauf und Wartung ueberleben die Aktualisierung - aber
        # nicht von selbst: der Installer loescht data/plugins/<x>/ bei jedem
        # Update (plugininstall.pl:886 -> :1631). preupgrade.sh legt beides
        # solange NEBEN den Ordner, postinstall.sh holt es zurueck.
        "historie": os.path.join(data, "historie.json"),
        "auftraege": os.path.join(data, "auftraege.json"),
        "mqtt_stand": os.path.join(data, "mqtt_stand.json"),
        # M5: Praefixe, unter denen einmal abgeraeumt werden soll (die
        # Oberflaeche traegt ein, der Waechter streicht nach dem Nachlesen).
        "altpraefix": os.path.join(data, "mqtt_altpraefix.json"),
        "verlauf": os.path.join(data, "verlauf"),
        "log": os.path.join(home, "log", "plugins", ordner, "funkwacht.log"),
        "general": os.path.join(home, "config", "system", "general.json"),
    }


def json_lesen(pfad, vorgabe=None):
    """Eine JSON-Datei lesen. Rueckgabe: (Daten, Zustand).

    Zustand ist 'ok', 'fehlt' oder 'kaputt'. Eine abgeschnittene Datei -
    Stromausfall mitten im Schreiben - ist ein FEHLER und kein leerer Wert.
    """
    leer = vorgabe if vorgabe is not None else {}
    if not os.path.isfile(pfad):
        return leer, "fehlt"
    try:
        with open(pfad, "r", encoding="utf-8") as f:
            roh = f.read()
    except Exception:
        return leer, "kaputt"
    if roh.strip() == "":
        return leer, "fehlt"
    try:
        d = json.loads(roh)
    except Exception:
        return leer, "kaputt"
    if not isinstance(d, (dict, list)):
        return leer, "kaputt"
    return d, "ok"


def json_schreiben(pfad, daten) -> bool:
    """Erst in eine Nebendatei, dann umbenennen.

    U1 (Pruefung 29.09.2026): die Nebendatei wird zurueckgelesen und
    verglichen, bevor sie die Datei ersetzt; scheitert irgendein Schritt,
    wird sie weggeraeumt - bis 1.0.8 blieb *.tmp.<pid> nach einer Ausnahme
    liegen. Die Aufrufstellen werten den Rueckgabewert aus.
    """
    if not pfad:
        return False
    ordner = os.path.dirname(pfad)
    tmp = "%s.tmp.%d" % (pfad, os.getpid())
    try:
        if not os.path.isdir(ordner):
            os.makedirs(ordner, exist_ok=True)
        text = json.dumps(daten, ensure_ascii=False, indent=1)
        with open(tmp, "w", encoding="utf-8") as f:
            f.write(text)
        with open(tmp, "r", encoding="utf-8") as f:
            if f.read() != text:
                raise OSError("die Nebendatei weicht nach dem Schreiben ab")
        os.replace(tmp, pfad)
        return True
    except Exception:
        try:
            os.remove(tmp)
        except OSError:
            pass
        return False


_gebremst = {}


def log(text, einmalig="", sekunden=3600, kb=None):
    """Ins Protokoll schreiben, wiederkehrende Meldungen gebremst.

    log/plugins liegt auf einer Ramdisk: eine unbegrenzt wachsende Datei
    frisst Arbeitsspeicher, nicht Plattenplatz. Die Grenze steht in der
    Konfiguration und damit an EINER Stelle fuer Dienst und Oberflaeche.
    """
    if einmalig:
        letzte = _gebremst.get(einmalig, 0)
        if time.time() - letzte < sekunden:
            return
        _gebremst[einmalig] = time.time()
    p = pfade()["log"]
    grenze = int(kb if kb else 500) * 1024
    try:
        if not os.path.isdir(os.path.dirname(p)):
            os.makedirs(os.path.dirname(p), exist_ok=True)
        if os.path.isfile(p) and os.path.getsize(p) > grenze:
            with open(p, "r", encoding="utf-8", errors="replace") as f:
                rest = f.readlines()[-400:]
            with open(p, "w", encoding="utf-8") as f:
                f.writelines(rest)
        with open(p, "a", encoding="utf-8") as f:
            f.write("[%s] %s\n" % (time.strftime("%Y-%m-%d %H:%M:%S"), text))
    except Exception:
        pass


def config() -> dict:
    c, zustand = json_lesen(pfade()["config"], {})
    if zustand == "kaputt":
        log("Die Konfiguration ist unlesbar. Es wird NICHTS geprueft und "
            "nichts geheilt, bis sie in der Oberflaeche neu gespeichert wurde.",
            "config_kaputt")
        c = {}
    if not isinstance(c, dict):
        c = {}
    vorgaben = {"takt": 60, "mqtt_ein": 1, "mqtt_topic": "funkwacht",
                "aktionstoken": "", "geraete": [], "zeilen": 8,
                "anlauf_s": 300, "ruhe_von": "", "ruhe_bis": "",
                "global_aus": 0, "log_kb": 500, "verlauf_tage": 90,
                "melden_aktiv": 1, "signal_ein": 0, "signal_url": "",
                "broker_host": "", "broker_port": "", "broker_user": "",
                "broker_pass": "", "broker_id": ""}
    for k, v in vorgaben.items():
        c.setdefault(k, v)
    c["kaputt"] = 1 if zustand == "kaputt" else 0
    c["takt"] = int(max(15, min(3600, fw_pruef.zahl(c["takt"], 60))))
    c["zeilen"] = int(max(1, min(24, fw_pruef.zahl(c["zeilen"], 8))))
    c["anlauf_s"] = int(max(0, min(3600, fw_pruef.zahl(c["anlauf_s"], 300))))
    c["log_kb"] = int(max(16, min(20000, fw_pruef.zahl(c["log_kb"], 500))))
    c["verlauf_tage"] = int(max(1, min(730, fw_pruef.zahl(c["verlauf_tage"], 90))))
    c["geraete"] = [fw_pruef.geraet_geradebiegen(g) for g in (c.get("geraete") or [])]
    return c


# ======================================================================
# Was kann dieses Geraet wirklich?
# ======================================================================

def befehl(argv, zeit=15, eingabe=None):
    """Einen Befehl ausfuehren. Rueckgabe: (rc, ausgabe).

    argv ist IMMER eine Liste, niemals eine Zeichenkette, und es wird keine
    Shell dazwischengeschaltet. Damit kann kein Wert aus der Konfiguration -
    ein Geraetename, ein USB-Pfad - zu einem zweiten Befehl werden.
    """
    try:
        p = subprocess.run(argv, input=(eingabe.encode("utf-8") if eingabe is not None else None),
                           stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                           timeout=zeit, check=False)
        return p.returncode, p.stdout.decode("utf-8", "replace")
    except FileNotFoundError:
        return 127, "Befehl nicht gefunden: %s" % argv[0]
    except subprocess.TimeoutExpired:
        return 124, "Zeitueberschreitung nach %d s" % zeit
    except Exception as e:  # pragma: no cover - Notnagel
        return 1, str(e)


SUDO_RECHTE = (
    ("systemctl", r"systemctl\s+restart"),
    ("docker", r"docker\s+restart"),
    ("uhubctl", r"uhubctl"),
    ("tee", r"tee\s+/sys/bus/usb/drivers/usb/(un)?bind"),
)


def sudo_lage() -> dict:
    """Welche der vier Rechte gelten wirklich?

    Werte je Eintrag: 1 = erlaubt, 0 = nicht erlaubt, -1 = nicht feststellbar.
    Ein pauschales "sudo -n true" beantwortet eine ANDERE Frage: die
    mitgelieferte Rechtedatei erlaubt genau diese Befehle und kein "true".
    """
    lage = {name: -1 for name, _ in SUDO_RECHTE}
    lage["alle"] = -1
    rc, aus = befehl(["sudo", "-n", "-l"], 8)
    if rc == 127:
        lage.update({name: 0 for name, _ in SUDO_RECHTE})
        lage["alle"] = 0
        lage["text"] = "sudo ist auf diesem Geraet nicht installiert."
        return lage
    if rc != 0:
        lage.update({name: 0 for name, _ in SUDO_RECHTE})
        lage["alle"] = 0
        lage["text"] = "sudo weist den Benutzer ohne Kennwort ab."
        return lage
    pauschal = re.search(r"\(\s*root\s*\)\s*NOPASSWD:\s*ALL", aus) or \
        re.search(r"\(\s*ALL(\s*:\s*ALL)?\s*\)\s*NOPASSWD:\s*ALL", aus)
    for name, muster in SUDO_RECHTE:
        lage[name] = 1 if (pauschal or re.search(muster, aus)) else 0
    lage["alle"] = 1 if (lage["systemctl"] == 1 and lage["tee"] == 1) else 0
    lage["text"] = "" if lage["alle"] else \
        ("Die Rechtedatei /etc/sudoers.d/funkwacht fehlt oder greift nicht. "
         "Das Plugin meldet dann nur, geheilt wird nichts.")
    return lage


def uhubctl_lesen():
    """uhubctl nach den Verteilern fragen. Rueckgabe: (rc, Ausgabe, mit_sudo)."""
    rc, aus = befehl(["sudo", "-n", "uhubctl"], 10)
    if rc == 0:
        return rc, aus, 1
    rc2, aus2 = befehl(["uhubctl"], 10)
    if rc2 == 127 and rc == 127:
        return 127, aus2, 0
    return rc2, aus2, 0


def faehigkeiten() -> dict:
    """Was steht auf diesem Geraet zur Verfuegung?"""
    f = {"sudo": 0, "sudo_lage": {}, "systemctl": 0, "docker": 0, "uhubctl": 0,
         "uhubctl_haehne": [], "uhubctl_root": 0, "sysfs": 0, "journalctl": 0,
         "php": 0, "meldung": ""}

    lage = sudo_lage()
    f["sudo_lage"] = lage
    f["sudo"] = 1 if lage.get("alle") == 1 else 0
    f["systemctl"] = 1 if befehl(["systemctl", "--version"], 5)[0] == 0 else 0
    f["docker"] = 1 if befehl(["docker", "--version"], 5)[0] == 0 else 0
    f["journalctl"] = 1 if befehl(["journalctl", "--version"], 5)[0] == 0 else 0
    f["php"] = 1 if befehl(["php", "--version"], 5)[0] == 0 else 0
    f["sysfs"] = 1 if os.path.isdir("/sys/bus/usb/drivers/usb") else 0

    rc, aus, mit_sudo = uhubctl_lesen()
    f["uhubctl_root"] = mit_sudo
    if rc == 127:
        f["meldung"] = "uhubctl ist nicht installiert."
    else:
        f["uhubctl"] = 1
        for zeile in aus.split("\n"):
            if "ppps" in zeile:
                m = re.search(r"hub\s+([0-9\-.]+)", zeile)
                if m:
                    f["uhubctl_haehne"].append(m.group(1))
        if not f["uhubctl_haehne"]:
            if not mit_sudo:
                f["meldung"] = ("uhubctl liess sich nur OHNE Root-Rechte befragen. "
                                "Was dabei herauskommt, ist keine verlaessliche "
                                "Auskunft ueber die Verteiler.")
            else:
                f["meldung"] = ("uhubctl laeuft, aber kein Hub dieses Geraets kann den "
                                "Portstrom schalten (ppps). Beim Raspberry Pi 4 ist das "
                                "normal - Stufe 3 bleibt hier wirkungslos.")
    return f


# Wo die Einhaengetabelle und die Blockgeraete liegen - Konstanten wie
# USB_BASIS, damit sich systemgeraete() gegen einen nachgebauten Baum messen
# laesst (D6).
PROC_MOUNTS = "/proc/mounts"
SYS_BLOCK = "/sys/block"
SYS_DEV_BLOCK = "/sys/dev/block"


def geraetenummer(pfad) -> tuple:
    """(major, minor) des Datentraegers, auf dem pfad liegt."""
    st = os.stat(pfad)
    return os.major(st.st_dev), os.minor(st.st_dev)


def systemgeraete() -> list:
    """USB-Kennungen, an denen / oder /boot haengen - die sind tabu.

    Wird in JEDEM Durchlauf neu gelesen: wer nach dem Start eine USB-Platte
    einhaengt, war vorher bis zum naechsten Neustart des Waechters
    ungeschuetzt.

    D6 (Pruefung 29.09.2026): startet der Kernel die Wurzel ohne initramfs,
    nennt /proc/mounts nur /dev/root - ein /sys/block/root gibt es nicht,
    und bis 1.0.8 blieb die Liste dann leer (Boot von SD, Wurzel auf einer
    USB-Platte). Deshalb zusaetzlich ueber die Geraetenummer des
    eingehaengten Verzeichnisses: /sys/dev/block/<major>:<minor> fuehrt
    ueber seinen Verweis zum USB-Pfad, auch fuer eine Partition.
    """
    tabu = []
    try:
        with open(PROC_MOUNTS, "r", encoding="utf-8", errors="replace") as f:
            zeilen = f.readlines()
    except Exception:
        return tabu
    ziele = set()
    for z in zeilen:
        t = z.split()
        if len(t) >= 2 and t[1] in ("/", "/boot", "/boot/firmware") and t[0].startswith("/dev/"):
            ziele.add(os.path.join(SYS_BLOCK, os.path.basename(t[0]).rstrip("0123456789")))
            try:
                gross, klein = geraetenummer(t[1])
                ziele.add(os.path.join(SYS_DEV_BLOCK, "%d:%d" % (gross, klein)))
            except Exception:
                pass
    for pfad in sorted(ziele):
        try:
            ziel = os.path.realpath(pfad)
        except Exception:
            continue
        for teil in ziel.split("/"):
            if re.match(r"^\d+-[\d.]+(:[\d.]+)?$", teil):
                tabu.append(teil)
    return sorted(set(tabu))


def betriebsdauer() -> float:
    """Wie lange laeuft das System schon? -1, wenn es sich nicht sagen laesst.

    Fail safe: laesst sich die Betriebsdauer nicht lesen, wird KEINE
    Anlaufschonzeit angenommen - sonst haette ein Waechter auf einem System
    ohne /proc/uptime dauerhaft nichts getan.
    """
    try:
        with open("/proc/uptime", "r", encoding="utf-8") as f:
            return float(f.read().split()[0])
    except Exception:
        return -1.0


# ======================================================================
# Messen
# ======================================================================

def alter_datei(pfad, wachstum=0, vorher=None):
    """Alter aus dem Aenderungsdatum - wahlweise aus dem WACHSTUM.

    Manche Dienste fassen ihre Protokolldatei auch dann an, wenn sie nichts
    mehr zu melden haben (Rotation, Oeffnen beim Start). Mit gesetztem Haken
    zaehlt deshalb nur, dass die Datei GROESSER geworden ist. Rueckgabe:
    (alter, groesse).
    """
    try:
        st = os.stat(pfad)
    except Exception:
        return None, None
    if not wachstum:
        return max(0.0, time.time() - st.st_mtime), st.st_size
    if vorher is None:
        # Der erste Durchlauf hat nichts zu vergleichen. Dann gilt das
        # Aenderungsdatum - sonst waere jeder Neustart des Waechters ein
        # "noch nie gehoert", und das ist eine Falschaussage.
        return max(0.0, time.time() - st.st_mtime), st.st_size
    if st.st_size > vorher:
        return 0.0, st.st_size
    if st.st_size < vorher:
        # Rotiert oder abgeschnitten: das ist ein Lebenszeichen des Dienstes,
        # kein Stillstand.
        return 0.0, st.st_size
    return max(0.0, time.time() - st.st_mtime), st.st_size


def alter_http(adresse, zeit=5):
    """Antwortet die Adresse? Rueckgabe 0 (frisch) oder None (nichts).

    KEINEN Weiterleitungen folgen: eine Weboberflaeche, die auf eine
    Anmelde- oder Fehlerseite umleitet, antwortet sonst mit 200.
    """
    import urllib.request
    import urllib.error

    class OhneUmleitung(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            return None

    try:
        oeffner = urllib.request.build_opener(OhneUmleitung)
        req = urllib.request.Request(adresse, headers={"User-Agent": "LoxBerry-Funkwacht"})
        with oeffner.open(req, timeout=zeit) as a:
            if 200 <= a.status < 300:
                return 0.0
    except urllib.error.HTTPError as e:
        if 200 <= e.code < 300:
            return 0.0
        return None
    except Exception:
        return None
    return None


def alter_seriell(pfad):
    """NICHT oeffnen - nur nachsehen, ob der Knoten da ist."""
    return 0.0 if os.path.exists(pfad) else None


def alter_bluetooth(kennung):
    k = (kennung or "hci0").strip() or "hci0"
    return 0.0 if os.path.isdir("/sys/class/bluetooth/%s" % k) else None


def alter_usb(sysfs, kennung=""):
    """Haengt der Stick ueberhaupt noch am Bus?

    Die naheliegendste Art und die einzige, die "Stick physisch weg" von
    "Dienst haengt" unterscheidet - ohne die serielle Schnittstelle
    anzufassen. Rueckgabe: (alter, bemerkung).

    Mit einer Kennung (1a86:55d4) wird zusaetzlich nachgesehen, ob dort
    wirklich DIESES Geraet steckt. Wer umsteckt, hat sonst denselben Pfad und
    ein anderes Geraet - und ein unbind darauf traefe das falsche.
    """
    pfad = str(sysfs or "").strip()
    if not re.match(r"^[0-9]+-[0-9]+(\.[0-9]+)*(:[0-9]+\.[0-9]+)?$", pfad):
        return None, "keine gueltige sysfs-Kennung"
    basis = os.path.join(USB_BASIS, pfad)
    if not os.path.isdir(basis):
        return None, "nicht am Bus"
    k = str(kennung or "").strip().lower()
    if not k:
        return 0.0, "am Bus"
    m = re.match(r"^([0-9a-f]{4}):([0-9a-f]{4})$", k)
    if not m:
        return 0.0, "Kennung unlesbar, nur Anwesenheit geprueft"
    try:
        with open(os.path.join(basis, "idVendor"), "r") as f:
            vid = f.read().strip().lower()
        with open(os.path.join(basis, "idProduct"), "r") as f:
            pid = f.read().strip().lower()
    except Exception:
        return 0.0, "am Bus, Kennung nicht lesbar"
    if vid == m.group(1) and pid == m.group(2):
        return 0.0, "am Bus, Kennung stimmt"
    return None, "an diesem Anschluss steckt %s:%s statt %s" % (vid, pid, k)


def dienst_lage(einheit):
    """systemd: laeuft die Einheit, und wie oft ist sie neu gestartet?

    Rueckgabe: (alter, neustarts, bemerkung). Ein Dienst, den systemd alle
    drei Minuten neu startet, ist nach dem Aenderungsdatum seines Protokolls
    KERNGESUND - genau dieser Fall ist der haeufigste Vorbote eines
    sterbenden Sticks. Deshalb geht der Neustartzaehler nach Loxone.
    """
    e = str(einheit or "").strip()
    if not re.match(r"^[A-Za-z0-9@:._\-]+$", e):
        return None, -1, "kein gueltiger Einheitenname"
    rc, aus = befehl(["systemctl", "show", "-p", "ActiveState", "-p", "NRestarts", e], 10)
    if rc != 0:
        return None, -1, "systemctl antwortet nicht (%s)" % rc
    zustand = ""
    neustarts = -1
    for z in aus.splitlines():
        if z.startswith("ActiveState="):
            zustand = z.split("=", 1)[1].strip()
        elif z.startswith("NRestarts="):
            neustarts = int(fw_pruef.zahl(z.split("=", 1)[1].strip(), -1))
    if zustand == "active":
        return 0.0, neustarts, "active"
    return None, neustarts, zustand or "unbekannt"


def docker_lage(name):
    """Docker: laeuft der Container, was sagt sein Healthcheck?

    Rueckgabe: (alter, neustarts, bemerkung).
    """
    n = str(name or "").strip()
    if not re.match(r"^[A-Za-z0-9][A-Za-z0-9_.\-]*$", n):
        return None, -1, "kein gueltiger Containername"
    rc, aus = befehl(["docker", "inspect", "--format",
                      "{{.State.Running}}|{{.RestartCount}}|"
                      "{{if .State.Health}}{{.State.Health.Status}}{{else}}-{{end}}",
                      n], 15)
    if rc != 0:
        return None, -1, "docker inspect antwortet nicht (%s)" % rc
    teile = aus.strip().split("|")
    if len(teile) < 3:
        return None, -1, "unerwartete Antwort"
    laeuft_er = teile[0].strip() == "true"
    neustarts = int(fw_pruef.zahl(teile[1], -1))
    gesundheit = teile[2].strip()
    if not laeuft_er:
        return None, neustarts, "Container steht"
    if gesundheit in ("unhealthy", "starting"):
        # starting ist KEIN Lebenszeichen und kein Befund: es ist ein
        # Zwischenzustand. Unhealthy dagegen ist eine Aussage des Containers
        # ueber sich selbst und wird ernst genommen.
        if gesundheit == "unhealthy":
            return None, neustarts, "Healthcheck meldet unhealthy"
        return 0.0, neustarts, "startet gerade"
    return 0.0, neustarts, gesundheit if gesundheit != "-" else "laeuft"


def alter_mqtt(thema):
    """Zeitstempel des letzten Empfangs - aus der Datei des Mithoerers."""
    d, _ = json_lesen(pfade()["mqtt_stand"], {})
    ts = d.get(thema) if isinstance(d, dict) else None
    if not ts:
        return None
    try:
        return max(0.0, time.time() - float(ts))
    except (TypeError, ValueError):
        return None


def eines_messen(art, pfad, thema, g, vorher_groesse):
    """Ein einzelnes Kriterium messen.

    Rueckgabe: (alter, groesse, neustarts, bemerkung)
    """
    if art == "datei":
        a, gr = alter_datei(pfad, g.get("wachstum"), vorher_groesse)
        return a, gr, -1, ""
    if art == "http":
        return alter_http(pfad), None, -1, ""
    if art == "seriell":
        return alter_seriell(pfad), None, -1, ""
    if art == "bluetooth":
        return alter_bluetooth(pfad), None, -1, ""
    if art == "usb":
        a, bem = alter_usb(pfad, g.get("kennung"))
        return a, None, -1, bem
    if art == "dienst":
        a, n, bem = dienst_lage(pfad or g.get("dienst"))
        return a, None, n, bem
    if art == "docker":
        a, n, bem = docker_lage(pfad or g.get("container"))
        return a, None, n, bem
    if art == "mqtt":
        return alter_mqtt(thema), None, -1, ""
    return None, None, -1, "unbekannte Art"


def messen(g, vorher_groesse=None):
    """Beide Kriterien messen und verbinden.

    Rueckgabe: (alter, groesse, neustarts, bemerkung, alter1, alter2)
    """
    a1, gr, n1, b1 = eines_messen(g["art"], g["pfad"], g["thema"], g, vorher_groesse)
    if not g.get("art2"):
        return a1, gr, n1, b1, a1, None
    a2, _, n2, b2 = eines_messen(g["art2"], g["pfad2"], g["thema2"], g, None)
    a = fw_pruef.alter_verbinden(a1, a2, g["verkn"])
    bem = " / ".join(x for x in (b1, b2) if x)
    return a, gr, (n1 if n1 >= 0 else n2), bem, a1, a2


# ======================================================================
# Heilen
# ======================================================================

def kennung_am_anschluss(pfad) -> tuple:
    """(Kennung vid:pid, Lage) am USB-Knoten pfad; Lage 'da', 'fehlt' oder
    'unlesbar'. Eine Schnittstelle (1-1.3:1.0) wird am Geraet 1-1.3 gelesen."""
    geraet = str(pfad or "").split(":", 1)[0]
    basis = os.path.join(USB_BASIS, geraet)
    if not geraet or not os.path.isdir(basis):
        return "", "fehlt"
    try:
        with open(os.path.join(basis, "idVendor"), "r") as f:
            vid = f.read().strip().lower()
        with open(os.path.join(basis, "idProduct"), "r") as f:
            pid = f.read().strip().lower()
    except Exception:
        return "", "unlesbar"
    return "%s:%s" % (vid, pid), "da"


def kennung_abweichung(g, pfad) -> str:
    """Steckt am Zielanschluss ein ANDERES Geraet als der Stick? "" heisst nein.

    D1 (Pruefung 29.09.2026): bis 1.0.8 sah der Waechter die fremde Kennung
    (Bemerkung "an diesem Anschluss steckt 0658:0200 statt 1a86:55d4") und
    setzte den Anschluss trotzdem per unbind/bind zurueck. Geprueft wird
    unmittelbar vor Stufe 2 und Stufe 3, wenn eine Kennung eingetragen ist.
    Steckt dort gar nichts, trifft der Eingriff kein fremdes Geraet.
    """
    soll = str(g.get("kennung") or "").strip().lower()
    if not soll:
        return ""
    ist, lage = kennung_am_anschluss(pfad)
    if lage == "fehlt":
        return ""
    if lage == "unlesbar":
        return "an %s laesst sich die Kennung nicht lesen (erwartet %s)" % (pfad, soll)
    if ist != soll:
        return "an %s steckt %s statt %s" % (pfad, ist, soll)
    return ""


def heilen(g, stufe, tabu):
    """Eine Stufe ausfuehren. Rueckgabe: (ok, abgelehnt, Beschreibung)."""
    if stufe == fw_pruef.STUFE_DIENST:
        # D5 (Pruefung 29.09.2026): der Name wird UNMITTELBAR vor sudo
        # geprueft - die Rechtedatei erlaubt "systemctl restart *", also jede
        # Einheit; bis 1.0.8 ging "reboot.target" ungeprueft hinaus.
        if g["container"]:
            grund = fw_pruef.container_pruefen(g["container"])
            if grund:
                return 0, 1, "ABGELEHNT: Container '%s' - %s." % (g["container"], grund)
            rc, aus = befehl(["docker", "restart", g["container"]], 60)
            if rc != 0:
                rc, aus = befehl(["sudo", "-n", "docker", "restart", g["container"]], 60)
            return (1 if rc == 0 else 0), 0, "docker restart %s -> %s" % (g["container"], rc)
        einheit, grund = fw_pruef.einheit_pruefen(g["dienst"])
        if grund:
            return 0, 1, "ABGELEHNT: Dienst '%s' - %s." % (g["dienst"], grund)
        rc, aus = befehl(["sudo", "-n", "systemctl", "restart", einheit], 60)
        return (1 if rc == 0 else 0), 0, "systemctl restart %s -> %s" % (einheit, rc)

    if stufe == fw_pruef.STUFE_SYSFS:
        u = g["usb_pfad"]
        if not re.match(r"^[0-9]+-[0-9]+(\.[0-9]+)*(:[0-9]+\.[0-9]+)?$", u or ""):
            return 0, 1, ("ABGELEHNT: '%s' sieht nicht wie ein USB-Pfad aus "
                          "(erwartet etwa 1-1.4)." % u)
        if fw_pruef.ist_systemgeraet(u, tabu):
            return 0, 1, ("ABGELEHNT: %s gehoert zu einem Geraet, auf dem das System "
                          "liegt (%s)." % (u, ", ".join(tabu)))
        abw = kennung_abweichung(g, u)
        if abw:
            return 0, 1, "ABGELEHNT: %s." % abw
        rc1, a1 = befehl(["sudo", "-n", "tee", "/sys/bus/usb/drivers/usb/unbind"],
                         20, eingabe=u)
        time.sleep(2)
        rc2, a2 = befehl(["sudo", "-n", "tee", "/sys/bus/usb/drivers/usb/bind"],
                         20, eingabe=u)
        ok = 1 if (rc1 == 0 and rc2 == 0) else 0
        return ok, 0, "sysfs unbind/bind %s -> %s/%s" % (u, rc1, rc2)

    if stufe == fw_pruef.STUFE_UHUBCTL:
        # D2 (Pruefung 29.09.2026): Wurzelverteiler, hub, hub.port und alles
        # darunter gegen die Systemgeraete; D1: Kennung am Zielanschluss.
        grund = fw_pruef.verteiler_sperre(g["hub"], g["port"], tabu)
        if grund:
            return 0, 1, "ABGELEHNT: Stufe 3 an %s Port %s - %s." % (g["hub"], g["port"], grund)
        abw = kennung_abweichung(g, "%s.%d" % (g["hub"], g["port"]))
        if abw:
            return 0, 1, "ABGELEHNT: %s." % abw
        rc1, a1 = befehl(["sudo", "-n", "uhubctl", "-l", g["hub"],
                          "-p", str(g["port"]), "-a", "off"], 30)
        time.sleep(3)
        rc2, a2 = befehl(["sudo", "-n", "uhubctl", "-l", g["hub"],
                          "-p", str(g["port"]), "-a", "on"], 30)
        ok = 1 if (rc1 == 0 and rc2 == 0) else 0
        return ok, 0, "uhubctl %s.%d aus/ein -> %s/%s" % (g["hub"], g["port"], rc1, rc2)

    return 0, 1, "ABGELEHNT: unbekannte Stufe %s" % stufe


def wuerde_heilen(g, stufe, tabu):
    """Welcher Befehl WUERDE abgesetzt? Nur Text, es wird nichts ausgefuehrt.

    Damit laesst sich das Heilen pruefen, ohne auf einen echten Ausfall zu
    warten - und man sieht die Ablehnung eines Systemgeraets, bevor sie im
    Ernstfall kommt.
    """
    if stufe == fw_pruef.STUFE_DIENST:
        if g["container"]:
            grund = fw_pruef.container_pruefen(g["container"])
            if grund:
                return "ABGELEHNT: Container '%s' - %s" % (g["container"], grund)
            return "docker restart %s   (sonst: sudo -n docker restart %s)" % (
                g["container"], g["container"])
        if not g["dienst"]:
            return "ABGELEHNT: weder Dienst noch Container eingetragen"
        einheit, grund = fw_pruef.einheit_pruefen(g["dienst"])
        if grund:
            return "ABGELEHNT: Dienst '%s' - %s" % (g["dienst"], grund)
        return "sudo -n systemctl restart %s" % einheit
    if stufe == fw_pruef.STUFE_SYSFS:
        u = g["usb_pfad"]
        if not re.match(r"^[0-9]+-[0-9]+(\.[0-9]+)*(:[0-9]+\.[0-9]+)?$", u or ""):
            return "ABGELEHNT: '%s' sieht nicht wie ein USB-Pfad aus" % u
        if fw_pruef.ist_systemgeraet(u, tabu):
            return "ABGELEHNT: %s traegt das System (%s)" % (u, ", ".join(tabu))
        abw = kennung_abweichung(g, u)
        if abw:
            return "ABGELEHNT: %s" % abw
        return ("echo %s | sudo -n tee /sys/bus/usb/drivers/usb/unbind   "
                "[2 s warten]   echo %s | sudo -n tee /sys/bus/usb/drivers/usb/bind"
                % (u, u))
    if stufe == fw_pruef.STUFE_UHUBCTL:
        grund = fw_pruef.verteiler_sperre(g["hub"], g["port"], tabu)
        if grund:
            return "ABGELEHNT: Stufe 3 an %s Port %s - %s" % (g["hub"], g["port"], grund)
        abw = kennung_abweichung(g, "%s.%d" % (g["hub"], g["port"]))
        if abw:
            return "ABGELEHNT: %s" % abw
        return ("sudo -n uhubctl -l %s -p %d -a off   [3 s warten]   "
                "sudo -n uhubctl -l %s -p %d -a on"
                % (g["hub"], g["port"], g["hub"], g["port"]))
    return "keine Stufe"


# ======================================================================
# Melden - ins LoxBerry-Zentrum und an SignalBot
# ======================================================================

def benachrichtigen(cfg, schwere, text):
    """Meldung ablegen. Fuer Python gibt es keine LoxBerry-Schnittstelle.

    Deshalb das Zwischenstueck bin/fw_notify.php, das dieselbe Funktion
    notify_ext() aufruft wie ein PHP-Plugin. Schlaegt es fehl, ist das kein
    Grund, den Dienst anzuhalten - die Werte gehen ohnehin per MQTT hinaus.
    """
    ok = False
    if cfg.get("melden_aktiv"):
        helfer = os.path.join(os.path.dirname(os.path.abspath(__file__)), "fw_notify.php")
        if os.path.isfile(helfer):
            rc, _ = befehl(["php", helfer, str(int(schwere)), text,
                            pfade()["plugin"]], 20)
            ok = (rc == 0)
    if cfg.get("signal_ein") and str(cfg.get("signal_url") or "").strip():
        signal_senden(str(cfg["signal_url"]).strip(), text)
    return ok


def signal_senden(url, text):
    """Einen Satz an den SignalBot schicken.

    Die Adresse steht vollstaendig in der Konfiguration, samt Wortzeichen des
    SignalBot - sie wird NICHT zusammengebaut. Ein zusammengebauter Endpunkt
    waere eine Vermutung ueber ein fremdes Plugin; eine Adresse zum
    Abschreiben ist eine Angabe des Anwenders.
    """
    import urllib.parse
    import urllib.request
    try:
        trenner = "&" if "?" in url else "?"
        voll = url + trenner + urllib.parse.urlencode({"text": text})
        req = urllib.request.Request(voll, headers={"User-Agent": "LoxBerry-Funkwacht"})
        with urllib.request.urlopen(req, timeout=10) as a:
            return 200 <= a.status < 300
    except Exception as e:
        log("SignalBot: %s" % e, "signal_fehler")
        return False


# ======================================================================
# Die Werteliste - EINE Quelle fuer MQTT und HTTP
# ======================================================================

def felder(stand: dict) -> list:
    """Jeder Eintrag ist (MQTT-Thema, HTTP-Feld, Wert).

    Ein HTTP-Feld None heisst: der Wert ist ein Text und gehoert nicht in eine
    Antwortzeile, die Loxone als Zahlen liest - er steht dann ueber MQTT und
    unter ?aktion=json bereit.
    """
    aus = []
    ger = stand.get("geraete") or {}
    aus.append(("ok", "OK", int(stand.get("ok", 0))))
    aus.append(("krank", "KRANK", int(stand.get("krank", 0))))
    aus.append(("geraete", "GERAETE", len(ger)))
    aus.append(("geheilt_gesamt", "GEHEILT", int(stand.get("geheilt_gesamt", 0))))
    aus.append(("versuche_gesamt", "VERSUCHE", int(stand.get("versuche_gesamt", 0))))
    aus.append(("alarm", "ALARM", int(stand.get("alarm", 0))))
    aus.append(("gesperrt", "GESPERRT", int(stand.get("gesperrt", 0))))
    aus.append(("wartung", "WARTUNG", int(stand.get("wartung", 0))))
    aus.append(("ts", "TS", int(stand.get("zeit", 0))))
    for nr in sorted(ger, key=lambda x: int(x)):
        e = ger[nr]
        v = "geraet%s/" % nr
        h = "G%s" % nr
        aus.append((v + "ok", h + "OK", int(e.get("ok", 0))))
        aus.append((v + "stufe", h + "STUFE", int(e.get("stufe", 0))))
        aus.append((v + "alter", h + "ALTER", int(e.get("alter", -1))))
        aus.append((v + "heilungen", h + "HEILUNGEN", int(e.get("heilungen", 0))))
        aus.append((v + "versuche", h + "VERSUCHE", int(e.get("versuche", 0))))
        aus.append((v + "abgelehnt", h + "ABGELEHNT", int(e.get("abgelehnt", 0))))
        aus.append((v + "heil24", h + "HEIL24", int(e.get("heil24", 0))))
        aus.append((v + "heil7t", h + "HEIL7T", int(e.get("heil7t", 0))))
        aus.append((v + "seit", h + "SEIT", int(e.get("seit", 0))))
        aus.append((v + "letzte", h + "LETZTE", int(e.get("letzte", 0))))
        aus.append((v + "neustarts", h + "NEUSTARTS", int(e.get("neustarts", -1))))
        aus.append((v + "grundnr", h + "GRUNDNR",
                    fw_pruef.GRUND_NR.get(e.get("grund", ""), 9)))
        aus.append((v + "warumnr", h + "WARUMNR",
                    fw_pruef.WARUM_NR.get(e.get("warum", ""), 9)))
        aus.append((v + "name", None, e.get("name", "")))
        aus.append((v + "grund", None, e.get("grund", "")))
        # M7 (Pruefung 29.09.2026): warum und bemerkung gehen bei der
        # Erholung als "-" hinaus. Ein leerer Wert geht gar nicht hinaus
        # (mqtt_senden), und ein virtueller Texteingang in Loxone behielt
        # sonst "abstand" oder "Container steht" fuer immer.
        aus.append((v + "warum", None, e.get("warum", "") or "-"))
        aus.append((v + "letzte_tat", None, e.get("letzte_tat", "")))
        aus.append((v + "bemerkung", None, e.get("bemerkung", "") or "-"))
    return aus


# Retain je THEMENSTAMM - Hausstandard seit 03.09.2026 (Regeln/07): Zustaende
# retained, Messwerte mit Zeitbezug nicht, das Lebenszeichen nie.
#
# Bis 1.0.2 ging alles als "publish" hinaus. Am Geraet gemessen (17.09.2026):
# `mosquitto_sub -t 'funkwacht/#' --retained-only` lieferte nichts. Nach einem
# Neustart des Miniservers oder des Gateways standen die Eingaenge damit leer,
# bis der naechste Durchlauf sie fuellte.
#
# Die drei Fragen, die der Hausstandard offenlaesst, hier entschieden:
# * Eine DAUER altert von selbst und ist nie retained (alter, seit, wartung -
#   "Sekunden, die die Wartung noch laeuft"): zurueckbehalten stuende nach
#   einer Woche noch "vor 3 s gehoert" da.
# * Ein ABSOLUTER ZEITSTEMPEL ist retained (letzte): er sagt genau, wann.
# * Ein ZAEHLFENSTER veraltet wie eine Dauer (heil24, heil7t): eine Heilung
#   faellt nach 24 h von selbst heraus, ohne dass jemand sendet.
# * Ein Text, der REGELMAESSIG LEER ist (warum, bemerkung), ist nie retained:
#   leere Werte gehen gar nicht hinaus (mqtt_senden), ein zurueckbehaltener
#   Text bliebe dann fuer immer stehen. Seit der Pruefung vom 29.09.2026
#   (M7) geht "leer" bei diesen beiden als "-" hinaus.
# * ts ist das Lebenszeichen und deshalb nie retained - retained zeigte es
#   immer "lebt".
# Unbekannter Stamm: publish. Die Oberflaeche fuehrt dieselbe Tabelle
# (fw_mqtt_retain() in fw_lib.php); der Reiter Test haelt beide gegeneinander.
#
# BERICHTIGT in 1.0.6 (Regeln/07 Abschnitt 3, Entscheidungen des Hausherrn
# vom 18./19.09.2026): eine Aussage des DIENSTES ist nie retained, und ok
# schon gar nicht. Prueffrage je Thema: wer stellt es fest - das Geraet oder
# der Dienst? Ein Funkstick meldet nie "ich bin gesund". ok, krank, alarm
# und je Stick ok, grund und grundnr sind das URTEIL des Waechters aus dem
# Alter seiner eigenen Messung; stufe und warumnr sind sein eigener Stand,
# gesperrt seine eigene Sperre (sie kippt mit der Uhr, ohne dass jemand
# sendet). Stirbt der Waechter, stuenden ok=1 und krank=0 zurueckbehalten im
# Broker, und Loxone laese nach einem Neustart "alle Sticks gesund" von einem
# toten Waechter. Bis 1.0.5 gingen diese neun retained hinaus (in WSL am
# empfangenen Datagramm gemessen, Pruefung-Funkwacht-1.0.6/messe_retain.sh,
# R1-R9); die Altwerte raeumt mqtt_altlast_abraeumen() einmal ab.
# Retained bleiben Konfiguration (geraete, name) und Verlauf (die Summen,
# letzte, letzte_tat): sie bleiben wahr, wenn der Waechter stirbt. neustarts
# zaehlt systemd fuer den fremden Dienst, nicht der Waechter. Dass
# Verlaufszaehler (versuche, abgelehnt, die Summen) retained bleiben duerfen,
# hat der Hausherr am 24.09.2026 entschieden (Regeln/07 Abschnitt 3, Nachlese).
RETAIN = {
    "ok": 0, "krank": 0, "geraete": 1, "geheilt_gesamt": 1,
    "versuche_gesamt": 1, "alarm": 0, "gesperrt": 0,
    "wartung": 0, "ts": 0,
    "geraetN/ok": 0, "geraetN/stufe": 0, "geraetN/alter": 0,
    "geraetN/heilungen": 1, "geraetN/versuche": 1, "geraetN/abgelehnt": 1,
    "geraetN/heil24": 0, "geraetN/heil7t": 0, "geraetN/seit": 0,
    "geraetN/letzte": 1, "geraetN/neustarts": 1, "geraetN/grundnr": 0,
    "geraetN/warumnr": 0, "geraetN/name": 1, "geraetN/grund": 0,
    "geraetN/warum": 0, "geraetN/letzte_tat": 1, "geraetN/bemerkung": 0,
}


def thema_stamm(thema: str) -> str:
    """'geraet12/ok' -> 'geraetN/ok'; Summenthemen bleiben, wie sie sind."""
    return re.sub(r"^geraet[0-9]+/", "geraetN/", str(thema))


def retain_fuer(thema: str) -> bool:
    return bool(RETAIN.get(thema_stamm(thema), 0))


def themen_tabelle() -> dict:
    """Jeder Stamm, den felder() erzeugen kann, mit seinem Retain-Wert.

    Gebaut aus einem ausgedachten Stand mit einem Geraet - so kommt jeder
    Stamm genau einmal vor, auch der eines Geraets. Ein Stamm ohne Eintrag in
    RETAIN erscheint mit None: das ist der Befund, den der Reiter Test meldet.
    """
    probe = {"ok": 1, "geraete": {"1": {"name": "Probe"}}}
    aus = {}
    for thema, _feld, _wert in felder(probe):
        stamm = thema_stamm(thema)
        aus[stamm] = RETAIN.get(stamm)
    return aus


# ======================================================================
# MQTT ueber den UDP-Eingang des Gateways
# ======================================================================

def mqtt_wert_saeubern(wert):
    """Das Gateway liest zeilenweise. Ein Zeilenumbruch im Wert zerlegt die
    Uebertragung, und aus den Bruchstuecken bildet es erfundene Themen."""
    text = str(wert)
    for z in ("\r\n", "\r", "\n", "\t"):
        text = text.replace(z, " ")
    while "  " in text:
        text = text.replace("  ", " ")
    return text.strip()


# M8 (Pruefung 29.09.2026): gesendet wird nur, was sich geaendert hat; den
# vollen Satz nach dem Start, nach einem Praefixwechsel und alle 30 Minuten.
# Bis 1.0.8 gingen in JEDEM Takt alle Themen hinaus (24 Sticks: 221
# Datagramme je Durchgang) - der UDP-Eingang des Gateways verwirft unter Last
# 17 bis 70 % (Regeln/07). Dazu 1 ms Pause je 20 Datagramme.
VOLL_ALLE_S = 1800
_gesendet = {}
_voll_naechster = 0.0


def mqtt_senden(paare, praefix):
    global _voll_naechster
    g, _ = json_lesen(pfade()["general"], {})
    m = g.get("Mqtt") or g.get("mqtt") or {}
    port = int(fw_pruef.zahl(m.get("Udpinport") or m.get("udpinport"), 0))
    if not port:
        log("MQTT: kein UDP-Eingangsport in der general.json - nichts gesendet.",
            "mqtt_kein_port")
        return False
    autostart = str(m.get("Gatewayautostart") or m.get("Autostart") or "") in ("1", "true", "True")
    if not autostart:
        log("MQTT: das Gateway steht nicht auf Autostart (System, MQTT Gateway). "
            "Es wird gesendet, aber vermutlich hoert niemand zu.", "mqtt_aus")
    jetzt = time.time()
    voll = jetzt >= _voll_naechster or _gesendet.get("#praefix") != praefix
    if voll:
        _gesendet.clear()
        _gesendet["#praefix"] = praefix
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        n = 0
        for k, v in paare:
            if v is None or v == "":
                continue
            wert = mqtt_wert_saeubern(v)
            if not voll and _gesendet.get(k) == wert:
                continue
            # "retain" ist ein eigener Befehl des UDP-Eingangs, gleichrangig
            # mit "publish" (mqttgateway.pl, gemessen 06.09.2026, Regeln/07).
            befehl_wort = "retain" if retain_fuer(k) else "publish"
            zeile = "%s %s/%s %s" % (befehl_wort, praefix, k, wert)
            s.sendto(zeile.encode("utf-8"), ("127.0.0.1", port))
            _gesendet[k] = wert
            n += 1
            if n % 20 == 0:
                time.sleep(0.001)
        s.close()
        if voll:
            _voll_naechster = jetzt + VOLL_ALLE_S
        return True
    except Exception as e:
        log("MQTT: %s" % e, "mqtt_fehler")
        return False


# ======================================================================
# Zurueckbehaltene Altwerte einmal abraeumen (1.0.6)
# ======================================================================
#
# Themen, die bis 1.0.5 retained hinausgingen und seit 1.0.6 fluechtig sind
# (siehe RETAIN). Ein fluechtiges publish ersetzt einen zurueckbehaltenen
# Wert im Broker NICHT; fort ist er erst, wenn eine LEERE Nutzlast mit retain
# auf dasselbe Thema faellt.
#
# Das geschieht direkt am Broker (paho, python3-paho-mqtt aus dpkg/apt), nicht
# ueber den UDP-Eingang des Gateways: der verwirft Datagramme in Stoessen, und
# sendto() meldet auch fuer ein verworfenes Erfolg (Regeln/07, am Geraet
# belegt am Beschattungswaechter 0.9.19 und KODI-NG 1.2.7: Merker gesetzt,
# Altwert stand weiter im Broker). Deshalb: loeschen mit QoS 1, dann
# NACHLESEN - ein neues Abonnement bekommt alles, was noch behalten ist - und
# den Merker erst schreiben, wenn nichts mehr kommt.
#
# Der Merker liegt im Datenordner. purge_installation raeumt ihn bei jedem
# Upgrade ab; dann wird einmal erneut nachgesehen - das kostet eine leere
# Nutzlast je noch liegendem Thema und nichts, wenn keines mehr liegt. Seine
# Kennung traegt Fassung, Praefix und Stammliste: kein Vorlaeufer schrieb
# diese Datei, ein anderes Praefix oder eine andere Liste gilt nicht als
# erledigt (Faelle R22, R25).
RETAIN_ALTLAST = ("ok", "krank", "alarm", "gesperrt", "geraetN/ok",
                  "geraetN/stufe", "geraetN/grundnr", "geraetN/warumnr",
                  "geraetN/grund")
RETAIN_ALTLAST_KENNUNG = "funkwacht-1.0.6-dienstaussagen"
_altlast_naechster = 0.0


def retain_merker_datei() -> str:
    d = pfade()["data"]
    return os.path.join(d, "retain_dienstaussagen_geraeumt") if d else ""


def mqtt_altlast_abraeumen(praefix, warten=3.0) -> int:
    """Rueckgabe 0 erledigt (auch: war schon erledigt), 1 Altwerte stehen
    noch (naechster Versuch spaeter), 2 nicht moeglich (keine Wurzel, kein
    paho, kein Broker, Anmeldung abgewiesen)."""
    praefix = str(praefix or "").strip("/")
    if not praefix or "#" in praefix or "+" in praefix:
        return 2
    merker = retain_merker_datei()
    if not merker:
        return 2
    soll = "%s|%s|%s" % (RETAIN_ALTLAST_KENNUNG, praefix, ",".join(RETAIN_ALTLAST))
    try:
        with open(merker, "r", encoding="utf-8") as f:
            if f.read().strip() == soll:
                return 0
    except OSError:
        pass
    def gehoert_dazu(thema):
        return thema_stamm(thema[len(praefix) + 1:]) in RETAIN_ALTLAST

    # Der Weg zum Broker steht seit 1.0.7 in _broker_leeren(): dieselbe
    # Bauart nehmen die Deinstallation (mqtt_leeren) und das Abraeumen
    # geloeschter Sticks (stick_themen_abraeumen).
    erg = _broker_leeren(praefix, gehoert_dazu, warten)
    if erg["rc"] == 2:
        log("MQTT: die zurueckbehaltenen Altwerte frueherer Fassungen lassen "
            "sich nicht abraeumen - %s." % erg["grund"], "retain_altlast")
        return 2
    rest, zu_leeren = erg["rest"], erg["geleert"]
    if rest:
        log("MQTT: %d von %d zurueckbehaltenen Altwerten stehen noch im Broker "
            "(zum Beispiel %s) - neuer Versuch spaeter."
            % (len(rest), len(zu_leeren), rest[0]), "retain_altlast")
        return 1
    # ERST JETZT der Merker - nach dem Nachlesen, nicht nach dem Senden.
    try:
        os.makedirs(os.path.dirname(merker), exist_ok=True)
        with open(merker, "w", encoding="utf-8") as f:
            f.write(soll + "\n")
    except OSError as e:
        log("MQTT: der Merker %s liess sich nicht schreiben (%s) - beim naechsten "
            "Durchgang wird erneut nachgesehen." % (merker, e), "retain_merker")
    if zu_leeren:
        log("MQTT: %d zurueckbehaltene Altwerte frueherer Fassungen geloescht und "
            "nachgelesen (%s)." % (len(zu_leeren), ", ".join(zu_leeren)))
    return 0


# ======================================================================
# Eigene Themen am Broker loeschen und nachlesen (1.0.7)
# ======================================================================
#
# Bis 1.0.6 raeumte die Funkwacht nur die neun Dienstaussagen einmal ab
# (oben). Zwei Luecken blieben (Pruefung-Funkwacht-1.0.7, messe_nachlese.sh):
# * uninstall/uninstall liess jedes retained Thema im Broker stehen -
#   Einrichtung und Verlauf retained seit 1.0.3 (Faelle U1-U4);
# * leerte man eine Stick-Zeile, blieben deren retained Themen stehen, und
#   Loxone las Name und Verlauf eines Sticks, den es nicht mehr gibt
#   (Faelle S1, S7).
# Beides geht jetzt direkt an den Broker, loescht nur, was WIRKLICH behalten
# liegt und der Funkwacht gehoert, und liest nach (Regeln/07 Abschnitt 3).

# geraet<N>/<feld>: N wie die Zeilennummer, ohne fuehrende Null, ein- oder
# zweistellig (die Oberflaeche fuehrt hoechstens 24 Zeilen, FW_GERAETE_MAX).
STICK_THEMA = re.compile(r"^geraet([1-9][0-9]?)/([a-z0-9_]+)$")
RETAIN_STICKS_KENNUNG = "funkwacht-1.0.7-sticks"
_sticks_naechster = 0.0


def eigenes_thema(praefix, thema) -> str:
    """Der Teil hinter dem Praefix, wenn DIESER Waechter das Thema sendet.

    Eigen ist ein Stamm aus RETAIN (dieselben Staemme seit 1.0.0) - ohne
    Nummer (ok, geraete, ...) oder als geraet<N>/<feld>. Sonst '': ein
    fremdes Thema unter demselben Praefix bleibt unberuehrt (Fall U2).
    """
    if not praefix or not str(thema).startswith(praefix + "/"):
        return ""
    rest = str(thema)[len(praefix) + 1:]
    if "/" not in rest:
        return rest if rest in RETAIN else ""
    m = STICK_THEMA.match(rest)
    if m and ("geraetN/" + m.group(2)) in RETAIN:
        return rest
    return ""


def je_retained() -> list:
    """Die Staemme, die je retained hinausgingen: RETAIN seit 1.0.6 und die
    neun, die 1.0.3 bis 1.0.5 retained sandten (RETAIN_ALTLAST)."""
    return sorted({s for s, v in RETAIN.items() if v} | set(RETAIN_ALTLAST))


def _broker_leeren(praefix, auswahl, warten=3.0) -> dict:
    """Behaltene Themen unter <praefix>/ am Broker loeschen und NACHLESEN.

    Geloescht wird nur, was WIRKLICH behalten im Broker liegt - gefunden ueber
    ein Abonnement - und was auswahl(thema) freigibt; danach ein zweites
    Abonnement: was dann noch behalten ankommt, ist stehengeblieben. Bis 1.0.6
    stand dieser Weg nur in mqtt_altlast_abraeumen(); Bauart wie
    VolkswagenID 0.9.24 und Skoda-Connect-NG 0.9.25.

    Rueckgabe: {"rc": 0 erledigt | 1 es blieb etwas stehen | 2 nicht
    moeglich, "geleert": [...], "rest": [...], "grund": Text}.
    """
    erg = {"rc": 2, "geleert": [], "rest": [], "grund": ""}
    try:
        import paho.mqtt.client as mq
    except ImportError:
        erg["grund"] = "paho fehlt (python3-paho-mqtt)"
        return erg
    import threading
    import fw_mqtt
    # M4 (Pruefung 29.09.2026): abgeraeumt wird am Broker des GATEWAYS
    # (general.json) - dorthin sendet der Waechter ueber den UDP-Eingang,
    # dort liegen die eigenen Themen. Bis 1.0.8 galt der Zugang des
    # Mithoerers; mit eigenem Broker fuer ihn raeumte die Funkwacht nie ab.
    zug = fw_mqtt.zugang_gateway()
    gesehen = set()
    angemeldet = threading.Event()
    code = {"wert": None}

    def bei_verbindung(_k, _d, _f, rc, *_r):
        try:
            code["wert"] = int(getattr(rc, "value", rc) or 0)
        except (TypeError, ValueError):
            code["wert"] = 0
        angemeldet.set()

    def bei_nachricht(_k, _d, n):
        if n.retain and n.payload and n.topic.startswith(praefix + "/") \
                and auswahl(n.topic):
            gesehen.add(n.topic)

    # Eine EIGENE Kennung: mit derselben wie der Mithoerer wuerfen sich beide
    # Verbindungen gegenseitig hinaus (MQTT-Regel; Hilfetext H_ID).
    kennung = "funkwacht-abraeumen-%d" % os.getpid()
    try:
        k = mq.Client(mq.CallbackAPIVersion.VERSION2, client_id=kennung)
    except (AttributeError, TypeError):
        try:
            k = mq.Client(mq.CallbackAPIVersion.VERSION1, client_id=kennung)
        except (AttributeError, TypeError):
            k = mq.Client(client_id=kennung)
    k.on_connect = bei_verbindung
    k.on_message = bei_nachricht
    if zug.get("user"):
        k.username_pw_set(zug["user"], zug.get("pass") or "")
    try:
        k.connect(zug["host"], zug["port"], 30)
    except Exception as e:
        erg["grund"] = "Broker %s:%s nicht erreichbar (%s)" % (zug["host"], zug["port"], e)
        return erg
    k.loop_start()
    try:
        # M6: Klartext wie im Mithoerer; ohne CONNACK ein eigener Satz.
        if not angemeldet.wait(10):
            erg["grund"] = ("der Broker %s:%s hat binnen 10 s nicht geantwortet (kein CONNACK)"
                            % (zug["host"], zug["port"]))
            return erg
        if code["wert"]:
            erg["grund"] = ("der Broker hat die Anmeldung abgewiesen: %s (Code %s)"
                            % (fw_mqtt.CONNACK_TEXT.get(code["wert"], "unbekannt"),
                               code["wert"]))
            return erg
        k.subscribe(praefix + "/#")
        time.sleep(warten)
        k.unsubscribe(praefix + "/#")
        zu_leeren = sorted(gesehen)
        for thema in zu_leeren:
            info = k.publish(thema, b"", qos=1, retain=True)
            try:
                info.wait_for_publish(5)
            except TypeError:           # paho 1.x vor 1.6 kennt kein timeout
                info.wait_for_publish()
        # NACHLESEN: ein neues Abonnement bekommt alles, was noch behalten ist.
        gesehen.clear()
        k.subscribe(praefix + "/#")
        time.sleep(warten)
        rest = sorted(gesehen)
    except Exception as e:
        erg["grund"] = "das Loeschen scheiterte (%s)" % e
        return erg
    finally:
        k.loop_stop()
        try:
            k.disconnect()
        except Exception:
            pass
    erg.update(rc=1 if rest else 0, geleert=zu_leeren, rest=rest)
    return erg


def sticks_merker_datei() -> str:
    d = pfade()["data"]
    return os.path.join(d, "retain_sticks_geraeumt") if d else ""


def stick_themen_abraeumen(praefix, aktiv, warten=3.0) -> int:
    """Die retained Themen von Sticks, die nicht (mehr) eingerichtet sind,
    am Broker loeschen. aktiv: die Nummern der Zeilen mit Namen.

    Nachgesehen wird nur, wenn sich Praefix oder Stickmenge seit dem letzten
    erledigten Mal geaendert haben (Merker mit Kennung, Praefix und Liste);
    der Merker liegt im Datenordner und faellt mit jedem Upgrade - danach
    wird einmal nachgesehen. Er entsteht ERST nach dem Nachlesen (Fall S6).
    Rueckgabe wie mqtt_altlast_abraeumen().
    """
    praefix = str(praefix or "").strip("/")
    if not praefix or "#" in praefix or "+" in praefix:
        return 2
    merker = sticks_merker_datei()
    if not merker:
        return 2
    nummern = set()
    for n in aktiv:
        try:
            nummern.add(str(int(n)))
        except (TypeError, ValueError):
            continue
    soll = "%s|%s|%s" % (RETAIN_STICKS_KENNUNG, praefix,
                         ",".join(sorted(nummern, key=int)))
    try:
        with open(merker, "r", encoding="utf-8") as f:
            if f.read().strip() == soll:
                return 0
    except OSError:
        pass

    def verwaist(thema):
        m = STICK_THEMA.match(eigenes_thema(praefix, thema))
        return bool(m) and m.group(1) not in nummern

    erg = _broker_leeren(praefix, verwaist, warten)
    if erg["rc"] == 2:
        log("MQTT: die Themen entfernter Sticks lassen sich nicht abraeumen - %s."
            % erg["grund"], "retain_sticks")
        return 2
    if erg["rc"] == 1:
        log("MQTT: %d von %d Themen entfernter Sticks stehen noch im Broker "
            "(zum Beispiel %s) - neuer Versuch spaeter."
            % (len(erg["rest"]), len(erg["geleert"]), erg["rest"][0]), "retain_sticks")
        return 1
    try:
        os.makedirs(os.path.dirname(merker), exist_ok=True)
        with open(merker, "w", encoding="utf-8") as f:
            f.write(soll + "\n")
    except OSError as e:
        log("MQTT: der Merker %s liess sich nicht schreiben (%s) - beim naechsten "
            "Durchgang wird erneut nachgesehen." % (merker, e), "retain_merker")
    if erg["geleert"]:
        log("MQTT: %d zurueckbehaltene Themen entfernter Sticks geloescht und "
            "nachgelesen (%s)." % (len(erg["geleert"]), ", ".join(erg["geleert"])))
    return 0


_altpraefix_naechster = 0.0


def altpraefixe_abraeumen(cfg, warten=3.0) -> None:
    """M5 (Pruefung 29.09.2026): unter einem bisherigen Praefix abraeumen.

    Die Oberflaeche traegt beim Speichern eines neuen Praefix (und beim
    Ausschalten von MQTT) das bisherige in mqtt_altpraefix.json ein. Hier wird
    darunter einmal abgeraeumt - nur Themen der Funkwacht, am Broker des
    Gateways, mit Nachlesen - und das Praefix erst DANACH aus der Liste
    gestrichen. Ist es wieder das laufende Praefix bei eingeschaltetem MQTT,
    faellt es ohne Abraeumen heraus. Gescheitert: in zehn Minuten wieder.
    """
    global _altpraefix_naechster
    datei = pfade()["altpraefix"]
    if not datei or time.time() < _altpraefix_naechster:
        return
    d, _ = json_lesen(datei, {})
    liste = d.get("praefixe") if isinstance(d, dict) else None
    if not isinstance(liste, list) or not liste:
        return
    jetzt_praefix = str(cfg.get("mqtt_topic") or "").strip("/")
    offen = []
    for eintrag in liste:
        p = str(eintrag or "").strip("/")
        if not p or "#" in p or "+" in p:
            continue
        if p == jetzt_praefix and cfg.get("mqtt_ein"):
            continue
        erg = _broker_leeren(p, lambda t, p=p: bool(eigenes_thema(p, t)), warten)
        if erg["rc"] == 0:
            log("MQTT: unter dem bisherigen Praefix '%s/' %d behaltene Themen geloescht und "
                "nachgelesen." % (p, len(erg["geleert"])))
        else:
            offen.append(p)
            log("MQTT: unter dem bisherigen Praefix '%s/' liess sich nicht abraeumen - %s - "
                "neuer Versuch spaeter." % (p, erg["grund"] or "es blieb etwas stehen"),
                "altpraefix")
    # Unmittelbar vor dem Schreiben neu lesen: die Oberflaeche kann in der
    # Zwischenzeit ein weiteres Praefix eingetragen haben.
    d2, _ = json_lesen(datei, {})
    neu = d2.get("praefixe") if isinstance(d2, dict) else None
    rest = [x for x in (neu if isinstance(neu, list) else [])
            if str(x or "").strip("/") in offen or x not in liste]
    if rest:
        if not json_schreiben(datei, {"praefixe": rest}):
            log("MQTT: %s liess sich nicht schreiben." % datei, "altpraefix_schreiben")
    else:
        try:
            os.remove(datei)
        except OSError:
            pass
    if offen:
        _altpraefix_naechster = time.time() + 600


def _stick_nummern() -> list:
    """Jede Stick-Nummer, die sich aus Konfiguration und Zustand ermitteln
    laesst: jede Zeile der Konfiguration (auch leere - sie kann frueher einen
    Namen getragen haben), dazu die Nummern in historie.json und stand.json."""
    n = set(range(1, len(config().get("geraete") or []) + 1))
    for datei in (pfade()["historie"], pfade()["stand"]):
        d, _ = json_lesen(datei, {})
        g = d.get("geraete") if isinstance(d, dict) else None
        if isinstance(g, dict):
            n |= {int(k) for k in g if re.match(r"^[1-9][0-9]?$", str(k))}
    return sorted(x for x in n if 1 <= x <= 99)


def _udp_leeren(praefix) -> int:
    """Rueckfall der Deinstallation ohne Broker oder ohne paho: je Thema, das
    je retained hinausging, ein leeres 'retain' an den UDP-Eingang. UDP
    bestaetigt nichts, und die Ausgabe sagt es (Regeln/07, "Ein Absender
    merkt nichts davon")."""
    g, _ = json_lesen(pfade()["general"], {})
    m = g.get("Mqtt") or g.get("mqtt") or {}
    port = int(fw_pruef.zahl(m.get("Udpinport") or m.get("udpinport"), 0))
    if not port:
        print("<INFO> MQTT: auch kein UDP-Eingang des Gateways in general.json - die "
              "behaltenen Themen bleiben stehen und sind im Broker von Hand zu loeschen.")
        return 2
    staemme = je_retained()
    themen = [s for s in staemme if not s.startswith("geraetN/")]
    for n in _stick_nummern():
        themen += ["geraet%d/%s" % (n, s[len("geraetN/"):])
                   for s in staemme if s.startswith("geraetN/")]
    geschickt = 0
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    except OSError as e:
        print("<INFO> MQTT: kein Socket (%s) - die behaltenen Themen bleiben stehen." % e)
        return 2
    try:
        for t in themen:
            try:
                s.sendto(("retain %s/%s " % (praefix, t)).encode("utf-8"),
                         ("127.0.0.1", port))
                geschickt += 1
            except OSError:
                pass
            # Der Eingang verwirft in Stoessen (Regeln/07, "Der UDP-Eingang
            # verliert unter Last Pakete"); ein kurzer Abstand schont ihn.
            time.sleep(0.01)
    finally:
        s.close()
    print("<INFO> MQTT: Rueckfall ueber den UDP-Eingang %d des Gateways: %d Loeschbefehle "
          "unter '%s/' geschickt (leere Nutzlast, 'retain')." % (port, geschickt, praefix))
    print("<INFO> MQTT: UDP bestaetigt nichts. Stehen die Themen danach noch, sind sie im "
          "Broker von Hand zu loeschen.")
    return 2


def mqtt_leeren(warten=3.0) -> int:
    """Fuer uninstall/uninstall: alle behaltenen EIGENEN Themen am Broker
    loeschen und nachlesen - auch die geloeschter Sticks, jede Nummer.

    Unabhaengig von "MQTT ein": die Altwerte koennen aus der Zeit stammen, als
    es an war (Fall U9). Ausgabe im Format des Installers. Rueckgabe 0
    geleert oder nichts zu leeren, 1 es blieb etwas stehen, 2 nicht am
    Broker moeglich (dann der UDP-Rueckfall).
    """
    if not pfade()["home"]:
        print("<INFO> MQTT: keine LoxBerry-Wurzel gelesen - am Broker wurde nichts geloescht.")
        return 2
    praefix = str(config().get("mqtt_topic") or "").strip("/")
    if not praefix or "#" in praefix or "+" in praefix:
        print("<INFO> MQTT: kein brauchbares Themenpraefix (%r) - am Broker wurde nichts "
              "geloescht." % praefix)
        return 2
    erg = _broker_leeren(praefix, lambda t: bool(eigenes_thema(praefix, t)), warten)
    if erg["rc"] == 0:
        if erg["geleert"]:
            print("<OK> MQTT: %d behaltene Themen unter '%s/' am Broker geloescht und "
                  "nachgemessen." % (len(erg["geleert"]), praefix))
        else:
            print("<INFO> MQTT: unter '%s/' war am Broker nichts von der Funkwacht "
                  "behalten - nachgemessen, nichts zu loeschen." % praefix)
        return 0
    if erg["rc"] == 1:
        print("<WARNING> MQTT: %d von %d behaltenen Themen unter '%s/' stehen nach dem "
              "Loeschen noch im Broker, zum Beispiel %s - bitte von Hand loeschen."
              % (len(erg["rest"]), len(erg["geleert"]), praefix, erg["rest"][0]))
        return 1
    print("<INFO> MQTT: am Broker nicht moeglich - %s." % erg["grund"])
    return _udp_leeren(praefix)


# ======================================================================
# Historie, Ereignisse, Verlauf
# ======================================================================

def historie_lesen() -> dict:
    h, zustand = json_lesen(pfade()["historie"], {})
    if zustand == "kaputt":
        log("historie.json ist unlesbar und wird als .kaputt beiseitegelegt.",
            "historie_kaputt")
        try:
            os.replace(pfade()["historie"], pfade()["historie"] + ".kaputt")
        except Exception:
            pass
        h = {}
    if not isinstance(h, dict):
        h = {}
    h.setdefault("geheilt_gesamt", 0)
    h.setdefault("versuche_gesamt", 0)
    h.setdefault("geraete", {})
    h.setdefault("stufen", {})
    h.setdefault("ereignisse", [])
    h.setdefault("wartung_bis", 0)
    return h


def historie_eintrag(h, nr) -> dict:
    e = h["geraete"].get(str(nr))
    if not isinstance(e, dict):
        e = {}
    e.setdefault("heilungen", 0)
    e.setdefault("versuche", 0)
    e.setdefault("abgelehnt", 0)
    e.setdefault("verlauf", [])
    e.setdefault("letzte_tat", "")
    e.setdefault("letzte_groesse", None)
    e.setdefault("seit_zeit", 0)
    e.setdefault("war_ok", 1)
    e.setdefault("stufenstatistik", {})   # {"1": [versuche, erfolge], ...}
    e.setdefault("pruefe_stufe", 0)
    e.setdefault("pruefe_ab", 0)
    h["geraete"][str(nr)] = e
    return e


def statistik_lesen(he) -> dict:
    """Die Stufenstatistik als {stufe: (versuche, erfolge)}."""
    aus = {}
    for k, v in (he.get("stufenstatistik") or {}).items():
        try:
            aus[int(k)] = (int(v[0]), int(v[1]))
        except (TypeError, ValueError, IndexError):
            continue
    return aus


def statistik_buchen(he, stufe, gelungen):
    s = he.setdefault("stufenstatistik", {})
    v, e = s.get(str(stufe), [0, 0])[:2] if str(stufe) in s else (0, 0)
    s[str(stufe)] = [int(v) + 1, int(e) + (1 if gelungen else 0)]


def ereignis(h, cfg, nr, name, text):
    """Eine Zeile in die Ereignisliste - hoechstens 200.

    Warum es sie gibt: nach einem Neustart des Miniservers war sonst nicht
    mehr feststellbar, WANN der letzte Ausfall war.
    """
    h["ereignisse"].insert(0, {"zeit": int(time.time()), "nr": int(nr),
                               "name": name, "text": text})
    del h["ereignisse"][200:]


def verlauf_schreiben(cfg, nr, name, stufe, ergebnis, grund):
    """Eine Zeile in die Tagesdatei - unter data/, NICHT auf der Ramdisk.

    Sie soll einen Neustart ueberdauern; log/plugins liegt im
    Arbeitsspeicher.
    """
    p = pfade()
    ordner = p["verlauf"]
    try:
        if not os.path.isdir(ordner):
            os.makedirs(ordner, exist_ok=True)
        datei = os.path.join(ordner, "funkwacht_%s.csv" % time.strftime("%Y%m%d"))
        neu = not os.path.isfile(datei)
        with open(datei, "a", encoding="utf-8") as f:
            if neu:
                f.write("zeit;nr;name;stufe;ergebnis;grund\n")
            f.write("%d;%d;%s;%s;%s;%s\n" % (
                int(time.time()), int(nr),
                mqtt_wert_saeubern(name).replace(";", ","),
                fw_pruef.STUFEN_NAMEN.get(stufe, stufe), ergebnis,
                mqtt_wert_saeubern(grund).replace(";", ",")))
        # Alte Tagesdateien wegraeumen
        tage = int(cfg.get("verlauf_tage", 90))
        for alt in os.listdir(ordner):
            voll = os.path.join(ordner, alt)
            try:
                if alt.startswith("funkwacht_") and alt.endswith(".csv") \
                        and time.time() - os.path.getmtime(voll) > tage * 86400:
                    os.remove(voll)
            except Exception:
                continue
    except Exception:
        pass


def auftraege_holen():
    """Auftraege von der Oberflaeche und vom Endpunkt einsammeln.

    WARUM UEBER EINE DATEI und nicht unmittelbar: historie.json hat genau
    EINEN Schreiber, naemlich diesen Dienst. Schriebe die Oberflaeche
    dazwischen, waere ihre Aenderung beim naechsten Durchlauf ueberschrieben -
    und niemand saehe es. Der Auftrag wird gelesen, ausgefuehrt und die Datei
    entfernt.
    """
    p = pfade()["auftraege"]
    d, zustand = json_lesen(p, {})
    if zustand != "ok" or not isinstance(d, dict):
        if zustand == "kaputt":
            try:
                os.remove(p)
            except Exception:
                pass
        return []
    try:
        os.remove(p)
    except Exception:
        pass
    liste = d.get("auftraege")
    return liste if isinstance(liste, list) else []


def auftrag_ablegen(eintrag) -> bool:
    """Einen Auftrag an den laufenden Waechter anhaengen (wie fw_auftrag() in
    fw_lib.php: hoechstens 20, dieselbe Datei)."""
    p = pfade()["auftraege"]
    d, _ = json_lesen(p, {})
    liste = d.get("auftraege") if isinstance(d, dict) else None
    liste = liste if isinstance(liste, list) else []
    liste.append(eintrag)
    return json_schreiben(p, {"auftraege": liste[-20:]})


def auftraege_ausfuehren(h, cfg, liste, tabu=None):
    """Quittieren, Wartung und Heilwuensche anwenden. Rueckgabe: Zahl der ausgefuehrten."""
    n = 0
    for a in liste:
        if not isinstance(a, dict):
            continue
        was = str(a.get("was") or "")
        if was == "quittieren":
            nr = int(fw_pruef.zahl(a.get("nr"), 0))
            ziele = [str(nr)] if nr else list(h["geraete"].keys())
            for z in ziele:
                he = historie_eintrag(h, z)
                # D7 (Pruefung 29.09.2026): Quittieren loescht Alarm und
                # Zaehler, nicht die laufende Erholungszeit. Der Zeitpunkt des
                # letzten Versuchs bleibt als erholung_ab stehen; bis 1.0.8
                # startete der Waechter 60 s nach einem Neustart den naechsten.
                if he.get("verlauf"):
                    he["erholung_ab"] = float(he["verlauf"][-1])
                he["verlauf"] = []
                h["stufen"][z] = 0
            log("Quittiert: %s" % ("Stick %d" % nr if nr else "alle Sticks"))
            ereignis(h, cfg, nr, "", "quittiert")
            n += 1
        elif was == "wartung":
            dauer = int(fw_pruef.zahl(a.get("dauer"), 60))
            dauer = max(0, min(1440, dauer))
            h["wartung_bis"] = int(time.time()) + dauer * 60 if dauer else 0
            log("Wartung: %s" % ("noch %d Minuten" % dauer if dauer else "beendet"))
            ereignis(h, cfg, 0, "", "Wartung %s" % ("%d min" % dauer if dauer else "aus"))
            n += 1
        elif was == "statistik_zuruecksetzen":
            for z in list(h["geraete"].keys()):
                historie_eintrag(h, z)["stufenstatistik"] = {}
            log("Stufenstatistik zurueckgesetzt.")
            n += 1
        elif was == "heilen":
            log(hand_im_dienst(h, cfg, tabu or [], a, time.time()), kb=cfg["log_kb"])
            n += 1
    return n


# ======================================================================
# Ein Durchlauf
# ======================================================================

def sperre_bestimmen(cfg, h, jetzt) -> str:
    """Welche Sperre gilt gerade? Leerer Text heisst: keine."""
    if cfg.get("global_aus"):
        return "global_aus"
    if int(fw_pruef.zahl(h.get("wartung_bis"), 0)) > jetzt:
        return "wartung"
    ortszeit = time.localtime(jetzt)
    minute = ortszeit.tm_hour * 60 + ortszeit.tm_min
    if fw_pruef.fenster_offen(minute, cfg.get("ruhe_von"), cfg.get("ruhe_bis")):
        return "nachtruhe"
    return ""


def durchlauf(cfg, hist, tabu, jetzt=None):
    jetzt = jetzt if jetzt is not None else time.time()
    uptime = betriebsdauer()
    anlauf = int(cfg.get("anlauf_s", 300))
    # Gemeint ist der Start des SYSTEMS, nicht der des Waechters.
    #
    # Hier stand zuerst zusaetzlich "(jetzt - GESTARTET) < 120". Das war ein
    # Entwurfsfehler mit zwei Folgen: ein Aufruf mit --einmal haette NIE
    # geheilt (dort ist der eigene Start immer gerade eben), und nach jedem
    # Neustart des Waechters waeren zwei Minuten verloren gewesen - obwohl
    # Datei, USB und HTTP dann laengst wieder messbar sind. Das einzige, was
    # nach einem Neustart des Waechters wirklich fehlt, sind die
    # MQTT-Zeitstempel, und dafuer gibt es "nie_gesehen heilt nicht".
    #
    # Fail safe: laesst sich die Betriebsdauer nicht lesen (-1), gilt KEINE
    # Schonzeit - sonst taete der Waechter dort dauerhaft nichts.
    schonzeit = bool(anlauf and 0 <= uptime < anlauf)
    sperre = sperre_bestimmen(cfg, hist, jetzt)

    neu = {"zeit": int(jetzt), "geraete": {}, "tabu": tabu, "ok": 1, "krank": 0,
           "alarm": 0, "kaputt": int(cfg.get("kaputt", 0)),
           "sperre": sperre, "gesperrt": 1 if sperre else 0,
           "schonzeit": 1 if schonzeit else 0,
           "wartung": max(0, int(fw_pruef.zahl(hist.get("wartung_bis"), 0)) - int(jetzt)),
           "geheilt_gesamt": int(hist.get("geheilt_gesamt", 0)),
           "versuche_gesamt": int(hist.get("versuche_gesamt", 0))}
    entschluesse = []
    meldungen = []

    for nr, g in enumerate(cfg["geraete"], start=1):
        if g["name"] == "":
            continue
        he = historie_eintrag(hist, nr)
        verlauf = [float(t) for t in he.get("verlauf", [])][-50:]
        bisher = int(hist["stufen"].get(str(nr), 0))

        alter, groesse, neustarts, bemerkung, a1, a2 = messen(g, he.get("letzte_groesse"))
        if groesse is not None:
            he["letzte_groesse"] = groesse

        entschluss = fw_pruef.entscheiden(
            g, alter, verlauf, bisher, jetzt, schonzeit=schonzeit, sperre=sperre,
            statistik=statistik_lesen(he),
            letzter_versuch=float(he.get("erholung_ab") or 0) or None)
        entschluesse.append(entschluss)

        # --- Erfolgskontrolle der letzten Heilung ---------------------
        if he.get("pruefe_stufe") and jetzt >= float(he.get("pruefe_ab") or 0):
            gelungen = bool(entschluss["ok"] and entschluss["grund"] != "erholung")
            statistik_buchen(he, int(he["pruefe_stufe"]), gelungen)
            log("Erfolgskontrolle %s: Stufe %s hat %s"
                % (g["name"], fw_pruef.STUFEN_NAMEN.get(int(he["pruefe_stufe"]), "?"),
                   "geholfen" if gelungen else "nicht geholfen"))
            ereignis(hist, cfg, nr, g["name"], "Stufe %s %s"
                     % (fw_pruef.STUFEN_NAMEN.get(int(he["pruefe_stufe"]), "?"),
                        "hat geholfen" if gelungen else "hat nicht geholfen"))
            he["pruefe_stufe"] = 0
            he["pruefe_ab"] = 0

        # --- Zustandswechsel merken -----------------------------------
        ist_ok = 1 if entschluss["ok"] else 0
        if ist_ok != int(he.get("war_ok", 1)) or not he.get("seit_zeit"):
            he["seit_zeit"] = jetzt
            if ist_ok != int(he.get("war_ok", 1)):
                meldungen.append((nr, g["name"], ist_ok, entschluss["grund"]))
            he["war_ok"] = ist_ok

        e = {"name": g["name"], "art": g["art"], "ok": ist_ok,
             "grund": entschluss["grund"],
             "alter": (-1 if alter is None else int(alter)),
             "alter1": (-1 if a1 is None else int(a1)),
             "alter2": (-1 if a2 is None else int(a2)),
             "stufe": bisher, "warum": entschluss["warum"],
             "bemerkung": bemerkung, "neustarts": int(neustarts),
             "letzte_tat": he.get("letzte_tat", ""),
             "heilungen": int(he.get("heilungen", 0)),
             "versuche": int(he.get("versuche", 0)),
             "abgelehnt": int(he.get("abgelehnt", 0)),
             "heil24": fw_pruef.im_fenster_zaehlen(verlauf, jetzt, 86400),
             "heil7t": fw_pruef.im_fenster_zaehlen(verlauf, jetzt, 7 * 86400),
             "seit": max(0, int(jetzt - float(he.get("seit_zeit") or jetzt))),
             "letzte": int(verlauf[-1]) if verlauf else 0,
             "statistik": {str(k): list(v) for k, v in statistik_lesen(he).items()}}

        if entschluss["ok"]:
            # Waehrend der Erholung wird die Eskalation NICHT zurueckgesetzt -
            # sonst finge sie nach jedem Versuch von vorn an und liefe im Kreis.
            if entschluss["grund"] != "erholung":
                e["stufe"] = 0
                hist["stufen"][str(nr)] = 0
            e["warum"] = ""
        elif entschluss["aktion"]:
            e.update(ausfuehren(cfg, hist, he, g, nr, entschluss, tabu, jetzt, neu))
        else:
            log("%s gilt als gestoert (%s), es wird nicht geheilt: %s"
                % (g["name"], entschluss["grund"], entschluss["warum"]),
                "krank_%d" % nr, kb=cfg["log_kb"])

        if not entschluss["ok"]:
            neu["krank"] += 1
            neu["ok"] = 0
        neu["geraete"][str(nr)] = e

    neu["alarm"] = fw_pruef.alarm_wert(entschluesse)
    neu["felder"] = {h2: w for _, h2, w in felder(neu) if h2 is not None}
    if meldungen:
        melden(cfg, meldungen)
    return neu


def ausfuehren(cfg, hist, he, g, nr, entschluss, tabu, jetzt, neu) -> dict:
    """Eine Heilung ausfuehren, buchen und die Erfolgskontrolle vormerken."""
    stufe = entschluss["aktion"]
    ok, abgelehnt, was = heilen(g, stufe, tabu)
    text = "%s: %s" % (fw_pruef.STUFEN_NAMEN[stufe], was)

    # Nach Stufe 2 oder 3 den Dienst gleich mitnehmen: ein unbind/bind laesst
    # ihn sonst mit einem toten Dateideskriptor zurueck.
    if ok and entschluss.get("danach"):
        ok2, ab2, was2 = heilen(g, entschluss["danach"], tabu)
        text += "  +  %s: %s" % (fw_pruef.STUFEN_NAMEN[entschluss["danach"]], was2)

    verlauf = [float(t) for t in he.get("verlauf", [])][-49:] + [jetzt]
    he["verlauf"] = verlauf
    he["letzte_tat"] = text
    he["versuche"] = int(he.get("versuche", 0)) + 1
    neu["versuche_gesamt"] += 1
    if ok:
        he["heilungen"] = int(he.get("heilungen", 0)) + 1
        neu["geheilt_gesamt"] += 1
    if abgelehnt:
        he["abgelehnt"] = int(he.get("abgelehnt", 0)) + 1
    hist["geheilt_gesamt"] = neu["geheilt_gesamt"]
    hist["versuche_gesamt"] = neu["versuche_gesamt"]
    hist["stufen"][str(nr)] = stufe
    # Erfolgskontrolle: nach der Erholungszeit wird nachgemessen. Nicht der
    # Rueckgabewert entscheidet, ob eine Stufe wirkt, sondern ob der Stick
    # zurueck ist.
    if ok:
        he["pruefe_stufe"] = stufe
        he["pruefe_ab"] = jetzt + g["ruhe_s"] + 5
    ergebnis = "gelungen" if ok else ("abgelehnt" if abgelehnt else "fehlgeschlagen")
    log("%s: %s (Grund: %s, %s)" % (g["name"], text, entschluss["grund"], ergebnis),
        kb=cfg["log_kb"])
    ereignis(hist, cfg, nr, g["name"], "%s - %s" % (text, ergebnis))
    verlauf_schreiben(cfg, nr, g["name"], stufe, ergebnis, entschluss["grund"])
    return {"stufe": stufe, "letzte_tat": text,
            "heilungen": int(he["heilungen"]), "versuche": int(he["versuche"]),
            "abgelehnt": int(he["abgelehnt"]),
            "heil24": fw_pruef.im_fenster_zaehlen(verlauf, jetzt, 86400),
            "heil7t": fw_pruef.im_fenster_zaehlen(verlauf, jetzt, 7 * 86400),
            "letzte": int(jetzt)}


def melden(cfg, meldungen):
    """Nur beim WECHSEL des Befundes melden - und mit Entwarnung.

    Eine Meldung je Minute waere keine Meldung, sondern Rauschen.
    """
    schlecht = [m for m in meldungen if not m[2]]
    gut = [m for m in meldungen if m[2]]
    if schlecht:
        text = "Gestoert: " + ", ".join("%s (%s)" % (m[1], m[3]) for m in schlecht)
        benachrichtigen(cfg, 3, text)
    if gut:
        text = "Wieder in Ordnung: " + ", ".join(m[1] for m in gut)
        benachrichtigen(cfg, 6, text)


def veroeffentlichen(cfg, stand):
    global _altlast_naechster, _sticks_naechster, _voll_naechster
    # M5: vor dem Schalter - auch "MQTT aus" hinterlaesst ein Praefix.
    altpraefixe_abraeumen(cfg)
    if not cfg.get("mqtt_ein"):
        return
    praefix = cfg["mqtt_topic"].strip("/")
    # Zuerst die Altwerte frueherer Fassungen abraeumen, UNMITTELBAR danach
    # geht der gueltige Wert (fluechtig) hinaus - die leere Nutzlast steht
    # so nur Sekunden im Miniserver. Scheitert es, kommt der naechste
    # Versuch erst in zehn Minuten: ein fehlender Broker soll nicht jeden
    # Takt um die Wartezeit verlaengern.
    if time.time() >= _altlast_naechster:
        merker = retain_merker_datei()
        war_erledigt = bool(merker) and os.path.isfile(merker)
        if mqtt_altlast_abraeumen(praefix) != 0:
            _altlast_naechster = time.time() + 600
        elif not war_erledigt:
            # M8: nach dem Abraeumen geht der volle Satz einmal hinaus, sonst
            # stuende ein geleertes Thema bis zu 30 Minuten leer.
            _voll_naechster = 0.0
    paare = [(m, w) for m, _, w in felder(stand)]
    mqtt_senden(paare, praefix)
    # Themen geleerter Stick-Zeilen abraeumen (1.0.7) - NACH dem Senden: das
    # Nachsehen dauert zweimal die Wartezeit, und die leere Nutzlast der
    # Altwerte oben soll nur Sekunden vor dem gueltigen Wert stehen
    # (Rueckschritt Pruefung-Funkwacht-1.0.6, Fall R26: davor waren es > 5 s).
    # Nachgesehen wird nur, wenn sich die Stickmenge geaendert hat;
    # gescheitert: in zehn Minuten.
    if time.time() >= _sticks_naechster:
        if stick_themen_abraeumen(praefix, (stand.get("geraete") or {}).keys()) != 0:
            _sticks_naechster = time.time() + 600


def beenden(signum, rahmen):
    global laeuft
    laeuft = False


# ======================================================================
# Trockenlauf und Heilung von Hand
# ======================================================================

def trockenlauf(cfg, hist, tabu):
    """Was WUERDE jetzt geschehen? Es wird nichts ausgefuehrt."""
    jetzt = time.time()
    uptime = betriebsdauer()
    anlauf = int(cfg.get("anlauf_s", 300))
    schonzeit = bool(anlauf and 0 <= uptime < anlauf)
    sperre = sperre_bestimmen(cfg, hist, jetzt)
    z = []
    z.append("Trockenlauf %s - es wird NICHTS ausgefuehrt." % time.strftime("%d.%m.%Y %H:%M:%S"))
    z.append("Betriebsdauer des Systems: %s"
             % ("nicht lesbar" if uptime < 0 else "%d s" % uptime))
    z.append("Anlaufschonzeit: %s" % ("JA - es wuerde nicht geheilt" if schonzeit else "nein"))
    z.append("Sperre: %s" % (sperre or "keine"))
    z.append("")
    n = 0
    for nr, g in enumerate(cfg["geraete"], start=1):
        if g["name"] == "":
            continue
        n += 1
        he = historie_eintrag(hist, nr)
        verlauf = [float(t) for t in he.get("verlauf", [])][-50:]
        bisher = int(hist["stufen"].get(str(nr), 0))
        alter, _, neustarts, bem, a1, a2 = messen(g, he.get("letzte_groesse"))
        e = fw_pruef.entscheiden(g, alter, verlauf, bisher, jetzt,
                                 schonzeit=schonzeit, sperre=sperre,
                                 statistik=statistik_lesen(he),
                                 letzter_versuch=float(he.get("erholung_ab") or 0) or None)
        z.append("Stick %d: %s   (%s%s)" % (
            nr, g["name"], g["art"], "+" + g["art2"] if g["art2"] else ""))
        z.append("   gemessenes Alter : %s" % ("noch nie gehoert" if alter is None
                                               else "%d s" % int(alter)))
        if g["art2"]:
            z.append("   davon Kriterium 1: %s, Kriterium 2: %s, verknuepft mit %s"
                     % ("-" if a1 is None else "%d s" % int(a1),
                        "-" if a2 is None else "%d s" % int(a2), g["verkn"].upper()))
        if bem:
            z.append("   Bemerkung        : %s" % bem)
        if neustarts >= 0:
            z.append("   Neustarts        : %d" % neustarts)
        z.append("   Urteil           : %s (%s)"
                 % ("gesund" if e["ok"] else "gestoert", e["grund"]))
        if e["aktion"]:
            z.append("   WUERDE JETZT     : Stufe %d - %s"
                     % (e["aktion"], fw_pruef.STUFEN_NAMEN[e["aktion"]]))
            if e.get("danach"):
                z.append("   und gleich danach: Stufe %d - %s"
                         % (e["danach"], fw_pruef.STUFEN_NAMEN[e["danach"]]))
        else:
            z.append("   wuerde nichts tun: %s" % (e["warum"] or "nichts noetig"))
        # ALLE eingerichteten Stufen mit ihrem vollstaendigen Befehl - nicht
        # nur die naechste. Eine Ablehnung auf Stufe 2 saehe man sonst erst,
        # wenn Stufe 1 durch ist, also genau dann nicht, wenn man sie braucht.
        for s in fw_pruef.stufen_folge(g):
            if not fw_pruef.stufe_belegt(g, s):
                continue
            merk = "  <== jetzt" if s == e["aktion"] else (
                "  <== gleich danach" if s == e.get("danach") else "")
            if s > g["hoechststufe"]:
                merk = "  (ueber der Hoechststufe, wird nie gezogen)"
            z.append("   Stufe %d %-8s : %s%s"
                     % (s, fw_pruef.STUFEN_NAMEN[s], wuerde_heilen(g, s, tabu), merk))
        st = statistik_lesen(he)
        if st:
            z.append("   Stufenstatistik  : %s" % ", ".join(
                "%s %d von %d" % (fw_pruef.STUFEN_NAMEN.get(k, k), v[1], v[0])
                for k, v in sorted(st.items())))
        z.append("")
    if not n:
        z.append("Es ist kein Stick eingetragen.")
    return "\n".join(z)


def dauerlaeufer_pid() -> int:
    """Laeuft der Waechter als Dauerlaeufer - ein ANDERER Prozess als dieser?

    Dieselbe Probe wie ist_dienst() in bin/dienst.sh: genau zwei Argumente,
    argv[0] ein Python, argv[1] dieses Skript (auch ueber einen Verweis oder
    relativ zum Arbeitsverzeichnis des Prozesses). Gesucht wird ueber /proc,
    nicht ueber dienst.pid - ein Dienst ohne PID-Datei waere sonst unsichtbar.
    """
    eigen = os.path.realpath(os.path.abspath(__file__))
    try:
        eintraege = os.listdir("/proc")
    except OSError:
        return 0
    for n in eintraege:
        if not n.isdigit() or int(n) == os.getpid():
            continue
        try:
            with open("/proc/%s/cmdline" % n, "rb") as f:
                roh = f.read()
        except OSError:
            continue
        teile = roh.rstrip(b"\0").split(b"\0")
        if len(teile) != 2 or not re.search(rb"(^|/)python[0-9.]*$", teile[0]):
            continue
        a1 = teile[1].decode("utf-8", "replace")
        if not a1.startswith("/"):
            try:
                a1 = os.path.join(os.readlink("/proc/%s/cwd" % n), a1)
            except OSError:
                continue
        if os.path.realpath(a1) == eigen:
            return int(n)
    return 0


def hand_wunsch(cfg, hist, wunsch) -> tuple:
    """Den Wunsch "N" oder "N:S" pruefen. Rueckgabe: (nr, Geraet, Stufe, "")
    oder (0, None, 0, Grund)."""
    teile = str(wunsch).split(":")
    nr = int(fw_pruef.zahl(teile[0], 0))
    stufe = int(fw_pruef.zahl(teile[1], 0)) if len(teile) > 1 else 0
    liste = list(enumerate(cfg["geraete"], start=1))
    treffer = [(n, g) for n, g in liste if n == nr and g["name"]]
    if not treffer:
        return 0, None, 0, "Stick %d gibt es nicht oder er hat keinen Namen." % nr
    n, g = treffer[0]
    if not stufe:
        he = historie_eintrag(hist, n)
        stufe = fw_pruef.naechste_stufe(g, int(hist["stufen"].get(str(n), 0)),
                                        statistik_lesen(he))
    if not stufe:
        return 0, None, 0, "Fuer %s ist keine Stufe eingetragen." % g["name"]
    if stufe not in (1, 2, 3):
        return 0, None, 0, "Stufe %s gibt es nicht." % stufe
    if not fw_pruef.stufe_belegt(g, stufe):
        return 0, None, 0, "Fuer %s ist Stufe %d (%s) nicht eingetragen." % (
            g["name"], stufe, fw_pruef.STUFEN_NAMEN[stufe])
    return n, g, stufe, ""


def hand_buchen(hist, cfg, n, g, was, zeit) -> None:
    """D3 (Pruefung 29.09.2026): ein Versuch von Hand steht im Verlauf - damit
    rechnen Erholung, Abstand und Tagesgrenze des Waechters ihn mit. Bis 1.0.8
    stand er dort nicht, und der naechste Durchlauf heilte sofort ein zweites
    Mal."""
    he = historie_eintrag(hist, n)
    he["verlauf"] = [float(t) for t in he.get("verlauf", [])][-49:] + [float(zeit)]
    ereignis(hist, cfg, n, g["name"], "von Hand: %s" % was)


def hand_im_dienst(h, cfg, tabu, auftrag, jetzt) -> str:
    """Ein Heilwunsch von Hand als Auftrag im laufenden Waechter (D3).

    Mit den Bremsen des Waechters: Sperre (Aus-Schalter, Wartung,
    Ruhefenster), Erholung, Heilen aus, Abstand und Tagesgrenze. Die
    Anlaufschonzeit gilt nicht - sie schuetzt vor Messungen kurz nach dem
    Start, und hier hat ein Mensch entschieden. Rueckgabe: Protokollsatz.
    """
    nr = int(fw_pruef.zahl(auftrag.get("nr"), 0))
    st = int(fw_pruef.zahl(auftrag.get("stufe"), 0))
    n, g, stufe, grund = hand_wunsch(cfg, h, "%d:%d" % (nr, st) if st else str(nr))
    if grund:
        return "Heilwunsch von Hand abgelehnt: %s" % grund
    he = historie_eintrag(h, n)
    verlauf = [float(t) for t in he.get("verlauf", [])][-50:]
    letzter = float(he.get("erholung_ab") or 0) or None
    warum = sperre_bestimmen(cfg, h, jetzt)
    if not warum and fw_pruef.in_erholung(g, verlauf or ([letzter] if letzter else []), jetzt):
        warum = "erholung"
    if not warum:
        erlaubt, w = fw_pruef.darf_heilen(g, verlauf, jetzt)
        warum = "" if erlaubt else w
    if warum:
        ereignis(h, cfg, n, g["name"], "von Hand gewuenscht, abgelehnt: %s" % warum)
        return "Heilwunsch von Hand fuer %s abgelehnt: %s." % (g["name"], warum)
    ok, abgelehnt, was = heilen(g, stufe, tabu)
    hand_buchen(h, cfg, n, g, was, jetzt)
    verlauf_schreiben(cfg, n, g["name"], stufe,
                      "gelungen" if ok else ("abgelehnt" if abgelehnt else "fehlgeschlagen"),
                      "von Hand")
    return "Von Hand geheilt (Auftrag): %s - %s" % (g["name"], was)


def von_hand_heilen(cfg, hist, tabu, wunsch):
    """Eine Stufe von Hand ausfuehren, wenn KEIN Waechter laeuft.

    wunsch ist "N" oder "N:S". Rueckgabe: (Text, Buchung oder None); die
    Buchung traegt main() in eine unmittelbar vorher neu gelesene
    historie.json ein (D3).
    """
    n, g, stufe, grund = hand_wunsch(cfg, hist, wunsch)
    if grund:
        return grund, None
    vorher, _, _, _, _, _ = messen(g, None)
    zeit = time.time()
    ok, abgelehnt, was = heilen(g, stufe, tabu)
    verlauf_schreiben(cfg, n, g["name"], stufe,
                      "gelungen" if ok else ("abgelehnt" if abgelehnt else "fehlgeschlagen"),
                      "von Hand")
    log("Von Hand geheilt: %s - %s" % (g["name"], was), kb=cfg["log_kb"])
    # Die Wirkung melden, nicht die Absicht: nach der Erholungszeit noch
    # einmal messen. Von Hand darf das dauern - hier sieht jemand zu.
    warte = min(30, max(3, int(g["ruhe_s"] / 4)))
    time.sleep(warte)
    nachher, _, _, _, _, _ = messen(g, None)
    z = ["Stick %d: %s" % (n, g["name"]),
         "Stufe %d (%s)" % (stufe, fw_pruef.STUFEN_NAMEN[stufe]),
         "Befehl : %s" % was,
         "Ergebnis: %s" % ("gelungen" if ok else
                           ("ABGELEHNT" if abgelehnt else "fehlgeschlagen")),
         "",
         "Nachgemessen nach %d s:" % warte,
         "   vorher : %s" % ("noch nie gehoert" if vorher is None else "%d s" % int(vorher)),
         "   nachher: %s" % ("noch nie gehoert" if nachher is None else "%d s" % int(nachher))]
    if nachher is not None and (vorher is None or nachher < vorher):
        z.append("   -> der Stick meldet sich wieder frischer als vorher.")
    else:
        z.append("   -> keine Besserung messbar. Das kann an der Erholungszeit")
        z.append("      liegen (%d s) - dann in einer Minute noch einmal ansehen."
                 % g["ruhe_s"])
    z.append("")
    z.append("Dieser Versuch zaehlt NICHT in die Stufenstatistik: er kam von")
    z.append("Hand und nicht aus einem gemessenen Ausfall. Er steht aber im")
    z.append("Verlauf - Erholung, Abstand und Tagesgrenze rechnen ihn mit.")
    return "\n".join(z), (n, g, was, zeit)


def dienst_selbsttest():
    """Faelle, die den Dienst betreffen und nicht den reinen Rechenkern."""
    zeilen, stand = [], {"n": 0, "f": 0}

    def pr(name, ist, soll):
        stand["n"] += 1
        if ist == soll:
            zeilen.append("[ OK ] " + name)
        else:
            stand["f"] += 1
            zeilen.append("[FEHL] %s: erhalten %r, erwartet %r" % (name, ist, soll))

    zeilen.append("")
    zeilen.append("-- Retain je Themenstamm --")
    tab = themen_tabelle()
    pr("jeder Stamm aus felder() steht in RETAIN",
       sorted(k for k, v in tab.items() if v is None), [])
    pr("RETAIN nennt keinen Stamm, den felder() nicht erzeugt",
       sorted(set(RETAIN) - set(tab)), [])
    pr("das Lebenszeichen ts ist nie retained", retain_fuer("ts"), False)
    pr("ok ist ein Urteil des Waechters und nie retained", retain_fuer("ok"), False)
    pr("ein Zustand der Konfiguration ist retained", retain_fuer("geraete"), True)
    pr("eine Dauer ist nicht retained", retain_fuer("geraet3/alter"), False)
    pr("die Stammbildung trifft zweistellige Nummern",
       thema_stamm("geraet12/stufe"), "geraetN/stufe")
    pr("ein absoluter Zeitstempel ist retained", retain_fuer("geraet1/letzte"), True)
    pr("ein unbekanntes Thema geht als publish", retain_fuer("gibt/es/nicht"), False)

    zeilen.append("")
    zeilen.append("-- Eigene Themen beim Abraeumen --")
    pr("ein Summenthema ist eigen", eigenes_thema("fw", "fw/geraete"), "geraete")
    pr("ein Stickthema ist eigen, auch zweistellig",
       eigenes_thema("fw", "fw/geraet17/name"), "geraet17/name")
    pr("ein fremdes Feld unter einem Stick ist nicht eigen",
       eigenes_thema("fw", "fw/geraet1/fremd"), "")
    pr("Nummer 0 ist nicht eigen", eigenes_thema("fw", "fw/geraet0/name"), "")
    pr("ein anderes Praefix ist nicht eigen", eigenes_thema("fw", "fwx/geraete"), "")
    pr("je retained gingen 19 Staemme hinaus", len(je_retained()), 19)

    zeilen.append("")
    zeilen.append("-- Fassung aus einer Quelle --")
    import tempfile
    with tempfile.TemporaryDirectory() as t:
        os.makedirs(os.path.join(t, "data", "system"))
        with open(os.path.join(t, "data", "system", "plugindatabase.json"), "w",
                  encoding="utf-8") as fh:
            json.dump({"plugins": {"x1": {"folder": "andere", "version": "9.9.9"},
                                   "x2": {"folder": "funkwacht01", "version": "4.5.6"}}}, fh)
        pr("die Datenbank wird ueber den Ordnernamen gelesen",
           fw_fassung.plugin_fassung("funkwacht01", t, hier=os.path.join(t, "leer")), "4.5.6")
        pr("ein fremder Ordner liefert nichts Erfundenes",
           fw_fassung.plugin_fassung("gibtsnicht", t, hier=os.path.join(t, "leer")), "")
    pr("im entpackten Archiv kommt sie aus plugin.cfg",
       fw_fassung.plugin_fassung("gibtsnicht", "") != "",
       os.path.isfile(os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                   "..", "plugin.cfg")))
    return stand["n"], stand["f"], zeilen


# ======================================================================
# Aufruf
# ======================================================================

def main():
    global laeuft
    argv = sys.argv[1:]

    if "--selbsttest" in argv:
        n, f, text = fw_pruef.selbsttest()
        n2, f2, zeilen = dienst_selbsttest()
        # EINE Kopfzeile mit der Summe: freigabe_pruefen.py liest die erste
        # Zeile der Form "N Faelle geprueft, M Fehlschlaege". Ein Fehlschlag
        # im zweiten Teil, der dort nicht mitzaehlt, waere unsichtbar.
        kopf, _, rest = text.partition("\n")
        print("Funkwacht-Kern %s und Dienst: %d Faelle geprueft, %d Fehlschlaege."
              % (fw_pruef.FASSUNG, n + n2, f + f2))
        print(rest.lstrip("\n"))
        print("\n".join(zeilen))
        return 1 if (f + f2) else 0

    if "--themen" in argv:
        # Fuer den Reiter Test: welche Themen sendet der Waechter, und welche
        # davon retained? Die Oberflaeche haelt ihre eigene Tabelle dagegen.
        print(json.dumps(themen_tabelle(), ensure_ascii=False, sort_keys=True))
        return 0

    if "--faehigkeit" in argv:
        f = faehigkeiten()
        f["systemgeraete"] = systemgeraete()
        f["fassung"] = fw_fassung.plugin_fassung()
        f["kern"] = fw_pruef.FASSUNG
        f["betriebsdauer"] = betriebsdauer()
        print(json.dumps(f, ensure_ascii=False, indent=1))
        return 0

    if "--mqtt-leeren" in argv:
        # Fuer uninstall/uninstall (1.0.7). Prueft die Wurzel selbst und
        # antwortet im Format des Installers.
        return mqtt_leeren()

    # Alles ab hier liest und schreibt in der Anlage. Ohne gelesene Wurzel
    # und Ordnernamen geschieht NICHTS (Regeln/06: ohne brauchbare Wurzel
    # warnen statt vollziehen). --selbsttest, --themen und --faehigkeit
    # oben brauchen keine - der Reiter Test ruft --themen ohne Umgebung.
    if not pfade()["home"]:
        sys.stderr.write(
            "FEHLER: Es wurde keine LoxBerry-Wurzel gelesen oder gefunden "
            "(LBHOMEDIR, LBPPLUGINDIR, Suche nach config/plugins, data/plugins "
            "und config/system/general.json oberhalb von %s). Es wurde nichts "
            "gelesen, geschrieben oder geheilt.\n"
            % os.path.dirname(os.path.abspath(__file__)))
        return 1

    if "--trocken" in argv:
        print(trockenlauf(config(), historie_lesen(), systemgeraete()))
        return 0

    if "--heile" in argv:
        i = argv.index("--heile")
        if len(argv) <= i + 1:
            print("Aufruf: --heile <Nummer>[:<Stufe>]")
            return 2
        cfg = config()
        hist = historie_lesen()
        n, g, stufe, grund = hand_wunsch(cfg, hist, argv[i + 1])
        if grund:
            print(grund)
            return 0
        # D3 (Pruefung 29.09.2026): laeuft der Waechter, schreibt dieser
        # Prozess NICHTS neben ihm her - der Wunsch geht als Auftrag in seine
        # Warteschlange, und er fuehrt ihn mit seinen Bremsen aus. Bis 1.0.8
        # ueberschrieb der Hand-Prozess nach seiner Wartezeit die Historie
        # des Waechters, und 60 s nach dem ersten Neustart kam der zweite.
        pid = dauerlaeufer_pid()
        if pid:
            teile = str(argv[i + 1]).split(":")
            st = int(fw_pruef.zahl(teile[1], 0)) if len(teile) > 1 else 0
            if auftrag_ablegen({"was": "heilen", "nr": n, "stufe": st,
                                "zeit": int(time.time())}):
                print("Der Waechter laeuft (Prozessnummer %d). Der Heilwunsch fuer Stick %d "
                      "(%s) ist als Auftrag vorgemerkt: der Waechter fuehrt ihn im naechsten "
                      "Durchlauf aus, spaetestens in %d Sekunden - mit seinen Bremsen "
                      "(Sperre, Erholung, Abstand, Tagesgrenze). Das Ergebnis steht danach "
                      "im Reiter Logdateien unter den Ereignissen."
                      % (pid, n, g["name"], cfg["takt"]))
                return 0
            print("Der Waechter laeuft (Prozessnummer %d), aber der Auftrag liess sich "
                  "nicht ablegen - bitte die Rechte an data/plugins pruefen." % pid)
            return 1
        text, buchung = von_hand_heilen(cfg, hist, systemgeraete(), argv[i + 1])
        print(text)
        if buchung:
            # Unmittelbar vor dem Schreiben neu gelesen: zwischen Lesen und
            # Schreiben lagen die Heilung und bis zu 30 s Wartezeit.
            frisch = historie_lesen()
            hand_buchen(frisch, cfg, buchung[0], buchung[1], buchung[2], buchung[3])
            if not json_schreiben(pfade()["historie"], frisch):
                print("ACHTUNG: historie.json liess sich nicht schreiben - der Versuch "
                      "steht nicht im Verlauf.")
                return 1
        return 0

    signal.signal(signal.SIGTERM, beenden)
    signal.signal(signal.SIGINT, beenden)

    p = pfade()
    letzte_tabu = None
    einmal = "--einmal" in argv
    while laeuft:
        cfg = config()
        tabu = systemgeraete()
        if tabu != letzte_tabu:
            log("Systemgeraete, die nie angefasst werden: %s"
                % (", ".join(tabu) if tabu else "keine"), kb=cfg["log_kb"])
            letzte_tabu = tabu
        hist = historie_lesen()
        auftraege_ausfuehren(hist, cfg, auftraege_holen(), tabu)
        neu = durchlauf(cfg, hist, tabu)
        # U1: eine gescheiterte Schrift wird gesagt, nicht verschwiegen.
        if not json_schreiben(p["historie"], hist):
            log("historie.json liess sich nicht schreiben - Zaehler und Verlauf dieses "
                "Durchlaufs fehlen.", "schreiben_historie", kb=cfg["log_kb"])
        if not json_schreiben(p["stand"], neu):
            log("stand.json liess sich nicht schreiben - Oberflaeche und Endpunkt zeigen "
                "den vorigen Stand.", "schreiben_stand", kb=cfg["log_kb"])
        veroeffentlichen(cfg, neu)
        if einmal:
            return 0
        ende = time.time() + cfg["takt"]
        while laeuft and time.time() < ende:
            time.sleep(0.5)
    log("Dienst beendet.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
