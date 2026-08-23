#!/usr/bin/env python3
"""Funkwacht - der MQTT-Mithoerer.

Schreibt je abonniertem Thema den Zeitpunkt der letzten Nachricht nach
data/plugins/<ordner>/mqtt_stand.json. Genau diese Datei liest der Waechter,
wenn ein Stick auf die Art "MQTT" eingerichtet ist.

WARUM ES DIESE DATEI GIBT
-------------------------
Bis 0.9.3 las der Waechter mqtt_stand.json - und niemand schrieb sie. Ein als
"MQTT" eingerichteter Stick galt damit dauerhaft als "noch nie gehoert" und
wurde sechsmal am Tag grundlos neu gestartet. Die Art war deshalb in 0.9.4
aus der Auswahl genommen; mit diesem Mithoerer ist sie wieder da.

WARUM OHNE FREMDE BIBLIOTHEK
----------------------------
paho-mqtt gibt es auf einem LoxBerry nicht zwingend, und was nicht in
dpkg/apt steht, ist nicht zugesichert. PEP 668 verbietet ausserdem ein
systemweites pip3 install. Das hier gebrauchte Stueck von MQTT 3.1.1 ist
klein: verbinden, abonnieren, zuhoeren, am Leben bleiben. Es wird NICHTS
veroeffentlicht - dieser Prozess ist ein Zuhoerer, kein Sender. Wer ihn
erweitern will, sollte das im Kopf behalten: ein Waechter, der auf dem Broker
schreibt, kann den Broker stoeren, den er ueberwacht.

WAS ER NICHT TUT
----------------
Er wertet den INHALT einer Nachricht nicht aus. Gemessen wird ausschliesslich,
DASS auf dem Thema etwas ankam - das ist das Lebenszeichen. Wer den Inhalt
braucht, liest ihn im Miniserver ueber das LoxBerry-Gateway; dieses Plugin
misst die Stille.

Aufrufe:
    fw_mqtt.py                 laeuft als Dienst
    fw_mqtt.py --probe 10      zehn Sekunden zuhoeren und berichten
    fw_mqtt.py --selbsttest    Paketbau und Themenvergleich nachrechnen

Kompatibel mit Python 3.9 und 3.11.
"""

from __future__ import annotations

import json
import os
import signal
import socket
import struct
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import fw_pruef  # noqa: E402

FASSUNG = "1.0.0"
laeuft = True

# MQTT 3.1.1, nur die Pakete, die hier gebraucht werden.
CONNECT, CONNACK = 0x10, 0x20
PUBLISH, SUBSCRIBE, SUBACK = 0x30, 0x82, 0x90
PINGREQ, PINGRESP, DISCONNECT = 0xC0, 0xD0, 0xE0

CONNACK_TEXT = {
    0: "angenommen",
    1: "Protokollfassung abgelehnt",
    2: "Client-Kennung abgelehnt",
    3: "Broker nicht verfuegbar",
    4: "Benutzername oder Kennwort falsch",
    5: "nicht autorisiert",
}


# ======================================================================
# Paketbau - rein rechnerisch, deshalb pruefbar
# ======================================================================

def laenge_kodieren(n: int) -> bytes:
    """Die variable Laengenangabe von MQTT (7 Bit je Byte, Fortsetzungsbit)."""
    if n < 0 or n > 268435455:
        raise ValueError("Laenge ausserhalb des Bereichs: %d" % n)
    aus = bytearray()
    while True:
        b = n % 128
        n //= 128
        if n > 0:
            b |= 0x80
        aus.append(b)
        if n == 0:
            break
    return bytes(aus)


def laenge_lesen(hole_byte) -> int:
    """Die variable Laengenangabe zurueckrechnen. hole_byte() liefert ein Byte."""
    wert = 0
    faktor = 1
    for _ in range(4):
        b = hole_byte()
        wert += (b & 0x7F) * faktor
        if not (b & 0x80):
            return wert
        faktor *= 128
    raise ValueError("Laengenangabe laenger als vier Byte")


