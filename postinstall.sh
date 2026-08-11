#!/bin/bash
# Funkwacht - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# postinstall laeuft IMMER, auch beim Upgrade - in plugininstall.pl gibt es
# dort kein if($isupgrade). Alles hier muss deshalb mehrfach ausfuehrbar sein,
# ohne Schaden anzurichten.
#
# Dieses Skript laeuft als Benutzer loxberry, NICHT als root. Deshalb steht
# uhubctl in dpkg/apt und wird nicht hier installiert; ein "apt-get install"
# an dieser Stelle scheiterte still an fehlenden Rechten.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-funkwacht}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"

mkdir -p "$PDATA" "$PLOG" "$PCONFIG" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" 2>/dev/null
chmod 700 "$PCONFIG" 2>/dev/null

[ -f "$PCONFIG/funkwacht.json" ] || echo '{}' > "$PCONFIG/funkwacht.json"
chmod 600 "$PCONFIG/funkwacht.json" 2>/dev/null

# Sicherung zurueckspielen (uebersteht Update UND Neuinstallation)
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$PCONFIG/funkwacht.json"
if [ -f "$BK" ]; then
    INHALT=$(cat "$CF" 2>/dev/null)
    if [ ! -s "$CF" ] || [ "$INHALT" = "{}" ]; then
        cp -p "$BK" "$CF" && echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi

# ---------- Python pruefen ----------
if ! command -v python3 >/dev/null 2>&1; then
    echo "<FAIL> python3 wurde nicht gefunden. Ohne Python laeuft der Waechter nicht."
    exit 1
fi
echo "<INFO> $(python3 --version 2>&1)"

# ---------- Die Rechtedatei ----------
# Sie muss root gehoeren und 0440 haben, sonst weist sudo sie ab. Als
# loxberry laesst sie sich nur ueber sudo ablegen - was voraussetzt, dass
# loxberry sudo schon darf (auf LoxBerry ist das der Fall). Klappt es nicht,
# wird das GESAGT und nicht verschwiegen: ohne diese Datei bleibt es beim
# Melden, geheilt wird dann nichts.
# Die Vorlage liegt in bin/, weil LoxBerry NUR bekannte Ordner uebernimmt -
# eine Datei im Wurzelverzeichnis des Archivs landet nirgends.
SUD="$PBIN/sudoers.funkwacht"
QUELLE=""
for K in "$SUD" "$1/sudoers" "$1/bin/sudoers.funkwacht"; do
    [ -f "$K" ] && QUELLE="$K" && break
done
if [ -n "$QUELLE" ]; then
    SUD="$QUELLE"
    if sudo -n test -d /etc/sudoers.d 2>/dev/null \
       && sudo -n install -o root -g root -m 0440 "$SUD" /etc/sudoers.d/funkwacht 2>/dev/null; then
        # Die Wirkung pruefen, nicht den Rueckgabewert: visudo sagt, ob die
        # Datei fuer sudo ueberhaupt lesbar ist. Eine fehlerhafte Datei in
        # /etc/sudoers.d legt SAEMTLICHE sudo-Aufrufe des Systems lahm -
        # deshalb wird sie sofort wieder entfernt, wenn visudo meckert.
        if sudo -n visudo -c -f /etc/sudoers.d/funkwacht >/dev/null 2>&1; then
            echo "<OK> Rechtedatei /etc/sudoers.d/funkwacht angelegt."
        else
            sudo -n rm -f /etc/sudoers.d/funkwacht 2>/dev/null
            echo "<FAIL> Die Rechtedatei war fehlerhaft und wurde wieder entfernt."
            echo "<INFO> Das Plugin laeuft weiter, kann aber nur melden, nicht heilen."
        fi
    else
        echo "<INFO> Die Rechtedatei konnte nicht nach /etc/sudoers.d/ gelegt werden."
        echo "<INFO> Das Plugin meldet dann nur; geheilt wird nichts."
        echo "<INFO> Nachholen als root mit:"
        echo "<INFO>   install -o root -g root -m 0440 $SUD /etc/sudoers.d/funkwacht"
        echo "<INFO>   visudo -c -f /etc/sudoers.d/funkwacht"
    fi
else
    echo "<INFO> sudoers-Vorlage nicht gefunden - Rechtedatei wurde nicht angelegt."
fi

# ---------- Selbsttest des Rechenkerns ----------
# Ohne Netz und ohne Geraet: rechnet die Entscheidungen durch und vergleicht
# sie mit den hinterlegten Sollwerten.
if [ -f "$PBIN/funkwacht_dienst.py" ]; then
    if AUS=$(python3 "$PBIN/funkwacht_dienst.py" --selbsttest 2>&1); then
        echo "<OK> Selbsttest: $(echo "$AUS" | tail -1)"
    else
        echo "<INFO> Der Selbsttest ist nicht sauber durchgelaufen:"
        echo "$AUS" | tail -20 | sed 's/^/<INFO> /'
    fi
fi

chown -R loxberry:loxberry "$PBIN" "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null

# ---------- Was kann dieses Geraet? ----------
# Gleich beim Einrichten messen und hinschreiben, statt den Nutzer raten zu
# lassen. Vor allem uhubctl verspricht mehr, als die meisten Verteiler halten.
if [ -f "$PBIN/funkwacht_dienst.py" ]; then
    python3 "$PBIN/funkwacht_dienst.py" --faehigkeit > "$PDATA/faehigkeit.json" 2>/dev/null
    HAEHNE=$(python3 -c "import json,sys; d=json.load(open('$PDATA/faehigkeit.json')); print(', '.join(d.get('uhubctl_haehne') or []) or 'keiner')" 2>/dev/null)
    echo "<INFO> Verteiler, die den Strom wirklich schalten koennen: $HAEHNE"
fi

# ---------- Dienst starten ----------
if [ -x "$PBIN/dienst.sh" ]; then
    "$PBIN/dienst.sh" restart >/dev/null 2>&1
    sleep 1
    if "$PBIN/dienst.sh" status >/dev/null 2>&1; then
        echo "<OK> Der Waechter laeuft."
    else
        echo "<INFO> Der Waechter konnte noch nicht gestartet werden."
        echo "<INFO> Der Minutentakt startet ihn beim naechsten Durchlauf erneut."
    fi
fi

echo "<OK> Installation abgeschlossen."
echo "<INFO> Naechste Schritte in der Plugin-Oberflaeche:"
echo "<INFO>  1. Reiter Test, Knopf 'Was kann dieses Geraet?' - er sagt, welche"
echo "<INFO>     Heilstufen hier ueberhaupt moeglich sind."
echo "<INFO>  2. Reiter Einstellungen: je Funkstick eine Zeile ausfuellen."
echo "<INFO>  3. Reiter Einbindung in Loxone: Vorlage herunterladen und einlesen."
exit 0
