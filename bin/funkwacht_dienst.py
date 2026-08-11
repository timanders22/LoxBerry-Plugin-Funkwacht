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
Geprueft wird deshalb ueber das Vorhandensein des Geraeteknotens und, wo
moeglich, ueber ein Lebenszeichen des Verbrauchers (MQTT-Thema, HTTP-Antwort,
Zeitstempel einer Datei).

Aufrufe:
    funkwacht_dienst.py               laeuft als Dienst
    funkwacht_dienst.py --einmal      ein Durchlauf, dann Schluss
    funkwacht_dienst.py --selbsttest  nur rechnen, nichts anfassen
    funkwacht_dienst.py --faehigkeit  was kann dieses Geraet wirklich?

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

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import fw_pruef  # noqa: E402

FASSUNG = "0.9.1"
laeuft = True


# ======================================================================
# Pfade
# ======================================================================

def wurzel() -> str:
    """Der LoxBerry-Wurzelordner - ohne festen Systempfad.

    Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis config/plugins UND
    webfrontend enthaelt.
    """
    d = os.path.dirname(os.path.abspath(__file__))
    for _ in range(8):
        if os.path.isdir(os.path.join(d, "config", "plugins")) \
                and os.path.isdir(os.path.join(d, "webfrontend")):
            return d
        eltern = os.path.dirname(d)
        if eltern == d:
            break
        d = eltern
    return ""


def pfade() -> dict:
    home = os.environ.get("LBHOMEDIR") or wurzel()
    ordner = os.environ.get("LBPPLUGINDIR") or "funkwacht"
    return {
        "home": home,
        "plugin": ordner,
        "config": os.path.join(home, "config", "plugins", ordner, "funkwacht.json"),
        "data": os.path.join(home, "data", "plugins", ordner),
        "log": os.path.join(home, "log", "plugins", ordner, "funkwacht.log"),
        "general": os.path.join(home, "config", "system", "general.json"),
    }


def json_lesen(pfad, vorgabe=None):
    try:
        with open(pfad, "r", encoding="utf-8") as f:
            d = json.load(f)
        return d if isinstance(d, (dict, list)) else (vorgabe if vorgabe is not None else {})
    except Exception:
        return vorgabe if vorgabe is not None else {}


def json_schreiben(pfad, daten) -> bool:
    """Erst in eine Nebendatei, dann umbenennen - so liest niemand einen
    halb geschriebenen Stand. Die Nebendatei traegt die Prozessnummer: Dienst
    und Oberflaeche koennen im selben Augenblick schreiben."""
    ordner = os.path.dirname(pfad)
    try:
        os.makedirs(ordner, exist_ok=True)
        tmp = "%s.tmp.%d" % (pfad, os.getpid())
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(daten, f, ensure_ascii=False, indent=1)
        os.replace(tmp, pfad)
        return True
    except Exception:
        return False


_gebremst = {}


def log(text, einmalig="", sekunden=3600):
    """Ins Protokoll schreiben, wiederkehrende Meldungen gebremst.

    log/plugins liegt auf einer Ramdisk: eine unbegrenzt wachsende Datei
    frisst Arbeitsspeicher, nicht Plattenplatz. Deshalb gekuerzt.
    """
    if einmalig:
        letzte = _gebremst.get(einmalig, 0)
        if time.time() - letzte < sekunden:
            return
        _gebremst[einmalig] = time.time()
    p = pfade()["log"]
    try:
        os.makedirs(os.path.dirname(p), exist_ok=True)
        if os.path.isfile(p) and os.path.getsize(p) > 512000:
            with open(p, "r", encoding="utf-8", errors="replace") as f:
                rest = f.readlines()[-400:]
            with open(p, "w", encoding="utf-8") as f:
                f.writelines(rest)
        with open(p, "a", encoding="utf-8") as f:
            f.write("[%s] %s\n" % (time.strftime("%Y-%m-%d %H:%M:%S"), text))
    except Exception:
        pass


def config() -> dict:
    c = json_lesen(pfade()["config"], {})
    c.setdefault("takt", 60)
    c.setdefault("mqtt_ein", 1)
    c.setdefault("mqtt_topic", "funkwacht")
    c.setdefault("aktionstoken", "")
    c.setdefault("geraete", [])
    c["takt"] = int(max(15, min(3600, fw_pruef.zahl(c["takt"], 60))))
    c["geraete"] = [fw_pruef.geraet_geradebiegen(g) for g in (c.get("geraete") or [])]
    return c


# ======================================================================
# Was kann dieses Geraet wirklich?
#
# Der Vorschlag, uhubctl zu benutzen, klingt einfacher als er ist: Portstrom
# schalten kann nur ein Hub, der "per-port power switching" beherrscht. Der
# eingebaute Hub des Raspberry Pi 4 kann es NICHT, aeltere Modelle nur den
# ganzen Bus auf einmal. Ein Knopf, der nichts bewirkt, ist schlimmer als
# keiner - deshalb wird gemessen und gesagt, was geht.
# ======================================================================

