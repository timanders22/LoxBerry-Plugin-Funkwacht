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

WARUM PAHO - UND WARUM ES BIS 1.0.1 ANDERS WAR
----------------------------------------------
Bis 1.0.1 baute diese Datei ihre MQTT-Haelfte selbst, mit der Begruendung,
paho sei auf einem LoxBerry nicht zugesichert und PEP 668 verbiete ein
systemweites pip3 install.

Gemessen am 07.09.2026, und beides trifft nicht zu: am Geraet meldet
`dpkg -l python3-paho-mqtt` die Fassung 2.1.0-1 als regulaeres
Debian-Paket, systemweit importierbar; fuenf Linien dieses Hauses binden es
ueber dpkg/apt genau so ein (APC-UPS, BLE-Scanner, Chromecast4lox,
Heimkino, Ultraschall), sechs weitere ueber eine venv. Ein Debian-Paket ist
kein pip - PEP 668 hat damit nichts zu tun. `python3-paho-mqtt` steht
seither auch in dpkg/apt dieses Plugins.

Was bleibt: es wird NICHTS veroeffentlicht - dieser Prozess ist ein
Zuhoerer, kein Sender. Wer ihn erweitert, sollte das im Kopf behalten: ein
Waechter, der auf dem Broker schreibt, kann den Broker stoeren, den er
ueberwacht.

Das Abonnement wird bei JEDER Verbindung neu gesetzt. paho fuehrt keinen
Abonnementspeicher; nach einem Broker-Neustart waere der Klient sonst
verbunden und auf nichts abonniert - schweigend.

WAS ER NICHT TUT
----------------
Er wertet den INHALT einer Nachricht nicht aus. Gemessen wird ausschliesslich,
DASS auf dem Thema etwas ankam - das ist das Lebenszeichen. Wer den Inhalt
braucht, liest ihn im Miniserver ueber das LoxBerry-Gateway; dieses Plugin
misst die Stille.

Aufrufe:
    fw_mqtt.py                 laeuft als Dienst
    fw_mqtt.py --probe 10      zehn Sekunden zuhoeren und berichten
    fw_mqtt.py --selbsttest    Themenvergleich und paho-Anbindung nachrechnen

