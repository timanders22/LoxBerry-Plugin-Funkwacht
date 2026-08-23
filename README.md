# Funkwacht

**Wächter für Funksticks am LoxBerry — Zigbee, Z-Wave, Bluetooth.**
Merkt, wenn ein Stick verstummt, weckt ihn in Stufen wieder auf — und misst
nach, ob es geholfen hat.

Version 1.0.0 · LoxBerry ab 3.0 · PHP 7.4 und 8.x · Python 3 · keine fremden
Bibliotheken

---

## Wofür

Funksticks fallen selten laut aus. Sie hören auf zu antworten: der Dienst
läuft weiter, die Oberfläche sieht normal aus, und erst Tage später fällt
auf, dass kein Bewegungsmelder mehr meldet. Die Funkwacht sieht in festem
Takt nach, ob noch etwas ankommt — und wenn nicht, tut sie etwas dagegen.

## Woran sie ein Lebenszeichen abliest

| Art | Gemessen wird | Trennt |
|---|---|---|
| **Datei** | Änderungsdatum, wahlweise nur ein **Wachstum** der Datei | — |
| **MQTT** | Zeitpunkt der letzten Nachricht auf einem Thema | — |
| **HTTP** | antwortet eine Adresse (ohne Weiterleitungen zu folgen) | — |
| **USB** | hängt der Stick überhaupt noch am Bus | **Stick weg** von **Dienst hängt** |
| **seriell** | ist die Gerätedatei da (sie wird **nicht** geöffnet) | — |
| **systemd-Dienst** | läuft die Einheit, und wie oft ist sie neu gestartet | flatternder Dienst |
| **Docker** | läuft der Container, was sagt sein Healthcheck | flatternder Container |
| **Bluetooth** | ist der Adapter angemeldet | — |

**Je Stick lassen sich zwei davon verknüpfen.** „USB vorhanden **und** MQTT
frisch" trennt sauber zwischen den beiden Fehlerbildern — und erlaubt, gleich
die richtige Stufe zu ziehen statt bei 1 anzufangen.

Beim Wachstum lohnt ein Blick: manche Dienste fassen ihre Protokolldatei auch
dann an, wenn sie nichts mehr zu melden haben. Dann ist das Änderungsdatum
frisch und der Stick trotzdem tot.

Und der Neustartzähler von systemd oder Docker ist der einzige Wert, der einen
Dienst verrät, den das System alle drei Minuten neu startet: nach dem
Änderungsdatum seines Protokolls sieht der kerngesund aus.

## Was sie nicht tut

**Sie öffnet niemals selbst eine serielle Schnittstelle.** Eine solche
Schnittstelle kann nur ein Programm gleichzeitig halten. Ein Wächter, der
`/dev/ttyUSB0` öffnet, um nachzusehen, ob der Stick lebt, nimmt sie
Zigbee2MQTT weg und erzeugt genau den Ausfall, den er verhindern soll.

**Sie fasst kein Gerät an, auf dem das System liegt.** In jedem Durchlauf
werden `/proc/mounts` und `/sys/block/*` gelesen. Wer von einer USB-SSD
startet — und das tun viele LoxBerry-Installationen —, wäre sonst einen
Tippfehler von einem abgehängten Wurzeldateisystem entfernt. Der Reiter *Test*
zeigt diese Geräte in der USB-Liste **sichtbar gesperrt**, nicht erst später
abgelehnt.

**Sie heilt nicht, wenn noch nie ein Lebenszeichen kam.** „Seit langem stumm"
und „noch nie gehört" sind zwei verschiedene Zustände. Der zweite ist fast
immer ein Eintragungsfehler, und ein USB-Reset wäre darauf die falsche
Antwort.

**Der Endpunkt hat keinen Heilbefehl, und er legt nichts an.** Das
Wortzeichen steht in der Adresse und ist damit im Netz sichtbar; für eine
Auskunft reicht das, für einen Hebel, der den USB-Bus zurücksetzt, nicht.

