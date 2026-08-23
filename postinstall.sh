#!/bin/bash
# Funkwacht - postinstall
#
# Der Installer ruft mit:  <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASE> <TEMPFOLDER>
#
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
# Zufallskennung aus &generate(10). Der absolute Arbeitsordner steht im
# SECHSTEN Argument. Bis 0.9.3 standen hier Rueckfallkandidaten der Form
# "$1/sudoers" - die konnten nie greifen, und die eigene preupgrade.sh sagte
# das im selben Archiv schon richtig. Ein Widerspruch in der eigenen
# Dokumentation ist eine Fehlerquelle, kein Schoenheitsfehler.
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
ARGV6=$6
PFOLDER="${ARGV3:-funkwacht}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi
TEMP=""
[ -n "$ARGV6" ] && [ -d "$ARGV6" ] && TEMP="$ARGV6"

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"

# data/plugins/<x>/verlauf traegt die Tagesdateien der Heilungen. Sie liegt
# unter data/ und NICHT unter log/ - log/plugins ist eine Ramdisk, und die
# Verschleisskurve soll einen Neustart ueberdauern.
mkdir -p "$PDATA/verlauf" "$PLOG" "$PCONFIG" || {
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

# ---------- Den geretteten Bestand zurueckholen ----------
# Gegenstueck zu preupgrade.sh. Der Installer hat data/plugins/<x>/ zwischen
# beiden Skripten vollstaendig geloescht (plugininstall.pl:886 -> :1631);
# was ueberleben soll, lag solange NEBEN dem Ordner.
#
# Zurueckgeholt wird nur, was fehlt. Eine Neuinstallation ohne vorherige
# Fassung findet keinen Bestand und faengt sauber bei null an - deshalb ist
# hier auch keine Meldung noetig, wenn nichts da ist.
BEST="$BASE/data/plugins/$PFOLDER.bestand"
if [ -d "$BEST" ]; then
    ZURUECK=""
    for F in historie.json faehigkeit.json; do
        if [ -f "$BEST/$F" ] && [ ! -f "$PDATA/$F" ]; then
            cp -p "$BEST/$F" "$PDATA/$F" 2>/dev/null && ZURUECK="$ZURUECK $F"
        fi
    done
    if [ -d "$BEST/verlauf" ] && [ -z "$(ls -A "$PDATA/verlauf" 2>/dev/null)" ]; then
        cp -rp "$BEST/verlauf/." "$PDATA/verlauf/" 2>/dev/null \
            && ZURUECK="$ZURUECK verlauf/"
    fi
    # stand.json wird NICHT zurueckgeholt - es ist ein Abbild und wird beim
    # naechsten Durchlauf neu geschrieben. Es dient hier nur dem Umstieg
    # einer 0.9.3 (siehe naechster Block) und wird danach geloescht.
    if [ -n "$ZURUECK" ]; then
        echo "<OK> Zaehler und Verlauf aus der Sicherung zurueckgeholt:$ZURUECK"
    fi
fi

# ---------- Zaehler und Verlauf aus stand.json uebernehmen ----------
# Bis 0.9.3 standen Zaehler und Verlauf in stand.json - und der Installer
# loescht data/plugins/<x>/ bei jeder Aktualisierung. Damit fiel genau die
# Zahl weg, die die Hilfe als die nuetzlichste bewirbt, und die Bremse fing
# wieder bei null an. Ab 0.9.4 stehen sie in historie.json.
#
# Gelesen wird die von preupgrade.sh GERETTETE stand.json - die im Datenordner
# hat der Installer inzwischen geloescht. Ohne diese Zeile waere der ganze
# Umstieg toter Code gewesen, und das waere niemandem aufgefallen.
ALT_STAND="$BEST/stand.json"
[ -s "$ALT_STAND" ] || ALT_STAND="$PDATA/stand.json"
if [ ! -f "$PDATA/historie.json" ] && [ -s "$ALT_STAND" ]; then
    if python3 - "$ALT_STAND" "$PDATA/historie.json" <<'PYEOF'
import json, sys
try:
    with open(sys.argv[1], encoding='utf-8') as f:
        alt = json.load(f)
except Exception:
    raise SystemExit(1)
if not isinstance(alt, dict):
    raise SystemExit(1)
neu = {'geheilt_gesamt': int(alt.get('geheilt_gesamt', 0) or 0),
       'versuche_gesamt': int(alt.get('geheilt_gesamt', 0) or 0),
       'geraete': {}, 'stufen': {}}
for nr, e in (alt.get('geraete') or {}).items():
    if not isinstance(e, dict):
        continue
    n = int(e.get('heilungen', 0) or 0)
    neu['geraete'][str(nr)] = {
        'heilungen': n, 'versuche': n, 'abgelehnt': 0,
        'verlauf': [float(t) for t in (e.get('verlauf') or [])][-50:],
        'letzte_tat': str(e.get('letzte_tat', '') or ''),
    }
    neu['stufen'][str(nr)] = int(e.get('stufe', 0) or 0)
with open(sys.argv[2], 'w', encoding='utf-8') as f:
    json.dump(neu, f, ensure_ascii=False, indent=1)
PYEOF
    then
        echo "<OK> Zaehler und Verlauf aus stand.json nach historie.json uebernommen."
    else
        echo "<INFO> Aus stand.json liess sich keine Historie uebernehmen - die Zaehler beginnen bei null."
    fi
fi
# Der Umstieg laeuft genau einmal; danach ist die alte Datei nur noch Ballast.
rm -f "$BEST/stand.json" 2>/dev/null

# ---------- Die Rechtedatei ----------
# Sie muss root gehoeren und 0440 haben, sonst weist sudo sie ab. Als
# loxberry laesst sie sich nur ueber sudo ablegen - was voraussetzt, dass
# loxberry sudo schon darf (auf LoxBerry ist das der Fall). Klappt es nicht,
# wird das GESAGT und nicht verschwiegen: ohne diese Datei bleibt es beim
# Melden, geheilt wird dann nichts.
# Die Vorlage liegt in bin/, weil LoxBerry NUR bekannte Ordner uebernimmt -
# eine Datei im Wurzelverzeichnis des Archivs landet nirgends. Genau deshalb
# ist die Zweitschrift im Wurzelverzeichnis in 0.9.4 entfallen.
SUD=""
for K in "$PBIN/sudoers.funkwacht" ${TEMP:+"$TEMP/bin/sudoers.funkwacht"}; do
    [ -f "$K" ] && SUD="$K" && break
done
if [ -n "$SUD" ]; then
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
        echo "<OK> Selbsttest: $(echo "$AUS" | head -1)"
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
    HAEHNE=$(python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(', '.join(d.get('uhubctl_haehne') or []) or 'keiner')" "$PDATA/faehigkeit.json" 2>/dev/null)
    echo "<INFO> Verteiler, die den Strom wirklich schalten koennen: ${HAEHNE:-unbekannt}"
fi

# ---------- Dienst starten ----------
if [ -x "$PBIN/dienst.sh" ]; then
    "$PBIN/dienst.sh" restart >/dev/null 2>&1
    sleep 1
    if "$PBIN/dienst.sh" status >/dev/null 2>&1; then
        echo "<OK> Der Waechter laeuft."
        # Der Mithoerer laeuft nur, wenn ueberhaupt ein Stick auf die Art MQTT
        # steht; dienst.sh entscheidet das selbst und sagt es.
        "$PBIN/dienst.sh" status 2>/dev/null | sed 's/^/<INFO> /'
    else
        echo "<INFO> Der Waechter konnte noch nicht gestartet werden."
        echo "<INFO> Der Minutentakt startet ihn beim naechsten Durchlauf erneut."
    fi
fi

echo "<OK> Installation abgeschlossen."
echo "<INFO> Naechste Schritte in der Plugin-Oberflaeche:"
echo "<INFO>  1. Reiter Test, Knopf 'Selbstpruefung' - er beantwortet in einer"
echo "<INFO>     Liste, ob die Einrichtung traegt."
echo "<INFO>  2. Reiter Test, Knopf 'Was kann dieses Geraet?' - er sagt, welche"
echo "<INFO>     Heilstufen hier ueberhaupt moeglich sind."
echo "<INFO>  3. Reiter Einstellungen: je Funkstick eine Zeile ausfuellen."
echo "<INFO>  4. Reiter Einbindung in Loxone: Vorlage herunterladen und einlesen."
exit 0
