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
PFOLDER="${ARGV3:-funkwacht}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi
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