def text(s: str) -> bytes:
    """Eine Zeichenkette in MQTT-Form: zwei Byte Laenge, dann UTF-8."""
    b = str(s).encode("utf-8")
    if len(b) > 65535:
        raise ValueError("Zeichenkette zu lang")
    return struct.pack("!H", len(b)) + b


def connect_paket(kennung: str, benutzer: str = "", kennwort: str = "",
                  keepalive: int = 60) -> bytes:
    """Das CONNECT-Paket bauen.

    Clean Session ist gesetzt: dieser Zuhoerer will keine nachgelieferten
    Nachrichten aus einer alten Sitzung. Ein Waechter, der nach einem Neustart
    eine Stunde alte Nachricht als frisches Lebenszeichen zaehlt, misst die
    Vergangenheit.
    """
    flags = 0x02                       # Clean Session
    rumpf = text("MQTT") + bytes([4])  # Protokollname und -fassung 3.1.1
    if benutzer:
        flags |= 0x80
        if kennwort:
            flags |= 0x40
    rumpf += bytes([flags]) + struct.pack("!H", int(keepalive))
    rumpf += text(kennung)
    if benutzer:
        rumpf += text(benutzer)
        if kennwort:
            rumpf += text(kennwort)
    return bytes([CONNECT]) + laenge_kodieren(len(rumpf)) + rumpf


def subscribe_paket(themen, paket_nr: int = 1) -> bytes:
    """Das SUBSCRIBE-Paket bauen. QoS 0 - mehr braucht ein Lebenszeichen nicht."""
    if not themen:
        raise ValueError("Ein SUBSCRIBE ohne Thema ist nicht zulaessig")
    rumpf = struct.pack("!H", paket_nr)
    for t in themen:
        rumpf += text(t) + bytes([0])
    return bytes([SUBSCRIBE]) + laenge_kodieren(len(rumpf)) + rumpf


def thema_passt(muster: str, thema: str) -> bool:
    """Trifft ein abonniertes Muster dieses Thema?

    MQTT kennt zwei Platzhalter, und sie bedeuten Verschiedenes:
        +   genau eine Ebene
        #   diese und alle darunter, nur am Ende erlaubt

    Der Vergleich steht hier, weil der Mithoerer die Zeitstempel unter dem
    MUSTER ablegen muss - der Waechter kennt nur das, was in der Konfiguration
    steht, nicht das konkrete Thema, das eingetroffen ist.
    """
    m = str(muster or "").split("/")
    t = str(thema or "").split("/")
    for i, teil in enumerate(m):
        if teil == "#":
            # '#' muss am Ende stehen und trifft mindestens eine Ebene.
            return i == len(m) - 1 and len(t) >= i
        if i >= len(t):
            return False
        if teil == "+":
            continue
        if teil != t[i]:
            return False
    return len(t) == len(m)


# ======================================================================
# Pfade und Konfiguration - dieselbe Rechnung wie im Waechter
# ======================================================================

def eigener_ordner() -> str:
    """Der Ordnername, in dem dieses Skript wirklich liegt.

    Installiert steht es in bin/plugins/<ordner>/ - damit stimmt der Name auch
    dann, wenn LoxBerry wegen eines zweiten Plugins mit demselben FOLDER nach
    "funkwacht01" installiert hat. Im entpackten Archiv heisst der Ordner
    schlicht "bin"; dann greift der vorgesehene Name.
    """
    name = os.path.basename(os.path.dirname(os.path.abspath(__file__)))
    return "funkwacht" if name in ("bin", "plugins", "", ".", "/") else name