## Die drei Stufen — und was danach kommt

| Stufe | Mittel | Hilft bei |
|---|---|---|
| 1 | `systemctl restart` / `docker restart` | hängender Dienst, gesunder Stick |
| 2 | unbind/bind über `/sys/bus/usb/drivers/usb` | festgefahrener Stick |
| 3 | `uhubctl` nimmt dem Anschluss den Strom | abgestürzter Stick |

Übersprungen wird jede Stufe, für die nichts eingetragen ist. **Die
Reihenfolge ist einstellbar:** bei manchen Aufbauten bringt ein Dienstneustart
nichts, solange der Stick selbst festgefahren ist — dort ist 2-1-3 richtig.

**Nach Stufe 2 oder 3 kommt der Dienstneustart gleich hinterher.** Ein
unbind/bind lässt den Dienst sonst mit einem toten Dateideskriptor zurück: der
Stick ist da, aber Zigbee2MQTT redet nicht mehr mit ihm.

**Und danach wird nachgemessen.** Nach der Erholungszeit prüft der Wächter, ob
der Stick wirklich zurück ist, und zählt das Ergebnis **je Stufe**. Damit
beantwortet das Plugin die Frage, die es vorher offenließ: wirkt das Heilen
überhaupt? Die Antwort steht im Reiter *Test* — „Stufe 1 half 12 von 14 Mal,
Stufe 2 nie".

### Stufe 3 verspricht mehr, als die meisten Verteiler halten

Portstrom schalten kann nur ein Verteiler mit *per-port power switching*.
**Der eingebaute Verteiler des Raspberry Pi 4 kann es nicht.** Der Reiter
*Test* misst nach und nennt genau die Verteiler, die es wirklich können.

## Sechs Bremsen, und jede beantwortet eine andere Frage

| Bremse | Frage |
|---|---|
| **Erholung** | Ist nach einem Versuch überhaupt schon etwas zu erwarten? |
| **Abstand** | Wie lange mindestens zwischen zwei Versuchen? |
| **Versuche je Tag** | Wann ist Schluss mit dem Versuchen? |
| **Anlaufschonzeit** | Ist das System gerade erst hochgefahren? |
| **Ruhefenster** | Darf um diese Uhrzeit etwas neu gestartet werden? |
| **Wartung** | Steht gerade jemand am Gerät? |

Die Wartung geht **von selbst wieder aus** — ein Schalter, den jemand von Hand
setzt, wird vergessen. Dazu kommt ein globaler „nur melden"-Schalter für einen
Umbau; der bleibt, bis ihn jemand zurücknimmt, und die Oberfläche sagt das
oben in einem Kasten.

## Was nach Loxone geht

Zwei Wege, beide gleichzeitig nutzbar: MQTT über das LoxBerry-Gateway und eine
Adresse, die der Miniserver abfragt. **Beide tragen dieselben Werte** — mit
einer Ausnahme mit Grund: über MQTT geht statt des Alters der **Zeitstempel**
hinaus, denn ein Alter wäre beim Senden immer null.

Je Stick dreizehn Eingänge, dazu zehn Summenwerte. Der Reiter *Einbindung in
Loxone* enthält beide Vorlagen zum Einlesen und die **komplette
Baustein-Liste** zum Nachbauen.

**Die Nummer eines Sticks ist seine Zeilennummer.** Zeile 3 heißt in Loxone
immer `G3…`, auch wenn Zeile 1 und 2 leer sind — eine geleerte Zeile behält
ihren Platz.

Am nützlichsten ist `HEIL7T`: die gelungenen Heilungen der letzten sieben
Tage. Steigt die Zahl über Wochen langsam an, ist ein Stick am Ende seiner
Kräfte, lange bevor er ganz ausfällt. Gehen `VERSUCHE` und `HEILUNGEN` weit
auseinander, wirkt das Heilen nicht — dann hilft kein weiterer Neustart,
sondern ein Blick auf die Rechte.

