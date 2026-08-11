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

# Den Dienst anhalten, BEVOR seine Dateien ersetzt werden. Ein laufender
# Prozess, dessen Quelltext unter ihm ausgetauscht wird, ist eine Wette;
# postinstall.sh startet ihn hinterher ohnehin neu.
DIENST="$BASE/bin/plugins/$PFOLDER/dienst.sh"
[ -x "$DIENST" ] && "$DIENST" stop >/dev/null 2>&1
echo "<OK> preupgrade abgeschlossen."
exit 0