Kompatibel mit Python 3.9 und 3.11.
"""

from __future__ import annotations

import json
import os
import signal
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import fw_pruef  # noqa: E402

FASSUNG = "1.0.2"
laeuft = True

# Die CONNACK-Codes von MQTT 3.1.1. paho reicht sie unveraendert durch,
# und sie sind das Erste, was man bei einem stummen Mithoerer wissen will.
CONNACK_TEXT = {
    0: "angenommen",
    1: "Protokollfassung abgelehnt",
    2: "Client-Kennung abgelehnt",
    3: "Broker nicht verfuegbar",
    4: "Benutzername oder Kennwort falsch",
    5: "nicht autorisiert",
}


# ======================================================================
# Themenvergleich - rein rechnerisch, deshalb pruefbar
# ======================================================================

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
    """Zuhoeren und aufschreiben, wann auf welchem Thema zuletzt etwas kam.

    Die Schnittstelle ist dieselbe wie vor 1.0.2 (verbinden, durchlauf,
    schliessen, .stand, .empfangen), damit main() unveraendert bleibt.
    """

    def __init__(self, zug, muster, keepalive=60):
        self.zug = zug
        self.muster = list(muster)
        self.keepalive = keepalive
        self.klient = None
        self.stand = {}
        self.empfangen = 0
        self.verbunden = False
        self.abbruch = None          # Grund, der kein Warten heilt

    # -- Netz ---------------------------------------------------------
    def verbinden(self):
        import paho.mqtt.client as mq

        # Die Rueckrufform wird ABGETASTET, nicht angenommen: paho 2.x
        # schreibt bei VERSION1 eine Verfallswarnung in jedes Protokoll,
        # paho 1.x kennt die Aufzaehlung gar nicht. Hausmuster, wie im
        # BLE-Scanner.
        try:
            self.klient = mq.Client(mq.CallbackAPIVersion.VERSION2,
                                    client_id=self.zug["kennung"])
        except (AttributeError, TypeError):
            try:
                self.klient = mq.Client(mq.CallbackAPIVersion.VERSION1,
                                        client_id=self.zug["kennung"])
            except (AttributeError, TypeError):
                self.klient = mq.Client(client_id=self.zug["kennung"])

        # ANMELDEN, nicht nur verbinden. Der Broker dieser Anlage weist
        # anonyme Verbindungen mit CONNACK 5 ab (am Geraet gemessen).
        if self.zug.get("user"):
            self.klient.username_pw_set(self.zug["user"], self.zug.get("pass") or "")

        def bei_verbindung(_c, _u, _f, rc, *_a):
            code = int(getattr(rc, "value", rc) or 0)
            if code != 0:
                # 4 und 5 sind falsche oder fehlende Zugangsdaten - die
                # behebt kein Warten, deshalb wird der Grund benannt und
                # der Dauerlauf abgebrochen statt im Kreis zu klopfen.
                self.verbunden = False
                self.abbruch = ("Der Broker weist ab: %s (Code %d)"
                                % (CONNACK_TEXT.get(code, "unbekannt"), code))
                return
            self.verbunden = True
            self.abbruch = None
            # BEI JEDER Verbindung abonnieren, nicht nur bei der ersten:
            # paho fuehrt keinen Abonnementspeicher. Nach einem
            # Broker-Neustart waere der Klient sonst verbunden und auf
            # nichts abonniert - und diese Datei misst dann die Stille
            # ihres eigenen Fehlers.
            for m in self.muster:
                self.klient.subscribe(m)

        def bei_nachricht(_c, _u, m):
            jetzt = time.time()
            self.empfangen += 1
            thema = m.topic
            for muster in self.muster:
                if thema_passt(muster, thema):
                    self.stand[muster] = jetzt
            # Das konkrete Thema zusaetzlich ablegen: bei einem Platzhalter
            # sieht man in der Oberflaeche sonst nie, WAS wirklich ankam.
            self.stand["#letztes"] = thema

        def bei_trennung(*_a, **_k):
            self.verbunden = False

        self.klient.on_connect = bei_verbindung
        self.klient.on_message = bei_nachricht
        self.klient.on_disconnect = bei_trennung
        self.klient.connect(self.zug["host"], self.zug["port"], self.keepalive)
        self.klient.loop_start()

        # Auf das CONNACK warten. Ohne das gaelte ein abgewiesener Klient
        # als verbunden, und der Dienst schwiege ueber den einzigen Grund,
        # den er kennt.
        ende = time.time() + 10
        while time.time() < ende and not self.verbunden and self.abbruch is None:
            time.sleep(0.05)
        if self.abbruch:
            raise OSError(self.abbruch)
        if not self.verbunden:
            raise OSError("Der Broker hat kein CONNACK geschickt.")

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
        """paho horcht in seinem eigenen Faden; hier wird nur geschrieben.

        Ein Verbindungsabriss ist KEIN Abbruch mehr: loop_start() baut die
        Verbindung selbst wieder auf, und on_connect abonniert dabei neu.
        Abgebrochen wird nur, was kein Warten heilt - eine Abweisung.
        """
        letzte_datei = 0.0
        while laeuft and (bis is None or time.time() < bis):
            if self.abbruch:
                raise OSError(self.abbruch)
            time.sleep(0.2)
            jetzt = time.time()
            # Hoechstens alle fuenf Sekunden schreiben: die Datei liegt
            # unter data/ und damit auf der Platte, nicht auf der Ramdisk.
            if self.stand and jetzt - letzte_datei > 5:
                self.stand_schreiben()
                letzte_datei = jetzt
        self.stand_schreiben()

    def schliessen(self):
        try:
            if self.klient:
                self.klient.loop_stop()
                self.klient.disconnect()
        except Exception:
            pass
        self.klient = None
        self.verbunden = False


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

    # ---------- paho ----------
    # Die Bibliothek ist die Grundlage dieses Dienstes seit 1.0.2. Wenn sie
    # fehlt, sagt das der Selbsttest - nicht erst der stumme Dienst.
    try:
        import paho.mqtt.client as _mq
        da = True
    except Exception:                                        # noqa: BLE001
        _mq = None
        da = False
    pr("paho-mqtt ist da", da, True)
    if da:
        k = None
        for versuch in ("v2", "v1", "alt"):
            try:
                if versuch == "v2":
                    k = _mq.Client(_mq.CallbackAPIVersion.VERSION2, client_id="probe")
                elif versuch == "v1":
                    k = _mq.Client(_mq.CallbackAPIVersion.VERSION1, client_id="probe")
                else:
                    k = _mq.Client(client_id="probe")
                break
            except (AttributeError, TypeError):
                continue
        pr("ein Klient laesst sich bauen", k is not None, True)
        # Die Anmeldung wird GESETZT, nicht nur gelesen: das war der Fehler
        # in Skoda-Connect-NG, und dieser Dienst darf ihn nicht erben.
        gesetzt = {"ja": False}
        if k is not None:
            try:
                k.username_pw_set("hans", "geheim")
                gesetzt["ja"] = True
            except Exception:                                # noqa: BLE001
                pass
        pr("username_pw_set laesst sich rufen", gesetzt["ja"], True)

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

    # ---------- Zugang ----------
    # Ein Zugang ohne Benutzer ist zulaessig (ein Broker ohne Anmeldung),
    # aber er darf nicht daran scheitern, dass die Schluessel fehlen.
    z = zugang()
    pr("der Zugang nennt einen Rechner", bool(z.get("host")), True)
    pr("der Zugang nennt einen Port", isinstance(z.get("port"), int), True)
    pr("der Zugang fuehrt ein Benutzerfeld", "user" in z, True)
    pr("der Zugang fuehrt ein Kennwortfeld", "pass" in z, True)

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
