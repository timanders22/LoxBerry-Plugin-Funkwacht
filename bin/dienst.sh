#!/bin/bash
#
# Funkwacht - Dienste starten, anhalten, nachsehen
#
# Es sind ZWEI Prozesse:
#   funkwacht_dienst.py   der Waechter - misst, urteilt, heilt
#   fw_mqtt.py            der Mithoerer - schreibt die Zeitstempel je Thema
#
# Der Mithoerer laeuft nur, wenn ueberhaupt ein Stick auf die Art MQTT
# eingerichtet ist; er beendet sich sonst von selbst und sagt es im
# Protokoll. Getrennt sind sie, weil ein haengender Broker sonst den
# Waechter mit anhielte - und der soll gerade dann messen, wenn etwas klemmt.
#
# Den eigenen Ort ueber readlink -f bestimmen: LoxBerry legt bin/plugins/<x>
# haeufig als Verweis an. Ohne readlink zeigt dirname "$0" auf den Verweis,
# und der Weg nach oben landet im falschen Ordner.

# Als loxberry laufen, nicht als root.
#
# Der minuetliche Waechter kommt aus dem Cron. Laeuft der als root - und je
# nach Ablage des Cronjobs tut er das -, dann gehoerten PID-Datei, Sollmerker
# und Protokoll danach root. Die Oberflaeche laeuft als loxberry und koennte
# den Dienst anschliessend weder anhalten noch neu starten: sie darf die
# Dateien nicht mehr schreiben. Schlimmer noch, 'dienst.sh stop' meldet dann
# Erfolg - das kill scheitert, aber das rm der PID-Datei gelingt, weil das
# Verzeichnis loxberry gehoert. Der Dienst laeuft weiter und ist nur noch
# ueber die Prozessliste zu finden.
#
# Deshalb setzt sich das Skript selbst herunter, EINMAL und bevor es
# irgendetwas anlegt. exec, damit kein zusaetzlicher Prozess stehen bleibt.
# '-s /bin/bash' ausdruecklich: ohne das nimmt su die Login-Shell aus
# /etc/passwd. Steht dort nologin oder /bin/false, endet dieses Skript hier
# still und ohne Meldung - und weil es 'exec' ist, kaeme nicht einmal ein
# Rueckgabewert zurueck. Auf einem regulaeren LoxBerry ist der Zweig ohnehin
# unerreichbar (der Cron laeuft bereits als loxberry); er greift nur, wenn
# jemand von Hand mit sudo aufruft.
#
# Woertlich uebernommen aus LoxBerry-Plugin-Dashboard-0.9.12, dort seit dem
# 16.08.2026 in Betrieb. Ueber den Bestand gezaehlt am 31.08.2026: 15 von 17
# dienst.sh hatten den Abstieg nicht, obwohl REGELN_2 ihn seit langem
# verlangt.
if [ "$(id -u)" = "0" ] && id loxberry >/dev/null 2>&1; then
    exec su -s /bin/bash loxberry -c "$(printf '%q ' "$0" "$@")"
fi

SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
BASE=$(cd "$SELF/../../.." && pwd)       # von bin/plugins/<x> zur LoxBerry-Wurzel

# Den Ordnernamen NICHT festschreiben, sondern dort ablesen, wo das Skript
# wirklich liegt. Beansprucht ein zweites Plugin denselben FOLDER, installiert
# LoxBerry es nach "funkwacht01" - ein hartes "funkwacht" zeigte dann auf das
# Verzeichnis des fremden Plugins. Steht die Umgebungsvariable schon, gilt sie.
PLUGIN="${LBPPLUGINDIR:-$(basename "$SELF")}"
case "$PLUGIN" in
    bin|plugins|""|.|/) PLUGIN=funkwacht ;;
esac
# Beide Python-Prozesse leiten ihre Pfade daraus ab - eine Wahrheit, nicht zwei.
export LBPPLUGINDIR="$PLUGIN"
export LBHOMEDIR="${LBHOMEDIR:-$BASE}"

DATA="$BASE/data/plugins/$PLUGIN"
LOG="$BASE/log/plugins/$PLUGIN"
PY=$(command -v python3 || echo /usr/bin/python3)

