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
PFOLDER="${ARGV3:-funkwacht}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
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
DIENST="$BASE/bin/plugins/$PFOLDER/dienst.sh"
[ -x "$DIENST" ] && "$DIENST" stop >/dev/null 2>&1
echo "<OK> preupgrade abgeschlossen."
exit 0
