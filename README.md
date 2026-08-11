# Funkwacht

**Wächter für Funksticks am LoxBerry — Zigbee, Z-Wave, Bluetooth.**
Merkt, wenn ein Stick verstummt, und weckt ihn in Stufen wieder auf.

Version 0.9.1 · LoxBerry ab 3.0 · PHP 7.4 und 8.x · Python 3

---

## Wofür

Funksticks fallen selten laut aus. Sie hören auf zu antworten: der Dienst
läuft weiter, die Oberfläche sieht normal aus, und erst Tage später fällt
auf, dass kein Bewegungsmelder mehr meldet. Die Funkwacht sieht in festem
Takt nach, ob noch etwas ankommt — und wenn nicht, tut sie etwas dagegen.

## Was sie nicht tut

**Sie öffnet niemals selbst eine serielle Schnittstelle.** Eine solche
Schnittstelle kann nur ein Programm gleichzeitig halten. Ein Wächter, der
`/dev/ttyUSB0` öffnet, um nachzusehen, ob der Stick lebt, nimmt sie
Zigbee2MQTT weg und erzeugt genau den Ausfall, den er verhindern soll.
Gemessen wird deshalb an dem, was der Dienst hinterlässt: dem
Änderungsdatum einer Protokolldatei, dem Zeitpunkt der letzten
MQTT-Nachricht, der Erreichbarkeit einer Weboberfläche. Bei der Art
„seriell“ wird nur geprüft, ob die Gerätedatei existiert.

**Sie fasst kein Gerät an, auf dem das System liegt.** Vor jedem Durchlauf
werden `/proc/mounts` und `/sys/block/*` gelesen und die USB-Geräte
ermittelt, die `/` oder `/boot` tragen. Wer von einer USB-SSD startet — und
das tun viele LoxBerry-Installationen —, wäre sonst einen Tippfehler von
einem abgehängten Wurzeldateisystem entfernt. Ein Heilversuch auf ein
solches Gerät wird abgelehnt und protokolliert, auch wenn er ausdrücklich
eingetragen wurde.

**Der Endpunkt schaltet nichts.** Die Adressen für den Miniserver geben
ausschließlich Auskunft. Das Wortzeichen steht in der Adresse und ist damit
im Netz sichtbar; für eine Auskunft reicht das, für einen Hebel, der den
USB-Bus zurücksetzt, nicht.

## Die drei Stufen

| Stufe | Mittel | Hilft bei |
|---|---|---|
| 1 | `systemctl restart` / `docker restart` | hängender Dienst, gesunder Stick |
| 2 | unbind/bind über `/sys/bus/usb/drivers/usb` | festgefahrener Stick — entspricht Aus- und Einstecken |
| 3 | `uhubctl` nimmt dem Anschluss den Strom | abgestürzter Stick, der auf nichts mehr reagiert |

Übersprungen wird jede Stufe, für die nichts eingetragen ist. Sobald ein
Stick wieder gesund ist, beginnt die Zählung von vorn.

### Stufe 3 verspricht mehr, als die meisten Verteiler halten

Portstrom schalten kann nur ein Verteiler mit *per-port power switching*.
**Der eingebaute Verteiler des Raspberry Pi 4 kann es nicht.** Ältere
Modelle bestenfalls den ganzen Bus — womit Tastatur und Festplatte
mitgingen. Der Reiter *Test* misst deshalb nach und nennt genau die
Verteiler, die es wirklich können. Taucht dort keiner auf, ist Stufe 3 auf
diesem Gerät wirkungslos; ein Knopf, der nichts bewirkt, ist schlimmer als
keiner.

## Was nach Loxone geht

Zwei Wege, beide gleichzeitig nutzbar: MQTT über das LoxBerry-Gateway und
eine Adresse, die der Miniserver abfragt. Die Vorlage im Reiter *Einbindung
in Loxone* bringt je Stick vier Eingänge (`OK`, `STUFE`, `ALTER`,
`HEILUNGEN`) und dazu fünf Summenwerte.

`ALTER` geht bis −1 hinunter: −1 heißt „noch nie ein Lebenszeichen“ und ist
etwas anderes als 0 („gerade eben gehört“). Ein nie angeschlossener Stick
soll im Baustein nicht wie ein kerngesunder aussehen.

Am nützlichsten ist `GEHEILT`: Steigt der Zähler über Wochen langsam an,
ist ein Stick am Ende seiner Kräfte, lange bevor er ganz ausfällt.

## Rechte

Zum Heilen braucht der Wächter `sudo` für genau vier Dinge: `systemctl
restart`, `docker restart`, `uhubctl` und ein `tee` auf die beiden
sysfs-Dateien. Die Rechtedatei legt `postinstall.sh` unter
`/etc/sudoers.d/funkwacht` ab und prüft sie mit `visudo -c`; ist sie
fehlerhaft, wird sie sofort wieder entfernt — eine kaputte Datei dort legt
sämtliche `sudo`-Aufrufe des Systems lahm.

Ohne diese Datei läuft das Plugin weiter und **meldet**, heilt aber nichts.
Der Reiter *Test* sagt es in der ersten Zeile.

Es gibt bewusst kein `sh -c` in der Befehlskette: kein Wert aus der
Konfiguration soll zu einem zweiten Befehl werden können. Der USB-Pfad und
die Verteilerkennung werden zusätzlich gegen ein Muster geprüft und bei
Abweichung **abgewiesen**, nicht zurechtgebogen.

## Prüfstand

* `python3 bin/funkwacht_dienst.py --selbsttest` — 38 Fälle des
  Rechenkerns, ohne Netz und ohne Geräte.
* `python3 bin/funkwacht_dienst.py --faehigkeit` — was dieses Gerät kann,
  als JSON.
* `python3 bin/funkwacht_dienst.py --einmal` — ein Durchlauf im Vordergrund.

Die Oberfläche ist gegen PHP 7.4.33 und 8.2.32 gerendert worden: alle fünf
Reiter, ohne Meldung, ohne unübersetzten Schlüssel, `sm-active`
serverseitig gesetzt.

## Ordner

```
bin/            Rechenkern, Dienst, Startskript, sudoers-Vorlage
cron/           Minutentakt — startet den Dienst, falls er steht
dpkg/apt        uhubctl (wird von LoxBerry als root installiert)
templates/      Sprachdateien und Hilfe
webfrontend/    html = Endpunkt für den Miniserver, htmlauth = Oberfläche
uninstall/      räumt auch /etc/sudoers.d/funkwacht weg
```
