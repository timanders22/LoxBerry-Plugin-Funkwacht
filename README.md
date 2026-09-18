# Funkwacht

**Wächter für Funksticks am LoxBerry — Zigbee, Z-Wave, Bluetooth.**
Merkt, wenn ein Stick verstummt, weckt ihn in Stufen wieder auf — und misst
nach, ob es geholfen hat.

Version 1.0.5 · LoxBerry ab 3.0 · PHP 7.4 und 8.x · Python 3 mit paho-mqtt
(Debian-Paket, über `dpkg/apt`)

---

## Neu in 1.0.5

**Wer ein Signal bekommt, wird vorher geprüft — Argument für Argument.**
`bin/dienst.sh` erkannte den eigenen Dienst daran, dass *irgendeines* der
Argumente eines Prozesses auf `/funkwacht_dienst.py` endete. Das trifft auch
einen Prozess, der den Pfad nur nebenbei nennt. Am 18.09.2026 in einem
Linux-Prüfstand gemessen: ein fremder Prozess `python3 -c '…' <Dienstpfad>`,
dessen Nummer in `dienst.pid` stand, galt als Dienst — `status` meldete
`Waechter laeuft (PID 5978)`, und nach `stop` war er tot. Für den Mithörer
(`mithoerer.pid`, `fw_mqtt.py`) galt dasselbe.

Ein Treffer hat jetzt genau zwei Argumente: das erste ist ein Python, das
zweite ist genau der eigene Skriptpfad — bei relativem Start gegen das
Arbeitsverzeichnis *des Prozesses* aufgelöst, nicht gegen das eigene. Ein
Einmallauf (`--selbsttest`, `--einmal`, `--faehigkeit` …) trägt ein drittes
Argument und ist deshalb kein Dienst; `stop` fasst ihn nicht mehr an.

Daran hängen drei weitere Berichtigungen:

- **`stop` nimmt auch einen Dienst ohne PID-Datei mit.** Der Installer löscht
  `data/plugins/funkwacht/` bei jeder Aktualisierung, der Minutentakt kann in
  dieser Lücke einen zweiten Wächter starten. Gemessen: `stop` meldete
  „Funkwacht angehalten.“, und danach lief er weiter. Gesucht wird jetzt über
  `/proc`, eingegrenzt auf den Dienstbenutzer, und zusätzlich über die
  PID-Datei — die findet auch einen Dienst, der jemand anderem gehört.
- **`uninstall` prüft ebenfalls vor dem Signal.** Ist `bin/dienst.sh`
  vorhanden, räumt der Aufruf eine Zeile höher schon auf; fehlt es, stand
  dort bisher ein `kill -9` auf die blanke Nummer aus der PID-Datei. Mit
  unbrauchbarem `dienst.sh` gemessen: der fremde Prozess war tot.
- **Die Oberfläche sagt nicht mehr „läuft“, wenn ein Fremder läuft.**
  `fw_dienst_pid()` in `webfrontend/html/fw_lib.php` hielt jeden Prozess für
  den Dienst, dessen Befehlszeile den Dateinamen irgendwo trug. Sie schickt
  kein Signal, entscheidet aber, was nach „Dienst anhalten“ gemeldet wird.

`status` und `start` melden seitdem die **gefundenen** Prozessnummern statt
des Inhalts der PID-Datei, und `stop` sagt es, wenn doch etwas übrig bleibt.

**Der Minutentakt startet den Wächter nicht mehr mitten in ein Update.**
Zwischen dem Aufräumen durch den Installer und `postinstall.sh` liegt fast
eine Minute — am Gerät gemessen 03:31:32 bis 03:32:24. In dieser Lücke ist
`data/plugins/funkwacht/` gelöscht, und `cron/cron.01min` ruft trotzdem
`dienst.sh start`. Am 18.09.2026 im Linux-Prüfstand nachgestellt: der Wächter
lief an, schrieb `historie.json` mit leeren Zählern neu, und die Rettung in
`postinstall.sh` fand die Datei vor und übersprang sich. Aus 4711 geheilten
Sticks wurden 0. Im Wettlauf mit 200 Takten blieben am Ende **zwei** Wächter
laufen.

`preupgrade.sh` legt jetzt als Erstes `data/plugins/funkwacht.upgrade_laeuft`
mit der Unixzeit an — neben dem Datenordner, denn der Ordner selbst wird ja
gelöscht. Solange die Marke jünger als eine Stunde ist, startet `dienst.sh
start` nichts und endet trotzdem sauber. Älter, aus der Zukunft oder ohne
lesbare Uhrzeit: die Marke gilt nicht — eine abgebrochene Installation darf
den Wächter nicht für immer stilllegen. Lässt sich die *Systemuhr* nicht
lesen, fällt die Prüfung dagegen geschlossen aus: wer das Alter nicht messen
kann, startet nicht. `postinstall.sh` startet den Wächter mit einer
ausdrücklichen Ausnahme, `postupgrade.sh` räumt die Marke erst danach weg —
diese Reihenfolge ist gemessen, umgekehrt liefen zwei Dienste. `uninstall`
räumt die Marke ebenfalls weg.

Der Reiter *Test* beantwortet die Frage jetzt mit: die Selbstprüfung sagt, ob
eine Marke liegt und wie alt sie ist. Die Oberfläche wird **nicht** gesperrt:
dafür gibt es in dieser Linie keinen gemessenen Schaden, und eine Sperre ohne
Schaden nimmt dem Anwender nur die Seite.

Prüfstand samt Eichung: `Pruefung-Funkwacht-1.0.5/`.

## Neu in 1.0.4

