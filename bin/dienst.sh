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

# Beide Dienste laufen als loxberry; wo es den Benutzer nicht gibt, als der
# eigene. Die Suche ueber /proc sieht nur dessen Prozesse an.
DIENST_UID=$(id -u loxberry 2>/dev/null || id -u)

mkdir -p "$DATA" "$LOG"

# ---------- Die eigenen Prozesse erkennen ----------
#
# Bis 1.0.5 stand in laeuft_p
#     tr '\0' '\n' < "/proc/$PID/cmdline" 2>/dev/null | grep -q "/$2\$"
# Das prueft zwar Argument fuer Argument, aber jedes Argument und nur dessen
# Ende - wer den Pfad irgendwo in seiner Befehlszeile traegt, gilt als Dienst.
# In WSL gemessen (Bestand-2026-09-18/klasse-F-nachmessung, Zeile 4b): ein
# fremder Prozess  python3 -c '...' <dienstpfad>, dessen Nummer in dienst.pid
# stand, wurde erkannt - "status" meldete "Waechter laeuft (PID 5978)", und
# nach "stop" war er tot. Dasselbe galt fuer den Mithoerer.
#
# Ein Treffer hat GENAU zwei Argumente: argv[0] ist ein Python, argv[1] ist
# genau der eigene Skriptpfad. Das dritte Argument schliesst die Einmallaeufe
# aus (--einmal, --selbsttest, --faehigkeit, --themen, --trocken, --heile,
# --probe): sie laufen als eigener Prozess, sind aber nicht der Dauerlaeufer
# und duerfen von "stop" nicht getroffen werden. Der Dauerlaeufer wird an
# genau einer Stelle gestartet, in start_p, als  "$PY" "$SELF/$2".
#
# Gelesen wird ohne Hilfsprogramm: "read -d ''" zerlegt die Befehlszeile am
# Nullbyte. Das spart je Prozess einen Aufruf von tr - der Minutentakt ruft
# diese Schleife sechzig Mal in der Stunde ueber alle Prozesse des Systems.
#
# $1 = Prozessnummer, $2 = Skriptpfad, $3 = derselbe Pfad ueber readlink -f
ist_dienst() {
    [ -r "/proc/$1/cmdline" ] || return 1
    {
        IFS= read -r -d '' fw_a0 || return 1
        IFS= read -r -d '' fw_a1 || return 1
        case "${fw_a0##*/}" in python|python3|python3.*) ;; *) return 1 ;; esac
        if [ "$fw_a1" != "$2" ]; then
            # Zweite Schreibweise desselben Skripts: LoxBerry legt
            # bin/plugins/<x> haeufig als Verweis an. Ein RELATIV gestarteter
            # Dienst traegt nur den Rest des Weges - der wird gegen das
            # Arbeitsverzeichnis DES PROZESSES aufgeloest, nicht gegen das
            # eigene; readlink -f allein naehme sonst das falsche.
            case "$fw_a1" in
                /*) fw_r=$(readlink -f "$fw_a1" 2>/dev/null) ;;
                *)  fw_w=$(readlink -f "/proc/$1/cwd" 2>/dev/null)
                    [ -n "$fw_w" ] || return 1
                    fw_r=$(readlink -f "$fw_w/$fw_a1" 2>/dev/null) ;;
            esac
            [ -n "$fw_r" ] && [ "$fw_r" = "$3" ] || return 1
        fi
        IFS= read -r -d '' fw_a2 && return 1
        return 0
    } < "/proc/$1/cmdline"
}

# Alle eigenen Prozesse eines der beiden Dienste, aufsteigend, ohne Dubletten.
#
# Zwei Quellen, weil keine allein reicht:
#   - die Suche ueber /proc findet auch einen Dienst OHNE PID-Datei.
#     purge_installation raeumt data/plugins/<ordner>/ bei jedem Upgrade ab
#     (Regeln/06); der Minutentakt kann in der Luecke einen zweiten starten.
#     In WSL gemessen: "stop" meldete "Funkwacht angehalten.", und danach lief
#     der Waechter weiter.
#   - die PID-Datei findet auch einen Dienst, der einem anderen Benutzer
#     gehoert (von Hand als root gestartet) und deshalb durch den
#     Benutzerfilter faellt.
#
# $1 = PID-Datei, $2 = Skriptname
dienste() {
    fw_s="$SELF/$2"
    fw_sr=$(readlink -f "$fw_s" 2>/dev/null)
    [ -n "$fw_sr" ] || fw_sr="$fw_s"
    {
        for fw_d in /proc/[0-9]*; do
            fw_n="${fw_d#/proc/}"
            ist_dienst "$fw_n" "$fw_s" "$fw_sr" || continue
            [ "$(stat -c %u "$fw_d" 2>/dev/null)" = "$DIENST_UID" ] || continue
            echo "$fw_n"
        done
        fw_p=""
        [ -f "$1" ] && IFS= read -r fw_p < "$1" 2>/dev/null
        case "$fw_p" in
            ''|*[!0-9]*) ;;
            *) ist_dienst "$fw_p" "$fw_s" "$fw_sr" && echo "$fw_p" ;;
        esac
    } | sort -un
}

# $1 = PID-Datei, $2 = Skriptname
laeuft_p() {
    [ -n "$(dienste "$1" "$2")" ]
}

# $1 = PID-Datei, $2 = Skriptname, $3 = Ausgabedatei
start_p() {
    fw_l=$(dienste "$1" "$2")
    if [ -n "$fw_l" ]; then
        # Die PID-Datei nachziehen, wenn sie fehlt oder veraltet ist. Die
        # Nummer ist argumentweise geprueft - eine ungeprueft uebernommene
        # Nummer darf hier nie hinein.
        printf '%s\n' "$fw_l" | head -n 1 > "$1" 2>/dev/null
        return 0
    fi
    nohup "$PY" "$SELF/$2" >> "$LOG/$3" 2>&1 &
    echo $! > "$1"
    sleep 1
    # Die Wirkung pruefen, nicht den Rueckgabewert: nohup meldet Erfolg,
    # auch wenn Python eine Sekunde spaeter aussteigt.
    if laeuft_p "$1" "$2"; then return 0; fi
    rm -f "$1"
    return 1
}

# $1 = PID-Datei, $2 = Skriptname
stop_p() {
    # ALLE eigenen Prozesse, nicht nur den aus der PID-Datei - und vor JEDEM
    # Signal geprueft, auch vor dem harten.
    fw_ziel=$(dienste "$1" "$2")
    if [ -z "$fw_ziel" ]; then rm -f "$1"; return 0; fi
    kill $fw_ziel 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do laeuft_p "$1" "$2" || break; sleep 1; done
    # Vor dem harten Signal wird NEU gesucht, nicht die Liste von vorhin
    # wiederverwendet: zwischen den beiden Signalen kann ein Prozess enden und
    # seine Nummer neu vergeben werden.
    fw_rest=$(dienste "$1" "$2")
    if [ -n "$fw_rest" ]; then kill -9 $fw_rest 2>/dev/null; sleep 1; fi
    rm -f "$1"
    # Nachsehen, nicht zusichern (Kernschicht 2, "Wirkung pruefen").
    [ -z "$(dienste "$1" "$2")" ]
}

WPID="$DATA/dienst.pid"
MPID="$DATA/mithoerer.pid"

# ---------- Laeuft gerade eine Aktualisierung? ----------
#
# Die Marke liegt NEBEN dem Datenordner: purge_installation loescht
# data/plugins/<ordner>/ bei jedem Upgrade (Regeln/06), den Nachbarn mit dem
# Punkt trifft es nicht. preupgrade.sh legt sie als Erstes an, postupgrade.sh
# raeumt sie weg, uninstall ebenfalls.
#
# Warum ueberhaupt: zwischen purge_installation und postinstall.sh liegt fast
# eine Minute (am Geraet gemessen, Regeln/06: 03:31:32 bis 03:32:24), und
# cron/cron.01min ruft in dieser Luecke "dienst.sh start". Der Waechter lief
# dann mit leerem Datenordner an und schrieb historie.json neu - die Rettung
# in postinstall.sh fand die Datei vor und uebersprang sich. Zaehler und
# Verlauf waren nach jeder Aktualisierung weg. Am 18.09.2026 in WSL gemessen
# (Pruefung-Funkwacht-1.0.5/messe_luecke.sh, Fall 1).
#
# Aelter als 3600 s, aus der Zukunft oder unlesbar: die Marke gilt NICHT -
# eine abgebrochene Installation darf den Dienst nicht fuer immer stilllegen.
# OHNE LESBARE UHR faellt die Pruefung GESCHLOSSEN aus: wer die Zeit nicht
# messen kann, kann das Alter nicht beurteilen und startet deshalb nicht.
#
# FW_START_TROTZ_MARKE=1 ist die Ausnahme fuer postinstall.sh: dort SOLL der
# Dienst wieder anlaufen, obwohl die Marke noch liegt - postupgrade.sh raeumt
# sie erst danach weg.
MARKE="$BASE/data/plugins/$PLUGIN.upgrade_laeuft"

upgrade_laeuft() {
    [ -f "$MARKE" ] || return 1
    [ -n "${FW_START_TROTZ_MARKE:-}" ] && return 1
    fw_jetzt=$(date +%s 2>/dev/null)
    case "$fw_jetzt" in ''|*[!0-9]*) return 0 ;; esac
    fw_dann=""
    IFS= read -r fw_dann < "$MARKE" 2>/dev/null
    case "$fw_dann" in ''|*[!0-9]*) return 1 ;; esac
    [ "$fw_dann" -gt "$fw_jetzt" ] && return 1
    [ $((fw_jetzt - fw_dann)) -lt 3600 ]
}

# Braucht dieses Haus ueberhaupt einen Mithoerer? Ohne Stick auf der Art MQTT
# waere ein zweiter Prozess Ballast.
mithoerer_noetig() {
    [ -f "$BASE/config/plugins/$PLUGIN/funkwacht.json" ] || return 1
    grep -q '"art2\{0,1\}"[[:space:]]*:[[:space:]]*"mqtt"' \
        "$BASE/config/plugins/$PLUGIN/funkwacht.json" 2>/dev/null
}

case "$1" in
    start)
        if upgrade_laeuft; then
            # Kein Fehler: der Takt darf sich nicht beschweren, und
            # postinstall.sh startet gleich selbst.
            echo "Eine Aktualisierung laeuft - es wird nichts gestartet."
            exit 0
        fi
        RC=0
        # Gemeldet werden die GEFUNDENEN Nummern, nicht der Inhalt der
        # PID-Datei: liegt dort eine fremde oder veraltete Nummer, waere sie
        # eine Falschaussage. Laufen zwei, stehen beide da.
        WLAUF=$(dienste "$WPID" funkwacht_dienst.py)
        if [ -n "$WLAUF" ]; then
            printf '%s\n' "$WLAUF" | head -n 1 > "$WPID" 2>/dev/null
            echo "Funkwacht laeuft bereits (PID $(printf '%s' "$WLAUF" | tr '\n' ' '))."
        elif start_p "$WPID" funkwacht_dienst.py dienst.out; then
            echo "Funkwacht gestartet (PID $(cat "$WPID"))."
        else
            echo "Funkwacht konnte nicht gestartet werden. Siehe $LOG/dienst.out"
            tail -n 20 "$LOG/dienst.out" 2>/dev/null
            RC=1
        fi
        if mithoerer_noetig; then
            MLAUF=$(dienste "$MPID" fw_mqtt.py)
            if [ -n "$MLAUF" ]; then
                printf '%s\n' "$MLAUF" | head -n 1 > "$MPID" 2>/dev/null
                echo "Mithoerer laeuft bereits (PID $(printf '%s' "$MLAUF" | tr '\n' ' '))."
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
        RC=0
        stop_p "$MPID" fw_mqtt.py || RC=1
        stop_p "$WPID" funkwacht_dienst.py || RC=1
        if [ "$RC" = "0" ]; then
            echo "Funkwacht angehalten."
        else
            # "angehalten" ist eine Zusicherung, kein Rueckgabewert. Wer nicht
            # ging, wird benannt.
            UEBRIG="$(dienste "$WPID" funkwacht_dienst.py) $(dienste "$MPID" fw_mqtt.py)"
            echo "FEHLER: Funkwacht laeuft weiter (PID $(printf '%s' "$UEBRIG" | tr '\n' ' '))."
        fi
        exit $RC
        ;;
    restart)
        "$0" stop
        "$0" start
        ;;
    status)
        RC=3
        # Auch hier die gefundenen Nummern, nicht der Inhalt der PID-Datei.
        # Die Zeichenfolge "laeuft (PID" bleibt wortgleich: preupgrade.sh
        # sucht danach (dort Zeile 86 und 87).
        WLAUF=$(dienste "$WPID" funkwacht_dienst.py)
        if [ -n "$WLAUF" ]; then
            echo "Waechter laeuft (PID $(printf '%s' "$WLAUF" | tr '\n' ' '))"
            RC=0
        else
            echo "Waechter steht"
        fi
        MLAUF=$(dienste "$MPID" fw_mqtt.py)
        if [ -n "$MLAUF" ]; then
            echo "Mithoerer laeuft (PID $(printf '%s' "$MLAUF" | tr '\n' ' '))"
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