### Zwei Befehle darf Loxone schicken

`quittieren` setzt Bremse und Eskalationsstand zurück — nützlich, wenn ein
Stick auf „Tagesgrenze" steht und jemand nachgesehen hat. `wartung` hält das
Heilen für eine Weile an. Beide **schalten am Gerät nichts**; das eine erlaubt
dem Wächter wieder zu urteilen, das andere hält ihn an. Dazu beantwortet der
Endpunkt `?selftest=1`, ohne etwas auszulösen.

## Einrichten, ohne zu raten

Der Reiter *Test* listet auf Knopfdruck die **USB-Geräte** dieses Rechners —
mit Kennung, Hersteller, Produkt, Verteiler, Anschluss und Gerätedatei, und
mit sichtbarer Sperre für die Systemgeräte. Dazu die **systemd-Dienste**, die
nach Funk aussehen, und die **Docker-Container**.

Im Reiter *Einstellungen* füllt ein Klick eine Zeile mit **fertigen
Vorschlagswerten** für die bekannten Fälle. Diese Pfade sind Vorschläge aus
der Erfahrung, keine Messwerte — deshalb steht neben jeder Zeile ein Knopf
**„Jetzt messen"**, der genau diese eine Zeile misst und das Ergebnis sofort
zeigt.

Und weil man das Heilen sonst nur durch einen echten Ausfall prüfen kann, gibt
es zwei Wege: **„Was würde jetzt geschehen?"** druckt je Stick das Urteil und
den vollständigen Befehl aus, ohne etwas zu tun — und ein **Heilversuch von
Hand**, der wirklich schaltet und danach nachmisst.

## Melden, wenn niemand hinsieht

Beim **Wechsel** des Befundes — und mit Entwarnung — geht eine Meldung ins
LoxBerry-Benachrichtigungszentrum und wahlweise an SignalBot. Eine Meldung je
Minute wäre keine Meldung, sondern Rauschen.

Der Reiter *Logdateien* führt außerdem eine **Ereignisliste** mit Zeitpunkten
und ein **Balkenbild der Heilungen je Tag** über dreißig Tage. Die
Tagesdateien liegen unter `data/` und überstehen einen Neustart.

**Und sie überstehen auch ein Update — aber nicht von selbst.** Der Installer
von LoxBerry räumt `data/plugins/<ordner>/` bei **jeder** Aktualisierung
vollständig ab; gemessen an `sbin/plugininstall.pl`, wo `purge_installation`
nicht nur im Deinstallations-, sondern auch im Upgrade-Zweig steht. Deshalb
legt `preupgrade.sh` Zähler und Verlauf vorher **neben** den Ordner, nach
`data/plugins/<ordner>.bestand`, und `postinstall.sh` holt sie zurück. Der
Punkt im Namen ist kein Zufall: ein `rm -rf <ordner>/` trifft den Nachbarn
nicht. Die Selbstprüfung fragt vorher nach, ob das gelingen wird — statt es
beim nächsten Update zu erfahren.

## Der MQTT-Mithörer

Die Art „MQTT" braucht jemanden, der die Zeitstempel je Thema mitschreibt.
Das tut `bin/fw_mqtt.py`, ein eigener kleiner Prozess: er verbindet sich mit
dem Broker, abonniert die eingetragenen Themen und merkt sich, **dass** etwas
ankam — den Inhalt wertet er nicht aus.

Er kommt **ohne fremde Bibliothek** aus. `paho-mqtt` gibt es auf einem
LoxBerry nicht zwingend, was nicht in `dpkg/apt` steht ist nicht zugesichert,
und PEP 668 verbietet ein systemweites `pip3 install`. Das gebrauchte Stück
von MQTT 3.1.1 ist klein: verbinden, abonnieren, zuhören, am Leben bleiben.
Geschrieben wird auf dem Broker **nichts** — ein Wächter, der dort sendet,
kann den Broker stören, den er überwacht.