def pfade() -> dict:
    home = os.environ.get("LBHOMEDIR") or ""
    if not home or not os.path.isdir(home):
        d = os.path.dirname(os.path.abspath(__file__))
        for _ in range(8):
            if os.path.isdir(os.path.join(d, "config", "plugins")) \
                    and os.path.isdir(os.path.join(d, "webfrontend")):
                home = d
                break
            eltern = os.path.dirname(d)
            if eltern == d:
                break
            d = eltern
    ordner = os.environ.get("LBPPLUGINDIR") or eigener_ordner()
    data = os.path.join(home, "data", "plugins", ordner)
    return {
        "home": home,
        "config": os.path.join(home, "config", "plugins", ordner, "funkwacht.json"),
        "data": data,
        "stand": os.path.join(data, "mqtt_stand.json"),
        "log": os.path.join(home, "log", "plugins", ordner, "funkwacht.log"),
        "general": os.path.join(home, "config", "system", "general.json"),
    }


def json_lesen(pfad, vorgabe):
    try:
        with open(pfad, "r", encoding="utf-8") as f:
            d = json.load(f)
        return d if isinstance(d, type(vorgabe)) else vorgabe
    except Exception:
        return vorgabe


def log(zeile):
    p = pfade()["log"]
    try:
        if not os.path.isdir(os.path.dirname(p)):
            os.makedirs(os.path.dirname(p), exist_ok=True)
        with open(p, "a", encoding="utf-8") as f:
            f.write("[%s] Mithoerer: %s\n"
                    % (time.strftime("%Y-%m-%d %H:%M:%S"), zeile))
    except Exception:
        pass


def zugang() -> dict:
    """Broker-Zugang: erst aus der eigenen Konfiguration, sonst aus LoxBerry.

    Der LoxBerry fuehrt die Zugangsdaten seines Brokers in der general.json.
    Sie hier noch einmal einzutippen waere eine zweite Wahrheit; deshalb sind
    die eigenen Felder leer vorbelegt und gelten nur, wenn sie gefuellt sind.
    """
    c = json_lesen(pfade()["config"], {})
    g = json_lesen(pfade()["general"], {})
    m = g.get("Mqtt") or g.get("mqtt") or {}

    def aus_lb(*namen):
        for n in namen:
            if m.get(n) not in (None, ""):
                return str(m.get(n))
        return ""

    host = str(c.get("broker_host") or "").strip() or aus_lb("Brokerhost", "brokerhost") or "127.0.0.1"
    # Der LoxBerry schreibt den Broker gelegentlich als host:port.
    port = str(c.get("broker_port") or "").strip()
    if ":" in host and not port:
        host, _, p = host.rpartition(":")
        port = p
    return {
        "host": host or "127.0.0.1",
        "port": int(fw_pruef.zahl(port or aus_lb("Brokerport", "brokerport"), 1883)),
        "user": str(c.get("broker_user") or "").strip() or aus_lb("Brokeruser", "brokeruser"),
        "pass": str(c.get("broker_pass") or "").strip() or aus_lb("Brokerpass", "brokerpass"),
        "kennung": (str(c.get("broker_id") or "").strip() or "funkwacht-%d" % os.getpid())[:60],
    }


def themen() -> list:
    """Alle Themen, die in der Konfiguration als Kriterium stehen."""
    c = json_lesen(pfade()["config"], {})
    aus = []
    for g in (c.get("geraete") or []):
        if not isinstance(g, dict):
            continue
        if g.get("art") == "mqtt" and str(g.get("thema") or "").strip():
            aus.append(str(g["thema"]).strip())
        if g.get("art2") == "mqtt" and str(g.get("thema2") or "").strip():
            aus.append(str(g["thema2"]).strip())
    # Reihenfolge erhalten, Dubletten weg
    gesehen = set()
    return [t for t in aus if not (t in gesehen or gesehen.add(t))]


# ======================================================================
# Der Mithoerer selbst
# ======================================================================

