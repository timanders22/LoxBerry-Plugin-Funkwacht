#!/bin/bash
# Funkwacht - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Die Reihenfolge des Installers ist:
#   preupgrade -> config/* aus dem Archiv ueber config/plugins/<ordner>
#              -> postinstall -> postupgrade -> Cleaning
# Wer eine Konfiguration ueber das Upgrade retten will, muss das VOR dem
# Kopierschritt tun, also hier - und nicht nach /tmp, das auf dem LoxBerry
# fluechtig ist.
#
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
# Zufallskennung aus &generate(10). Der absolute Arbeitsordner steht im
# sechsten Argument. Deshalb wird hier ausschliesslich mit $3 und $5
# gearbeitet.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-${LBPPLUGINDIR:-funkwacht}}"
BASE="${ARGV5:-${LBHOMEDIR:-}}"
# Die Wurzel wird GELESEN: $5 vom Installer, sonst $LBHOMEDIR - und dann
# nur diese; traegt sie kein config/plugins und data/plugins, geschieht
# nichts. Sind beide leer, wird vom eigenen Ablageort aufwaerts gesucht, bis
# ein Verzeichnis config/plugins, data/plugins UND config/system/general.json
# traegt (Regeln/06, Raumklima-Vorfall). Danach KEIN Rueckfall auf feste
# Ebenen. Bis 1.0.6 stand hier ein Rueckfall ueber dem eigenen Ablageort,
# ohne general.json und ohne Abbruch; in einem fremden Baum wurde dort
# angelegt bzw. geloescht (Pruefung-Funkwacht-1.0.6/messe_haken.sh, Faelle
# K1, K4, K6, K8).
fw_wurzel_suchen() {
    fw_v=$(cd "$1" 2>/dev/null && pwd -P) || return 1
    fw_i=0
    while [ -n "$fw_v" ] && [ "$fw_v" != "/" ] && [ "$fw_i" -lt 8 ]; do
        if [ -d "$fw_v/config/plugins" ] && [ -d "$fw_v/data/plugins" ] \
           && [ -f "$fw_v/config/system/general.json" ]; then
            echo "$fw_v"
            return 0
        fi
        fw_v=$(dirname "$fw_v")
        fw_i=$((fw_i + 1))
    done
    return 1
}
if [ -z "$BASE" ]; then
    BASE=$(fw_wurzel_suchen "$(dirname "$(readlink -f "$0")")") || BASE=""