**Die Deinstallation räumte nicht auf.** Der LoxBerry-Installer legt das
Deinstallationsskript unter `data/system/uninstall/funkwacht` ab und übergibt
ihm Ordnernamen und LoxBerry-Wurzel als Argumente. Das Skript leitete den
Ordnernamen aus seinem eigenen Ablageort ab und kam dort auf `system` statt
`funkwacht`. Folge: Wächter und Mithörer liefen nach dem Deinstallieren
weiter, und `funkwacht.backup.json` sowie `data/plugins/funkwacht.bestand`
blieben liegen. Ordnername und Wurzel kommen jetzt aus den Argumenten.
Gefunden am Govee-Plugin, dort am Gerät nachgerechnet.

## Neu in 1.0.3

Am LoxBerry durchgemessen (17.09.2026, installiert war 1.0.2). Behoben ist,
was dort gefunden wurde:

- **Die Heilrechte kommen jetzt wirklich an.** Bis 1.0.2 sollte
  `postinstall.sh` die Rechtedatei nach `/etc/sudoers.d/` legen — das Skript
  läuft aber als `loxberry` und durfte es nie. Am Gerät fehlte die Datei, der
  Reiter *Test* meldete `docker=nein uhubctl=nein tee=nein`, und geheilt wurde
  nichts außer einem Dienstneustart. Die Datei liegt jetzt als
  `sudoers/sudoers` im Archiv; LoxBerry legt sie bei der Installation selbst
  als root ab und räumt sie beim Deinstallieren wieder weg.
- **Eine abgewiesene Anmeldung am Broker nennt ihren Grund.** paho 2.x meldet
  falsche Zugangsdaten als Code 134, nicht 4 (am Gerät gemessen); der Mithörer
  schrieb „unbekannt (Code 134)". Beide Zählweisen stehen jetzt in der Tabelle.
- **Zustände gehen retained an den Broker** (Hausstandard): nach einem
  Neustart des Miniservers stehen sie sofort wieder da. Welche Themen
  retained sind, zeigt der Reiter *MQTT* in einer eigenen Spalte; das
  Lebenszeichen `ts` und alle Werte, die von selbst veralten, bleiben
  flüchtig.
- **Die Fassungsauskunft stimmt.** `faehigkeit.json` meldete am Gerät
  `"fassung": "1.0.0"` bei installierter 1.0.2; die Nummer kommt jetzt aus
  der Plugin-Datenbank des LoxBerry.
- **Kein `__pycache__` mehr im installierten Ordner**, und `preupgrade.sh`
  sagt, ob es einen laufenden Wächter angehalten hat.

## Neu in 1.0.2

- **Das Auswahlfeld zeichnet seinen Pfeil selbst.** Bis 1.0.1 kam er von der
  Oberfläche des LoxBerry. Am 05.09.2026 am Gerät gemessen (LoxBerry 4.0.0.15,
  `system/css/components.css`): deren Regel `.lb-content select`
  gibt es erst seit der neuen Oberfläche, und jede eigene Feldregel mit der
  Kurzform `background:` löscht sie wieder. Darauf soll sich eine
  Plugin-Oberfläche nicht verlassen (`Regeln/04`). Sonst ist an dieser
  Fassung nichts geändert.

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
Rechtedatei liegt als `sudoers/sudoers` im Archiv. LoxBerry kopiert sie bei
der Installation selbst, als root, nach `<LoxBerry>/system/sudoers/funkwacht`
— `/etc/sudoers.d` ist auf dem LoxBerry ein Verweis dorthin — und löscht sie
vor jedem Upgrade und beim Deinstallieren. `postinstall.sh` prüft danach mit
`visudo -c` und `sudo -n -l`, ob sie angekommen ist und greift.

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

* Reiter *Test*, Knopf **Selbstprüfung** — neunzehn Fragen mit Haken, Kreuz
  oder „hier lässt sich nichts messen".
* `python3 bin/funkwacht_dienst.py --selbsttest` — 115 Fälle: Rechenkern,
  Retain je Thema, Fassungsquelle; ohne Netz und ohne Geräte.
* `python3 bin/funkwacht_dienst.py --themen` — jedes gesendete Thema mit
  seinem Retain-Wert.
* `python3 bin/fw_mqtt.py --selbsttest` — 29 Fälle: Themenvergleich,
  paho-Anbindung, Anmeldegründe in beiden Zählweisen.
* `python3 bin/fw_suche.py --selbsttest` — 17 Fälle der Suchhilfe.
* `python3 bin/funkwacht_dienst.py --trocken` — was würde jetzt geschehen?
* `python3 bin/funkwacht_dienst.py --heile 2:1` — Stick 2, Stufe 1, von Hand.
* `python3 bin/fw_mqtt.py --probe 10` — zehn Sekunden am Broker zuhören.
* `python3 bin/funkwacht_dienst.py --faehigkeit` — was dieses Gerät kann.

## Ordner

```
bin/            Rechenkern, Wächter, Mithörer, Suchhilfe, Meldebrücke,
                Fassungsauskunft, Startskript
sudoers/        die Rechtedatei — LoxBerry legt sie als root ab
cron/           Minutentakt — startet die Dienste, falls sie stehen
dpkg/apt        uhubctl und python3-paho-mqtt (installiert LoxBerry als root)
templates/      Sprachdateien und Hilfe
webfrontend/    html = Endpunkt für den Miniserver, htmlauth = Oberfläche
uninstall/      räumt die Sicherungen neben den Ordnern weg
```

Die Sprachdateien werden aus einer Quelle erzeugt:
`Werkzeuge/fw_sprache_erzeugen.py` im Arbeitsordner. Wer eine von Hand
ändert, zieht den Erzeuger im selben Zug mit.