class Mithoerer:
    def __init__(self, zug, muster, keepalive=60):
        self.zug = zug
        self.muster = list(muster)
        self.keepalive = keepalive
        self.sock = None
        self.puffer = b""
        self.stand = {}
        self.zuletzt_gesendet = 0.0
        self.empfangen = 0

    # -- Netz ---------------------------------------------------------
    def verbinden(self):
        self.sock = socket.create_connection((self.zug["host"], self.zug["port"]),
                                             timeout=10)
        self.sock.settimeout(1.0)
        self.sock.sendall(connect_paket(self.zug["kennung"], self.zug["user"],
                                        self.zug["pass"], self.keepalive))
        art, rumpf = self.paket_lesen(zeit=10)
        if art != CONNACK or len(rumpf) < 2:
            raise OSError("Der Broker hat kein CONNACK geschickt.")
        code = rumpf[1]
        if code != 0:
            raise OSError("Der Broker weist ab: %s (Code %d)"
                          % (CONNACK_TEXT.get(code, "unbekannt"), code))
        self.sock.sendall(subscribe_paket(self.muster))
        art, _ = self.paket_lesen(zeit=10)
        if art != SUBACK:
            raise OSError("Der Broker hat das Abonnement nicht bestaetigt.")
        self.zuletzt_gesendet = time.time()

    def byte(self, zeit):
        ende = time.time() + zeit
        while True:
            if self.puffer:
                b = self.puffer[0]
                self.puffer = self.puffer[1:]
                return b
            if time.time() > ende:
                raise socket.timeout("keine Daten")
            try:
                d = self.sock.recv(4096)
            except socket.timeout:
                continue
            if not d:
                raise OSError("Der Broker hat die Verbindung geschlossen.")
            self.puffer += d

    def paket_lesen(self, zeit=1.0):
        kopf = self.byte(zeit)
        laenge = laenge_lesen(lambda: self.byte(zeit))
        rumpf = b""
        while len(rumpf) < laenge:
            rumpf += bytes([self.byte(zeit)])
        return kopf & 0xF0, rumpf

    # -- Auswerten ----------------------------------------------------
    def publish_auswerten(self, kopf_flags, rumpf):
        if len(rumpf) < 2:
            return
        tl = struct.unpack("!H", rumpf[0:2])[0]
        thema = rumpf[2:2 + tl].decode("utf-8", "replace")
        jetzt = time.time()
        self.empfangen += 1
        for m in self.muster:
            if thema_passt(m, thema):
                self.stand[m] = jetzt
        # Das konkrete Thema zusaetzlich ablegen: bei einem Platzhalter sieht
        # man in der Oberflaeche sonst nie, WAS wirklich ankam.
        self.stand["#letztes"] = thema

    def stand_schreiben(self):
        p = pfade()
        try:
            if not os.path.isdir(p["data"]):
                os.makedirs(p["data"], exist_ok=True)
            tmp = "%s.tmp.%d" % (p["stand"], os.getpid())
            with open(tmp, "w", encoding="utf-8") as f:
                json.dump(self.stand, f, ensure_ascii=False, indent=1)
            os.replace(tmp, p["stand"])
            return True
        except Exception:
            return False

    # -- Hauptschleife ------------------------------------------------
    def durchlauf(self, bis=None):
        letzte_datei = 0.0
        while laeuft and (bis is None or time.time() < bis):
            try:
                art, rumpf = self.paket_lesen(zeit=1.0)
            except socket.timeout:
                art = None
            if art == PUBLISH:
                self.publish_auswerten(0, rumpf)
            elif art == DISCONNECT:
                raise OSError("Der Broker hat abgemeldet.")
            jetzt = time.time()
            if jetzt - self.zuletzt_gesendet > self.keepalive / 2:
                self.sock.sendall(bytes([PINGREQ, 0]))
                self.zuletzt_gesendet = jetzt
            # Hoechstens alle fuenf Sekunden schreiben: die Datei liegt unter
            # data/ und damit auf der Platte, nicht auf der Ramdisk.
            if self.stand and jetzt - letzte_datei > 5:
                self.stand_schreiben()
                letzte_datei = jetzt
        self.stand_schreiben()

    def schliessen(self):
        try:
            if self.sock:
                self.sock.sendall(bytes([DISCONNECT, 0]))
                self.sock.close()
        except Exception:
            pass
        self.sock = None


