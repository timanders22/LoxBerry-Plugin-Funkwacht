#!/bin/bash
# Funkwacht - postupgrade
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# postinstall.sh laeuft beim Upgrade ohnehin - der Installer ruft es immer
# auf, und dort wird auch der Dienst wieder gestartet. Wuerde dieses Skript
# es zusaetzlich aufrufen, liefe es ZWEIMAL, mit allem, was darin nicht
# idempotent ist.
#
# Was hier bleibt: das zwischengespeicherte Abbild verwerfen. Aendert sich
# der Aufbau von stand.json zwischen zwei Fassungen, zeigte die Oberflaeche
# sonst bis zum naechsten Durchlauf alte Felder - oder rechnete damit.
#
# WAS HIER AUSDRUECKLICH NICHT GELOESCHT WIRD: historie.json. Dort stehen seit
# 0.9.4 die Zaehler und der Verlauf der Heilversuche - also die Zahl, die die
# Hilfe als die nuetzlichste bewirbt ("steigt sie ueber Wochen an, ist ein
# Stick am Ende seiner Kraefte"), und die Grundlage der Bremse.
#
# BERICHTIGUNG zur Fassung 0.9.4: Dass es genuegt, hier nichts zu loeschen,
# war falsch. Der Installer selbst raeumt data/plugins/<x>/ bei JEDER
# Aktualisierung ab - gemessen an sbin/plugininstall.pl (Zweig master,
# 23.08.2026): &purge_installation steht im Upgrade-Zweig (:886), und deren
# Rumpf loescht ohne Bedingung (:1631). Zu diesem Zeitpunkt hier ist die
# Datei also laengst weg. Sie ueberlebt nur, weil preupgrade.sh sie vorher
# NEBEN den Ordner legt und postinstall.sh sie zurueckholt.
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
rm -f "$BASE/data/plugins/$PFOLDER/stand.json"

# ---------- Die Marke aus preupgrade.sh wegraeumen ----------
# Dies ist das LETZTE Hakenskript dieser Linie - ein postroot.sh gibt es
# nicht. Die Marke faellt hier und nicht frueher: postinstall.sh hat den
# Waechter mit FW_START_TROTZ_MARKE=1 schon gestartet, und faellt die Marke
# vor diesem Start, kann der Minutentakt genau dazwischen einen ZWEITEN
# Dienst starten. Gemessen am 18.09.2026 in WSL (200 Takte waehrend des
# Hakenlaufs): mit dieser Reihenfolge ein Dienst.
#
# Bleibt sie liegen - abgebrochene Installation -, gilt sie nach 3600 s
# ohnehin nicht mehr; dienst.sh rechnet das nach.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    rm -f "$MARKE"
    # Die Wirkung pruefen, nicht den Rueckgabewert.
    if [ -f "$MARKE" ]; then
        echo "<WARNING> Die Marke $MARKE liess sich nicht entfernen; der Waechter"
        echo "<WARNING> startet erst wieder, wenn sie aelter als eine Stunde ist."
    else
        echo "<OK> Aktualisierung abgemeldet."
    fi
fi

echo "<OK> postupgrade abgeschlossen - beim naechsten Durchlauf wird frisch gemessen."
echo "<INFO> Zaehler und Verlauf wurden ueber die Aktualisierung gerettet."
exit 0