Die Zugangsdaten sind **leer richtig**: dann gelten die, die LoxBerry für
seinen Broker führt.

## Rechte

Zum Heilen braucht der Wächter `sudo` für fünf Dinge: `systemctl restart`,
`docker restart`, `uhubctl` mit Argumenten, `uhubctl` ohne Argumente (das
zählt nur die Verteiler auf) und ein `tee` auf die beiden sysfs-Dateien. Die
Rechtedatei legt `postinstall.sh` unter `/etc/sudoers.d/funkwacht` ab und
prüft sie mit `visudo -c`; ist sie fehlerhaft, wird sie sofort wieder
entfernt.

Ohne diese Datei läuft das Plugin weiter und **meldet**, heilt aber nichts.
Der Reiter *Test* sagt es **je Befehl einzeln** — ein pauschales
`sudo -n true` beantwortet eine andere Frage.

Es gibt bewusst kein `sh -c` in der Befehlskette. Der USB-Pfad und die
Verteilerkennung werden gegen ein Muster geprüft und bei Abweichung
**abgewiesen**, nicht zurechtgebogen. Jedes Formular der Oberfläche trägt ein
aus dem Wortzeichen abgeleitetes Merkmal, und ein Wachposten prüft es vor
allen Handlern.

**Drei Knöpfe verlangen ein Häkchen**, weil sich ihre Wirkung nicht
zurücknehmen lässt: ein neues Wortzeichen, das Verwerfen der Stufenstatistik
und das Zurückspielen einer Sicherung. Ohne Häkchen geschieht **nichts**, und
die Oberfläche sagt das auch — ein Knopf, der stumm nichts tut, wäre
schlimmer. Geprüft wird das Häkchen **vor** allem anderen; einen halb
zurückgespielten Zustand kann es damit nicht geben. Daneben steht jeweils,
was dabei **nicht** geschieht — die Angst vor dem Knopf ist sonst größer als
sein Schaden.

## Prüfstand

* Reiter *Test*, Knopf **Selbstprüfung** — siebzehn Fragen mit Haken, Kreuz
  oder „hier lässt sich nichts messen".
* `python3 bin/funkwacht_dienst.py --selbsttest` — 104 Fälle des Rechenkerns,
  ohne Netz und ohne Geräte.
* `python3 bin/fw_mqtt.py --selbsttest` — 45 Fälle Paketbau und
  Themenvergleich.
* `python3 bin/fw_suche.py --selbsttest` — 17 Fälle der Suchhilfe.
* `python3 bin/funkwacht_dienst.py --trocken` — was würde jetzt geschehen?
* `python3 bin/funkwacht_dienst.py --heile 2:1` — Stick 2, Stufe 1, von Hand.
* `python3 bin/fw_mqtt.py --probe 10` — zehn Sekunden am Broker zuhören.
* `python3 bin/funkwacht_dienst.py --faehigkeit` — was dieses Gerät kann.

## Ordner

```
bin/            Rechenkern, Wächter, Mithörer, Suchhilfe, Meldebrücke,
                Startskript, sudoers-Vorlage
cron/           Minutentakt — startet die Dienste, falls sie stehen
dpkg/apt        uhubctl (wird von LoxBerry als root installiert)
templates/      Sprachdateien und Hilfe
webfrontend/    html = Endpunkt für den Miniserver, htmlauth = Oberfläche
uninstall/      räumt auch /etc/sudoers.d/funkwacht weg
```

Die Sprachdateien werden aus einer Quelle erzeugt:
`Werkzeuge/fw_sprache_erzeugen.py` im Arbeitsordner. Wer eine von Hand
ändert, zieht den Erzeuger im selben Zug mit.