def beenden(signum, rahmen):
    global laeuft
    laeuft = False


# ======================================================================
# Selbsttest
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

    # ---------- Laengenangabe ----------
    pr("Laenge 0", laenge_kodieren(0), b"\x00")
    pr("Laenge 127 passt in ein Byte", laenge_kodieren(127), b"\x7f")
    pr("Laenge 128 braucht zwei", laenge_kodieren(128), b"\x80\x01")
    pr("Laenge 16383", laenge_kodieren(16383), b"\xff\x7f")
    pr("Laenge 16384", laenge_kodieren(16384), b"\x80\x80\x01")
    for n in (0, 1, 127, 128, 300, 16383, 16384, 2097151, 2097152):
        roh = laenge_kodieren(n)
        i = {"k": 0}

        def hole():
            b = roh[i["k"]]
            i["k"] += 1
            return b
        pr("Laenge %d hin und zurueck" % n, laenge_lesen(hole), n)

    # ---------- Zeichenketten ----------
    pr("Zeichenkette traegt ihre Laenge", text("MQTT"), b"\x00\x04MQTT")
    pr("Umlaute zaehlen in Byte, nicht in Zeichen", text("ae")[1], 2)
    pr("ein Umlaut ist zwei Byte", len(text("ä")) - 2, 2)

    # ---------- CONNECT ----------
    p = connect_paket("wacht", "", "", 60)
    pr("CONNECT beginnt mit 0x10", p[0], CONNECT)
    pr("CONNECT nennt das Protokoll", p[2:8], b"\x00\x04MQTT")
    pr("Protokollfassung 4 (das ist 3.1.1)", p[8], 4)
    pr("ohne Benutzer nur Clean Session", p[9], 0x02)
    pr("Keepalive steht drin", struct.unpack("!H", p[10:12])[0], 60)
    pu = connect_paket("wacht", "hans", "geheim")
    pr("mit Benutzer und Kennwort sind beide Flags gesetzt", pu[9], 0x02 | 0x80 | 0x40)
    pn = connect_paket("wacht", "hans", "")
    pr("mit Benutzer ohne Kennwort nur eines", pn[9], 0x02 | 0x80)
    pr("die Laengenangabe stimmt mit dem Rumpf ueberein", len(p) - 2, p[1])

    # ---------- SUBSCRIBE ----------
    s = subscribe_paket(["a/b", "c/#"], 7)
    pr("SUBSCRIBE traegt 0x82 (QoS 1 ist Pflicht)", s[0], SUBSCRIBE)
    pr("die Paketnummer steht vorn", struct.unpack("!H", s[2:4])[0], 7)
    pr("jedes Thema endet mit dem QoS-Byte", s[-1], 0)
    fehler = ""
    try:
        subscribe_paket([])
    except ValueError as e:
        fehler = "abgewiesen"
    pr("ein SUBSCRIBE ohne Thema wird abgewiesen", fehler, "abgewiesen")

    # ---------- Themenvergleich ----------
    pr("genaues Thema trifft", thema_passt("a/b/c", "a/b/c"), True)
    pr("anderes Thema trifft nicht", thema_passt("a/b/c", "a/b/d"), False)
    pr("kuerzeres Thema trifft nicht", thema_passt("a/b/c", "a/b"), False)
    pr("laengeres Thema trifft nicht", thema_passt("a/b/c", "a/b/c/d"), False)
    pr("+ trifft genau eine Ebene", thema_passt("a/+/c", "a/x/c"), True)
    pr("+ trifft keine zwei Ebenen", thema_passt("a/+/c", "a/x/y/c"), False)
    pr("+ trifft auch eine leere Ebene", thema_passt("a/+/c", "a//c"), True)
    pr("# trifft alles darunter", thema_passt("a/#", "a/b/c/d"), True)
    pr("# trifft auch die Ebene selbst", thema_passt("a/#", "a/b"), True)
    pr("# trifft nicht einen anderen Zweig", thema_passt("a/#", "b/c"), False)
    pr("# allein trifft alles", thema_passt("#", "irgendwas/tief"), True)
    pr("zigbee2mqtt/bridge/state, der Regelfall",
       thema_passt("zigbee2mqtt/bridge/state", "zigbee2mqtt/bridge/state"), True)
    pr("zigbee2mqtt/# trifft ein Geraetethema",
       thema_passt("zigbee2mqtt/#", "zigbee2mqtt/Bewegungsmelder/Flur"), True)
    pr("leeres Muster trifft nichts Sinnvolles", thema_passt("", "a"), False)

    # ---------- CONNACK-Texte ----------
    pr("Code 5 ist der, den man am haeufigsten sieht",
       CONNACK_TEXT[5], "nicht autorisiert")
    pr("jeder Code hat einen Text", sorted(CONNACK_TEXT), [0, 1, 2, 3, 4, 5])

    kopf = "Funkwacht-Mithoerer %s: %d Faelle geprueft, %d Fehlschlaege." % (
        FASSUNG, stand["n"], stand["f"])
    return stand["n"], stand["f"], kopf + "\n\n" + "\n".join(zeilen)