mkdir -p "$DATA" "$LOG"

# $1 = PID-Datei, $2 = Skriptname
laeuft_p() {
    [ -f "$1" ] || return 1
    PID=$(cat "$1" 2>/dev/null)
    [ -n "$PID" ] || return 1
    # Argumentweise pruefen, nicht mit grep ueber die ganze Befehlszeile:
    # ein grep auf "funkwacht" faende auch die eigene Suche.
    tr '\0' '\n' < "/proc/$PID/cmdline" 2>/dev/null | grep -q "/$2\$" && return 0
    return 1
}

# $1 = PID-Datei, $2 = Skriptname, $3 = Ausgabedatei
start_p() {
    if laeuft_p "$1" "$2"; then return 0; fi
    nohup "$PY" "$SELF/$2" >> "$LOG/$3" 2>&1 &
    echo $! > "$1"
    sleep 1
    # Die Wirkung pruefen, nicht den Rueckgabewert: nohup meldet Erfolg,
    # auch wenn Python eine Sekunde spaeter aussteigt.
    if laeuft_p "$1" "$2"; then return 0; fi
    rm -f "$1"
    return 1
}

stop_p() {
    if ! laeuft_p "$1" "$2"; then rm -f "$1"; return 0; fi
    PID=$(cat "$1")
    kill "$PID" 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do laeuft_p "$1" "$2" || break; sleep 1; done
    if laeuft_p "$1" "$2"; then kill -9 "$PID" 2>/dev/null; sleep 1; fi
    rm -f "$1"
    return 0
}

WPID="$DATA/dienst.pid"
MPID="$DATA/mithoerer.pid"

# Braucht dieses Haus ueberhaupt einen Mithoerer? Ohne Stick auf der Art MQTT
# waere ein zweiter Prozess Ballast.
mithoerer_noetig() {
    [ -f "$BASE/config/plugins/$PLUGIN/funkwacht.json" ] || return 1
    grep -q '"art2\{0,1\}"[[:space:]]*:[[:space:]]*"mqtt"' \
        "$BASE/config/plugins/$PLUGIN/funkwacht.json" 2>/dev/null
}

case "$1" in
    start)
        RC=0
        if laeuft_p "$WPID" funkwacht_dienst.py; then
            echo "Funkwacht laeuft bereits (PID $(cat "$WPID"))."
        elif start_p "$WPID" funkwacht_dienst.py dienst.out; then
            echo "Funkwacht gestartet (PID $(cat "$WPID"))."
        else
            echo "Funkwacht konnte nicht gestartet werden. Siehe $LOG/dienst.out"
            tail -n 20 "$LOG/dienst.out" 2>/dev/null
            RC=1
        fi
        if mithoerer_noetig; then
            if laeuft_p "$MPID" fw_mqtt.py; then
                echo "Mithoerer laeuft bereits (PID $(cat "$MPID"))."
            elif start_p "$MPID" fw_mqtt.py mithoerer.out; then
                echo "Mithoerer gestartet (PID $(cat "$MPID"))."
            else
                echo "Mithoerer konnte nicht gestartet werden. Siehe $LOG/mithoerer.out"
                RC=1
            fi
        else
            stop_p "$MPID" fw_mqtt.py
            echo "Kein Stick auf der Art MQTT - der Mithoerer wird nicht gebraucht."
        fi
        exit $RC
        ;;
    stop)
        stop_p "$MPID" fw_mqtt.py
        stop_p "$WPID" funkwacht_dienst.py
        echo "Funkwacht angehalten."
        ;;
    restart)
        "$0" stop
        "$0" start
        ;;
    status)
        RC=3
        if laeuft_p "$WPID" funkwacht_dienst.py; then
            echo "Waechter laeuft (PID $(cat "$WPID"))"
            RC=0
        else
            echo "Waechter steht"
        fi
        if laeuft_p "$MPID" fw_mqtt.py; then
            echo "Mithoerer laeuft (PID $(cat "$MPID"))"
        elif mithoerer_noetig; then
            echo "Mithoerer steht - er wird aber gebraucht"
            RC=3
        else
            echo "Mithoerer steht (wird nicht gebraucht)"
        fi
        exit $RC
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status}"; exit 1
        ;;
esac