def befehl(argv, zeit=15, eingabe=None):
    """Einen Befehl ausfuehren. Rueckgabe: (rc, ausgabe).

    argv ist IMMER eine Liste, niemals eine Zeichenkette, und es wird keine
    Shell dazwischengeschaltet. Damit kann kein Wert aus der Konfiguration -
    ein Geraetename, ein USB-Pfad - zu einem zweiten Befehl werden. Wer hier
    ein "sh -c" einbaut, oeffnet genau diese Tuer wieder.
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


def faehigkeiten() -> dict:
    """Was steht auf diesem Geraet zur Verfuegung?"""
    f = {"sudo": 0, "systemctl": 0, "docker": 0, "uhubctl": 0,
         "uhubctl_haehne": [], "sysfs": 0, "meldung": ""}

    rc, _ = befehl(["sudo", "-n", "true"], 5)
    f["sudo"] = 1 if rc == 0 else 0
    f["systemctl"] = 1 if befehl(["systemctl", "--version"], 5)[0] == 0 else 0
    f["docker"] = 1 if befehl(["docker", "--version"], 5)[0] == 0 else 0
    f["sysfs"] = 1 if os.path.isdir("/sys/bus/usb/drivers/usb") else 0

    rc, aus = befehl(["uhubctl"], 10)
    if rc == 127:
        f["meldung"] = "uhubctl ist nicht installiert."
    else:
        f["uhubctl"] = 1
        # uhubctl nennt in der Kopfzeile eines Hubs die Faehigkeit ppps -
        # "per-port power switching". Ohne sie schaltet nichts, auch wenn der
        # Aufruf mit 0 zurueckkommt.
        for zeile in aus.split("\n"):
            if "ppps" in zeile:
                m = re.search(r"hub\s+([0-9\-.]+)", zeile)
                if m:
                    f["uhubctl_haehne"].append(m.group(1))
        if not f["uhubctl_haehne"]:
            f["meldung"] = ("uhubctl laeuft, aber kein Hub dieses Geraets kann den "
                            "Portstrom schalten (ppps). Beim Raspberry Pi 4 ist das "
                            "normal - Stufe 3 bleibt hier wirkungslos.")
    return f


def systemgeraete() -> list:
    """USB-Kennungen, an denen / oder /boot haengen - die sind tabu.

    Ein unbind darauf legt ein von USB gebootetes System sofort still, und
    zwar dauerhaft. Lieber eine Heilung ablehnen als das.
    """
    tabu = []
    try:
        with open("/proc/mounts", "r", encoding="utf-8", errors="replace") as f:
            zeilen = f.readlines()
    except Exception:
        return tabu
    geraete = set()
    for z in zeilen:
        t = z.split()
        if len(t) >= 2 and t[1] in ("/", "/boot", "/boot/firmware") and t[0].startswith("/dev/"):
            geraete.add(os.path.basename(t[0]).rstrip("0123456789"))
    for name in geraete:
        pfad = "/sys/block/%s" % name
        try:
            ziel = os.path.realpath(pfad)
        except Exception:
            continue
        # .../usb1/1-1/1-1.1/1-1.1:1.0/host0/... -> die USB-Kennungen einsammeln
        for teil in ziel.split("/"):
            if re.match(r"^\d+-[\d.]+(:[\d.]+)?$", teil):
                tabu.append(teil)
    return sorted(set(tabu))


# ======================================================================
# Messen
# ======================================================================

def alter_datei(pfad):
    try:
        return max(0.0, time.time() - os.path.getmtime(pfad))
    except Exception:
        return None


def alter_http(adresse, zeit=5):
    """Antwortet die Adresse? Rueckgabe 0 (frisch) oder None (nichts)."""
    import urllib.request
    try:
        req = urllib.request.Request(adresse, headers={"User-Agent": "LoxBerry-Funkwacht"})
        with urllib.request.urlopen(req, timeout=zeit) as a:
            if 200 <= a.status < 400:
                return 0.0
    except Exception:
        return None
    return None


def alter_seriell(pfad):
    """NICHT oeffnen - nur nachsehen, ob der Knoten da ist.

    Ein Oeffnen naehme dem laufenden Dienst die Schnittstelle weg. Der
    Knoten verschwindet, wenn sich der Stick abmeldet - und genau das ist
    der Fall, den dieses Plugin fangen soll.
    """
    return 0.0 if os.path.exists(pfad) else None


def alter_bluetooth(kennung):
    k = (kennung or "hci0").strip() or "hci0"
    return 0.0 if os.path.isdir("/sys/class/bluetooth/%s" % k) else None


def mqtt_stand() -> dict:
    """Zeitstempel je Thema, den der Mithoerer abgelegt hat."""
    return json_lesen(os.path.join(pfade()["data"], "mqtt_stand.json"), {})


def alter_mqtt(thema):
    d = mqtt_stand()
    ts = d.get(thema)
    if not ts:
        return None
    return max(0.0, time.time() - float(ts))


def alter_messen(g):
    art = g["art"]
    if art == "datei":
        return alter_datei(g["pfad"])
    if art == "http":
        return alter_http(g["pfad"])
    if art == "seriell":
        return alter_seriell(g["pfad"])
    if art == "bluetooth":
        return alter_bluetooth(g["pfad"])
    if art == "mqtt":
        return alter_mqtt(g["thema"])
    return None


# ======================================================================
# Heilen
# ======================================================================

def heilen(g, stufe, tabu):
    """Eine Stufe ausfuehren. Rueckgabe: (ok, Beschreibung)."""
    if stufe == fw_pruef.STUFE_DIENST:
        if g["container"]:
            # Erst ohne sudo: gehoert loxberry zur Gruppe docker, genuegt das.
            rc, aus = befehl(["docker", "restart", g["container"]], 60)
            if rc != 0:
                rc, aus = befehl(["sudo", "-n", "docker", "restart", g["container"]], 60)
            return (1 if rc == 0 else 0), "docker restart %s -> %s" % (g["container"], rc)
        rc, aus = befehl(["sudo", "-n", "systemctl", "restart", g["dienst"]], 60)
        return (1 if rc == 0 else 0), "systemctl restart %s -> %s" % (g["dienst"], rc)

    if stufe == fw_pruef.STUFE_SYSFS:
        u = g["usb_pfad"]
        # Der Pfad geht als Text an einen Befehl, der als root laeuft. Was
        # nicht ins Muster passt, wird ABGEWIESEN und nicht zurechtgebogen:
        # ein zurechtgebogener Pfad haenge sonst ein anderes Geraet ab als
        # das gemeinte.
        if not re.match(r"^[0-9]+-[0-9]+(\.[0-9]+)*(:[0-9]+\.[0-9]+)?$", u or ""):
            return 0, ("ABGELEHNT: '%s' sieht nicht wie ein USB-Pfad aus "
                       "(erwartet etwa 1-1.4)." % u)
        if fw_pruef.ist_systemgeraet(u, tabu):
            return 0, ("ABGELEHNT: %s gehoert zu einem Geraet, auf dem das System "
                       "liegt (%s)." % (u, ", ".join(tabu)))
        # tee statt "sh -c echo > ...": ohne Shell gibt es keine Umleitung,
        # die man mit einem Semikolon verlaengern koennte. Der Pfad kommt
        # ueber die Standardeingabe, nicht als Teil einer Befehlszeile.
        rc1, a1 = befehl(["sudo", "-n", "tee", "/sys/bus/usb/drivers/usb/unbind"],
                         20, eingabe=u)
        time.sleep(2)
        rc2, a2 = befehl(["sudo", "-n", "tee", "/sys/bus/usb/drivers/usb/bind"],
                         20, eingabe=u)
        ok = 1 if (rc1 == 0 and rc2 == 0) else 0
        return ok, "sysfs unbind/bind %s -> %s/%s" % (u, rc1, rc2)

    if stufe == fw_pruef.STUFE_UHUBCTL:
        if not re.match(r"^[0-9]+(-[0-9]+(\.[0-9]+)*)?$", g["hub"] or ""):
            return 0, ("ABGELEHNT: '%s' sieht nicht wie eine Verteilerkennung aus "
                       "(erwartet etwa 1-1)." % g["hub"])
        rc1, a1 = befehl(["sudo", "-n", "uhubctl", "-l", g["hub"],
                          "-p", str(g["port"]), "-a", "off"], 30)
        time.sleep(3)
        rc2, a2 = befehl(["sudo", "-n", "uhubctl", "-l", g["hub"],
                          "-p", str(g["port"]), "-a", "on"], 30)
        ok = 1 if (rc1 == 0 and rc2 == 0) else 0
        return ok, "uhubctl %s.%d aus/ein -> %s/%s" % (g["hub"], g["port"], rc1, rc2)

    return 0, "unbekannte Stufe %s" % stufe


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


def mqtt_senden(paare, praefix):
    g = json_lesen(pfade()["general"], {})
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
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        for k, v in paare.items():
            if v is None or v == "":
                continue   # lieber nichts als eine erfundene 0
            zeile = "publish %s/%s %s" % (praefix, k, mqtt_wert_saeubern(v))
            s.sendto(zeile.encode("utf-8"), ("127.0.0.1", port))
        s.close()
        return True
    except Exception as e:
        log("MQTT: %s" % e, "mqtt_fehler")
        return False


# ======================================================================
# Ein Durchlauf
# ======================================================================

def durchlauf(cfg, stand, tabu):
    jetzt = time.time()
    neu = {"zeit": int(jetzt), "geraete": {}, "tabu": tabu,
           "ok": 1, "krank": 0, "geheilt_gesamt": int(stand.get("geheilt_gesamt", 0))}
    alt = stand.get("geraete", {})

    for nr, g in enumerate(cfg["geraete"], start=1):
        if g["name"] == "":
            continue
        e_alt = alt.get(str(nr), {})
        verlauf = [float(t) for t in e_alt.get("verlauf", [])][-50:]
        bisher = int(e_alt.get("stufe", 0))

        alter = alter_messen(g)
        entschluss = fw_pruef.entscheiden(g, alter, verlauf, bisher, jetzt)

        e = {"name": g["name"], "art": g["art"], "ok": entschluss["ok"],
             "grund": entschluss["grund"], "alter": (-1 if alter is None else int(alter)),
             "stufe": bisher, "verlauf": verlauf, "warum": entschluss["warum"],
             "letzte_tat": e_alt.get("letzte_tat", ""), "heilungen": int(e_alt.get("heilungen", 0))}

        if entschluss["ok"]:
            # Gesund heisst: die Eskalation faengt beim naechsten Mal wieder
            # bei null an. Ohne das bliebe ein Geraet nach einem einzigen
            # Ausrutscher fuer immer auf Stufe 3.
            e["stufe"] = 0
            e["warum"] = ""
        elif entschluss["aktion"]:
            ok, was = heilen(g, entschluss["aktion"], tabu)
            e["stufe"] = entschluss["aktion"]
            e["letzte_tat"] = "%s: %s" % (fw_pruef.STUFEN_NAMEN[entschluss["aktion"]], was)
            e["verlauf"] = (verlauf + [jetzt])[-50:]
            e["heilungen"] += 1
            neu["geheilt_gesamt"] += 1
            log("%s: %s (Grund: %s)" % (g["name"], e["letzte_tat"], entschluss["grund"]))
            mqtt_senden({"alarm": "%s: %s" % (g["name"], e["letzte_tat"])},
                        cfg["mqtt_topic"].strip("/"))
        else:
            log("%s gilt als gestoert (%s), es wird nicht geheilt: %s"
                % (g["name"], entschluss["grund"], entschluss["warum"]),
                "krank_%d" % nr)

        if not entschluss["ok"]:
            neu["krank"] += 1
            neu["ok"] = 0
        neu["geraete"][str(nr)] = e

    return neu


def veroeffentlichen(cfg, stand):
    if not cfg.get("mqtt_ein"):
        return
    paare = {"ok": stand["ok"], "krank": stand["krank"],
             "geraete": len(stand["geraete"]),
             "geheilt_gesamt": stand["geheilt_gesamt"],
             "alter": max(0, int(time.time()) - int(stand["zeit"]))}
    for nr, e in stand["geraete"].items():
        v = "geraet%s/" % nr
        paare[v + "name"] = e["name"]
        paare[v + "ok"] = e["ok"]
        paare[v + "grund"] = e["grund"]
        paare[v + "stufe"] = e["stufe"]
        paare[v + "heilungen"] = e["heilungen"]
    mqtt_senden(paare, cfg["mqtt_topic"].strip("/"))


def beenden(signum, rahmen):
    global laeuft
    laeuft = False


def main():
    global laeuft
    argv = sys.argv[1:]

    if "--selbsttest" in argv:
        n, f, text = fw_pruef.selbsttest()
        print(text)
        return 1 if f else 0

    if "--faehigkeit" in argv:
        f = faehigkeiten()
        f["systemgeraete"] = systemgeraete()
        print(json.dumps(f, ensure_ascii=False, indent=1))
        return 0

    signal.signal(signal.SIGTERM, beenden)
    signal.signal(signal.SIGINT, beenden)

    p = pfade()
    standdatei = os.path.join(p["data"], "stand.json")
    tabu = systemgeraete()
    if tabu:
        log("Systemgeraete, die nie angefasst werden: %s" % ", ".join(tabu))

    einmal = "--einmal" in argv
    while laeuft:
        cfg = config()
        stand = json_lesen(standdatei, {})
        neu = durchlauf(cfg, stand, tabu)
        json_schreiben(standdatei, neu)
        veroeffentlichen(cfg, neu)
        if einmal:
            return 0
        # In kleinen Schritten schlafen, damit ein Stoppsignal nicht bis zum
        # Ende des Takts warten muss.
        ende = time.time() + cfg["takt"]
        while laeuft and time.time() < ende:
            time.sleep(0.5)
    log("Dienst beendet.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