# ======================================================================
# Aufruf
# ======================================================================

def main():
    global laeuft
    argv = sys.argv[1:]

    if "--selbsttest" in argv:
        n, f, t = selbsttest()
        print(t)
        return 1 if f else 0

    probe = 0
    if "--probe" in argv:
        i = argv.index("--probe")
        probe = int(fw_pruef.zahl(argv[i + 1] if len(argv) > i + 1 else 10, 10))
        probe = max(2, min(60, probe))

    m = themen()
    if not m:
        text_aus = "Kein Stick ist auf die Art MQTT eingerichtet - es gibt nichts zu abonnieren."
        if probe:
            print(text_aus)
            return 0
        log(text_aus)
        return 0

    zug = zugang()
    if not probe:
        signal.signal(signal.SIGTERM, beenden)
        signal.signal(signal.SIGINT, beenden)

    if probe:
        print("Broker %s:%d, Kennung %s" % (zug["host"], zug["port"], zug["kennung"]))
        print("Abonniert: %s" % ", ".join(m))
        h = Mithoerer(zug, m)
        try:
            h.verbinden()
        except Exception as e:
            print("Verbindung nicht zustande gekommen: %s" % e)
            return 1
        print("Verbunden. %d Sekunden zuhoeren ..." % probe)
        try:
            h.durchlauf(bis=time.time() + probe)
        except Exception as e:
            print("Abbruch: %s" % e)
        h.schliessen()
        print("")
        print("%d Nachrichten empfangen." % h.empfangen)
        if h.stand.get("#letztes"):
            print("Zuletzt eingetroffen: %s" % h.stand["#letztes"])
        for t in m:
            wann = h.stand.get(t)
            print("  %-45s %s" % (t, "gehoert" if wann else "STILL"))
        print("")
        print("STILL heisst nicht kaputt: manche Themen kommen nur alle paar")
        print("Minuten. Laenger zuhoeren, oder das Hoechstalter grosszuegiger")
        print("ansetzen als den Sendetakt des Dienstes.")
        return 0

    # Dauerbetrieb mit Wiederanlauf. Die Wartezeit waechst, damit ein Broker,
    # der gar nicht da ist, nicht im Sekundentakt angeklopft wird.
    warte = 5
    log("gestartet, %d Thema/Themen: %s" % (len(m), ", ".join(m)))
    while laeuft:
        h = Mithoerer(zug, m)
        try:
            h.verbinden()
            log("verbunden mit %s:%d" % (zug["host"], zug["port"]))
            warte = 5
            h.durchlauf()
        except Exception as e:
            log("%s - neuer Versuch in %d s" % (e, warte))
        finally:
            h.schliessen()
        ende = time.time() + warte
        while laeuft and time.time() < ende:
            time.sleep(0.5)
        warte = min(300, warte * 2)
    log("beendet.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
