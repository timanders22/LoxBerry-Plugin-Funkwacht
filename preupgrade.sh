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
    exit 1
fi
case "$PFOLDER" in
    ''|.|..|*/*)
        echo "<FAIL> Ungueltiger Ordnername '$PFOLDER' - es wurde nichts angefasst."
        exit 1 ;;
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
    echo "<WARNING> Die Marke $MARKE liess sich nicht anlegen; der Minutentakt"
    echo "<WARNING> koennte den Waechter mitten in der Aktualisierung starten."
fi

CF="$BASE/config/plugins/$PFOLDER/funkwacht.json"
if [ -f "$CF" ]; then
    cp -p "$CF" "$BASE/config/plugins/$PFOLDER.backup.json" \
        && chmod 600 "$BASE/config/plugins/$PFOLDER.backup.json" 2>/dev/null \
        && echo "<OK> Konfiguration gesichert."
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
if [ -d "$PDATA" ]; then
    mkdir -p "$BEST" 2>/dev/null
    GERETTET=""
    # stand.json wird mitgenommen, weil postinstall daraus einmalig die
    # Zaehler einer 0.9.3 uebernimmt - ohne Rettung waere die Datei dort
    # laengst geloescht und der Umstieg liefe ins Leere.
    for F in historie.json stand.json faehigkeit.json; do
        [ -f "$PDATA/$F" ] && cp -p "$PDATA/$F" "$BEST/$F" 2>/dev/null \
            && GERETTET="$GERETTET $F"
    done
    if [ -d "$PDATA/verlauf" ]; then
        rm -rf "$BEST/verlauf" 2>/dev/null
        cp -rp "$PDATA/verlauf" "$BEST/verlauf" 2>/dev/null \
            && GERETTET="$GERETTET verlauf/"
    fi
    # Die Wirkung pruefen, nicht den Rueckgabewert: liegt hinterher wirklich
    # etwas da?
    if [ -n "$(ls -A "$BEST" 2>/dev/null)" ]; then
        echo "<OK> Bestand gesichert:$GERETTET"
    elif [ -f "$PDATA/historie.json" ]; then
        echo "<INFO> Der Bestand konnte nicht gesichert werden - Zaehler und"
        echo "<INFO> Verlauf beginnen nach dieser Aktualisierung bei null."
    fi
fi

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
echo "<OK> preupgrade abgeschlossen."
exit 0
