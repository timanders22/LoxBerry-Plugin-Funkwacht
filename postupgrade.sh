#!/bin/bash
# Funkwacht - postupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# postinstall.sh laeuft beim Upgrade ohnehin - der Installer ruft es immer
# auf, und dort wird auch der Dienst wieder gestartet. Wuerde dieses Skript
# es zusaetzlich aufrufen, liefe es ZWEIMAL, mit allem, was darin nicht
# idempotent ist.
#
# Was hier bleibt: das zwischengespeicherte Abbild verwerfen. Aendert sich
# der Aufbau von stand.json zwischen zwei Fassungen, zeigte die Oberflaeche
# sonst bis zum naechsten Durchlauf alte Felder - oder rechnete damit.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-funkwacht}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi
rm -f "$BASE/data/plugins/$PFOLDER/stand.json"
echo "<OK> postupgrade abgeschlossen - beim naechsten Durchlauf wird frisch gemessen."
exit 0