elif [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    echo "<FAIL> $BASE (fuenftes Argument bzw. \$LBHOMEDIR) traegt kein config/plugins und data/plugins."
    BASE=""
fi
if [ -z "$BASE" ]; then
    echo "<FAIL> Es wurde keine LoxBerry-Wurzel gelesen oder gefunden: weder als fuenftes"
    echo "<FAIL> Argument noch in \$LBHOMEDIR, und oberhalb von $(dirname "$0") traegt kein"
    echo "<FAIL> Verzeichnis config/plugins, data/plugins und config/system/general.json."
    echo "<FAIL> Es wurde nichts angelegt, nichts geloescht und kein Dienst angefasst."
    exit 2
fi
case "$PFOLDER" in
    ''|.|..|*/*)
        echo "<FAIL> Ungueltiger Ordnername '$PFOLDER' - es wurde nichts angefasst."
        exit 2 ;;
esac

# ---------- Die Marke, als Erstes ----------
# Zwischen purge_installation (unmittelbar nach diesem Skript) und
# postinstall.sh liegt fast eine Minute; am Geraet gemessen 03:31:32 bis
# 03:32:24 (Regeln/06). In dieser Luecke ruft cron/cron.01min "dienst.sh
# start", und der Waechter lief mit leerem Datenordner an: er schrieb
# historie.json neu, und die Rettung in postinstall.sh fand die Datei vor und
# uebersprang sich - Zaehler und Verlauf waren nach jeder Aktualisierung weg.
# Am 18.09.2026 in WSL nachgestellt und gemessen.
#
# Die Marke liegt NEBEN dem Datenordner, sonst loescht purge_installation sie
# gleich mit. Sie traegt die Unixzeit; dienst.sh achtet sie, solange sie
# juenger als 3600 s ist. postupgrade.sh raeumt sie weg, uninstall ebenfalls.
# Als ERSTES, damit zwischen Marke und Luecke kein Takt liegt.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
mkdir -p "$BASE/data/plugins" 2>/dev/null
if date +%s > "$MARKE" 2>/dev/null && [ -s "$MARKE" ]; then
    echo "<OK> Aktualisierung angemeldet - der Minutentakt startet in der Luecke nichts."
else
    # Die Wirkung pruefen, nicht den Rueckgabewert. Ohne Marke laeuft die
    # Aktualisierung weiter - nur eben mit dem alten Risiko.
    rm -f "$MARKE" 2>/dev/null
    # 05.11.2026: ohne Marke haelt die Installation das Update fuer eine Neuinstallation und legt die Einstellungen beiseite; bis hierher nur eine Warnung.
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen - die Aktualisierung wird abgebrochen,"
    echo "<FAIL> die bisherige Fassung bleibt unveraendert installiert."
    exit 2
fi
# ---------- Update-Schutz (05.11.2026, Bauart Govee 0.9.34) ----------
# plugininstall.pl bricht nur bei Rueckgabewert >1 vor purge_installation ab. Die Kopie entsteht als <Ziel>.neu.<pid>, traegt 0600,
# BEVOR Inhalt hineinkommt, wird mit cmp verglichen und erst dann per mv an ihren Platz gestellt: eine heile Sicherung bleibt heil.
ZS_FEHL=""
zs_hinlegen() {   # $1 Quelle, $2 Ziel
    [ -d "$2" ] && return 1
    zs_neu="$2.neu.$$"
    rm -f "$zs_neu" 2>/dev/null
    if { : > "$zs_neu"; } 2>/dev/null && chmod 600 "$zs_neu" 2>/dev/null \
       && cp "$1" "$zs_neu" 2>/dev/null && cmp -s "$1" "$zs_neu" \
       && mv -f "$zs_neu" "$2" 2>/dev/null; then
        return 0
    fi
    rm -f "$zs_neu" 2>/dev/null
    return 1
}
zs_schluss() {   # Fehlschlag gesammelt: Marke weg, Abbruch vor purge_installation
    [ -n "$ZS_FEHL" ] || return 0
    echo "<FAIL> Nicht gesichert:$ZS_FEHL - die Aktualisierung wird abgebrochen, damit der Purge die Originale nicht loescht."
    echo "<FAIL> Die bisherige Fassung bleibt installiert. Bitte Platz und Rechte unter config/plugins und data/plugins pruefen."
    rm -f "$MARKE" 2>/dev/null
    exit 2
}

CF="$BASE/config/plugins/$PFOLDER/funkwacht.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
# I1 (Pruefung 29.09.2026): zur Zweitschrift wird nur eine Konfiguration, die
# sich als NICHT LEERES JSON-Objekt lesen laesst. Bis 1.0.8 kopierte dieser
# Schritt ungeprueft: eine abgeschnittene oder leere funkwacht.json
# ueberschrieb die heile Zweitschrift, und nach der Aktualisierung waren
# Sticks und Aktionstoken weg (Befund Installer 1, Faelle C und D). Gebaut
# wird unter .neu, verglichen, erst dann umbenannt; die Rechte werden VOR dem
# Umbenennen gesetzt.
if [ -f "$CF" ]; then
    if python3 -c 'import json,sys; d=json.load(open(sys.argv[1],encoding="utf-8")); sys.exit(0 if isinstance(d,dict) and d else 1)' "$CF" 2>/dev/null; then
        rm -f "$BK.neu" 2>/dev/null
        if zs_hinlegen "$CF" "$BK"; then
            echo "<OK> Konfiguration gesichert."
        else
            rm -f "$BK.neu" 2>/dev/null
            echo "<WARNING> Die Konfiguration liess sich nicht sichern - die bisherige Zweitschrift bleibt unberuehrt."
            ZS_FEHL="$ZS_FEHL funkwacht.json"
        fi
    else
        echo "<WARNING> $CF ist leer oder kein lesbares JSON-Objekt. Sie wird NICHT gesichert;"
        echo "<WARNING> die bisherige Zweitschrift $BK bleibt unberuehrt."
    fi
fi

# ---------- Den Bestand retten ----------
# GEMESSEN an sbin/plugininstall.pl (Zweig master, 23.08.2026): der Installer
# ruft &purge_installation NICHT nur beim Deinstallieren, sondern auch im
# Upgrade-Zweig (:886, unmittelbar nach diesem Skript) - und deren Rumpf
# raeumt ohne jede Bedingung ab (:1629 ff.):
#     rm -rf config/plugins/<f>/   bin/plugins/<f>/   data/plugins/<f>/
# Damit waeren historie.json und die Tagesdateien nach JEDER Aktualisierung
# weg: die Zaehler faengen bei null an, der 30-Tage-Balken ist leer, und ein
# dauerhaft defekter Stick bekommt frische Versuche. Genau der Fehler, den
# 0.9.4 eine Ebene tiefer behoben hat (Zaehler in stand.json).
#
# Der Ordner mit dem Punkt liegt NEBEN dem Ordner: "rm -rf .../$PFOLDER/"
# trifft ihn nicht. uninstall raeumt ihn selbst weg.
BEST="$BASE/data/plugins/$PFOLDER.bestand"
PDATA="$BASE/data/plugins/$PFOLDER"
# S1 (Pruefung 29.09.2026, Entscheidung 1 des Hausherrn): ein Bestand aus
# einem FRUEHEREN Vorgang wird nie eingespielt. Bis Stufe 1 kopierte dieser
# Schritt ueber einen vorhandenen .bestand hinweg - was dort schon lag und
# jetzt fehlt (etwa eine alte historie.json), blieb stehen und kam mit der
# frischen Marke zurueck. Deshalb: den neuen Bestand unter .neu bauen und je
# Datei vergleichen, dann den alten wegraeumen, erst dann umbenennen
# (Regeln/06: neben dem Platz bauen, pruefen, dann umbenennen). Der alte
# faellt auch dann, wenn diesmal nichts zu sichern ist.
NEU="$BEST.neu"
rm -rf "$NEU" 2>/dev/null
GERETTET=""
NICHT=""
if [ -d "$PDATA" ] && mkdir -p "$NEU" 2>/dev/null; then
    # stand.json wird mitgenommen, weil postinstall daraus einmalig die
    # Zaehler einer 0.9.3 uebernimmt - ohne Rettung waere die Datei dort
    # laengst geloescht und der Umstieg liefe ins Leere.
    # mqtt_stand.json (M1, Pruefung 29.09.2026): die Zeitstempel des
    # Mithoerers. Ohne sie galt ein stiller Stick nach jeder Aktualisierung
    # als "nie gesehen" - und "nie gesehen heilt nicht".
    # mqtt_altpraefix.json (S1): die Vormerkung abzuraeumender Praefixe.
    # Gruppe 44 (1.0.17): Bestandsaufnahme JEDER Datei, die Waechter (funkwacht_dienst.py), Mithoerer (fw_mqtt.py),
    # dienst.sh, Oberflaeche (fw_lib.php, fw_test.php), Endpunkt, Befehlsbruecke und Sprachmodul in
    # data/plugins/<ordner>/ schreiben (Probe P-U Teil A, Bericht Gruppe 44). Neu gerettet:
    #   retain_dienstaussagen_geraeumt  Merker der einmaligen Altwert-Abraeumung (1.0.6) - sonst sieht der Waechter
    #                                   nach jedem Update erneut am Broker nach (zweimal die Wartezeit, R26)
    #   retain_sticks_geraeumt          Merker der Abraeumung geleerter Stick-Zeilen (1.0.7) - ebenso
    #   ansage.json                     Wiederholsperre der Ansage (30 min je Anlass und Stick) - sonst kaeme eine
    #                                   gerade gesprochene Ansage nach dem Update erneut
    # Bewusst NICHT: auftraege.json (ein Auftrag von vor dem Update - quittieren, wartung, heilen - soll danach
    # nicht mehr laufen; die Wartung selbst steht in historie.json), gleichwert.json (X-7, 60 s), einmalmeldung.json
    # (120 s), dienst.pid und mithoerer.pid (Prozesse des alten Standes), token.lock und ansage.lock (Sperren),
    # *.tmp.* (halbe Schriften), historie.json.kaputt (Befund des Augenblicks, liegt im alten Datenordner),
    # bestand_zurueckgeholt (Merker von postinstall fuer postupgrade), <art>_letzte.json (Ergebnis der letzten Ansage
    # fuer den Reiter Test), befehle/ (Zustand, Waechter und Sperren der Befehlsbruecke - laufzeitgebunden).
    # Ausserhalb des Datenordners und vom Update nicht betroffen: Konfiguration und Zweitschrift (oben), das Token
    # (in der Konfiguration), Protokolle (log/plugins/<ordner>/).
    for F in historie.json stand.json faehigkeit.json mqtt_stand.json mqtt_altpraefix.json \
             retain_dienstaussagen_geraeumt retain_sticks_geraeumt ansage.json; do
        [ -f "$PDATA/$F" ] || continue
        if cp -p "$PDATA/$F" "$NEU/$F" 2>/dev/null && cmp -s "$PDATA/$F" "$NEU/$F"; then
            GERETTET="$GERETTET $F"
        else
            NICHT="$NICHT $F"
            ZS_FEHL="$ZS_FEHL $F"
        fi
    done
    if [ -d "$PDATA/verlauf" ]; then
        if cp -rp "$PDATA/verlauf" "$NEU/verlauf" 2>/dev/null \
           && diff -rq "$PDATA/verlauf" "$NEU/verlauf" >/dev/null 2>&1; then
            GERETTET="$GERETTET verlauf/"
        else
            NICHT="$NICHT verlauf/"
            ZS_FEHL="$ZS_FEHL verlauf"
        fi
    fi
fi
if [ -e "$BEST" ] || [ -L "$BEST" ]; then
    rm -rf "$BEST" 2>/dev/null
    if [ -e "$BEST" ] || [ -L "$BEST" ]; then
        echo "<WARNING> Die Sicherung $BEST aus einem frueheren Vorgang liess sich nicht"
        echo "<WARNING> wegraeumen; postinstall.sh wuerde sie einspielen. Bitte von Hand loeschen."
    else
        echo "<INFO> Eine Sicherung aus einem frueheren Vorgang ($BEST) ist weggeraeumt."
    fi
fi
# Die Wirkung pruefen, nicht den Rueckgabewert: liegt hinterher wirklich
# etwas da?
if [ -n "$GERETTET" ] && [ ! -e "$BEST" ] && mv "$NEU" "$BEST" 2>/dev/null \
   && [ -n "$(ls -A "$BEST" 2>/dev/null)" ]; then
    echo "<OK> Bestand gesichert:$GERETTET"
    [ -n "$NICHT" ] && echo "<WARNING> Nicht gesichert:$NICHT - diese Teile beginnen nach der Aktualisierung neu."
elif [ -n "$GERETTET" ] || [ -f "$PDATA/historie.json" ]; then
    echo "<INFO> Der Bestand konnte nicht gesichert werden - Zaehler und"
    echo "<INFO> Verlauf beginnen nach dieser Aktualisierung bei null."
    ZS_FEHL="$ZS_FEHL Bestand"
fi
rm -rf "$NEU" 2>/dev/null

# Den Dienst anhalten, BEVOR seine Dateien ersetzt werden. Ein laufender
# Prozess, dessen Quelltext unter ihm ausgetauscht wird, ist eine Wette;
# postinstall.sh startet ihn hinterher ohnehin neu.
#
# Und SAGEN, was geschah - nach der Wirkung, nicht nach dem Aufruf. Bis 1.0.2
# hielt das Skript stumm an; preupgrade_meldung_pruefen.py meldete dazu
# "lief: es wird gesagt, dass angehalten wurde" als Fehlschlag. Als laufend
# gilt, was status mit 0 beantwortet ODER was eine Prozessnummer nennt: der
# Waechter kann laufen, waehrend status wegen eines fehlenden Mithoerers 3
# sagt.
# Gruppe 44 (1.0.17): die Befehlsbruecke anhalten - der Waechter in cron.01min startet sie nach dem Update wieder,
# wenn der Haken gesetzt ist. Die Marke .upgrade_laeuft (oben) haelt ihn bis dahin an.
BBSH="$BASE/bin/plugins/$PFOLDER/befehlsbruecke.sh"
if [ -f "$BBSH" ]; then
    if bash "$BBSH" stop >/dev/null 2>&1; then
        echo "<INFO> Befehlsbruecke angehalten (der Waechter startet sie nach dem Update, wenn der Haken gesetzt ist)."
    else
        echo "<INFO> Befehlsbruecke: stop meldete einen Fehler - sie lief vermutlich nicht."
    fi
else
    echo "<INFO> Keine Befehlsbruecke installiert (Fassung vor 1.0.17) - nichts anzuhalten."
fi

zs_schluss
DIENST="$BASE/bin/plugins/$PFOLDER/dienst.sh"
if [ -x "$DIENST" ]; then
    LAGE=$("$DIENST" status 2>/dev/null); LAGE_RC=$?
    "$DIENST" stop >/dev/null 2>&1
    if [ "$LAGE_RC" = "0" ] || printf '%s' "$LAGE" | grep -q "laeuft (PID"; then
        if "$DIENST" status 2>/dev/null | grep -q "laeuft (PID"; then
            echo "<WARNING> Der Waechter liess sich vor der Aktualisierung nicht anhalten."
        else
            echo "<INFO> Der laufende Waechter wurde fuer die Aktualisierung angehalten; postinstall.sh startet ihn danach wieder."
        fi
    else
        echo "<INFO> Der Waechter lief nicht - es war nichts anzuhalten."
    fi
fi
zs_schluss
echo "<OK> preupgrade abgeschlossen."
exit 0
