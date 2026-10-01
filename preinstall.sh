#!/bin/bash

# Funkwacht - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# X-1 (Welle 4, 01.10.2026; Entscheidung 1 vom 29.09.2026), Bauform
# Abfahrtsassistent 1.6.19. Der Installer ruft dieses Skript bei JEDEM
# Einbau auf, nach dem Aufraeumen der alten Fassung und VOR dem Kopieren von
# Konfiguration, Cron-Datei und Oberflaeche (sbin/plugininstall.pl:
# preupgrade :846, purge :874, preinstall :877, Cron :990, HTML :1066 -
# Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh anlegt (kein
# Altersvergleich). Dann tut es nichts: Zweitschrift und Bestand braucht
# postinstall.sh zum Zurueckspielen.
#
# Ohne Marke ist es eine NEUINSTALLATION. Eine liegengebliebene Zweitschrift
# (config/plugins/<ordner>.backup.json) und ein liegengebliebener Bestand
# (data/plugins/<ordner>.bestand) einer frueheren Installation gehen nach
# <name>.alt, gemeldet mit genau einer <WARNING>. Bis 1.0.9 tat das erst
# postinstall.sh - zwischen dem Kopieren der Oberflaeche und postinstall.sh
# holte fw_config() in fw_lib.php beim ersten Seitenaufruf die Zweitschrift
# der frueheren Installation zurueck (Sticks und Wortzeichen einer laengst
# deinstallierten Anlage). postinstall.sh behaelt seinen Block als
# Rueckfall; er findet danach nichts mehr. Die Selbstheilung liest .alt nie;
# die Deinstallation raeumt es ab.

ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
PFOLDER="${ARGV3:-funkwacht}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Wurzelsuche wie in preupgrade.sh und postinstall.sh: ohne config/plugins,
# data/plugins UND config/system/general.json wird nichts angefasst
# (Regeln/06, Raumklima-Vorfall).
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv/rm daneben.
case "$PFOLDER" in
    ''|.|..|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    # Aktualisierung: nichts zu tun, postinstall.sh spielt zurueck.
    exit 0
fi

BK="$BASE/config/plugins/$PFOLDER.backup.json"
BEST="$BASE/data/plugins/$PFOLDER.bestand"
BEISEITE=""
FEST=""
for ZIEL in "$BK" "$BEST"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null && [ ! -e "$ZIEL" ]; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
[ -f "$BK.alt" ] && [ ! -L "$BK.alt" ] && chmod 600 "$BK.alt" 2>/dev/null

if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    FW_TEXT="<WARNING> Neuinstallation: Einstellungen einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && FW_TEXT="$FW_TEXT Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && FW_TEXT="$FW_TEXT Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$FW_TEXT"
fi
exit 0
