<?php
/**
 * Funkwacht - gemeinsame Bibliothek
 *
 * Liegt unter webfrontend/html/, weil der Miniserver-Endpunkt sie ebenso
 * braucht wie die Oberflaeche. 29 der 46 Plugin-Linien dieses Hauses halten
 * es genauso, und zwar genau die 29 mit einem Endpunkt.
 *
 * Praefix 'fw_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * WAS HIER SEIT 0.9.4 GILT
 * ------------------------
 * - fw_config($erzeugen) - der unangemeldete Endpunkt legt nichts an.
 * - Eine beschaedigte Konfiguration ist ein Fehler, kein leerer Wert.
 * - fw_geraete() zaehlt nach der ZEILENNUMMER, genau wie der Waechter.
 * - fw_zeile() liest die Werteliste, die der Waechter abgelegt hat, statt
 *   sie ein zweites Mal zu rechnen.
 * - fw_formtoken() gegen Formulare, die auf fremden Seiten stehen.
 *
 * WAS 1.0.0 DAZUBEKOMMEN HAT
 * --------------------------
 * - Auftraege an den Waechter (quittieren, Wartung) ueber EINE Datei, damit
 *   historie.json genau einen Schreiber behaelt.
 * - Vorlage der Steuerbefehle (VirtualOut) fuer genau diese Auftraege.
 * - Ereignisliste, Tagesdateien und ein Balkenbild der Heilungen.
 * - Vorlagen fuer die bekannten Sticks, Suchhilfe, Sichern und Zurueckspielen.
 */

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen. */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            /* Dritte Bedingung config/system/general.json (Regeln/06):
             * config/plugins und webfrontend allein traegt auch ein
             * Pruefstandsrest; bis 1.0.6 legte die Oberflaeche dort an
             * (Pruefung-Funkwacht-1.0.6, Faelle Q3, Q4). */
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

if (!function_exists('fw_e')) {
    function fw_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
/* Gemeinsame Sprachausgabe (Abschrift von Werkzeuge/gemeinsam/sprachausgabe.php, Nr. 36 b; seit 1.0.17 Stamm 1.2.1
 * mit weiteren Ausgabezielen, fw_mit_zielen(), Welle Mehrfach-Ziele). Liegt neben dieser Datei; die Datei schuetzt
 * sich selbst gegen doppeltes Laden. */
require_once __DIR__ . '/sprachausgabe.php';
require_once __DIR__ . '/meldung.php';     // Statusmeldungen an SignalBot (seit 1.0.32)
/* 1.0.27: das gemeinsame Sicherungsmodul (Abschrift von Werkzeuge/gemeinsam/sicherung.php; Auftrag des Hausherrn
 * 12.11.2026, Umbau dieser Linie 18.11.2026). Liegt neben dieser Datei und schuetzt sich selbst gegen doppeltes
 * Laden. */
require_once __DIR__ . '/sicherung.php';
require_once __DIR__ . '/haus_bereiche.php';     // gemeinsame Bereiche unter haus/ (seit 1.0.32, Regeln/07)
function fw_x($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

/* Wie viele Zeilen die Oberflaeche hoechstens fuehrt. Die WIRKLICHE Zahl
 * steht in der Konfiguration (fw_zeilen) und laesst sich vom Bediener
 * erhoehen; diese Grenze ist nur der Deckel. */
define('FW_GERAETE_MAX', 24);
define('FW_STUFEN_TEXT', 'keine|dienst|sysfs|uhubctl');

/**
 * Welcher Ordnername gilt, wenn er sich nicht ableiten laesst?
 *
 * Ein harter Rueckfall auf "funkwacht" ist gefaehrlich: beansprucht ein
 * zweites Plugin denselben FOLDER, haelt LoxBerry beide fuer verschieden -
 * der MD5-Schluessel der plugindatabase.json entsteht aus Autor, E-Mail und
 * Name - und installiert das zweite nach "funkwacht01". Der Rueckfall zeigte
 * dann auf das Verzeichnis des FREMDEN Plugins und legte dort eine
 * Konfiguration an, die niemand liest.
 *
 * Deshalb: erst positiv suchen, wo die EIGENE Konfigurationsdatei liegt.
 * Erst wenn es keine gibt, wird der vorgesehene Name genommen - und auch das
 * nur, wenn dort nichts Fremdes steht.
 */
function fw_ordner_bestimmen($basis, $abgeleitet)
{
    $wurzel = $basis . '/config/plugins';
    if ($abgeleitet !== '' && is_file($wurzel . '/' . $abgeleitet . '/funkwacht.json')) {
        return $abgeleitet;
    }
    $treffer = array();
    foreach ((array) @glob($wurzel . '/*/funkwacht.json') as $f) {
        $treffer[] = basename(dirname($f));
    }
    /* Genau einer ist eindeutig. Bei mehreren wird nichts geraten - dann
     * entscheidet der vorgesehene Name wie bei einer Neuinstallation. */
    if (count($treffer) === 1) { return $treffer[0]; }
    $vorgesehen = $wurzel . '/funkwacht';
    if (!is_dir($vorgesehen)) { return 'funkwacht'; }
    $inhalt = (array) @scandir($vorgesehen);
    $inhalt = array_diff($inhalt, array('.', '..'));
    if (!$inhalt) { return 'funkwacht'; }
    /* Dort liegt etwas, und es ist nicht unseres: den abgeleiteten Namen
     * stehen lassen, statt in fremde Dateien zu schreiben. */
    return $abgeleitet !== '' ? $abgeleitet : 'funkwacht';
}

function fw_paths($neu = false)
{
    static $p = null;
    if ($p !== null && !$neu) { return $p; }
    /* Die Wurzel wird GELESEN (Regeln/06): ein gesetztes LBHOMEDIR gilt mit
     * config/plugins und data/plugins darunter - oder gar nichts; ohne
     * LBHOMEDIR wird gesucht (lb_wurzel_ermitteln, mit general.json). Bis
     * 1.0.6 stand nach der Suche ein fester Rueckfall zwei Ebenen ueber
     * dieser Datei, und fw_log(), fw_token() und fw_config() legten dort an -
     * aus einem ausgepackten Archiv im Archiv selbst (Fall Q5). Ohne Wurzel
     * gibt es jetzt KEINE Pfade: jeder Eintrag ist ''. */
    $home = (string) getenv('LBHOMEDIR');
    if ($home !== '') {
        if (!is_dir($home . '/config/plugins') || !is_dir($home . '/data/plugins')) { $home = ''; }
    } else {
        $home = lb_wurzel_ermitteln();
    }
    $dir = getenv('LBPPLUGINDIR');
    if (!$dir) { $dir = basename(dirname(__FILE__)); }
    if ($home === '') {
        $p = array('home' => '', 'plugin' => $dir);
        foreach (array('configdir', 'config', 'sicherung', 'zugang', 'zugang_sicherung', 'datadir', 'stand', 'historie',
                       'auftraege', 'mqttstand', 'verlauf', 'bestand', 'logdir', 'log',
                       'dienstlog', 'bindir', 'altpraefix', 'einmal', 'gleichwert') as $fw_k) {
            $p[$fw_k] = '';
        }
        return $p;
    }
    $basis = $home;
    if ($dir === '' || $dir === '.' || $dir === '/' || $dir === 'html' || $dir === 'plugins') {
        $dir = fw_ordner_bestimmen($basis, $dir);
    }
    $p = array(
        'home'      => $home,
        'plugin'    => $dir,
        'configdir' => $basis . '/config/plugins/' . $dir,
        'config'    => $basis . '/config/plugins/' . $dir . '/funkwacht.json',
        'sicherung' => $basis . '/config/plugins/' . $dir . '.backup.json',
        /* 1.0.27 (Entscheidung des Hausherrn 18.11.2026): das Broker-Kennwort, das der Mithoerer liest, in einer
         * eigenen Datei 0600 (Regeln/05) - mit Zweitschrift neben dem Konfigordner wie funkwacht.json. */
        'zugang'    => $basis . '/config/plugins/' . $dir . '/zugang.json',
        'zugang_sicherung' => $basis . '/config/plugins/' . $dir . '.backup.zugang.json',
        'datadir'   => $basis . '/data/plugins/' . $dir,
        'stand'     => $basis . '/data/plugins/' . $dir . '/stand.json',
        'historie'  => $basis . '/data/plugins/' . $dir . '/historie.json',
        'auftraege' => $basis . '/data/plugins/' . $dir . '/auftraege.json',
        'mqttstand' => $basis . '/data/plugins/' . $dir . '/mqtt_stand.json',
        /* M5: Praefixe, unter denen der Waechter einmal abraeumen soll. */
        'altpraefix' => $basis . '/data/plugins/' . $dir . '/mqtt_altpraefix.json',
        /* U8: das Ergebnis eines POST fuer den folgenden GET (0600). */
        'einmal'    => $basis . '/data/plugins/' . $dir . '/einmalmeldung.json',
        /* X-7 (Welle 4): der zuletzt angenommene Sollwert je Befehl. */
        'gleichwert' => $basis . '/data/plugins/' . $dir . '/gleichwert.json',
        'verlauf'   => $basis . '/data/plugins/' . $dir . '/verlauf',
        /* NEBEN dem Datenordner - der Installer loescht data/plugins/<x>/
         * bei jedem Update, den Nachbarn mit dem Punkt trifft er nicht. */
        'bestand'   => $basis . '/data/plugins/' . $dir . '.bestand',
        'logdir'    => $basis . '/log/plugins/' . $dir,
        'log'       => $basis . '/log/plugins/' . $dir . '/funkwacht.log',
        'dienstlog' => $basis . '/log/plugins/' . $dir . '/dienst.out',
        'bindir'    => $basis . '/bin/plugins/' . $dir,
    );
    return $p;
}

/* ==================================================================
 * Konfiguration
 * ================================================================== */

function fw_geraet_vorgabe()
{
    return array(
        'name' => '', 'aktiv' => 1,
        'art' => 'datei', 'pfad' => '', 'thema' => '', 'kennung' => '',
        'wachstum' => 0,
        'art2' => '', 'pfad2' => '', 'thema2' => '', 'verkn' => 'oder',
        'hoechstalter' => 300,
        'heilen' => 1, 'dienst' => '', 'container' => '',
        'usb_pfad' => '', 'hub' => '', 'port' => 0, 'hoechststufe' => 2,
        'reihenfolge' => 'normal', 'dienst_nach' => 1, 'lernen' => 0,
        'ruhe_s' => 120, 'abstand_s' => 600, 'je_tag' => 6,
    );
}

function fw_vorgaben()
{
    return array(
        'geraete'      => array(),
        'zeilen'       => 8,
        'takt'         => 60,
        'mqtt_ein'     => 1,
        'mqtt_topic'   => 'funkwacht',
        /* Gruppe 44 (1.0.17): Befehle ueber die Befehlsbruecke (ab Werk und beim Update aus; UDP 11884 nimmt im
         * Heimnetz ohne Anmeldung an) und der HTTP-Endpunkt (Neuinstallation aus, beim Update setzt postinstall.sh 1,
         * wenn der Schluessel fehlt - wer die HTTP-Vorlagen nutzt, aendert nichts). */
        'befehle_mqtt_ein' => 0,
        'endpunkt_ein' => 0,
        'aktionstoken' => '',
        'anlauf_s'     => 300,
        'ruhe_von'     => '',
        'ruhe_bis'     => '',
        'global_aus'   => 0,
        'log_kb'       => 500,
        'verlauf_tage' => 90,
        'melden_aktiv' => 1,
        'signal_ein'   => 0,
        'signal_url'   => '',
        'broker_host'  => '',
        'broker_port'  => '',
        'broker_user'  => '',
        /* 1.0.27: broker_pass steht nicht mehr hier, sondern in zugang.json (fw_broker_pass(), fw_zugang_speichern()). */
        'broker_id'    => '',
        /* Nr. 36 b (Stufe 2): Ansage ueber die gemeinsame Sprachausgabe - ab Werk keine Ausgabeart
         * ('aus'); die Anlaesse sind an, wirken aber erst mit einer Ausgabeart. */
        'ansage_gestoert' => 1,
        'ansage_wieder'   => 1,
        'tts'          => ansage_werk_block(),
        /* 1.0.27 (Regeln/05: was das Zurueckspielen oder ein gemeinsames Modul in die Konfiguration schreibt, steht
         * in den Vorgaben): die Regeln der Sprachansage (seit 1.0.19, fw_ansage_regeln() liest ohne Eintrag dieselben
         * Vorgaben) und der Vermerk der Sprechtoken (nie aus einer Sicherung, ohne Eintrag dasselbe wie leer). */
        'ansage_regeln' => ansage_regeln_vorgaben(),
        'ansage_token_herkunft' => array(),
    );
}

/** Die Arten, die sich auswaehlen lassen. Reihenfolge = Reihenfolge im Feld. */
function fw_arten()
{
    return array(
        'datei'     => 'FW_ART.DATEI',
        'mqtt'      => 'FW_ART.MQTT',
        'http'      => 'FW_ART.HTTP',
        'usb'       => 'FW_ART.USB',
        'seriell'   => 'FW_ART.SERIELL',
        'dienst'    => 'FW_ART.DIENST',
        'docker'    => 'FW_ART.DOCKER',
        'bluetooth' => 'FW_ART.BLUETOOTH',
    );
}

/** Dasselbe mit dem leeren Eintrag - fuer das zweite Kriterium. */
function fw_arten2()
{
    return array('' => 'FW_ART.KEINE') + fw_arten();
}

/** Zahlencodes fuer Loxone - dieselbe Zuordnung wie in bin/fw_pruef.py. */
function fw_grund_nr()
{
    return array('frisch' => 0, 'veraltet' => 1, 'nie_gesehen' => 2,
                 'zeitsprung' => 3, 'aus' => 4, 'erholung' => 5);
}

function fw_warum_nr()
{
    return array('' => 0, 'frei' => 0, 'heilen_aus' => 1, 'abstand' => 2,
                 'tagesgrenze' => 3, 'keine_stufe_mehr' => 4, 'nie_gesehen' => 5,
                 'anlaufzeit' => 6, 'nachtruhe' => 7, 'wartung' => 8,
                 'global_aus' => 9, 'dienst_fehlt' => 10);
}

/**
 * Fertige Zeilen fuer die bekannten Faelle.
 *
 * ACHTUNG, und das steht auch in der Oberflaeche: Diese Pfade sind
 * VORSCHLAEGE aus der Erfahrung, keine Messwerte. Sie werden in das Feld
 * gesetzt und muessen dort geprueft werden - der Knopf "Jetzt messen" neben
 * der Zeile beantwortet in einer Sekunde, ob der Pfad stimmt.
 */
function fw_vorlagen($zb = null)
{
    /* Gruppe 44 (1.0.17, E8, Sammelstelle #41): Zigbee2MQTT ODER Zigbee2MqttNG - die Vorlage bestimmt den Dienst
     * selbst (fw_zigbee_dienst(): Plugin-Datenbank, Gegenprobe systemd). Bis 1.0.16 gab es drei Vorlagen; zwei
     * nannten fest den alten Dienst zigbee2mqtt, und ein falscher Dienstname fuehrte zu "Dienst nicht vorhanden - es
     * wird nicht geheilt" (funkwacht_dienst.py, dienst_fehlt_hinweis()). Die Vorlage "Zigbee2MqttNG ueber MQTT"
     * entfaellt in der Liste; gespeicherte Zeilen bleiben unberuehrt. Die Oberflaeche zeigt, was erkannt wurde.
     * B10 (30.10.2026, vorher B2): den Pfad der Protokolldatei liest fw_zigbee_logpfad() aus der configuration.yaml
     * des Dienstes (log_directory, log_file); der alte feste Vorschlag <ordner>/data/log/log.txt stimmte unter
     * Zigbee2MqttNG nicht. Er gehoert wie jeder Vorlagenpfad mit "Jetzt messen" geprueft. Ein falscher Pfad heilt
     * nicht: "nie gesehen" sperrt das Heilen (warum nie_gesehen). $zb['yaml'] nur fuer Proben. */
    $zb = is_array($zb) ? $zb : fw_zigbee_dienst();
    return array(
        'z2m_paket' => array(
            'text' => 'VORL.Z2M_PAKET',
            'werte' => array('art' => 'datei',
                             'pfad' => fw_zigbee_logpfad($zb['ordner'], isset($zb['yaml']) ? $zb['yaml'] : null),
                             'wachstum' => 1, 'dienst' => $zb['dienst'],
                             'hoechstalter' => 900, 'hoechststufe' => 2),
        ),
        'z2m_mqtt' => array(
            'text' => 'VORL.Z2M_MQTT',
            /* M2 (Pruefung 29.09.2026): bridge/state ist retained und kommt
             * nur bei einer Aenderung - als Lebenszeichen taugt es nicht.
             * bridge/health sendet Zigbee2MQTT 2.x im Takt von health.interval
             * (Vorgabe 10 Minuten, am Geraet gemessen); 1500 s lassen zwei
             * Takte Luft. Zigbee2MqttNG sendet unter denselben Themen
             * (Koordinator 08.10.2026, z2mng_hausstandard F-1).
             * S3 (Pruefung 29.09.2026): kein zweites Kriterium.
             * c1 (Entscheidung 16, Welle 4): zunaechst NUR MELDEN - Heilen
             * ab Werk aus. Eingeschaltet wird es nach einer Woche
             * Beobachtung von Hand; der Dienst steht schon da. */
            'werte' => array('art' => 'mqtt', 'thema' => 'zigbee2mqtt/bridge/health',
                             'art2' => '', 'pfad2' => '', 'verkn' => 'oder',
                             'dienst' => $zb['dienst'], 'heilen' => 0,
                             'hoechstalter' => 1500, 'hoechststufe' => 2),
        ),
        'z2m_docker' => array(
            'text' => 'VORL.Z2M_DOCKER',
            'werte' => array('art' => 'docker', 'pfad' => 'zigbee2mqtt',
                             'container' => 'zigbee2mqtt',
                             'hoechstalter' => 900, 'hoechststufe' => 2),
        ),
        'zwavejs' => array(
            'text' => 'VORL.ZWAVEJS',
            'werte' => array('art' => 'http', 'pfad' => 'http://127.0.0.1:8091/',
                             'dienst' => 'zwave-js-ui',
                             'hoechstalter' => 600, 'hoechststufe' => 2),
        ),
        'deconz' => array(
            'text' => 'VORL.DECONZ',
            'werte' => array('art' => 'dienst', 'pfad' => 'deconz',
                             'dienst' => 'deconz',
                             'hoechstalter' => 600, 'hoechststufe' => 2),
        ),
        'bluetooth' => array(
            'text' => 'VORL.BLUETOOTH',
            'werte' => array('art' => 'bluetooth', 'pfad' => 'hci0',
                             'dienst' => 'bluetooth',
                             'hoechstalter' => 600, 'hoechststufe' => 1),
        ),
        'seriell' => array(
            'text' => 'VORL.SERIELL',
            'werte' => array('art' => 'seriell', 'pfad' => '/dev/ttyUSB0',
                             'hoechstalter' => 300, 'hoechststufe' => 2),
        ),
    );
}

/**
 * Eine JSON-Datei lesen. Rueckgabe: array(Daten, Zustand).
 *
 * Zustand ist 'ok', 'fehlt' oder 'kaputt'. Eine abgeschnittene Datei -
 * Stromausfall mitten im Schreiben - ergibt json_decode() === null. Wer
 * daraus ein leeres Array macht, schreibt stillschweigend die
 * Werkseinstellung zurueck und nimmt die noch heile Zweitschrift mit.
 */
function fw_json_lesen_geprueft($pfad)
{
    if (!is_file($pfad)) { return array(array(), 'fehlt'); }
    $roh = @file_get_contents($pfad);
    if ($roh === false) { return array(array(), 'kaputt'); }
    if (trim($roh) === '') { return array(array(), 'fehlt'); }
    $d = json_decode($roh, true);
    if (!is_array($d)) { return array(array(), 'kaputt'); }
    return array($d, 'ok');
}

function fw_json_lesen($pfad)
{
    list($d, $z) = fw_json_lesen_geprueft($pfad);
    return $z === 'ok' ? $d : array();
}

/**
 * Erst in eine Nebendatei, dann umbenennen.
 *
 * Die Nebendatei traegt die Prozessnummer und einen Zufallsanteil: Waechter
 * und Oberflaeche koennen im selben Augenblick schreiben. Das Ergebnis von
 * json_encode wird geprueft: bei ungueltigem UTF-8 liefert es false, und
 * file_put_contents macht daraus eine LEERE Datei mit Rueckgabe 0.
 */
function fw_json_schreiben($pfad, $daten, $rechte = null)
{
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) { return false; }
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { return false; }
    $tmp = $pfad . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(3));
    $fh = @fopen($tmp, 'c');
    if ($fh === false) { return false; }
    if ($rechte !== null) { @chmod($tmp, $rechte); }
    /* Geschrieben ist erst, was GANZ geschrieben ist (U1, Pruefung
     * 29.09.2026, Befund code 5): fwrite() liefert bei voller Karte eine
     * kleinere Zahl, nicht false - in WSL gemessen 8192 von 19143 Byte,
     * Rueckgabe true, und rename() machte die gekuerzte Datei zur
     * Konfiguration. Deshalb: Laenge vergleichen, fflush und fclose pruefen,
     * die Nebendatei zuruecklesen und vergleichen, erst dann umbenennen. */
    $n = ftruncate($fh, 0) ? @fwrite($fh, $json) : false;
    $ok = ($n === strlen($json));
    $ok = @fflush($fh) && $ok;
    $ok = @fclose($fh) && $ok;
    if ($ok) {
        clearstatcache(true, $tmp);
        $ok = (@file_get_contents($tmp) === $json);
    }
    if (!$ok) { @unlink($tmp); return false; }
    if (!@rename($tmp, $pfad)) { @unlink($tmp); return false; }
    return true;
}

/** Ein Geraet auf gueltige Grenzen bringen - dieselbe Rechnung wie im Kern. */
function fw_geraet_geradebiegen($roh)
{
    $g = fw_geraet_vorgabe();
    foreach ($g as $k => $v) {
        if (isset($roh[$k])) { $g[$k] = $roh[$k]; }
    }
    $arten = fw_arten();
    $g['name'] = trim((string) $g['name']);
    if (!isset($arten[$g['art']])) { $g['art'] = 'datei'; }
    if ($g['art2'] !== '' && !isset($arten[$g['art2']])) { $g['art2'] = ''; }
    $g['verkn'] = in_array($g['verkn'], array('und', 'oder'), true) ? $g['verkn'] : 'oder';
    $g['reihenfolge'] = $g['reihenfolge'] === 'usb_zuerst' ? 'usb_zuerst' : 'normal';
    foreach (array('aktiv', 'heilen', 'wachstum', 'dienst_nach', 'lernen') as $k) {
        $g[$k] = empty($g[$k]) ? 0 : 1;
    }
    $g['hoechstalter'] = max(10, min(86400, (int) $g['hoechstalter']));
    $g['hoechststufe'] = max(0, min(3, (int) $g['hoechststufe']));
    $g['ruhe_s']    = max(10, min(3600, (int) $g['ruhe_s']));
    $g['abstand_s'] = max(30, min(86400, (int) $g['abstand_s']));
    $g['je_tag']    = max(0, min(50, (int) $g['je_tag']));
    $g['port']      = max(0, min(99, (int) $g['port']));
    return $g;
}

/**
 * Die Konfiguration lesen.
 *
 * $erzeugen = false bedeutet: NUR lesen. Kein mkdir, kein Zurueckschreiben,
 * kein Beiseitelegen. Der unangemeldete Endpunkt ruft ausschliesslich so auf.
 */
function fw_config($erzeugen = true)
{
    $p = fw_paths();
    list($roh, $zustand) = fw_json_lesen_geprueft($p['config']);

    if ($zustand === 'kaputt') {
        if ($erzeugen) {
            @rename($p['config'], $p['config'] . '.kaputt');
            fw_log('Die Konfiguration war unlesbar und liegt jetzt als '
                 . basename($p['config']) . '.kaputt daneben.');
        }
        $zustand = 'fehlt';
        $roh = array();
    }

    if ($zustand === 'fehlt') {
        list($sicher, $zs) = fw_json_lesen_geprueft($p['sicherung']);
        if ($zs === 'ok') {
            $roh = $sicher;
            if ($erzeugen) {
                if (!is_dir($p['configdir'])) { @mkdir($p['configdir'], 0775, true); }
                if (fw_json_schreiben($p['config'], $roh, 0600)) {
                    fw_log('Konfiguration aus der Zweitschrift wiederhergestellt.');
                } else {
                    fw_log('Die Konfiguration liess sich aus der Zweitschrift nicht wiederherstellen '
                         . '(Schreiben gescheitert); gelesen wird vorerst die Zweitschrift.');
                }
            }
        }
    }

    /* 1.0.27: der Uebergang des Broker-Kennworts nach zugang.json und deren Selbstheilung aus der Zweitschrift - nur
     * beim Lesen mit Schreibrecht, also nie vom unangemeldeten Endpunkt (fw_config(false)). */
    if ($erzeugen) {
        if (is_array($roh) && array_key_exists('broker_pass', $roh) && fw_zugang_uebergang() === 'uebernommen') {
            unset($roh['broker_pass']);
        }
        fw_zugang_lesen(true);
    }
    $cfg = array_merge(fw_vorgaben(), is_array($roh) ? $roh : array());
    if (!is_array($cfg['geraete'])) { $cfg['geraete'] = array(); }
    $cfg['zeilen'] = max(1, min(FW_GERAETE_MAX, (int) $cfg['zeilen']));
    /* Es werden immer so viele Zeilen gefuehrt, wie eingestellt sind - oder
     * so viele, wie belegt sind. Wer die Zahl kleiner stellt, verliert damit
     * keine Zeile stillschweigend. */
    $belegt = 0;
    foreach ($cfg['geraete'] as $i => $g) {
        if (is_array($g) && trim((string) (isset($g['name']) ? $g['name'] : '')) !== '') {
            $belegt = $i + 1;
        }
    }
    $anzahl = max($cfg['zeilen'], $belegt);
    for ($i = 0; $i < $anzahl; $i++) {
        $cfg['geraete'][$i] = fw_geraet_geradebiegen(
            isset($cfg['geraete'][$i]) && is_array($cfg['geraete'][$i])
                ? $cfg['geraete'][$i] : array());
    }
    $cfg['geraete'] = array_slice($cfg['geraete'], 0, $anzahl);

    $cfg['takt']         = max(15, min(3600, (int) $cfg['takt']));
    $cfg['anlauf_s']     = max(0, min(3600, (int) $cfg['anlauf_s']));
    $cfg['log_kb']       = max(16, min(20000, (int) $cfg['log_kb']));
    $cfg['verlauf_tage'] = max(1, min(730, (int) $cfg['verlauf_tage']));
    foreach (array('mqtt_ein', 'global_aus', 'melden_aktiv', 'signal_ein', 'ansage_gestoert', 'ansage_wieder', 'befehle_mqtt_ein', 'endpunkt_ein') as $k) {
        $cfg[$k] = empty($cfg[$k]) ? 0 : 1;
    }
    foreach (array('ruhe_von', 'ruhe_bis') as $k) {
        $cfg[$k] = preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', (string) $cfg[$k])
            ? (string) $cfg[$k] : '';
    }
    $t = preg_replace('#[^A-Za-z0-9_/\-]#', '', (string) $cfg['mqtt_topic']);
    $cfg['mqtt_topic'] = trim($t, '/') !== '' ? trim($t, '/') : 'funkwacht';
    return $cfg;
}

/**
 * Speichern - und die Zweitschrift aus DENSELBEN Daten schreiben.
 *
 * Zuerst schreiben, dann zuruecklesen - und nur, wenn das gelingt, wird die
 * Zweitschrift erneuert. Bis 0.9.3 wurde kopiert; eine beschaedigte
 * Konfiguration riss die heile Sicherung mit.
 */
function fw_config_speichern($cfg, $leere_objekte = false, $lade_behalten = true)
{
    $p = fw_paths();
    /* 1.0.32 (Lade-Beanstandungen, Regeln/05, Entscheidung des Hausherrn "Dateiwert behalten"): ein beim Laden
     * beanstandetes Feld, das der neue Stand nicht geaendert hat, behaelt seinen gespeicherten Wert - auch beim ersten
     * Token (fw_token() schrieb die normierte Konfiguration). Nicht beim Zurueckspielen, beim Vervollstaendigen und beim
     * Uebergang des Broker-Kennworts (beide schreiben Dateiwerte) und beim Knopf "Angezeigte Werte uebernehmen". */
    if ($lade_behalten && is_array($cfg)) { $cfg = fw_lade_behalten($cfg); }
    if (!fw_json_schreiben($p['config'], $cfg, 0600)) { return false; }
    list($zurueck, $zustand) = fw_json_lesen_geprueft($p['config']);
    if ($zustand !== 'ok') {
        fw_log('Die Konfiguration liess sich nach dem Schreiben nicht zuruecklesen. '
             . 'Die Zweitschrift bleibt unangetastet.');
        return false;
    }
    // Nur aus fw_config_vervollstaendigen() (11.11.2026): die Zweitschrift entsteht aus der zurueckgelesenen Datei;
    // ohne diesen Schritt kaeme ein leeres Objekt ({}) dort als [] an. Das Speichern im Formular ruft ohne Argument.
    if ($leere_objekte) {
        $zurueck = fw_json_leere_objekte($zurueck, json_decode((string) @file_get_contents($p['config'])));
    }
    if (!fw_json_schreiben($p['sicherung'], $zurueck, 0600)) {
        fw_log('Die Zweitschrift liess sich nicht erneuern; die Konfiguration selbst ist geschrieben.');
    }
    return true;
}

/* ==================================================================
 * 1.0.27: Das Broker-Kennwort in einer eigenen Datei (zugang.json, 0600)
 * ==================================================================
 *
 * Entscheidung des Hausherrn 18.11.2026 nach der Hausregel "was ein Dienst braucht, in eine eigene Datei 0600"
 * (Regeln/05, Hausstandard seit 03.09.2026). Der Mithoerer (bin/fw_mqtt.py, zugang()) liest das Kennwort hier; bis
 * 1.0.26 stand es als broker_pass in funkwacht.json. Form: {"broker_pass": "..."}. Die Zweitschrift
 * <ordner>.backup.zugang.json liegt neben dem Konfigordner wie die von funkwacht.json, mit denselben Rechten, und
 * entsteht aus DENSELBEN, zurueckgelesenen Daten (Bauart fw_config_speichern()). Der Uebergang fuer bestehende
 * Anlagen steht in fw_zugang_uebergang(). Ein Kennwort steht nie im Protokoll.
 */

/**
 * zugang.json lesen. Rueckgabe: array(Kennwort|null, Zustand), Zustand 'ok' | 'fehlt' | 'kaputt'.
 * Eine Datei ohne den Schluessel broker_pass (auch "{}") ist 'kaputt', nicht leer. Fehlt die Datei und traegt die
 * Zweitschrift das Kennwort (Merkmal: der Schluessel broker_pass als Text), gilt dieses; mit $erzeugen wird es einmal
 * zurueckgeschrieben und das einmal protokolliert (Regeln/05: Selbstheilung nach Inhalt).
 */
function fw_zugang_lesen($erzeugen = false)
{
    $p = fw_paths();
    if ($p['zugang'] === '') { return array(null, 'fehlt'); }
    clearstatcache(true, $p['zugang']);
    list($d, $z) = fw_json_lesen_geprueft($p['zugang']);
    if ($z === 'ok') {
        return (array_key_exists('broker_pass', $d) && is_string($d['broker_pass']))
            ? array($d['broker_pass'], 'ok') : array(null, 'kaputt');
    }
    if ($z === 'kaputt' || is_file($p['zugang'])) { return array(null, 'kaputt'); }
    list($s, $zs) = fw_json_lesen_geprueft($p['zugang_sicherung']);
    if ($zs !== 'ok' || !array_key_exists('broker_pass', $s) || !is_string($s['broker_pass'])) {
        return array(null, 'fehlt');
    }
    if ($erzeugen && !fw_upgrade_laeuft()) {
        if (fw_json_schreiben($p['zugang'], array('broker_pass' => $s['broker_pass']), 0600)) {
            fw_log('zugang.json fehlte und wurde aus der Zweitschrift wiederhergestellt (Broker-Kennwort).');
        } else {
            fw_log('zugang.json fehlt und liess sich aus der Zweitschrift nicht wiederherstellen (Schreiben gescheitert).');
        }
    }
    return array($s['broker_pass'], 'ok');
}

/**
 * Das geltende Broker-Kennwort: aus zugang.json; solange der Uebergang aussteht (Datei fehlt), aus funkwacht.json.
 * Eine unlesbare zugang.json ergibt '' (fail closed: dann gilt das Kennwort des LoxBerry wie bei leerem Feld) - der
 * Reiter Test zeigt sie rot. Dieselbe Reihenfolge liest bin/fw_mqtt.py.
 */
function fw_broker_pass($cfg = null)
{
    list($pw, $z) = fw_zugang_lesen(false);
    if ($z === 'ok') { return $pw; }
    if ($z === 'kaputt') { return ''; }
    if (!is_array($cfg)) {
        $p = fw_paths();
        list($cfg) = $p['config'] !== '' ? fw_json_lesen_geprueft($p['config']) : array(array());
    }
    return (isset($cfg['broker_pass']) && is_string($cfg['broker_pass'])) ? $cfg['broker_pass'] : '';
}

/**
 * Das Broker-Kennwort schreiben: zugang.json (0600), zuruecklesen, erst dann die Zweitschrift aus den zurueckgelesenen
 * Daten. Rueckgabe: true, wenn zugang.json geschrieben und gleich zurueckgelesen ist.
 */
function fw_zugang_speichern($pw)
{
    $p = fw_paths();
    if ($p['zugang'] === '' || !is_string($pw)) { return false; }
    if (!fw_json_schreiben($p['zugang'], array('broker_pass' => $pw), 0600)) { return false; }
    list($zurueck, $z) = fw_json_lesen_geprueft($p['zugang']);
    if ($z !== 'ok' || !isset($zurueck['broker_pass']) || $zurueck['broker_pass'] !== $pw) {
        fw_log('zugang.json liess sich nach dem Schreiben nicht zuruecklesen. Die Zweitschrift bleibt unangetastet.');
        return false;
    }
    if (!fw_json_schreiben($p['zugang_sicherung'], $zurueck, 0600)) {
        fw_log('Die Zweitschrift von zugang.json liess sich nicht erneuern; zugang.json selbst ist geschrieben.');
    }
    return true;
}

/**
 * Uebergang fuer bestehende Anlagen (1.0.27): traegt funkwacht.json noch broker_pass, kommt es nach zugang.json und
 * wird danach aus funkwacht.json und ihrer Zweitschrift entfernt. Erst zugang.json schreiben und zuruecklesen, dann
 * funkwacht.json - scheitert ein Schritt, bleibt der Schluessel stehen, und der Mithoerer liest ihn weiter dort; es geht
 * nichts verloren, und der naechste Aufruf versucht es erneut. Traegt zugang.json schon ein nicht leeres Kennwort, gilt
 * dieses (es wurde nach dem Umbau gesetzt), und der alte Schluessel wird nur entfernt. Eine unlesbare zugang.json haelt
 * den Uebergang an. Nicht waehrend einer Aktualisierung (Marke), nie ohne LoxBerry-Wurzel. Die Werte aus der Datei
 * bleiben, wie sie sind (fw_config_speichern(..., true)), fremde Schluessel auch. Genau eine Protokollzeile, ohne Wert.
 * Aufgerufen aus fw_config() (Lesen mit Schreibrecht, nie vom unangemeldeten Endpunkt) und fw_config_vervollstaendigen()
 * (Waechterstart, postupgrade.sh). Rueckgabe: 'kein' | 'uebernommen' | 'fehler'.
 */
function fw_zugang_uebergang()
{
    $p = fw_paths();
    if ($p['config'] === '' || $p['zugang'] === '' || fw_upgrade_laeuft()) { return 'kein'; }
    clearstatcache(true, $p['config']);
    if (!is_file($p['config'])) { return 'kein'; }
    list($datei, $zustand) = fw_json_lesen_geprueft($p['config']);
    if ($zustand !== 'ok' || !array_key_exists('broker_pass', $datei)) { return 'kein'; }
    if (!is_string($datei['broker_pass'])) {
        fw_log('Broker-Kennwort NICHT nach zugang.json uebernommen: broker_pass in funkwacht.json ist kein Text.');
        return 'fehler';
    }
    list($pw, $z) = fw_zugang_lesen(true);
    if ($z === 'kaputt') {
        fw_log('Broker-Kennwort NICHT nach zugang.json uebernommen: zugang.json ist unlesbar und bleibt unberuehrt.');
        return 'fehler';
    }
    $schon = ($z === 'ok' && $pw !== '');
    if (!$schon && !fw_zugang_speichern($datei['broker_pass'])) {
        fw_log('Broker-Kennwort NICHT nach zugang.json uebernommen: zugang.json liess sich nicht schreiben.');
        return 'fehler';
    }
    $datei = fw_json_leere_objekte($datei, json_decode((string) @file_get_contents($p['config'])));
    unset($datei['broker_pass']);
    if (!fw_config_speichern($datei, true, false)) {     // 1.0.32: Dateiwerte - kein "Dateiwert behalten"
        fw_log('Broker-Kennwort steht in zugang.json, liess sich aber aus funkwacht.json nicht entfernen.');
        return 'fehler';
    }
    fw_log('Broker-Kennwort aus funkwacht.json nach zugang.json (0600) uebernommen und dort entfernt'
         . ($schon ? '; zugang.json trug schon eines, es gilt weiter.' : '.'));
    return 'uebernommen';
}

/**
 * Laeuft gerade eine Aktualisierung? Die Marke legt preupgrade.sh an,
 * postupgrade.sh raeumt sie weg; aelter als 3600 s gilt sie nicht (dieselbe
 * Rechnung wie fw_gesund_fakten(); bin/dienst.sh rechnet mit dem Inhalt).
 */
function fw_upgrade_laeuft()
{
    $p = fw_paths();
    if ($p['datadir'] === '') { return false; }
    $marke = $p['datadir'] . '.upgrade_laeuft';
    clearstatcache(true, $marke);
    return is_file($marke) && (time() - (int) @filemtime($marke)) < 3600;
}

/** Leere Objekte des Rohtextes ($o, json_decode ohne true) im Feld $a wieder als Objekt fuehren (11.11.2026),
 *  damit json_encode() sie als {} und nicht als [] schreibt - oben wie verschachtelt, auch unter fremden
 *  Schluesseln. Nur fuer fw_config_vervollstaendigen(); das Speichern im Formular bleibt, wie es ist.
 *  Bauart Bewaesserung 0.9.56 (bw_json_leere_objekte()). */
function fw_json_leere_objekte($a, $o)
{
    if (!is_array($a)) { return $a; }
    if (is_object($o)) {
        if (!get_object_vars($o) && $a === array()) { return new stdClass(); }
        foreach ($a as $k => $v) {
            $ks = (string) $k;
            if (is_array($v) && property_exists($o, $ks)) { $a[$k] = fw_json_leere_objekte($v, $o->$ks); }
        }
    } elseif (is_array($o)) {
        foreach ($a as $k => $v) {
            if (is_array($v) && array_key_exists($k, $o)) { $a[$k] = fw_json_leere_objekte($v, $o[$k]); }
        }
    }
    return $a;
}

/* ==================================================================
 * Lade-Beanstandungen (1.0.32; Regeln/05, Sicherungsmodul 1.3.0)
 *
 * fw_config() bringt eine von Hand verbogene Datei fuer den Betrieb in ihre Grenzen (Zahlen, Haken, Ruhezeit, Thema,
 * je Geraet Art, Verknuepfung, Reihenfolge, Grenzen). Das bleibt - aber jede solche Ersetzung wird gemeldet (Selbst-
 * pruefung im Reiter Test [ !! ], Seite gelb, einmal ins Protokoll) und nie in die Datei zurueckgeschrieben.
 * ================================================================== */

/** Die rohen Werte von funkwacht.json (ohne jede Normierung), null = keine lesbare Datei. Schreibt nichts. */
function fw_lade_roh()
{
    $p = fw_paths();
    if ($p['config'] === '') { return null; }
    clearstatcache(true, $p['config']);
    list($roh, $zustand) = fw_json_lesen_geprueft($p['config']);
    return ($zustand === 'ok' && is_array($roh)) ? $roh : null;
}

/** Die Beschreibung fuer die Lade-Beanstandungen (geheim: 'geheim' der Sicherungsbeschreibung). $maskiert = true fuer
 *  den gelben Kasten (gibt unmittelbar aus: auch die Pruefsaetze der Linie werden maskiert, fw_einstellung_pruefen()
 *  liefert Klartext); false fuer Meldungen und Selbstpruefung (die Seite maskiert bei der Ausgabe). */
function fw_lade_b($maskiert = true)
{
    $b = fw_sicherung_beschreibung();
    /* Die Sprechtoken der Datei (eine Sicherung traegt keine) nimmt das Modul fuer die Pruefung selbst heraus
     * (sicherung_lade_ohne_geheim(), 1.3.0) - keine eigene Umgehung. */
    if (!$maskiert) {
        $b['e'] = function ($s) { return (string) $s; };
        return $b;
    }
    $m = function ($t) {
        if (is_array($t)) { return array_map(function ($x) { return fw_e((string) $x); }, $t); }
        return ($t === '' || $t === null) ? $t : fw_e((string) $t);
    };
    $pr = $b['pruefen'];
    $b['pruefen'] = function ($k, $w, $neu = null, $roh = null) use ($pr, $m) { return $m(call_user_func($pr, $k, $w, $neu, $roh)); };
    foreach ($b['schluessel'] as $sk => $kn) {
        if (!is_array($kn) || !isset($kn['pruefen']) || !is_callable($kn['pruefen'])) { continue; }
        $kp = $kn['pruefen'];
        $b['schluessel'][$sk]['pruefen'] = function ($w, $k = '', $neu = null, $roh = null) use ($kp, $m) {
            return $m(call_user_func($kp, $w, $k, $neu, $roh));
        };
    }
    if (isset($b['quer']) && is_callable($b['quer'])) {
        $q = $b['quer'];
        $b['quer'] = function ($neu, $r = null, $d = null) use ($q) {
            $aus = array();
            foreach ((array) call_user_func($q, $neu, $r, $d) as $x) {
                if (is_array($x) && count($x) >= 2) { $x[1] = fw_e((string) $x[1]); }
                $aus[] = $x;
            }
            return $aus;
        };
    }
    return $b;
}

/** Die Lade-Beanstandungen: rohe Dateiwerte gegen den verwendeten Stand ($cfg, sonst fw_config(false)). */
function fw_lade_beanstandungen($cfg = null, $maskiert = true)
{
    $d = fw_lade_roh();
    if ($d === null) { return array(); }
    return sicherung_lade_beanstandungen(fw_lade_b($maskiert), $d, is_array($cfg) ? $cfg : fw_config(false));
}

/** Vor dem Schreiben: jedes beanstandete Feld, das $cfg nicht geaendert hat, bekommt seinen Dateiwert zurueck. */
function fw_lade_behalten(array $cfg)
{
    $d = fw_lade_roh();
    if ($d === null) { return $cfg; }
    $v = fw_config(false);
    list($cfg, ) = sicherung_lade_behalten($cfg, $d, $v, sicherung_lade_beanstandungen(fw_lade_b(false), $d, $v));
    return $cfg;
}

/** Dienst (bin/fw_vervollstaendigen.php, Waechterstart und postupgrade.sh): fehlende Einstellungen eintragen, dann die
 *  Lade-Beanstandungen einmal melden. Rueckgabe wie fw_config_vervollstaendigen(). */
function fw_dienst_pflege()
{
    $r = fw_config_vervollstaendigen();
    fw_lade_melden(fw_lade_beanstandungen());     // Kennung D
    return $r;
}

/** Einmal je Aenderung ins Protokoll (nur Namen und Gruende, nie Werte). */
function fw_lade_melden(array $beanst)
{
    $d = fw_paths()['datadir'];
    if ($d === '' || (!is_dir($d) && !@mkdir($d, 0775, true))) { return; }
    if (sicherung_lade_melden_faellig($beanst, $d . '/lade_beanstandungen.merk')) {
        fw_log(sicherung_lade_protokollzeile($beanst));
    }
}

/**
 * Konfiguration vervollstaendigen (seit 1.0.25, Regeln/05 "Die Konfiguration
 * wird vervollstaendigt, nicht nur ergaenzt"; Bauform Docker NG 1.3.7).
 *
 * Bis 1.0.24 ergaenzte fw_config() fehlende Schluessel bei jedem Lesen nur
 * im Speicher; in funkwacht.json kamen sie erst mit dem naechsten Speichern
 * im Formular. Aufgerufen ueber bin/fw_vervollstaendigen.php vom Waechter
 * beim Start (funkwacht_dienst.py, vor dem ersten Lesen der Konfiguration)
 * und von postupgrade.sh nach dem Wegraeumen der Marke.
 *
 * - Nur mit LoxBerry-Wurzel, nicht waehrend einer Aktualisierung (Marke).
 * - Nur, wenn funkwacht.json lesbar ist, ein Objekt ist und ein gueltiges,
 *   nicht leeres Aktionstoken als Text traegt (seit 1.0.32 genuegt jedes,
 *   wie am Endpunkt). postinstall.sh legt "{}" an, und
 *   fw_config_speichern() ueberschreibt die Zweitschrift immer: ohne diese
 *   Bedingung ersetzte das Vervollstaendigen eine Zweitschrift mit Token
 *   durch eine ohne. Eine fehlende oder kaputte Datei ist Sache der
 *   Selbstheilung in fw_config().
 * - Geschrieben werden die WERTE AUS DER DATEI, nicht die von fw_config()
 *   begrenzten; fremde Schluessel bleiben stehen. Fehlende Schluessel
 *   bekommen den Wert, den fw_config() fuer sie liest - das ist hier die
 *   Vorgabe, denn fw_config() kennt keine Lese-Regel fuer Bestandsdateien
 *   (endpunkt_ein = 1 fuer den Bestand setzt allein postinstall.sh beim
 *   Update, Gruppe 44; das laeuft vorher, mit Marke ruht diese Funktion).
 * - Nur die Schluessel der Vorgaben (die schreibt auch das Speichern), nur
 *   oberste Ebene, nur wenn einer fehlt - damit bleibt es bei einem
 *   Schreibvorgang.
 * - Geschrieben wird ueber fw_config_speichern() (0600, Zweitschrift wie
 *   beim Speichern). Genau eine Protokollzeile mit den Namen, nie Werte.
 *
 * Rueckgabe: array('stand' => ohne_wurzel|ruht|ohne_datei|ohne_merkwort
 *                  |vollstaendig|ergaenzt|fehler, 'schluessel' => array(...),
 *                  'kennwort' => kein|uebernommen|fehler)
 *
 * 1.0.27: vorher der Uebergang des Broker-Kennworts nach zugang.json (fw_zugang_uebergang(); er schreibt nur,
 * wenn funkwacht.json broker_pass noch traegt, und ruht wie dieses Vervollstaendigen waehrend einer
 * Aktualisierung), danach das Vervollstaendigen wie bisher (fw_config_vervollstaendigen_kern()).
 */
function fw_config_vervollstaendigen()
{
    $kw = fw_zugang_uebergang();
    $e = fw_config_vervollstaendigen_kern();
    $e['kennwort'] = $kw;
    return $e;
}

/** Das Vervollstaendigen selbst (bis 1.0.26 fw_config_vervollstaendigen()), unveraendert. */
function fw_config_vervollstaendigen_kern()
{
    $p = fw_paths();
    if ($p['config'] === '') {
        return array('stand' => 'ohne_wurzel', 'schluessel' => array());
    }
    if (fw_upgrade_laeuft()) {
        return array('stand' => 'ruht', 'schluessel' => array());
    }
    clearstatcache(true, $p['config']);
    if (!is_file($p['config'])) {
        return array('stand' => 'ohne_datei', 'schluessel' => array());
    }
    list($datei, $zustand) = fw_json_lesen_geprueft($p['config']);
    if ($zustand !== 'ok' || !is_array($datei) || !array_key_exists('aktionstoken', $datei)
        || !is_string($datei['aktionstoken']) || $datei['aktionstoken'] === '') {
        /* 1.0.32 (Lade-Beanstandungen, Regeln/05): jedes nicht leere Token genuegt; bis 1.0.31 ruhte das
         * Vervollstaendigen bei einem formwidrigen (fw_token_gueltig()), und fehlende Schluessel kamen nie in die Datei.
         * Das formwidrige Token bleibt und wird gemeldet. */
        return array('stand' => 'ohne_merkwort', 'schluessel' => array());
    }
    // Ein leeres Objekt der Datei ({}) bliebe nach json_decode(..., true) ein leeres Feld und kaeme beim
    // Schreiben als [] zurueck - der Rohwert wird erhalten, bevor ergaenzt wird (11.11.2026).
    $datei = fw_json_leere_objekte($datei, json_decode((string) @file_get_contents($p['config'])));
    $fehlend = array();
    foreach (fw_vorgaben() as $k => $w) {
        /* endpunkt_ein bleibt aussen vor (11.11.2026): den Uebergang fuer Bestandsanlagen setzt das
         * Update; ein hier festgeschriebenes "aus" koennte ein spaeteres Update nicht mehr berichtigen. */
        if ($k === 'endpunkt_ein') {
            continue;
        }
        if (!array_key_exists($k, $datei)) {
            $datei[$k] = $w;
            $fehlend[] = $k;
        }
    }
    if (!$fehlend) {
        return array('stand' => 'vollstaendig', 'schluessel' => array());
    }
    if (!fw_config_speichern($datei, true, false)) {     // 1.0.32: Dateiwerte - kein "Dateiwert behalten"
        fw_log('Konfiguration NICHT vervollstaendigt: funkwacht.json liess sich nicht schreiben ('
             . count($fehlend) . ' fehlende Schluessel).');
        return array('stand' => 'fehler', 'schluessel' => $fehlend);
    }
    fw_log('Konfiguration vervollstaendigt - ' . count($fehlend)
         . ' fehlende Vorgaben in funkwacht.json geschrieben: ' . implode(', ', $fehlend));
    return array('stand' => 'ergaenzt', 'schluessel' => $fehlend);
}

/**
 * Die Geraete mit Namen - Schluessel ist die ZEILENNUMMER.
 *
 * Genau daran hing der stille Befund aus 0.9.3: der Waechter zaehlte nach der
 * Zeile, die Oberflaeche nach den belegten Zeilen. Eine leere Zeile davor
 * genuegte, und der virtuelle Eingang "ZWave" zeigte den Zustand von
 * "Zigbee".
 */
function fw_geraete($erzeugen = true)
{
    $out = array();
    $nr = 0;
    foreach (fw_config($erzeugen)['geraete'] as $g) {
        $nr++;
        if (trim((string) $g['name']) === '') { continue; }
        $g['nr'] = $nr;
        $out[$nr] = $g;
    }
    return $out;
}

function fw_token_erzeugen($laenge = 24)
{
    $z = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) { $t .= $z[random_int(0, strlen($z) - 1)]; }
    return $t;
}

/**
 * Das Aktionstoken holen, bei Bedarf erzeugen - hinter einer Dateisperre.
 * NUR aus dem angemeldeten Bereich aufrufen: die Funktion schreibt.
 */
function fw_token()
{
    $cfg = fw_config();
    /* Begruendete Abweichung von Regeln/05 "neu nur, wenn der Schluessel fehlt" (Entscheidung des Hausherrn 24.11.2026,
     * Lade-Beanstandungen Punkt 1): ein LEERES Token erzeugt hier ein neues, weil das Formularmerkmal (fw_formtoken())
     * am Token haengt - ohne Token liesse sich kein Formular mehr absenden. Ein vorhandenes, auch formwidriges Token
     * bleibt (fw_config() ersetzt es nie; der Endpunkt nimmt es an, fw_lade_beanstandungen() meldet es - Punkt 2/11). */
    if (trim((string) $cfg['aktionstoken']) !== '') { return (string) $cfg['aktionstoken']; }
    $p = fw_paths();
    if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); }
    $fp = @fopen($p['datadir'] . '/token.lock', 'c+');
    if ($fp === false) {
        $cfg['aktionstoken'] = fw_token_erzeugen();
        fw_config_speichern($cfg);
        return (string) $cfg['aktionstoken'];
    }
    if (@flock($fp, LOCK_EX)) {
        $cfg = fw_config();                 // zweiter Blick unter der Sperre
        if (trim((string) $cfg['aktionstoken']) === '') {
            $cfg['aktionstoken'] = fw_token_erzeugen();
            fw_config_speichern($cfg);
        }
        @flock($fp, LOCK_UN);
    }
    fclose($fp);
    return (string) $cfg['aktionstoken'];
}

/**
 * Das Merkmal gegen fremde Formulare.
 *
 * htmlauth/ schuetzt gegen den unangemeldeten Aufruf - NICHT dagegen, dass
 * der Browser eines angemeldeten Bedieners ein Formular abschickt, das auf
 * einer fremden Seite steht. Abgeleitet, nicht gespeichert; fail closed.
 */
function fw_formtoken()
{
    $t = trim((string) fw_config()['aktionstoken']);
    if ($t === '') { return ''; }
    return hash_hmac('sha256', 'formular-v1', $t);
}

/* ==================================================================
 * Auftraege an den Waechter
 *
 * WARUM UEBER EINE DATEI und nicht unmittelbar: historie.json hat genau
 * EINEN Schreiber, naemlich den Waechter. Schriebe die Oberflaeche
 * dazwischen, waere ihre Aenderung beim naechsten Durchlauf ueberschrieben -
 * und niemand saehe es. Der Waechter liest die Auftragsdatei zu Beginn jedes
 * Durchlaufs, fuehrt sie aus und entfernt sie.
 *
 * Der Preis ist eine Verzoegerung von hoechstens einem Prueftakt, und das
 * wird dem Bediener auch gesagt. Die Alternative waere eine Dateisperre
 * ueber zwei Sprachen hinweg - mehr Bauteile fuer denselben Zweck.
 * ================================================================== */

function fw_auftrag($was, $nr = 0, $dauer = 0)
{
    $p = fw_paths();
    $alt = fw_json_lesen($p['auftraege']);
    $liste = isset($alt['auftraege']) && is_array($alt['auftraege']) ? $alt['auftraege'] : array();
    $liste[] = array('was' => (string) $was, 'nr' => (int) $nr,
                     'dauer' => (int) $dauer, 'zeit' => time());
    $liste = array_slice($liste, -20);
    return fw_json_schreiben($p['auftraege'], array('auftraege' => $liste));
}

/** Wie lange dauert es hoechstens, bis ein Auftrag wirkt? */
function fw_auftrag_wartezeit()
{
    return (int) fw_config(false)['takt'];
}

/* ==================================================================
 * Zustand, Historie und Protokoll
 * ================================================================== */

function fw_stand() { return fw_json_lesen(fw_paths()['stand']); }

function fw_historie() { return fw_json_lesen(fw_paths()['historie']); }

function fw_alter()
{
    $s = fw_stand();
    return isset($s['zeit']) && (int) $s['zeit'] > 0 ? max(0, time() - (int) $s['zeit']) : -1;
}

/** Die Grenze der Protokollkappung - EINE Stelle fuer Waechter und Oberflaeche. */
function fw_log_kb()
{
    return (int) fw_config(false)['log_kb'];
}

function fw_log($text)
{
    $p = fw_paths();
    if (!is_dir($p['logdir'])) { @mkdir($p['logdir'], 0775, true); }
    /* log/plugins liegt auf einer Ramdisk - eine unbegrenzt wachsende Datei
     * frisst Arbeitsspeicher, nicht Plattenplatz. */
    $grenze = fw_log_kb() * 1024;
    /* PHP merkt sich die Antworten von stat(). Ohne diese Zeile sieht
     * filesize() innerhalb EINES Prozesses die Groesse des ersten Aufrufs und
     * danach nie wieder eine neue - file_put_contents(..., FILE_APPEND) macht
     * den Eintrag nicht ungueltig. fw_log() wird an fuenfzehn Stellen
     * gerufen; die Kappung fiele nach dem ersten Mal aus, und auf einer
     * Ramdisk kostet das Arbeitsspeicher. */
    clearstatcache(true, $p['log']);
    if (is_file($p['log']) && filesize($p['log']) > $grenze) {
        $rest = array_slice(file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -400);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

/**
 * Die letzten Zeilen einer Datei, neueste zuerst - rueckwaerts mit fseek.
 * Erst fragen, dann oeffnen: das @ schaltet die Ausgabe ab, nicht den
 * Fehler-Aufnehmer.
 */
function fw_log_ende($datei, $anzahl = 400, $block = 8192)
{
    if (!is_file($datei)) { return array(); }
    $fp = @fopen($datei, 'rb');
    if ($fp === false) { return array(); }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $anzahl);
}

/* ==================================================================
 * Verlauf - die Verschleisskurve
 * ================================================================== */

/** Die Tagesdateien, neueste zuerst. */
function fw_verlaufsdateien()
{
    $ordner = fw_paths()['verlauf'];
    if (!is_dir($ordner)) { return array(); }
    $aus = array();
    foreach ((array) glob($ordner . '/funkwacht_*.csv') as $f) {
        if (is_file($f)) { $aus[] = $f; }
    }
    rsort($aus);
    return $aus;
}

/**
 * Heilungen je Tag der letzten $tage - fuer das Balkenbild.
 *
 * Gezaehlt werden die ZEILEN der Tagesdateien, nicht die Dateien: eine Datei
 * je Tag entsteht auch dann, wenn nur ein Versuch abgelehnt wurde.
 */
function fw_verlauf_tage($tage = 30)
{
    $aus = array();
    for ($i = $tage - 1; $i >= 0; $i--) {
        $aus[date('Ymd', time() - $i * 86400)] = 0;
    }
    foreach (fw_verlaufsdateien() as $f) {
        $tag = preg_replace('/^.*funkwacht_(\d{8})\.csv$/', '$1', basename($f));
        if (!isset($aus[$tag])) { continue; }
        $n = 0;
        $fp = @fopen($f, 'r');
        if ($fp === false) { continue; }
        while (($z = fgets($fp)) !== false) {
            if (strpos($z, 'zeit;') === 0) { continue; }
            if (trim($z) !== '') { $n++; }
        }
        fclose($fp);
        $aus[$tag] = $n;
    }
    return $aus;
}

/**
 * Ein Balkenbild als Inline-SVG.
 *
 * Kein fremdes Bildpaket, keine Adresse nach draussen: das SVG steht im
 * HTML. Die Werte sind ganze Zahlen - ein leerer Tag bekommt keinen Balken,
 * nicht einen der Hoehe null, damit man beides unterscheiden kann.
 */
function fw_verlauf_svg($werte, $breite = 900, $hoehe = 150)
{
    $n = count($werte);
    if ($n < 1) { return ''; }
    $max = max(1, max($werte));
    $bb = $breite / $n;
    $o = '<svg viewBox="0 0 ' . (int) $breite . ' ' . (int) $hoehe . '" width="100%" '
       . 'height="' . (int) $hoehe . '" role="img" '
       . 'style="background:#fafafa;border:1px solid #ddd;border-radius:6px;">';
    for ($i = 1; $i <= 3; $i++) {
        $y = $hoehe - 20 - ($hoehe - 30) * $i / 3;
        $o .= '<line x1="0" y1="' . round($y, 1) . '" x2="' . (int) $breite
            . '" y2="' . round($y, 1) . '" stroke="#e0e0e0" stroke-width="1"/>';
    }
    $i = 0;
    foreach ($werte as $tag => $w) {
        $x = $i * $bb;
        if ($w > 0) {
            $h = ($hoehe - 30) * $w / $max;
            $o .= '<rect x="' . round($x + 1, 1) . '" y="' . round($hoehe - 20 - $h, 1)
                . '" width="' . round(max(1, $bb - 2), 1) . '" height="' . round($h, 1)
                . '" fill="#6dac20"><title>' . fw_x(substr($tag, 6, 2) . '.' . substr($tag, 4, 2)
                . '.' . substr($tag, 0, 4) . ': ' . $w) . '</title></rect>';
        }
        if ($i === 0 || $i === $n - 1 || $i === (int) ($n / 2)) {
            $o .= '<text x="' . round($x + $bb / 2, 1) . '" y="' . ($hoehe - 6)
                . '" font-size="10" fill="#666" text-anchor="middle">'
                . fw_x(substr($tag, 6, 2) . '.' . substr($tag, 4, 2)) . '</text>';
        }
        $i++;
    }
    $o .= '<text x="4" y="12" font-size="10" fill="#666">' . fw_x($max) . '</text>';
    $o .= '</svg>';
    return $o;
}

/* ==================================================================
 * Dienst
 * ================================================================== */

/**
 * Ist diese Nummer der eigene Dauerlaeufer?
 *
 * Dieselbe Probe wie in bin/dienst.sh, und aus demselben Anlass: bis 1.0.5
 * genuegte hier, dass IRGENDEIN Argument den Dateinamen trug. Ein fremder
 * Prozess  python3 -c '...' <dienstpfad>  bestand die Probe - am 18.09.2026
 * am Aufrufweg der dienst.sh gemessen (Bestand-2026-09-18/klasse-F-
 * nachmessung, Zeile 4b). Diese Funktion schickt zwar kein Signal, sie
 * beantwortet aber die Frage "laeuft der Dienst?" fuer die Oberflaeche und
 * fuer fw_dienst_schalten() - eine falsche Antwort ist dort eine
 * Falschaussage, keine Kleinigkeit.
 *
 * Ein Treffer hat GENAU zwei Argumente: argv[0] ist ein Python, argv[1] ist
 * genau der eigene Skriptpfad. Das dritte Argument schliesst die Einmallaeufe
 * aus (--selbsttest, --faehigkeit, --einmal und die uebrigen).
 */
function fw_ist_dienst($pid, $skript)
{
    $cmd = @file_get_contents('/proc/' . (int) $pid . '/cmdline');
    if ($cmd === false || $cmd === '') { return false; }
    /* Die Befehlszeile endet mit einem Nullbyte - das gaebe ein leeres
     * letztes Stueck, das kein Argument ist. */
    if (substr($cmd, -1) === "\0") { $cmd = substr($cmd, 0, -1); }
    $teile = explode("\0", $cmd);
    if (count($teile) !== 2) { return false; }
    /* preg_match statt einer Erweiterung: mb_* und ctype_* sind auf einem
     * LoxBerry nicht garantiert geladen (Regeln/02). */
    if (!preg_match('#(^|/)python[0-9.]*$#', $teile[0])) { return false; }
    if ($teile[1] === $skript) { return true; }
    $a = @realpath($teile[1]);
    $b = @realpath($skript);
    return ($a !== false && $b !== false && $a === $b);
}

function fw_dienst_pid($datei = 'dienst.pid', $prozess = 'funkwacht_dienst.py')
{
    $p = fw_paths();
    $f = $p['datadir'] . '/' . $datei;
    if (!is_file($f)) { return 0; }
    $pid = (int) trim((string) @file_get_contents($f));
    if ($pid <= 0) { return 0; }
    return fw_ist_dienst($pid, $p['bindir'] . '/' . $prozess) ? $pid : 0;
}

function fw_mithoerer_pid() { return fw_dienst_pid('mithoerer.pid', 'fw_mqtt.py'); }

/**
 * Liegt diese Datei im ausgepackten Archiv (webfrontend/html/fw_lib.php)?
 * Nur dann gilt der Rueckfall auf die Nachbarn des Archivs (bin/,
 * templates/lang). Installiert liegt sie in webfrontend/html/plugins/<ordner>/;
 * dort fuehrte derselbe Rueckfall bis 1.0.6 nach webfrontend/html/bin/ bzw.
 * in templates/plugins/funkwacht/ - fremde Baeume (Pruefung-Funkwacht-1.0.7,
 * messe_nachlese.sh, Faelle B7, B8, B10, B12).
 */
function fw_archivlage()
{
    return basename(__DIR__) === 'html' && basename(dirname(__DIR__)) === 'webfrontend';
}

function fw_dienst_skript()
{
    $p = fw_paths();
    $k = array();
    if ($p['bindir'] !== '') { $k[] = $p['bindir'] . '/dienst.sh'; }
    if (fw_archivlage()) { $k[] = dirname(dirname(__DIR__)) . '/bin/dienst.sh'; }
    foreach ($k as $d) {
        if (is_file($d)) { return $d; }
    }
    return '';
}

/**
 * Den Waechter schalten. Rueckgabe: array(ok, Text).
 * Die WIRKUNG wird gemeldet, nicht der Rueckgabewert.
 */
function fw_dienst_schalten($was)
{
    if (!in_array($was, array('start', 'stop', 'restart'), true)) {
        return array(0, fw_t('DIENST.UNBEKANNT'));
    }
    $s = fw_dienst_skript();
    if ($s === '') { return array(0, fw_t('DIENST.KEIN_SKRIPT')); }
    $aus = array();
    $rc = 0;
    @exec(escapeshellarg($s) . ' ' . escapeshellarg($was) . ' 2>&1', $aus, $rc);
    sleep(1);
    $pid = fw_dienst_pid();
    $ok = ($was !== 'stop') ? ($pid > 0) : ($pid === 0);
    $text = implode("\n", $aus);
    return array($ok ? 1 : 0, $text === '' ? sprintf(fw_t('DIENST.RUECKGABE'), (int) $rc) : $text);
}

function fw_faehigkeiten()
{
    return fw_json_lesen(fw_paths()['datadir'] . '/faehigkeit.json');
}

/**
 * Ein Argument fuer die Schale anfuehren, Byte fuer Byte: fuer Themen, Werte und Texte, nicht fuer Pfade.
 * Bis 1.0.18 stand hier escapeshellarg(). Es verwirft unter PHP 7.4 jedes Byte, das im LC_CTYPE kein gueltiges Zeichen ist; Apache startet mit LANG=C, eine
 * Befehlsbruecke ohne LANG: aus "Kueche" mit Umlaut wurde "Kche", ohne Fehler (am Geraet gemessen 01.11.2026). setlocale() fehlt, wo keine UTF-8-Locale
 * erzeugt ist, und aendert basename() und PCRE. Einfache Anfuehrungszeichen sind fuer sh und dash byte-erhaltend; ein Nullbyte kann kein Argument tragen.
 */
function fw_shell_arg($s)
{
    return "'" . str_replace("'", "'\\''", str_replace("\0", '', (string) $s)) . "'";
}

/** Ein Python-Werkzeug aus bin/ aufrufen. Rueckgabe: array(rc, Text). */
function fw_bin_aufruf($datei, $argumente = array(), $zeit = 60)
{
    $p = fw_paths();
    $ziel = '';
    $kand = array();
    if ($p['bindir'] !== '') { $kand[] = $p['bindir'] . '/' . $datei; }
    if (fw_archivlage()) { $kand[] = dirname(dirname(__DIR__)) . '/bin/' . $datei; }
    foreach ($kand as $k) {
        if (is_file($k)) { $ziel = $k; break; }
    }
    if ($ziel === '') { return array(127, ''); }
    $befehl = 'python3 ' . escapeshellarg($ziel);
    foreach ($argumente as $a) { $befehl .= ' ' . fw_shell_arg((string) $a); }
    $aus = array();
    $rc = 0;
    @exec($befehl . ' 2>&1', $aus, $rc);
    return array($rc, implode("\n", $aus));
}

/**
 * Eine Adresse abrufen, bei der ein Fehlschlag ein VORGESEHENER Ausgang ist.
 *
 * Rueckgabe: array($text, $statuscode). $text ist false, wenn nichts kam;
 * $statuscode ist 0, wenn keine Statuszeile ankam.
 *
 * Warum nicht einfach @file_get_contents: Das @ unterdrueckt nur die
 * Standardbehandlung. Ein GESETZTER Fehlerbehandler - und der Pruefstand
 * rendern.py setzt einen - sieht die Warnung trotzdem und meldet sie als
 * Befund, obwohl nichts kaputt ist. Gemessen unter PHP 7.4.33 und 8.4.24 in
 * beide Richtungen: mit @ sieht der Behandler die Warnung, mit dem Austausch
 * unten nicht, und danach greift er wieder.
 *
 * Und der Statuscode wird gleich mitgenommen: eine Abweisung, die als HTTP
 * 200 ankommt, sieht im Rumpf richtig aus und ist trotzdem falsch.
 */
function fw_http_holen($url, $timeout = 8)
{
    $ctx = stream_context_create(array('http' => array(
        'timeout' => (int) $timeout, 'ignore_errors' => true,
        'follow_location' => 0, 'max_redirects' => 1)));
    /* U2 (Pruefung 29.09.2026): die Kopfzeilen kommen seit dieser Fassung
     * aus stream_get_meta_data() - Bauart eb_http_abruf() der Einspeisebremse
     * 0.9.28. Die alte, im Geltungsbereich entstehende Kopfzeilen-Variable
     * meldet PHP 8.5 schon beim Uebersetzen als ueberholt, PHP 9 soll sie
     * abschaffen - dann hiesse jeder Code 0. Die Ersatzfunktion gibt es unter
     * 7.4 nicht; wrapper_data gibt es in allen drei Fassungen. */
    set_error_handler(function () { return true; });
    $fp = fopen($url, 'r', false, $ctx);
    $text = false;
    $meta = null;
    if ($fp !== false) {
        $meta = stream_get_meta_data($fp);
        $text = stream_get_contents($fp);
        fclose($fp);
    }
    restore_error_handler();
    $code = 0;
    $kopf = (is_array($meta) && isset($meta['wrapper_data']) && is_array($meta['wrapper_data']))
        ? $meta['wrapper_data'] : array();
    foreach ($kopf as $z) {
        if (is_string($z) && preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $m)) { $code = (int) $m[1]; }
    }
    return array($text, $code);
}

/* ==================================================================
 * MQTT
 * ================================================================== */

function fw_mqtt_zustand()
{
    $p = fw_paths();
    $leer = array('gefunden' => 0, 'autostart' => 0, 'udpport' => 0, 'fassung' => 0,
                  'broker' => '', 'brokerport' => 0);
    if ($p['home'] === '') { return $leer; }
    $gen = fw_json_lesen($p['home'] . '/config/system/general.json');
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) { $m = $gen['Mqtt']; }
    elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) { $m = $gen['mqtt']; }
    if (!$m) { return $leer; }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross])) { return $m[$gross]; }
        return isset($m[$klein]) ? $m[$klein] : '';
    };
    return array(
        'gefunden'  => 1,
        'autostart' => in_array((string) $hol('Gatewayautostart', 'gatewayautostart'),
                                array('1', 'true'), true) ? 1 : 0,
        'udpport'   => (int) $hol('Udpinport', 'udpinport'),
        /* 0 heisst "nicht lesbar" und wird NICHT auf 1 vorbelegt - unbekannt
         * und Fassung 1 sind verschiedene Aussagen. */
        'fassung'   => (int) $hol('Gatewayversion', 'gatewayversion'),
        'broker'    => (string) $hol('Brokerhost', 'brokerhost'),
        'brokerport' => (int) $hol('Brokerport', 'brokerport'),
    );
}

/** Dieselbe Saeuberung wie im Waechter - fuer die Selbstpruefung. */
function fw_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

/**
 * Die Themen, die der Waechter veroeffentlicht.
 * Diese Liste MUSS zu felder() in bin/funkwacht_dienst.py passen; der Reiter
 * Test haelt beide gegeneinander.
 */
function fw_mqtt_themen()
{
    return array(
        'ok'                  => 'FW_MQTT.OK',
        'krank'               => 'FW_MQTT.KRANK',
        'geraete'             => 'FW_MQTT.GERAETE',
        'geheilt_gesamt'      => 'FW_MQTT.GEHEILT',
        'versuche_gesamt'     => 'FW_MQTT.VERSUCHE',
        'alarm'               => 'FW_MQTT.ALARM',
        'gesperrt'            => 'FW_MQTT.GESPERRT',
        'wartung'             => 'FW_MQTT.WARTUNG',
        'ts'                  => 'FW_MQTT.TS',
        /* Gruppe 44 (1.0.17, B1): Lebenszeichen 0...999 je Durchgang, nie retained (Gateway V2: Noncached). */
        'zaehler'             => 'FW_MQTT.ZAEHLER',
        'geraetN/ok'          => 'FW_MQTT.G_OK',
        'geraetN/stufe'       => 'FW_MQTT.G_STUFE',
        'geraetN/alter'       => 'FW_MQTT.G_ALTER',
        'geraetN/heilungen'   => 'FW_MQTT.G_HEILUNGEN',
        'geraetN/versuche'    => 'FW_MQTT.G_VERSUCHE',
        'geraetN/abgelehnt'   => 'FW_MQTT.G_ABGELEHNT',
        'geraetN/heil24'      => 'FW_MQTT.G_HEIL24',
        'geraetN/heil7t'      => 'FW_MQTT.G_HEIL7T',
        'geraetN/seit'        => 'FW_MQTT.G_SEIT',
        'geraetN/letzte'      => 'FW_MQTT.G_LETZTE',
        'geraetN/neustarts'   => 'FW_MQTT.G_NEUSTARTS',
        'geraetN/grundnr'     => 'FW_MQTT.G_GRUNDNR',
        'geraetN/warumnr'     => 'FW_MQTT.G_WARUMNR',
        'geraetN/name'        => 'FW_MQTT.G_NAME',
        'geraetN/grund'       => 'FW_MQTT.G_GRUND',
        'geraetN/warum'       => 'FW_MQTT.G_WARUM',
        'geraetN/letzte_tat'  => 'FW_MQTT.G_LETZTE_TAT',
        'geraetN/bemerkung'   => 'FW_MQTT.G_BEMERKUNG',
    );
}

/**
 * Retain je Themenstamm: 1 = zurueckbehalten, 0 = fluechtig.
 *
 * DIESELBE Tabelle wie RETAIN in bin/funkwacht_dienst.py - dort wird gesendet,
 * hier wird angezeigt. Der Reiter Test haelt beide gegeneinander
 * (fw_probe_themen(), ruft funkwacht_dienst.py --themen).
 *
 * Hausstandard seit 03.09.2026 (Regeln/07): Zustaende retained, Messwerte mit
 * Zeitbezug nicht, das Lebenszeichen nie. Eine Dauer (alter, seit, wartung)
 * und ein Zaehlfenster (heil24, heil7t) altern von selbst; ein Text, der
 * regelmaessig leer ist (warum, bemerkung), bliebe zurueckbehalten fuer immer
 * stehen; ts ist das Lebenszeichen.
 */
function fw_mqtt_retain()
{
    return array(
        /* Seit 1.0.6 fluechtig: die Aussagen des Waechters ueber seine
         * Sticks und sich selbst (Begruendung an RETAIN im Waechter). */
        'ok' => 0, 'krank' => 0, 'geraete' => 1, 'geheilt_gesamt' => 1,
        'versuche_gesamt' => 1, 'alarm' => 0, 'gesperrt' => 0,
        'wartung' => 0, 'ts' => 0, 'zaehler' => 0,
        'geraetN/ok' => 0, 'geraetN/stufe' => 0, 'geraetN/alter' => 0,
        'geraetN/heilungen' => 1, 'geraetN/versuche' => 1, 'geraetN/abgelehnt' => 1,
        'geraetN/heil24' => 0, 'geraetN/heil7t' => 0, 'geraetN/seit' => 0,
        'geraetN/letzte' => 1, 'geraetN/neustarts' => 1, 'geraetN/grundnr' => 0,
        'geraetN/warumnr' => 0, 'geraetN/name' => 1, 'geraetN/grund' => 0,
        'geraetN/warum' => 0, 'geraetN/letzte_tat' => 1, 'geraetN/bemerkung' => 0,
    );
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Geprueefter PHP-Nachbau des LoxoneTemplateBuilder - Attributreihenfolge,
 * CRLF und der Tabulator vor den Kindelementen entsprechen dem Original,
 * einschliesslich HintText, dem Info-Kindelement und Unit je Befehl.
 * ================================================================== */

function fw_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'HintText="" ';
    $o .= 'Title="' . fw_x($kopf['title']) . '" ';
    $o .= 'Comment="' . fw_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . fw_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . fw_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . fw_x($c['title']) . '" ';
        $o .= 'Comment="' . fw_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . fw_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="1" ';
        $o .= 'DestValHigh="1" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . fw_x(isset($c['min']) ? $c['min'] : '-100') . '" ';
        $o .= 'MaxVal="' . fw_x(isset($c['max']) ? $c['max'] : '100') . '" ';
        $o .= 'Unit="' . fw_x(isset($c['unit']) ? $c['unit'] : '<v.0>') . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Die Vorlage der Steuerbefehle.
 *
 * templateType 3, CmdInit und CmdSep am Wurzelelement, je Befehl beide
 * Methoden, Repeat und HintText - gemessen an den Ausfuhren aus der
 * laufenden Anlage.
 *
 * DER TITEL EINES AUSGANGS DARF KEIN GLEICHHEITSZEICHEN TRAGEN. An einem
 * anderen Plugin wurde aus "&lp=1" durch blosses Ersetzen von "&" der Name
 * "EVCC_MODUS_LP=1".
 */
function fw_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut ';
    $o .= 'HintText="" ';
    $o .= 'Title="' . fw_x($kopf['title']) . '" ';
    $o .= 'Comment="' . fw_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . fw_x($kopf['address']) . '" ';
    $o .= 'CloseAfterSend="true" ';
    $o .= 'CmdInit="" ';
    $o .= 'CmdSep=""';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . fw_x($c['title']) . '" ';
        $o .= 'Comment="' . fw_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'CmdOnMethod="GET" ';
        $o .= 'CmdOn="' . fw_x($c['on']) . '" ';
        $o .= 'CmdOnHTTP="" ';
        $o .= 'CmdOnPost="" ';
        $o .= 'CmdOffMethod="GET" ';
        $o .= 'CmdOff="' . fw_x(isset($c['off']) ? $c['off'] : '') . '" ';
        $o .= 'CmdOffHTTP="" ';
        $o .= 'CmdOffPost="" ';
        $o .= 'Analog="' . (empty($c['analog']) ? 'false' : 'true') . '" ';
        $o .= 'Repeat="0" ';
        $o .= 'RepeatRate="0" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/**
 * Die Felder je Geraet: Kuerzel, Einheit, Grenzen, Sprachschluessel.
 *
 * ALTER geht ausdruecklich bis -1 hinunter. -1 heisst "noch nie ein
 * Lebenszeichen" und ist etwas anderes als 0 ("gerade eben gehoert").
 * NEUSTARTS ebenso: -1 heisst "diese Art zaehlt keine Neustarts".
 *
 * Reihenfolge und Namen muessen zu felder() im Waechter passen; der Reiter
 * Test haelt beide gegeneinander.
 */
function fw_felder()
{
    return array(
        'OK'        => array('',  0,  1,          'FW_FELD.OK'),
        'STUFE'     => array('',  0,  3,          'FW_FELD.STUFE'),
        /* Welle Bild 15 (1.0.15, Entwurf Welle 15 fw, E11): MaxVal ist in Loxone die Validierungsgrenze -
         * bis 1.0.14 stand hier 86400, und ein Stick, der laenger als einen Tag schweigt, zeigte 0. */
        'ALTER'     => array('s', -1, 31536000,   'FW_FELD.G_ALTER'),
        'HEILUNGEN' => array('',  0,  9999,       'FW_FELD.HEILUNGEN'),
        'VERSUCHE'  => array('',  0,  9999,       'FW_FELD.VERSUCHE'),
        'ABGELEHNT' => array('',  0,  9999,       'FW_FELD.ABGELEHNT'),
        'HEIL24'    => array('',  0,  99,         'FW_FELD.HEIL24'),
        'HEIL7T'    => array('',  0,  999,        'FW_FELD.HEIL7T'),
        'SEIT'      => array('s', 0,  8640000,    'FW_FELD.SEIT'),
        'LETZTE'    => array('',  0,  4102444800, 'FW_FELD.LETZTE'),
        'NEUSTARTS' => array('',  -1, 99999,      'FW_FELD.NEUSTARTS'),
        'GRUNDNR'   => array('',  0,  9,          'FW_FELD.GRUNDNR'),
        'WARUMNR'   => array('',  0,  10,         'FW_FELD.WARUMNR'),
    );
}

/** Die Summenfelder - dieselbe Quelle fuer Vorlage, Tabelle und Zeile. */
function fw_summenfelder()
{
    return array(
        'OK'       => array('',  0, 1,          'FW_FELD.SUM_OK'),
        'KRANK'    => array('',  0, 99,         'FW_FELD.SUM_KRANK'),
        'GERAETE'  => array('',  0, 99,         'FW_FELD.SUM_GERAETE'),
        'GEHEILT'  => array('',  0, 99999,      'FW_FELD.SUM_GEHEILT'),
        'VERSUCHE' => array('',  0, 99999,      'FW_FELD.SUM_VERSUCHE'),
        'ALARM'    => array('',  0, 1,          'FW_FELD.SUM_ALARM'),
        'GESPERRT' => array('',  0, 1,          'FW_FELD.SUM_GESPERRT'),
        'WARTUNG'  => array('s', 0, 86400,      'FW_FELD.SUM_WARTUNG'),
        'TS'       => array('',  0, 4102444800, 'FW_FELD.SUM_TS'),
        /* Welle Bild 15 (1.0.15, E11): MinVal -1, denn ohne stand.json liefert der Endpunkt ALTER=-1
         * (bis 1.0.14 MinVal 0: Loxone zeigte "gerade geprueft"); MaxVal wie je Stick. */
        'ALTER'    => array('s', -1, 31536000, 'FW_FELD.SUM_ALTER'),
    );
}

function fw_klartext($schluessel)
{
    return trim(strip_tags(html_entity_decode(fw_t($schluessel), ENT_QUOTES, 'UTF-8')));
}

function fw_einheit($e)
{
    return $e === '' ? '<v.0>' : ('<v.0> ' . $e);
}

function fw_endpunkt()
{
    $p = fw_paths();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    return 'http://' . $host . '/plugins/' . $p['plugin'] . '/index.php';
}

/** Der Titel eines virtuellen Eingangs - je Stick, nicht als Platzhalter. */
function fw_titel($g, $nr, $feld)
{
    $kurz = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $g['name']));
    if ($kurz === '') { $kurz = 'STICK' . $nr; }
    return 'FW_' . substr($kurz, 0, 12) . '_' . $feld;
}

/**
 * Das Suchmuster eines Feldes - mit fuehrendem Semikolon.
 * Ohne es faende das Muster OK= auch die Stelle G1OK= in einer spaeteren
 * Zeile. Heute ginge das gut, weil die Summenzeile zuerst kommt - aber das
 * ist eine Wette auf die Reihenfolge.
 */
function fw_muster($feld)
{
    return '\i;' . $feld . '=\i\v';
}

function fw_vorlage()
{
    $cmds = array();
    foreach (fw_geraete() as $nr => $g) {
        foreach (fw_felder() as $feld => $info) {
            $cmds[] = array(
                'title'   => fw_titel($g, $nr, $feld),
                'comment' => $g['name'] . ': ' . fw_klartext($info[3]),
                'check'   => fw_muster('G' . $nr . $feld),
                'min'     => $info[1],
                'max'     => $info[2],
                'unit'    => fw_einheit($info[0]),
            );
        }
    }
    foreach (fw_summenfelder() as $feld => $info) {
        $cmds[] = array(
            'title'   => 'FW_' . $feld,
            'comment' => fw_klartext($info[3]),
            'check'   => fw_muster($feld),
            'min'     => $info[1],
            'max'     => $info[2],
            'unit'    => fw_einheit($info[0]),
        );
    }
    $adresse = fw_endpunkt() . '?token=' . fw_token() . '&aktion=status';
    return array('VI_FUNKWACHT.xml', fw_xml_virtual_in_http(array(
        'title'   => 'Funkwacht',
        'address' => $adresse,
        'polling' => '60',
        'comment' => sprintf(fw_klartext('FW_XML.KOPF'), date('d.m.Y')),
    ), $cmds));
}

/**
 * Die Vorlage der Steuerbefehle.
 *
 * Sie schaltet AUSDRUECKLICH keine Heilung: es gibt keinen Befehl, mit dem
 * sich von aussen ein USB-Anschluss zuruecksetzen liesse. Was hier steht,
 * ERLAUBT dem Waechter wieder zu urteilen (quittieren) oder haelt ihn
 * voruebergehend an (Wartung) - beides ist harmlos, und beides fehlte
 * bisher: ein Stick auf "Tagesgrenze" liess sich weder aus Loxone noch aus
 * der Oberflaeche zuruecksetzen.
 */
function fw_vorlage_vo()
{
    $p = fw_paths();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    $basis = '/plugins/' . $p['plugin'] . '/index.php?token=' . rawurlencode(fw_token());
    $cmds = array(
        array('title' => 'FW_QUITTIEREN',
              'comment' => fw_klartext('LOX.VO_QUITTIEREN'),
              'on' => $basis . '&aktion=quittieren'),
        array('title' => 'FW_WARTUNG_EIN',
              'comment' => fw_klartext('LOX.VO_WARTUNG'),
              'on' => $basis . '&aktion=wartung&dauer=60',
              'off' => $basis . '&aktion=wartung&dauer=0'),
    );
    foreach (fw_geraete() as $nr => $g) {
        $kurz = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $g['name']));
        if ($kurz === '') { $kurz = 'STICK' . $nr; }
        $cmds[] = array(
            'title' => 'FW_QUITT_' . substr($kurz, 0, 12),
            'comment' => sprintf(fw_klartext('LOX.VO_QUITT_EINZELN'), $g['name']),
            'on' => $basis . '&aktion=quittieren&nr=' . (int) $nr);
    }
    return array('VQ_FUNKWACHT.xml', fw_xml_virtual_out(array(
        'title'   => 'Funkwacht Steuerbefehle',
        'address' => 'http://' . $host,
        'comment' => sprintf(fw_klartext('FW_XML.KOPF_VO'), date('d.m.Y')),
    ), $cmds));
}

/**
 * Der erste eingetragene Stick statt des Mustersticks "Zigbee" der Musterliste (Welle Bild 15, 1.0.15).
 *
 * Rueckgabe: Ersetzungstabelle fuer strtr() ueber Zeilen, Kaesten und Hinweise der Baustein-Listen. Die Formen
 * stehen so in der Liste: FW_ZIGBEE_<FELD> (Titel wie fw_titel() der Importvorlage), FW_QUITT_ZIGBEE (wie
 * fw_vorlage_vo()), funkwacht_geraet1_<thema> (Virtueller Texteingang: so benennt das MQTT-Gateway das Thema
 * <praefix>/geraet<n>/<thema>), Zigbee in deutschen und englischen Anfuehrungszeichen sowie Zigbee- und
 * "Zigbee " in Bausteinnamen wie "Zigbee-Stick" oder "Zigbee Fehlversuche heute". Ohne eingetragenen Stick
 * bleibt das Beispiel; nur das Themenpraefix folgt der Einstellung. strtr() ersetzt die laengste Form zuerst
 * und nie zweimal. Die Datei bleibt reines ASCII: Anfuehrungszeichen als \u{201E}, \u{201C}, \u{201D}.
 */
function fw_bs_beispiel()
{
    $geraete = fw_geraete();
    $erster = $geraete ? reset($geraete) : null;
    $cfg = fw_config();
    $thema = trim(str_replace('/', '_', isset($cfg['mqtt_topic']) ? (string) $cfg['mqtt_topic'] : ''), '_');
    if ($thema === '') { $thema = 'funkwacht'; }
    $nr = $erster ? (int) $erster['nr'] : 1;
    $map = array('funkwacht_geraet1_' => $thema . '_geraet' . $nr . '_');
    if ($erster) {
        $name = (string) $erster['name'];
        $kopf = fw_titel($erster, $nr, '');
        $map['FW_ZIGBEE_'] = $kopf;
        $map['FW_QUITT_ZIGBEE'] = 'FW_QUITT_' . substr($kopf, 3, -1);
        $map["\u{201E}Zigbee\u{201C}"] = "\u{201E}" . $name . "\u{201C}";
        $map["\u{201C}Zigbee\u{201D}"] = "\u{201C}" . $name . "\u{201D}";
        $map['Zigbee-'] = $name . '-';
        $map['Zigbee '] = $name . ' ';
    }
    return $map;
}

/**
 * Die Baustein-Listen fuer den Reiter "Einbindung in Loxone" (Welle Bild 15, 1.0.15; Hausherr 11.10.2026:
 * Vollausstattung, ein absoluter Laie soll das Maximum leicht uebernehmen koennen; Entwurf Welle 15 fw,
 * Entscheidung 12). Drei Seiten aus dem LoxBerry-Plugins Musterprojekt, dort in Loxone Config gebaut und
 * verbunden (Musterprojekt/baustein_listen.txt, Abschnitte "Funkwacht" 21, "Funkwacht Sticks" 30 und
 * "Funkwacht Steuerung" 6 Zeilen, wortgleich). Je Tabellenzeile EIN Sprachschluessel LOX.Z<seite>_<nn> =
 * "Typ | Name | Parameter | Eingaenge" ohne Auszeichnung; index.php gibt jede Zelle und jeden Satz maskiert
 * aus. Den Musterstick ersetzt fw_bs_beispiel() durch den ersten eingetragenen Stick.
 *
 * Bis 1.0.14 rechnete diese Funktion eine Liste mit 15 Zeilen (Nachzug G2, 02.10.2026), die vom Bild abwich
 * und Unbaubares enthielt: Merker als Quelle, Statistik als Baustein, Vergleicher ohne Von/Voff (die
 * Werkseinstellung 5/1 meldete einen einzelnen gestoerten Stick nie), den Status-Ausgang als Alarmweg.
 *
 * Rueckgabe: je Seite 'kopf', 'seite' (Name der Musterseite), 'bild', 'schritte' und 'hinweise' (Saetze),
 * 'zeilen' (je array(Nummer, Typ, Name, Parameter, Eingaenge)) - alles reiner Text.
 */
function fw_bausteinliste()
{
    $map = fw_bs_beispiel();
    $seiten = array(
        array('kopf' => fw_t('LOX.BS1_H'), 'seite' => 'Funkwacht', 'bild' => 'einbindung_loxone.png', 'z' => 1, 'n' => 21,
              'schritte' => array(
                sprintf(fw_t('LOX.BS1_S1'), fw_t('REITER.EINSTELLUNGEN'), fw_t('VORL.H'), fw_t('DIENST.K_START'), fw_t('REITER.TEST'), fw_t('TEST.K_ZEILE')),
                sprintf(fw_t('LOX.BS1_S2'), fw_t('REITER.LOXONE'), fw_t('LOX.K_VI')),
                fw_t('LOX.BS1_S3'),
                fw_t('LOX.BS1_S4'),
                fw_t('LOX.BS1_S5'),
                fw_t('LOX.BS1_S6')),
              'hinweise' => array(
                fw_t('LOX.BS1_H1'),
                fw_t('LOX.BS1_H2'),
                fw_t('LOX.BS1_H3'),
                fw_t('LOX.BS1_H4'),
                fw_t('LOX.BS1_H5'),
                fw_t('LOX.BS1_H6'),
                sprintf(fw_t('LOX.BS1_H7'), fw_t('LOX.K_TOKEN_NEU'), fw_t('REITER.LOXONE')))),
        array('kopf' => fw_t('LOX.BS2_H'), 'seite' => 'Funkwacht Sticks', 'bild' => 'einbindung_loxone_sticks.png', 'z' => 2, 'n' => 30,
              'schritte' => array(
                sprintf(fw_t('LOX.BS2_S1'), fw_t('LOX.K_VI')),
                fw_t('LOX.BS2_S2'),
                sprintf(fw_t('LOX.BS2_S3'), fw_t('MQTT.EIN')),
                fw_t('LOX.BS2_S4'),
                fw_t('LOX.BS2_S5'),
                sprintf(fw_t('LOX.BS2_S6'), fw_t('REITER.TEST'), fw_t('TEST.K_MESSEN1'))),
              'hinweise' => array(
                fw_t('LOX.BS2_H1'),
                sprintf(fw_t('LOX.BS2_H2'), fw_t('EINST.L_ART2'), fw_t('FW_ART.DIENST'), fw_t('EINST.L_VERKN')),
                fw_t('LOX.BS2_H3'),
                fw_t('LOX.BS2_H4'),
                fw_t('LOX.BS2_H5'))),
        array('kopf' => fw_t('LOX.BS3_H'), 'seite' => 'Funkwacht Steuerung', 'bild' => 'einbindung_loxone_steuerung.png', 'z' => 3, 'n' => 6,
              'schritte' => array(
                sprintf(fw_t('LOX.BS3_S1'), fw_t('REITER.LOXONE'), fw_t('LOX.K_VQ')),
                fw_t('LOX.BS3_S2'),
                fw_t('LOX.BS3_S3'),
                fw_t('LOX.BS3_S4')),
              'hinweise' => array(
                fw_t('LOX.BS3_H1'),
                fw_t('LOX.BS3_H2'))),
    );
    $aus = array();
    $ersetzen = function ($x) use ($map) { return strtr($x, $map); };
    foreach ($seiten as $s) {
        $zeilen = array();
        for ($i = 1; $i <= $s['n']; $i++) {
            $c = array_pad(explode(' | ', strtr(fw_t(sprintf('LOX.Z%d_%02d', $s['z'], $i)), $map), 4), 4, '');
            $zeilen[] = array($i, $c[0], $c[1], $c[2], $c[3]);
        }
        $aus[] = array('kopf' => $s['kopf'], 'seite' => $s['seite'], 'bild' => $s['bild'],
                       'schritte' => array_map($ersetzen, $s['schritte']),
                       'hinweise' => array_map($ersetzen, $s['hinweise']), 'zeilen' => $zeilen);
    }
    return $aus;
}

/**
 * Die Statuszeile fuer den Miniserver.
 *
 * Sie rechnet NICHTS selbst nach, sondern gibt die Werteliste aus, die der
 * Waechter in stand.json abgelegt hat. Nur ALTER entsteht hier, weil es im
 * Augenblick der Frage gerechnet werden muss.
 */
function fw_zeile($stand)
{
    $f = isset($stand['felder']) && is_array($stand['felder']) ? $stand['felder'] : null;
    $alter = fw_alter();
    if ($f === null) {
        return sprintf("FUNKWACHT;OK=0;KRANK=0;GERAETE=0;GEHEILT=0;VERSUCHE=0;"
                     . "ALARM=0;GESPERRT=0;WARTUNG=0;TS=0;ALTER=%d\n", $alter);
    }
    $w = function ($k) use ($f) {
        return isset($f[$k]) && is_numeric($f[$k]) ? (string) (0 + $f[$k]) : '-';
    };
    $o = 'FUNKWACHT';
    foreach (array_keys(fw_summenfelder()) as $feld) {
        $o .= ';' . $feld . '=' . ($feld === 'ALTER' ? (string) $alter : $w($feld));
    }
    $o .= "\n";
    foreach (array_keys((array) (isset($stand['geraete']) ? $stand['geraete'] : array())) as $nr) {
        $o .= 'STICK' . (int) $nr;
        foreach (array_keys(fw_felder()) as $feld) {
            $o .= ';G' . (int) $nr . $feld . '=' . $w('G' . (int) $nr . $feld);
        }
        $o .= "\n";
    }
    return $o;
}

/** Traegt die Vorlage genau die Felder, die der Waechter auch liefert? */
function fw_felder_kongruent()
{
    $stand = fw_stand();
    if (!isset($stand['felder']) || !is_array($stand['felder'])) {
        return array(-1, fw_klartext('TEST.P_KEINE_MESSUNG'));
    }
    $soll = array();
    foreach (fw_geraete() as $nr => $g) {
        foreach (array_keys(fw_felder()) as $feld) { $soll[] = 'G' . $nr . $feld; }
    }
    foreach (array_keys(fw_summenfelder()) as $feld) {
        if ($feld !== 'ALTER') { $soll[] = $feld; }   // ALTER rechnet der Endpunkt
    }
    $ist = array_keys($stand['felder']);
    sort($soll);
    sort($ist);
    $fehlt = array_diff($soll, $ist);
    $zuviel = array_diff($ist, $soll);
    if (!$fehlt && !$zuviel) {
        /* Gemeldet wird die Zahl der GEMESSENEN Felder, nicht die der
         * erwarteten - sonst meldet die Zeile eine Zahl, die auch dann
         * stimmt, wenn gar nichts gemessen wurde. */
        return array(1, sprintf(fw_klartext('TEST.P_FELDER_OK'), count($ist)));
    }
    return array(0, sprintf(fw_klartext('TEST.P_FELDER_ABW'),
        $fehlt ? implode(', ', $fehlt) : '-', $zuviel ? implode(', ', $zuviel) : '-'));
}

/* ==================================================================
 * Pruefstellen - EINE fuer Formular und Zurueckspielen
 *
 * Seit der Pruefung vom 29.09.2026 (U3, U6, D5) pruefen das Formular und
 * das Zurueckspielen einer Sicherung mit DENSELBEN Funktionen. Bis 1.0.8
 * nahm das Zurueckspielen fremde Schluessel, Felder statt Texten und ein
 * Aktionstoken "Array" an; eine Sicherung mit geraete als Zuordnung legte
 * Oberflaeche und Endpunkt still (TypeError in fw_config()).
 * ================================================================== */

/* D5: Einheiten, die das Plugin nie neu startet - DIESELBE Liste wie
 * EINHEIT_TABU in bin/fw_pruef.py. Ein Eintrag mit * am Ende gilt als
 * Anfang des Namens. Die sudo-Regel "systemctl restart *" erlaubt jede
 * Einheit; die Einschraenkung leistet das Plugin, nicht sudo. */
define('FW_EINHEIT_TABU', array('reboot', 'poweroff', 'halt', 'shutdown', 'kexec',
    'rescue', 'emergency', 'ssh', 'sshd', 'apache2', 'cron', 'systemd', 'systemd-*', 'dbus',
    'mosquitto', 'lbdefaults', 'loxberry*'));

/** Die Zahlenfelder je Stick: Formularfeld, von, bis. */
function fw_geraet_zahlen()
{
    return array(
        'hoechstalter' => array('g_alter', 10, 86400),
        'hoechststufe' => array('g_stufe', 0, 3),
        'ruhe_s'       => array('g_ruhe', 10, 3600),
        'abstand_s'    => array('g_abstand', 30, 86400),
        'je_tag'       => array('g_tag', 0, 50),
        'port'         => array('g_port', 0, 99),
    );
}

/** Die Zahlenfelder des Betriebs: Formularfeld, von, bis. */
function fw_betrieb_zahlen()
{
    return array(
        'takt'         => array('takt', 15, 3600),
        'anlauf_s'     => array('anlauf_s', 0, 3600),
        'log_kb'       => array('log_kb', 16, 20000),
        'verlauf_tage' => array('verlauf_tage', 1, 730),
    );
}

/**
 * Eine GANZE Zahl pruefen. Rueckgabe: array(Wert oder null, Grund).
 * Grund: '' | 'keine_zahl' | 'keine_ganzzahl' | 'ausserhalb'.
 * Es wird nichts gerundet (U6; bis 1.0.8 wurde 60.4 still zu 60).
 */
function fw_ganzzahl_pruefen($roh, $von, $bis)
{
    if (is_int($roh)) {
        $w = $roh;
    } elseif (is_string($roh) && preg_match('/^\s*-?[0-9]{1,9}\s*$/', $roh)) {
        $w = (int) trim($roh);
    } elseif (is_float($roh) || (is_string($roh) && is_numeric(trim($roh)))) {
        return array(null, 'keine_ganzzahl');
    } else {
        return array(null, 'keine_zahl');
    }
    if ($w < $von || $w > $bis) { return array(null, 'ausserhalb'); }
    return array($w, '');
}

/** Traegt der Text ein Steuerzeichen? Kennwoerter werden nie veraendert (U7). */
function fw_steuerzeichen($s)
{
    return preg_match('/[\x00-\x1F\x7F]/', (string) $s) === 1;
}

/**
 * Einen systemd-Einheitennamen pruefen (D5). Rueckgabe: '' oder ein Kuerzel
 * des Grundes ('leer', 'zeichen', 'strich', 'anfang', 'endung', 'doppelt',
 * 'vorlage', 'tabu:<eintrag>').
 * Erlaubt: [A-Za-z0-9@._-], kein fuehrendes -, Endung .service oder keine
 * (dann haengt der Waechter .service an). Dieselbe Rechnung wie
 * einheit_pruefen() in bin/fw_pruef.py.
 */
function fw_einheit_pruefen($e)
{
    if (!is_string($e) || $e === '') { return 'leer'; }
    if (!preg_match('/^[A-Za-z0-9@._\-]+$/', $e)) { return 'zeichen'; }
    if ($e[0] === '-') { return 'strich'; }
    /* a2 (Welle 4, 01.10.2026): ".versteckt", "@x" und "_x" meint niemand
     * als Einheit - bis 1.0.9 gingen sie durch und scheiterten erst an
     * systemctl. */
    if (!preg_match('/^[A-Za-z0-9]/', $e)) { return 'anfang'; }
    $basis = $e;
    if (substr($basis, -8) === '.service') {
        $basis = substr($basis, 0, -8);
    } elseif (preg_match('/\.(target|socket|mount|automount|swap|path|timer|slice|scope|device)$/', $basis)) {
        return 'endung';
    }
    if ($basis === '') { return 'leer'; }
    /* a2: "x.service.service" - die Endung steht doppelt. */
    if (preg_match('/\.(service|target|socket|mount|automount|swap|path|timer|slice|scope|device)$/', $basis)) {
        return 'doppelt';
    }
    /* a2: eine Vorlage braucht genau ein @ und eine Instanz dahinter
     * (getty@tty1); "x@" laesst sich nicht neu starten. */
    if (strpos($basis, '@') !== false
        && (substr_count($basis, '@') > 1 || substr($basis, -1) === '@')) {
        return 'vorlage';
    }
    $at = strpos($basis, '@');
    $vorn = strtolower($at === false ? $basis : substr($basis, 0, $at));
    foreach (FW_EINHEIT_TABU as $t) {
        $treffer = substr($t, -1) === '*'
            ? strpos($vorn, substr($t, 0, -1)) === 0
            : $vorn === $t;
        if ($treffer) { return 'tabu:' . $t; }
    }
    return '';
}

/** Das Kuerzel aus fw_einheit_pruefen() als Satz. */
function fw_einheit_grund($kuerzel)
{
    if (strpos($kuerzel, 'tabu:') === 0) {
        return sprintf(fw_t('EINHEIT.TABU'), substr($kuerzel, 5));
    }
    return fw_t('EINHEIT.' . strtoupper($kuerzel));
}

/** Einen Containernamen pruefen (D5). Rueckgabe: '' oder 'zeichen'. */
function fw_container_pruefen($c)
{
    return (is_string($c) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]*$/', $c)) ? '' : 'zeichen';
}

/**
 * Hat das Aktionstoken die Form, die fw_token_erzeugen() erzeugt?
 * 24 Zeichen aus abcdefghijkmnpqrstuvwxyz23456789 (ohne l, o, 0, 1) - seit
 * 1.0.0 unveraendert (in 1.0.0, 1.0.4 und 1.0.7 nachgelesen).
 */
function fw_token_gueltig($t)
{
    return is_string($t) && preg_match('/^[a-km-np-z2-9]{24}\z/', $t) === 1;
}

/**
 * Die Zusammenhaenge einer Stick-Zeile - das, was das Formular beim
 * Speichern beanstandet. $g traegt schon die richtigen Typen. Rueckgabe:
 * Liste der Beanstandungen, leer heisst gueltig.
 */
function fw_geraet_pruefen($g, $nr, &$felder = array())
{
    /* X-2 (Welle 4): $felder sammelt die Schluessel der beanstandeten
     * Felder, damit die Oberflaeche genau diese markiert. */
    $f = array();
    /* D5: auch in einer Zeile ohne Namen - der Name kann spaeter kommen. */
    if ($g['dienst'] !== '') {
        $grund = fw_einheit_pruefen($g['dienst']);
        if ($grund !== '') {
            $f[] = sprintf(fw_t('FEHLER.EINHEIT'), $nr, $g['dienst'], fw_einheit_grund($grund));
            $felder[] = 'dienst';
        }
    }
    if ($g['container'] !== '' && fw_container_pruefen($g['container']) !== '') {
        $f[] = sprintf(fw_t('FEHLER.CONTAINER'), $nr, $g['container']);
        $felder[] = 'container';
    }
    $leer = ($g['name'] === '' && $g['pfad'] === '' && $g['thema'] === '');
    if ($leer) { return $f; }
    if ($g['name'] === '') {
        $f[] = sprintf(fw_t('FEHLER.NAME_FEHLT'), $nr);
        $felder[] = 'name';
    }
    foreach (array(array($g['art'], $g['pfad'], $g['thema'], ''),
                   array($g['art2'], $g['pfad2'], $g['thema2'], '2')) as $k) {
        if ($k[0] === '') { continue; }
        if ($k[0] === 'mqtt' && $k[2] === '') {
            $f[] = sprintf(fw_t('FEHLER.THEMA_FEHLT'), $nr);
            $felder[] = 'thema' . $k[3];
        }
        if ($k[0] !== 'mqtt' && $k[1] === ''
            && !in_array($k[0], array('dienst', 'docker'), true)) {
            $f[] = sprintf(fw_t('FEHLER.PFAD_FEHLT'), $nr);
            $felder[] = 'pfad' . $k[3];
        }
    }
    if ($g['art'] === 'dienst' && $g['pfad'] === '' && $g['dienst'] === '') {
        $f[] = sprintf(fw_t('FEHLER.DIENST_FEHLT'), $nr);
        $felder[] = 'dienst';
    }
    if ($g['art'] === 'docker' && $g['pfad'] === '' && $g['container'] === '') {
        $f[] = sprintf(fw_t('FEHLER.CONTAINER_FEHLT'), $nr);
        $felder[] = 'container';
    }
    /* Gross- oder Kleinbuchstaben: der Waechter vergleicht ohne Ruecksicht
     * darauf. Bis 1.0.9 schrieb das Formular die Kennung still klein
     * (Nr. 19, Welle 4) - jetzt bleibt sie, wie sie eingetippt ist. */
    if ($g['kennung'] !== '' && !preg_match('/^[0-9a-f]{4}:[0-9a-f]{4}$/i', $g['kennung'])) {
        $f[] = sprintf(fw_t('FEHLER.KENNUNG'), $nr, $g['kennung']);
        $felder[] = 'kennung';
    }
    /* a3 (Entscheidung 16, Welle 4): sobald Stufe 2 oder 3 eingeschaltet
     * ist (Heilen an, Hoechststufe reicht hin, USB-Pfad bzw. Verteiler
     * eingetragen), ist die erwartete Kennung Pflicht. Ohne sie setzte der
     * Waechter auch ein fremdes Geraet zurueck, das jemand in denselben
     * Anschluss gesteckt hat; er lehnt Stufe 2/3 ohne Kennung seit dieser
     * Fassung ab (kennung_abweichung in bin/funkwacht_dienst.py). */
    $fw_st2 = $g['heilen'] && $g['hoechststufe'] >= 2 && $g['usb_pfad'] !== '';
    $fw_st3 = $g['heilen'] && $g['hoechststufe'] >= 3 && $g['hub'] !== '';
    if (($fw_st2 || $fw_st3) && $g['kennung'] === '') {
        $f[] = sprintf(fw_t('FEHLER.KENNUNG_PFLICHT'), $nr);
        $felder[] = 'kennung';
    }
    /* Heilen ohne einen einzigen Hebel ist ein eingeschalteter
     * Schalter, der nichts tut. Lieber jetzt sagen. */
    if ($g['heilen'] && $g['hoechststufe'] > 0
        && $g['dienst'] === '' && $g['container'] === ''
        && $g['usb_pfad'] === '' && $g['hub'] === '') {
        $f[] = sprintf(fw_t('FEHLER.KEIN_HEBEL'), $nr);
        $felder[] = 'heilen';
    }
    if ($g['hoechststufe'] >= 3 && ($g['hub'] === '' || $g['port'] <= 0)) {
        $f[] = sprintf(fw_t('FEHLER.UHUBCTL_UNVOLLSTAENDIG'), $nr);
        $felder[] = 'hub';
        $felder[] = 'port';
    }
    /* Die Erholungszeit laenger als der Mindestabstand hiesse: der
     * Stick gilt bis zum naechsten erlaubten Versuch als gesund und
     * es wird nie wieder geheilt. Melden, nicht zurechtbiegen. */
    if ($g['ruhe_s'] >= $g['abstand_s']) {
        $f[] = sprintf(fw_t('FEHLER.RUHE_ZU_LANG'), $nr, $g['ruhe_s'], $g['abstand_s']);
        $felder[] = 'ruhe_s';
        $felder[] = 'abstand_s';
    }
    return $f;
}

/* ==================================================================
 * a1 (Welle 4, 01.10.2026): Stufe 3 schon beim Speichern beurteilen
 *
 * Bis 1.0.9 sah man erst zur Laufzeit - als Ablehnung im Protokoll -, dass
 * der Waechter einen Verteiler sperrt (etwa den Wurzelverteiler "2" am
 * Raspberry Pi 4). Dieselbe Rechnung wie ist_systemgeraet() und
 * verteiler_sperre() in bin/fw_pruef.py; die Systemgeraete legt der
 * Waechter in jedem Durchlauf als 'tabu' in stand.json ab. Ohne stand.json
 * (Waechter lief noch nie) werden nur Wurzelverteiler und Form geprueft.
 * ================================================================== */

function fw_ist_systemgeraet($u, $tabu)
{
    $u = trim((string) $u);
    if ($u === '') { return false; }
    foreach ((array) $tabu as $s) {
        $s = trim(is_string($s) ? $s : '');
        if ($s === '') { continue; }
        if ($u === $s || strpos($u, $s . ':') === 0 || strpos($u, $s . '.') === 0
            || strpos($s, $u . ':') === 0) {
            return true;
        }
    }
    return false;
}

/** '' oder der Grund (Satz), warum der Waechter Stufe 3 hier ablehnen wuerde. */
function fw_verteiler_sperre($hub, $port, $tabu)
{
    $h = trim((string) $hub);
    $tabu = array_values(array_filter((array) $tabu, 'is_string'));
    if (preg_match('/^[0-9]+$/', $h)) {
        return sprintf(fw_t('EINST.VT_WURZEL'), $h);
    }
    if (!preg_match('/^[0-9]+-[0-9]+(\.[0-9]+)*$/', $h)) {
        return sprintf(fw_t('EINST.VT_FORM'), $h);
    }
    $ziel = $h . '.' . (int) $port;
    if (fw_ist_systemgeraet($h, $tabu) || fw_ist_systemgeraet($ziel, $tabu)) {
        return sprintf(fw_t('EINST.VT_SYSTEM'), $ziel, implode(', ', $tabu));
    }
    foreach ($tabu as $s) {
        $s = trim($s);
        if ($s !== '' && (strpos($s, $h . '.') === 0 || strpos($s, $h . ':') === 0)) {
            return sprintf(fw_t('EINST.VT_NACHBAR'), $h, $s);
        }
    }
    return '';
}

/* ==================================================================
 * Docker-2 (Welle 4): Ist Docker da und ansprechbar?
 *
 * Die Art "Docker" fragt "docker inspect" OHNE sudo. Fehlt Docker oder
 * fehlt dem Benutzer loxberry der Zugriff, meldete die Zeile bis 1.0.9 nur
 * "docker inspect antwortet nicht". Rueckgabe array(lage, grund) mit lage
 * ok | fehlt | kein_zugriff; grund ist schlichter Text (nicht maskiert).
 * Bauart mt_docker_lage() (Matter2Lox 0.9.34): "docker info" mit Frist.
 * Die Funkwacht legt keine Container an - sie verweist auf Docker NG.
 * ================================================================== */

function fw_docker_lage($sekunden = 5)
{
    $da = array();
    @exec('command -v docker 2>/dev/null', $da);
    if (!$da) {
        return array('fehlt', '');
    }
    $sekunden = max(1, (int) $sekunden);
    $aus = array();
    $rc = 0;
    @exec('timeout -k 2 ' . $sekunden . ' docker info --format '
          . escapeshellarg('{{.ServerVersion}}') . ' 2>&1', $aus, $rc);
    if ($rc === 0) {
        return array('ok', '');
    }
    $t = strtolower(trim(implode(' ', $aus)));
    if ($rc === 124 || $rc === 137) {
        return array('kein_zugriff', sprintf(fw_t('EINST.DOCKER_G_HAENGT'), $sekunden));
    }
    if (strpos($t, 'permission denied') !== false) {
        return array('kein_zugriff', fw_t('EINST.DOCKER_G_GRUPPE'));
    }
    if (strpos($t, 'cannot connect') !== false || strpos($t, 'daemon running') !== false) {
        return array('kein_zugriff', fw_t('EINST.DOCKER_G_DIENST'));
    }
    return array('kein_zugriff', sprintf(fw_t('EINST.DOCKER_G_FEHLER'), $rc));
}

/* ==================================================================
 * X-7 (Welle 4, Entscheidung 19): Gleichwert-Unterdrueckung
 *
 * Gilt nur fuer Sollwert-Befehle. In dieser Linie ist das "wartung" mit
 * seiner Dauer (ein Modus mit Ablauf); "quittieren" ist ein Taster und
 * bleibt ungebremst. Derselbe Wert innerhalb von 60 s wird nicht erneut
 * beauftragt (UNVERAENDERT=1), kein 429. Der Merker wird unter flock
 * gefuehrt und faellt geschlossen aus ('MERKER' -> 503). Bauart
 * by_gleichwert_pruefen() (BYD Autos 0.9.22), Vorbild EVCC 0.9.37.
 * ================================================================== */

define('FW_GLEICHWERT_S', 60);

/**
 * Rueckgabe array(Urteil, Sekunden): 'UNVERAENDERT' (derselbe Wert ging vor
 * weniger als 60 s hinaus), 'MERKER' (geschlossen ausfallen) oder '' -
 * dann ist der Wert jetzt vorgemerkt.
 */
function fw_gleichwert_pruefen($schluessel, $wert)
{
    $f = fw_paths()['gleichwert'];
    if ($f === '') { return array('MERKER', 0); }
    if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
    $fh = @fopen($f, 'c+');
    if ($fh === false || !@flock($fh, LOCK_EX)) {
        if (is_resource($fh)) { fclose($fh); }
        return array('MERKER', 0);
    }
    $m = json_decode((string) stream_get_contents($fh), true);
    if (!is_array($m)) { $m = array(); }      // unlesbar gilt als leer
    $jetzt = time();
    $e = isset($m[$schluessel]) && is_array($m[$schluessel]) ? $m[$schluessel] : null;
    if ($e !== null && isset($e['w'], $e['t']) && is_scalar($e['w']) && is_scalar($e['t'])) {
        $seit = $jetzt - (int) $e['t'];
        if ($seit >= 0 && $seit < FW_GLEICHWERT_S && (string) $e['w'] === (string) $wert) {
            flock($fh, LOCK_UN);
            fclose($fh);
            return array('UNVERAENDERT', $seit);
        }
    }
    $m[$schluessel] = array('w' => (string) $wert, 't' => $jetzt);
    $json = (string) json_encode($m);
    $ok = ftruncate($fh, 0) && rewind($fh) && fwrite($fh, $json) === strlen($json) && fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok ? array('', 0) : array('MERKER', 0);
}

/** Den Merker eines Befehls verwerfen (Auftrag gescheitert, Oberflaeche hat geschaltet). */
function fw_gleichwert_vergessen($schluessel)
{
    $f = fw_paths()['gleichwert'];
    if ($f === '' || !is_file($f)) { return true; }
    $fh = @fopen($f, 'c+');
    if ($fh === false || !@flock($fh, LOCK_EX)) {
        if (is_resource($fh)) { fclose($fh); }
        return false;
    }
    $m = json_decode((string) stream_get_contents($fh), true);
    if (!is_array($m)) { $m = array(); }
    unset($m[$schluessel]);
    $json = (string) json_encode((object) $m);
    $ok = ftruncate($fh, 0) && rewind($fh) && fwrite($fh, $json) === strlen($json) && fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

/** Ein Grund aus fw_ganzzahl_pruefen() als Satz einer Beanstandung. */
function fw_sich_zahlgrund($wo, $grund, $von, $bis)
{
    if ($grund === 'ausserhalb') { return sprintf(fw_t('SICH.W_BEREICH'), $wo, $von, $bis); }
    return sprintf(fw_t('SICH.W_ZAHL'), $wo);
}

/** Ein Text, wie ihn das Formular annimmt: kein Steuer- oder Anfuehrungszeichen, kein Rand. */
function fw_sich_text_taugt($v)
{
    return is_string($v) && !preg_match('/[\x00-\x1F\x7F"\']/', $v) && $v === trim($v);
}

/**
 * Eine Stick-Zeile aus einer Sicherung: Typ, Grenzen, Auswahllisten, Muster -
 * und danach dieselben Zusammenhaenge wie das Formular (fw_geraet_pruefen).
 * Nichts wird zurechtgebogen: was nicht passt, wird beanstandet.
 * Rueckgabe: Liste der Beanstandungen, leer heisst gueltig.
 */
function fw_geraet_zeile_pruefen($roh, $nr)
{
    if (!is_array($roh) || ($roh !== array() && array_values($roh) === $roh)) {
        return array(sprintf(fw_t('SICH.ZEILE_KAPUTT'), $nr));
    }
    $f = array();
    $vorgabe = fw_geraet_vorgabe();
    $zahlen = fw_geraet_zahlen();
    $haken = array('aktiv', 'heilen', 'wachstum', 'dienst_nach', 'lernen');
    $auswahl = array('art' => array_keys(fw_arten()), 'art2' => array_keys(fw_arten2()),
                     'verkn' => array('und', 'oder'),
                     'reihenfolge' => array('normal', 'usb_zuerst'));
    $g = $vorgabe;
    foreach ($roh as $k => $v) {
        $k = (string) $k;
        $wo = sprintf(fw_t('SICH.ZEILE_FELD'), $nr, $k);
        if (!array_key_exists($k, $vorgabe)) {
            $f[] = sprintf(fw_t('SICH.FREMD'), $wo);
        } elseif (isset($zahlen[$k])) {
            list($w, $grund) = fw_ganzzahl_pruefen($v, $zahlen[$k][1], $zahlen[$k][2]);
            if ($grund !== '') {
                $f[] = fw_sich_zahlgrund($wo, $grund, $zahlen[$k][1], $zahlen[$k][2]);
            } else {
                $g[$k] = $w;
            }
        } elseif (in_array($k, $haken, true)) {
            if (!in_array($v, array(0, 1, true, false), true)) {
                $f[] = sprintf(fw_t('SICH.W_HAKEN'), $wo);
            } else {
                $g[$k] = $v ? 1 : 0;
            }
        } elseif (isset($auswahl[$k])) {
            if (!is_string($v) || !in_array($v, $auswahl[$k], true)) {
                $f[] = sprintf(fw_t('SICH.W_AUSWAHL'), $wo, implode(', ', $auswahl[$k]));
            } else {
                $g[$k] = $v;
            }
        } elseif (!is_string($v)) {
            $f[] = sprintf(fw_t('SICH.W_TEXT'), $wo);
        } elseif (!fw_sich_text_taugt($v)) {
            $f[] = sprintf(fw_t('SICH.W_ZEICHEN'), $wo);
        } else {
            $g[$k] = $v;
        }
    }
    $fehlt = array();
    foreach (array_keys($vorgabe) as $k) {
        if (!array_key_exists($k, $roh)) { $fehlt[] = $k; }
    }
    if ($fehlt) {
        $f[] = sprintf(fw_t('SICH.ZEILE_FEHLT'), $nr, implode(', ', $fehlt));
    }
    if ($f) { return $f; }
    return fw_geraet_pruefen($g, $nr);
}

/**
 * Einen Schluessel der Sicherung pruefen - dieselben Grenzen, Muster und
 * Auswahllisten wie das Formular. Rueckgabe: Liste der Beanstandungen.
 */
function fw_einstellung_pruefen($k, $w)
{
    $wo = sprintf(fw_t('SICH.SCHLUESSEL'), $k);
    /* Nr. 36 b: der Block tts mit den Regeln des Moduls (Ausgabeart, Adresse im Heimnetz, Vorlage). Ein
     * Sprechtoken in einer Sicherungsdatei weist seit 1.0.27 die Pruefung des Blocks in fw_sicherung_beschreibung()
     * vorher ab. */
    if ($k === 'tts') {
        $fw_tg = '';
        if (is_array($w) && ansage_wert_pruefen($w, $fw_tg, fw_ansage_modi()) !== null) { return array(); }
        return array(sprintf(fw_t('DURCHSAGE.SICH_WERT'),
                             is_array($w) ? ansage_kennung_text($fw_tg, fw_ansage_k()) : 'tts'));
    }
    $zahlen = fw_betrieb_zahlen();
    if ($k === 'geraete') {
        /* Eine LISTE von Zeilen, keine Zuordnung mit Schluesseln: aus
         * {"a": {...}} wurde bis 1.0.8 ein TypeError in fw_config(). */
        if (!is_array($w) || ($w !== array() && array_values($w) !== $w)) {
            return array(sprintf(fw_t('SICH.W_LISTE'), $wo));
        }
        if (count($w) > FW_GERAETE_MAX) {
            return array(sprintf(fw_t('SICH.W_ZU_VIELE'), $wo, FW_GERAETE_MAX));
        }
        $f = array();
        foreach ($w as $i => $z) {
            $f = array_merge($f, fw_geraet_zeile_pruefen($z, $i + 1));
        }
        return $f;
    }
    if ($k === 'zeilen' || isset($zahlen[$k])) {
        $von = $k === 'zeilen' ? 1 : $zahlen[$k][1];
        $bis = $k === 'zeilen' ? FW_GERAETE_MAX : $zahlen[$k][2];
        list(, $grund) = fw_ganzzahl_pruefen($w, $von, $bis);
        return $grund === '' ? array() : array(fw_sich_zahlgrund($wo, $grund, $von, $bis));
    }
    if (in_array($k, array('mqtt_ein', 'global_aus', 'melden_aktiv', 'signal_ein', 'ansage_gestoert', 'ansage_wieder', 'befehle_mqtt_ein', 'endpunkt_ein'), true)) {
        return in_array($w, array(0, 1, true, false), true)
            ? array() : array(sprintf(fw_t('SICH.W_HAKEN'), $wo));
    }
    if ($k === 'aktionstoken') {
        return fw_token_gueltig($w) ? array() : array(sprintf(fw_t('SICH.W_TOKEN'), $wo));
    }
    if ($k === 'broker_port' && is_int($w)) { $w = (string) $w; }
    if (!is_string($w)) { return array(sprintf(fw_t('SICH.W_TEXT'), $wo)); }
    if ($k === 'mqtt_topic') {
        /* 1.0.32: kein Praefix auf einem gemeinsamen Bereich unter haus/ (Regeln/07) - wie im Formular. */
        if (is_string($w) && hb_bereich($w) !== '') {
            return array($k . ': ' . sprintf(fw_t('HAUSBEREICH.SICH_W'), hb_bereich($w)));
        }
        /* 1.0.26: \z statt $ (Regeln/05) - $ liess einen Zeilenumbruch am Ende durch, und das Zurueckspielen
         * speicherte ihn roh; gilt ebenso fuer Uhrzeit, Adresse und Anschluss darunter. */
        /* 1.0.32 (Entscheidung des Hausherrn 24.11.2026, Punkt 19): Grossbuchstaben sind zulaessig - fw_config()
         * behaelt sie, und unter genau diesem Thema wird gesendet (MQTT unterscheidet Gross und Klein). */
        return (preg_match('#^[A-Za-z0-9_\-/]+\z#', $w) && trim($w, '/') === $w)
            ? array() : array(sprintf(fw_t('SICH.W_THEMA'), $wo));
    }
    if ($k === 'ruhe_von' || $k === 'ruhe_bis') {
        return ($w === '' || preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]\z/', $w))
            ? array() : array(sprintf(fw_t('SICH.W_UHRZEIT'), $wo));
    }
    if ($k === 'signal_url') {
        return ($w === '' || preg_match('#^https?://[^\s"\']+\z#', $w))
            ? array() : array(sprintf(fw_t('SICH.W_ADRESSE'), $wo));
    }
    if ($k === 'broker_port') {
        return ($w === '' || (preg_match('/^[0-9]{1,5}\z/', $w) && (int) $w >= 1 && (int) $w <= 65535))
            ? array() : array(sprintf(fw_t('SICH.W_PORT'), $wo));
    }
    if ($k === 'broker_pass') {
        return fw_steuerzeichen($w) ? array(sprintf(fw_t('SICH.W_KENNWORT'), $wo)) : array();
    }
    /* broker_host, broker_user, broker_id: was das Formular annimmt. */
    return fw_sich_text_taugt($w) ? array() : array(sprintf(fw_t('SICH.W_ZEICHEN'), $wo));
}

/* ==================================================================
 * Einmalmeldung nach dem POST (U8, Regeln/04)
 *
 * Jeder POST endet mit 303; das Ergebnis reist in einer Datei im
 * Datenordner (0600), die der folgende GET liest UND loescht; aelter als
 * 120 s wird verworfen. Bauart eb_einmal_schreiben/lesen der Einspeisebremse
 * 0.9.28. Aktionstoken und Broker-Kennwort stehen nicht darin: sie werden
 * vor dem Schreiben durch *** ersetzt (ein Kennwort erst ab vier Zeichen,
 * sonst traefe das Ersetzen gewoehnliche Woerter).
 * ================================================================== */

function fw_einmal_schreiben($meldungen, $fehler, $misslungen, $test, $eingaben = null)
{
    $f = fw_paths()['einmal'];
    if ($f === '') { return false; }
    $cfg = fw_config(false);
    $geheim = array();
    /* 1.0.27: das Broker-Kennwort kommt aus zugang.json (fw_broker_pass()). */
    foreach (array((isset($cfg['aktionstoken']) && is_string($cfg['aktionstoken'])) ? $cfg['aktionstoken'] : '',
                   fw_broker_pass()) as $s) {
        if (strlen($s) >= 4) { $geheim[] = $s; }
    }
    $weg = function ($t) use ($geheim) {
        return $geheim ? str_replace($geheim, '***', (string) $t) : (string) $t;
    };
    return fw_json_schreiben($f, array(
        'zeit' => time(),
        'meldungen' => array_values(array_map($weg, $meldungen)),
        'fehler' => array_values(array_map($weg, $fehler)),
        'misslungen' => array_values(array_map($weg, $misslungen)),
        'test' => $weg($test),
        /* X-2 (Regeln/04, Welle 4): die eingetippten Werte EINES
         * beanstandeten Formulars - nie ein Kennwort (das Feld wird gar nicht
         * gesammelt), und auch hier ersetzt $weg Wortzeichen und Kennwort. */
        'eingaben' => fw_einmal_eingaben_saeubern($eingaben, $weg)), 0600);
}

/** X-2: nur die erwartete Form, Texte durch $weg, sonst null. */
function fw_einmal_eingaben_saeubern($e, $weg)
{
    if (!is_array($e) || !isset($e['form']) || !is_string($e['form'])) { return null; }
    $aus = array('form' => $e['form'], 'falsch' => array(), 'werte' => array(), 'haken' => array());
    foreach (array('falsch') as $k) {
        foreach (isset($e[$k]) && is_array($e[$k]) ? $e[$k] : array() as $v) {
            if (is_string($v)) { $aus[$k][] = $v; }
        }
    }
    foreach (isset($e['werte']) && is_array($e['werte']) ? $e['werte'] : array() as $k => $v) {
        if (is_string($v)) { $aus['werte'][(string) $k] = $weg($v); }
    }
    foreach (isset($e['haken']) && is_array($e['haken']) ? $e['haken'] : array() as $k => $v) {
        $aus['haken'][(string) $k] = $v ? 1 : 0;
    }
    return $aus;
}

function fw_einmal_lesen()
{
    $f = fw_paths()['einmal'];
    if ($f === '' || !is_file($f)) { return null; }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) { return null; }
    $liste = function ($k) use ($d) {
        return isset($d[$k]) && is_array($d[$k]) ? array_map('strval', $d[$k]) : array();
    };
    return array('meldungen' => $liste('meldungen'), 'fehler' => $liste('fehler'),
                 'misslungen' => $liste('misslungen'),
                 'test' => isset($d['test']) ? (string) $d['test'] : '',
                 'eingaben' => fw_einmal_eingaben_saeubern(isset($d['eingaben']) ? $d['eingaben'] : null,
                                                           'strval'));
}

/* ==================================================================
 * Praefixwechsel (M5)
 *
 * Wer das Praefix aendert oder MQTT abschaltet, hinterliesse die
 * zurueckbehaltenen Themen unter dem bisherigen Praefix fuer immer im
 * Broker. Die Oberflaeche merkt sich das bisherige Praefix hier; der
 * Waechter raeumt im naechsten Durchgang darunter ab (nur Themen der
 * Funkwacht), liest nach und streicht es erst dann aus der Liste.
 * ================================================================== */

function fw_altpraefix_merken($praefix)
{
    $f = fw_paths()['altpraefix'];
    if ($f === '' || !is_string($praefix) || $praefix === '') { return false; }
    $d = fw_json_lesen($f);
    $liste = isset($d['praefixe']) && is_array($d['praefixe']) ? $d['praefixe'] : array();
    if (!in_array($praefix, $liste, true)) { $liste[] = $praefix; }
    return fw_json_schreiben($f, array('praefixe' => array_values($liste)));
}

/* ==================================================================
 * Sichern und Zurueckspielen
 * ================================================================== */

/**
 * 1.0.27: Die Beschreibung dieser Linie fuer das gemeinsame Sicherungsmodul (sicherung.php neben dieser Datei;
 * Auftrag des Hausherrn 12.11.2026, Umbau dieser Linie 18.11.2026 "Ja, umbauen").
 *
 * Bis 1.0.26 stand hier ein eigener Zweig (fw_sicherung_bauen(), fw_sicherung_pruefen(), fw_sicherung_lesen()):
 * ein unbekannter Schluessel wies die ganze Datei ab, eine Datei galt als eigene, sobald sie eine Liste geraete trug,
 * ein leerer Wert ueberschrieb den geltenden, und der Haken stand im Formular dieser Linie. Seither wie in jeder Linie
 * mit dem Modul: Kennzeichen _plugin = LoxBerry-Plugin-Funkwacht (Name des Repositorys; bis 1.0.26 gab es keines),
 * eine Datei mit fremdem Kennzeichen wird abgewiesen, eine ohne nur dann gelesen, wenn sie eine aeltere eigene
 * Sicherung ist (fw_sicherung_alt_erkennen()); unbekannte Schluessel werden verworfen und mit Namen genannt; ein
 * leerer Wert ueberschreibt keinen bestehenden; der Haken "Zurueckspielen bestaetigen" kommt aus dem Modul.
 *
 * Wie bisher: jeder Wert geht durch fw_einstellung_pruefen() (dieselben Grenzen, Muster und Auswahllisten wie das
 * Formular), jede Stick-Zeile durch fw_geraet_zeile_pruefen() - fehlt ihr ein Feld, wird abgewiesen; jede Fassung seit
 * 1.0.0 schrieb alle 25 Felder (gemessen 18.11.2026 an fw_geraet_vorgabe() aller Archive 1.0.0 bis 1.0.26). Eine Datei
 * mit Sprechtoken wird abgewiesen, ebenso "Meldung an Signal" ein ohne Adresse. Was eine fruehere Fassung nicht kannte
 * (Sprachansage seit 1.0.11, befehle_mqtt_ein und endpunkt_ein seit 1.0.17, ansage_regeln seit 1.0.19), behaelt den
 * geltenden Wert und wird genannt. Der Vermerk ansage_token_herkunft kommt nie aus einer Datei.
 *
 * Inhalt der Datei: alle Einstellungen samt Wortzeichen (Aktionstoken) und, als Teil "zugang", das Broker-Kennwort
 * aus zugang.json (seit 1.0.27 nicht mehr in funkwacht.json). Die Sprechtoken stehen nie darin.
 */
function fw_sicherung_beschreibung()
{
    $p = fw_paths();
    $tts = array_merge(array_keys(ansage_werk_block()), ansage_geheim());
    /* Ein Feld des Blocks, das eine aeltere Sicherung noch nicht kannte (etwa die weiteren Ziele seit 1.0.17), behaelt
     * den geltenden Wert und wird genannt (Regeln/05 Nr. 6) - bis 1.0.26 geschah das still ("+ $fw_tj"). Die
     * Sprechtoken stehen nie in einer Sicherung; sie behaelt fw_sicherung_vorbereiten(), ohne sie zu nennen. */
    $tts_knoten = array('ziele' => sicherung_liste(array('art', 'wo', 'laut', 'sprache', 'ein'))
                                   + array('fehlt' => 'behalten'));
    foreach (array_diff(array_keys(ansage_werk_block()), ansage_geheim()) as $tk) {
        if (!isset($tts_knoten[$tk])) { $tts_knoten[$tk] = array('fehlt' => 'behalten'); }
    }
    $schluessel = array(
        'geraete' => sicherung_liste(array_keys(fw_geraet_vorgabe())),
        'tts' => sicherung_block($tts, $tts_knoten)
                 + array(
                     'fehlt' => 'behalten',
                     'sichern' => 'ansage_sicherung_bereinigen',
                     'pruefen' => function ($w, $k = '', $neu = null, $roh = null) {
                         /* Eine Sicherung dieses Plugins traegt nie ein Sprechtoken - traegt die Datei eines, wird sie
                          * abgewiesen. Gemessen am Block der DATEI, denn Regel 3 des Moduls setzt ein leeres Feld vorher
                          * auf den geltenden Wert. */
                         $tm = is_array($roh) ? ansage_sicherung_mangel($roh) : array();
                         if ($tm) { return sprintf(fw_t('DURCHSAGE.SICH_TOKEN'), implode(', ', $tm)); }
                         return fw_einstellung_pruefen('tts', ansage_sicherung_bereinigen($w));
                     }),
        'ansage_regeln' => sicherung_block(array_keys(ansage_regeln_vorgaben())) + array(
            'fehlt' => 'behalten',
            'pruefen' => function ($w) {
                $g = '';
                return ansage_regeln_wert($w, $g) === null
                    ? 'ansage_regeln: ' . ansage_kennung_text($g !== '' ? $g : 'M_R_BLOCK', fw_ansage_k()) : '';
            },
            'wandeln' => function ($w) { $g = ''; return ansage_regeln_wert($w, $g); }),
        'zugang' => sicherung_block(array('broker_pass')),
    );
    foreach (array('ansage_gestoert', 'ansage_wieder', 'befehle_mqtt_ein', 'endpunkt_ein') as $s) {
        $schluessel[$s] = array('fehlt' => 'behalten');
    }
    return array(
        'kennzeichen' => 'LoxBerry-Plugin-Funkwacht',
        'hinweis' => 'Sicherung der Einstellungen des LoxBerry-Plugins Funkwacht. Enthaelt das Wortzeichen '
                   . '(Aktionstoken) und das Broker-Kennwort - wie ein Passwort behandeln. Die Sprechtoken fuer '
                   . 'Alexa-NG und Chromecast 4 Lox NG stehen nie darin; beim Zurueckspielen bleiben die hinterlegten.',
        /* Die Fassung im Kopf kommt aus der Plugin-Datenbank bzw. plugin.cfg - keine weitere Stelle im Code. */
        'home' => (string) $p['home'], 'ordner' => (string) $p['plugin'],
        'plugin_cfg' => dirname(dirname(__DIR__)) . '/plugin.cfg',
        'vorgaben' => fw_vorgaben(),
        'schluessel' => $schluessel,
        'nie_aus_datei' => array('ansage_token_herkunft'),
        /* 1.0.32: nie mit Wert in den Lade-Beanstandungen (Modul 1.3.0) - die Adresse des SignalBot (sie traegt dessen
         * Wortzeichen) und die Sprechtoken; aktionstoken und der Teil zugang sind es immer. */
        'geheim' => array_merge(array('signal_url'), array_map(function ($n) { return 'tts.' . $n; }, ansage_geheim())),
        'teile' => array('zugang' => 'fw_sicherung_zugang_lesen'),
        'teile_jetzt' => array('zugang' => 'fw_sicherung_zugang_jetzt'),
        'pruefen' => function ($k, $w) { return fw_einstellung_pruefen($k, $w); },
        'quer' => 'fw_sicherung_quer',
        'uebersetzer' => 'fw_sicherung_alt_erkennen',
        'vorbereiten' => 'fw_sicherung_vorbereiten',
        'jetzt' => function () { return fw_config(); },
        't' => 'fw_t',
        'e' => 'fw_e',
    );
}

/**
 * 1.0.27: Eine Sicherung dieser Linie aus der Zeit vor dem Kennzeichen _plugin (1.0.0 bis 1.0.26) traegt im Kopf
 * _fassung mit dem Text "Funkwacht" - geschrieben nur vom Sichern-Knopf dieser Linie (bis 1.0.26
 * $cfg['_fassung'] = 'Funkwacht' in fw_sicherung_bauen(); gemessen 18.11.2026 an allen 27 Archiven 1.0.0 bis 1.0.26),
 * dazu immer die Liste geraete (an ihr erkannte fw_sicherung_lesen() sie seit 1.0.8) und keinen anderen Kopf als
 * _erzeugt, _fassung und _warnung. Keine andere Linie im Arbeitsordner schreibt _fassung als Text (gemessen
 * 18.11.2026 ueber die PHP-, Python-, JSON- und JS-Dateien aller Plugin-Ordner und der Werkzeuge; dasselbe Muster
 * schlaegt an Funkwacht 1.0.26 an). Das Broker-Kennwort stand dort oben als broker_pass; es kommt in den Teil
 * "zugang", wie ihn diese Fassung schreibt. Rueckgabe: die Daten oder null.
 */
function fw_sicherung_alt_erkennen($d)
{
    if (!is_array($d) || !isset($d['_fassung']) || $d['_fassung'] !== 'Funkwacht' || !array_key_exists('geraete', $d)) {
        return null;
    }
    foreach (array_keys($d) as $k) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_' && !in_array($k, array('_erzeugt', '_fassung', '_warnung'), true)) { return null; }
    }
    if (array_key_exists('broker_pass', $d) && !array_key_exists('zugang', $d)) {
        $d['zugang'] = array('broker_pass' => $d['broker_pass']);
        unset($d['broker_pass']);
    }
    return $d;
}

/**
 * 1.0.27: Der Teil "zugang" einer Sicherung - das Broker-Kennwort. Er geht nach zugang.json (0600), nicht in die
 * Konfiguration. Rueckgabe fuer das Modul: array(Feld|null, Beanstandungen[]). Unbekannte Felder hat das Modul schon
 * verworfen und genannt; ein leeres Kennwort in der Datei laesst das geltende stehen (Regel 3). Gepruft wird wie
 * im Formular: kein Steuerzeichen, sonst unveraendert (U7).
 */
function fw_sicherung_zugang_lesen($w)
{
    if (!is_array($w)) {
        return array(null, array(sprintf(fw_t('SICH.W_FELD'), sprintf(fw_t('SICH.SCHLUESSEL'), 'zugang'))));
    }
    if (!array_key_exists('broker_pass', $w)) { return array(array(), array()); }
    $m = fw_einstellung_pruefen('broker_pass', $w['broker_pass']);
    return $m ? array(null, $m) : array(array('broker_pass' => $w['broker_pass']), array());
}

/** 1.0.27: Der geltende Stand des Teils "zugang" (fuer Regel 3 und fuer das Sichern). */
function fw_sicherung_zugang_jetzt()
{
    return array('broker_pass' => fw_broker_pass());
}

/**
 * 1.0.27: Was nur im Zusammenspiel falsch sein kann (wie bis 1.0.26): "Meldung an Signal" ein ohne Adresse.
 * Rueckgabe fuer das Modul: Liste von array(Name, Text).
 */
function fw_sicherung_quer($neu, $r)
{
    $bean = array();
    foreach ($r['beanstandet'] as $x) { $bean[] = $x['name']; }
    /* 1.0.32: "Meldung an Signal ein ohne Adresse" entfaellt - die Meldung geht ueber das gemeinsame Modul (keine Adresse). */
    return array();
}

/**
 * 1.0.27: Vor dem Speichern, wie bis 1.0.26 nach der Pruefung: der Block tts in der Form des Sprachmoduls und mit
 * den geltenden Sprechtoken (sie stehen nie in einer Sicherung), die Stick-Zeilen und Zahlen in der Schreibweise der
 * Konfiguration (true -> 1, Zahltext -> Zahl). Werte sind hier schon geprueft; nichts wird zurechtgebogen.
 * Gespeichert wird genau, was das Modul liefert (Bauform vom 15.11.2026): bis 1.0.26 ergaenzte "+ $fw_tj" den Block
 * aus dem geltenden Stand - auch um Felder, die diese Fassung nicht kennt; die fallen jetzt weg und werden genannt.
 */
function fw_sicherung_vorbereiten($neu, $jetzt, $r)
{
    $tj = fw_tts($jetzt);
    if (!in_array('tts', $r['fehlt_behalten'], true) && isset($neu['tts']) && is_array($neu['tts'])) {
        $g = '';
        $tv = ansage_wert_pruefen(ansage_sicherung_bereinigen($neu['tts']), $g, fw_ansage_modi());
        if (is_array($tv)) {
            list($tv) = ansage_vervollstaendigen($tv);
            $neu['tts'] = ansage_sicherung_tokens_behalten($tv, $tj);
        }
    }
    if (!isset($neu['geraete']) || !is_array($neu['geraete'])) { $neu['geraete'] = array(); }
    foreach ($neu['geraete'] as $i => $g) {
        $neu['geraete'][$i] = fw_geraet_geradebiegen(is_array($g) ? $g : array());
    }
    foreach (array_keys(fw_betrieb_zahlen()) as $k) {
        if (isset($neu[$k])) { $neu[$k] = (int) $neu[$k]; }
    }
    $neu['zeilen'] = (int) $neu['zeilen'];
    foreach (array('mqtt_ein', 'global_aus', 'melden_aktiv', 'signal_ein', 'ansage_gestoert', 'ansage_wieder', 'befehle_mqtt_ein', 'endpunkt_ein') as $k) {
        $neu[$k] = !empty($neu[$k]) ? 1 : 0;
    }
    $neu['broker_port'] = (string) $neu['broker_port'];
    return $neu;
}

/** 1.0.27: Die Sicherung als Feld (Kopf mit _plugin, _fassung, _stand; Einstellungen; Teil "zugang"). */
function fw_sicherung_daten()
{
    return sicherung_bauen(fw_sicherung_beschreibung(), fw_config(), array('zugang' => fw_sicherung_zugang_jetzt()));
}

/**
 * X-3 (Welle 4), seit 1.0.27 ueber das Modul: Welche gespeicherten Werte wuerde das eigene Zurueckspielen abweisen?
 * Gefragt wird DIESELBE Pruefung mit der Datei, die "Einstellungen sichern" liefern wuerde. Rueckgabe: die Namen
 * (leer: die Sicherung liesse sich zurueckspielen), nie Werte.
 */
function fw_sicherung_altwerte()
{
    $b = fw_sicherung_beschreibung();
    $cfg = fw_config();
    return sicherung_selbstpruefung($b, sicherung_bauen($b, $cfg, array('zugang' => fw_sicherung_zugang_jetzt())), $cfg);
}

/* ==================================================================
 * Nr. 36 b (Stufe 2, seit 1.0.11): Ansage ueber die gemeinsame Sprachausgabe
 * ==================================================================
 *
 * Ab Werk aus (Ausgabeart 'aus'). Angesagt wird, wenn der Befund eines Sticks WECHSELT - auf gestoert
 * (ansage_gestoert) oder zurueck auf in Ordnung (ansage_wieder): dieselbe Stelle, an der der Waechter die
 * LoxBerry-Meldung und SignalBot ausloest; beide laufen unabhaengig davon weiter. Nie ein Wert im Takt.
 * Hoechstens eine Ansage je Anlass und Stick in 30 min (Wiederholsperre); eine gesperrte Ansage wird NICHT
 * nachgeholt. Der Waechter ist in Python geschrieben und ruft bin/fw_ansage.php (ENTWURF, Abschnitt 4);
 * entschieden, gesprochen und protokolliert wird hier. Ins Protokoll kommt nur das Ergebnis, nie der Text
 * (Nr. 18).
 */
if (!defined('FW_ANSAGE_SPERRE_S')) { define('FW_ANSAGE_SPERRE_S', 1800); }

/** Erlaubte Ausgabearten: alle des Moduls ausser 'audioserver' (kein Antwortweg zu Loxone im Waechter). Weitere
 *  Ausgabeziele (Tabelle, 'ziele') seit 1.0.17 mit Stamm 1.2.1 - fw_mit_zielen() unten; ohne Tabelle wie bisher. */
function fw_ansage_modi()
{
    /* Gruppe 44 (1.0.17, Sprachmodul 1.2.1, E7): aus ansage_modi() abgeleitet statt abgeschrieben - damit kommen
     * sonos4lox und (ab Modul 1.1.4) sonox dazu. audioserver bleibt draussen (kein Antwortweg zu Loxone im
     * Waechter). Bis 1.0.16 stand hier eine feste Liste ohne Sonos. */
    $m = function_exists('ansage_modi') ? ansage_modi()
        : array('aus', 'musicserver', 'ms4h', 'custom', 'alexang', 'cc4lox');
    $aus = array();
    foreach ($m as $x) {
        if ($x === 'audioserver') {
            continue;
        }
        if ($x === 'sonox' && (!defined('ANSAGE_FASSUNG') || version_compare(ANSAGE_FASSUNG, '1.1.4', '<'))) {
            continue;
        }
        $aus[] = $x;
    }
    return $aus;
}

/** Welle Mehrfach-Ziele (Sprachmodul 1.2.1): bietet diese Linie die Tabelle der weiteren Ausgabeziele an? Nur, wenn die
 *  geladene Abschrift sie kann - laden zwei Abschriften im selben Prozess, gilt die zuerst geladene. */
function fw_mit_zielen() {
    return version_compare(ANSAGE_FASSUNG, '1.2.0', '>=') && function_exists('ansage_ziele_html')
        && function_exists('ansage_ziele_pruefen') && function_exists('ansage_testknoepfe_html');
}

/** Welle Mehrfach-Ziele (1.0.17), X-2: aus den gesammelten Eingaben (fw_eingaben_sammeln(), Schluessel name[N]; Texte
 *  in 'werte', Haken in 'haken') wieder das POST der Tabelle (ziele_post); null, wenn die Tabelle nicht darin steht
 *  (die gespeicherten Ziele bleiben sichtbar). Ein Haken ohne Hakenzeichen fehlt wie im POST. */
function fw_ziele_x2_post(array $e)
{
    $p = array();
    foreach (array('werte', 'haken') as $art) {
        if (!isset($e[$art]) || !is_array($e[$art])) { continue; }
        foreach ($e[$art] as $k => $v) {
            if (!preg_match('/^(tts_ziel_[a-z]+)\[([2-9])\]\z/', (string) $k, $m)) { continue; }
            if ($art === 'haken') {
                if ($v) { $p[$m[1]][(int) $m[2]] = '1'; }
            } elseif (is_string($v)) {
                $p[$m[1]][(int) $m[2]] = $v;
            }
        }
    }
    return isset($p['tts_ziel_art']) ? $p : null;
}

/** 1.0.32 (Entscheidung des Hausherrn 24.11.2026, F1 "Abloesen"): der Block 'meldung' des gemeinsamen Moduls aus dem
 *  Schalter signal_ein ("Zusaetzlich an SignalBot schicken", 0/1) - gemeldet werden wie bisher Stoerung und Rueckkehr. */
function fw_meld_aus_cfg($cfg)
{
    if (!is_array($cfg)) { return null; }
    $ein = !empty($cfg['signal_ein']) ? 1 : 0;
    return array('ein' => $ein, 'haken' => array('gestoert' => 1, 'wieder' => 1),
                 'sperre_min' => defined('MELD_SPERRE_VORGABE') ? MELD_SPERRE_VORGABE : 60);
}

/** 1.0.32 ("Ja, Grund anhaengen"): der Satz der Meldung an SignalBot - der Ansagesatz, bei einer Stoerung mit dem
 *  Grund in Klartext ([GRUND], aus dem Waechter; ein unbekannter oder fehlender Grund bleibt weg). */
function fw_meld_satz($satz, array $d)
{
    if (!isset($d['anlass'], $d['grund']) || $d['anlass'] !== 'gestoert' || !is_string($d['grund'])
        || !preg_match('/^[a-z_]{1,30}\z/', $d['grund'])) {
        return $satz;
    }
    $k = 'GRUND.' . strtoupper($d['grund']);
    $g = trim(html_entity_decode(strip_tags(fw_t($k)), ENT_QUOTES, 'UTF-8'));
    return ($g === '' || $g === $k) ? $satz : $satz . ' (' . $g . ')';
}

/** Die Anlaesse: Kennung => Konfigurationsschluessel. */
function fw_ansage_anlaesse()
{
    return array('gestoert' => 'ansage_gestoert', 'wieder' => 'ansage_wieder');
}

/** Alle Schluessel der Ansage in der Konfiguration (Sicherungen von vor 1.0.11 tragen sie nicht). */
function fw_ansage_schluessel()
{
    return array_merge(array('tts'), array_values(fw_ansage_anlaesse()));
}

/* ---------------- Sprachansagen: Regeln aus dem Stamm (1.0.19, Welle S2, Stamm sprachausgabe.php 1.3.2) ----------------
 *
 * Nachtruhe, Sperre und Schalter "Ansagen aus" stehen im Stamm. Ab Werk EIN: ansage_werk_block() in den Vorgaben, also nur fuer einen
 * nie gespeicherten Block; ein gespeicherter bleibt, auch 'aus'. Ein Minutenlauf und ein Dienst sprechen nie selbst: Sie legen den Anlass
 * in <Daten>/ansage_warte.json, ein abgekoppelter Sender (php -r, eigene Sperre) spricht und wartet auf die Lautsprecher.
 */

/** So alt darf ein wartender Anlass werden (nach einem Update oder Hang wird nichts Aufgestautes angesagt). */
if (!defined('FW_ANSAGE_HOECHSTALTER')) { define('FW_ANSAGE_HOECHSTALTER', 600); }

/** Die Regeln: gespeichert und gueltig, sonst aus einer alten Einstellung dieser Linie, sonst die Vorgabe (mit Nachtruhe). */
function fw_ansage_regeln($cfg = null)
{
    if ($cfg === null) { $cfg = fw_config(false); }
    $g = '';
    if (isset($cfg['ansage_regeln'])) {
        $r = ansage_regeln_wert($cfg['ansage_regeln'], $g);
        return $r === null ? ansage_regeln_vorgaben() : $r;
    }
    $r = ansage_regeln_vorgaben();
    $p = ansage_regeln_wert($r, $g);
    return $p === null ? ansage_regeln_vorgaben() : $p;
}

/** Die Warteliste unter einer Dateisperre aendern: $fn(Liste) -> array(neue Liste, Ergebnis). */
function fw_ansage_warte($fn)
{
    $d = fw_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $f = $d . '/ansage_warte.json';
    $h = @fopen($f, 'c+');
    if ($h === false) { return null; }
    if (!flock($h, LOCK_EX)) { fclose($h); return null; }
    $alt = json_decode((string) stream_get_contents($h), true);
    list($neu, $erg) = call_user_func($fn, is_array($alt) ? $alt : array());
    rewind($h);
    ftruncate($h, 0);
    fwrite($h, (string) json_encode($neu));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    @chmod($f, 0600);
    return $erg;
}

/**
 * Anlaesse hinlegen und den Sender starten; wartet auf nichts. $l: Eintraege fuer ansage_regel_sprechen() (schluessel, text, dringend,
 * sperre_min, nur_nach). Die Schale schliesst vor dem exec jede Deskriptor-Nummer ueber 2, sonst erbte der Sender die Sperren des Rufers.
 */
function fw_ansage_legen(array $l, $jetzt = null)
{
    if (!$l) { return 'nichts'; }
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    fw_ansage_warte(function ($alt) use ($l, $jetzt) {
        foreach ($l as $e) { $alt[] = $e + array('zeit' => $jetzt); }
        return array(array_slice($alt, -50), null);
    });
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY) && stripos(basename(PHP_BINARY), 'php') === 0 && stripos(basename(PHP_BINARY), 'fpm') === false) ? PHP_BINARY : 'php';
    $befehl = 'for f in /proc/$$/fd/*; do n=${f##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done; '
            . 'exec ' . escapeshellarg($php) . ' -r ' . escapeshellarg('chdir(dirname($argv[1])); $_SERVER["SCRIPT_FILENAME"] = $_SERVER["PHP_SELF"] = $argv[1]; require $argv[1]; fw_ansage_senden();') . ' -- ' . escapeshellarg(__FILE__);
    $aus = array();
    $rc = 1;
    @exec('bash -c ' . escapeshellarg($befehl) . ' </dev/null >/dev/null 2>&1 &', $aus, $rc);
    return $rc === 0 ? 'gestartet' : 'kein_start';
}

/** Das Sprechtoken aus Alexa-NG und Chromecast4lox uebernehmen (Stamm 1.3.1). Schreibt NUR bei einer Aenderung, nennt nie das Token. */
function fw_ansage_token_pflegen($cfg = null)
{
    if ($cfg === null) { $cfg = fw_config(false); }
    list($neu, $her2, $g) = ansage_token_abgleich(fw_tts($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), fw_ansage_k());
    if (!$g) { return false; }
    $cfg = fw_config(false);
    $cfg['tts'] = $neu;
    $cfg['ansage_token_herkunft'] = $her2;
    if (!fw_config_speichern($cfg)) { return false; }
    fw_log('Ansage: Sprechtoken uebernommen (' . implode(', ', $g) . ').');
    return true;
}

/** Der Block fuer Testansage und Pruefzeile: uebernommene Token aufgeloest. Nur im Speicher. */
function fw_ansage_vor_ort($cfg = null)
{
    if ($cfg === null) { $cfg = fw_config(false); }
    return ansage_tafel_einreihen(ansage_token_fuer_lauf(fw_tts($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), fw_ansage_k()), fw_ansage_regeln($cfg), fw_ansage_k(), true);     // Stamm 1.4.0: die Tafel, wenn sie bereitsteht
}

/** Der Block fuer die Pruefzeile: nur die eingerichteten Ziele; ist keines eingerichtet, sagt die Zeile "aus" statt eines Kreuzes. */
function fw_ansage_pruefblock($cfg = null)
{
    if ($cfg === null) { $cfg = fw_config(false); }
    $t = fw_tts($cfg);
    if ($t['mode'] === 'aus') { return $t; }
    list($b) = ansage_ziele_bereit(fw_ansage_vor_ort($cfg), fw_ansage_k());
    if ($b === null) { $t['mode'] = 'aus'; return $t; }
    return $b;
}

/** Die Lage im Reiter Test: Ziele, Nachtruhe, Schalter. Nur im geoeffneten Reiter (der Schalter fragt den Broker). */
function fw_ansage_lage_html($cfg = null)
{
    if ($cfg === null) { $cfg = fw_config(false); }
    $k = fw_ansage_k();
    $o = array();
    foreach (ansage_regel_lage(fw_tts($cfg), fw_ansage_regeln($cfg), $k, true) as $z) {
        $o[] = '<div class="sm-hilfe">' . htmlspecialchars((string) $z[1], ENT_QUOTES, 'UTF-8') . '</div>';
    }
    return implode("\n", $o) . "\n";
}

/** Die Felder der Regeln fuer die Einmalmeldung (X-2). */
function fw_ansage_regel_felder(array $anlaesse = array())
{
    return ansage_regeln_x2_felder($anlaesse);
}

/**
 * Im Vorgang sprechen, wo der Rufer das Ergebnis braucht (Antwort an Loxone, Befehl): Regeln aus dem Stamm mit "dringend, ohne Sperre" - es zaehlt nur der Schalter.
 * Schalter gesetzt: stand -1, Kennung SCHALTER. Nichts bereit oder Original-Audioserver: wie bisher ansage_sprechen(), damit die Antwort sagt, was fehlt.
 */
function fw_ansage_direkt($text, array $e = array(), $tts = null)
{
    $conf = fw_config(false);
    $k = fw_ansage_k();
    $t = $tts === null ? fw_tts($conf) : $tts;
    $a = ansage_regel_sprechen(array($e + array('schluessel' => 'direkt', 'text' => (string) $text, 'dringend' => true, 'sperre_min' => 0)), $t, fw_ansage_regeln($conf), $k);
    if ($a['r'] !== null) { return $a['r']; }
    if ($a['regel'] === 'SCHALTER') {
        return array('stand' => -1, 'kennung' => 'SCHALTER', 'art' => isset($t['mode']) ? (string) $t['mode'] : '-', 'http' => 0, 'zeile' => '', 'zeichen' => 0);
    }
    return ansage_sprechen((string) $text, $t, $k);
}

/**
 * Der Sender: eigene Sperre, nimmt die Warteliste und spricht nach den Regeln. "aus" und "kein Ziel eingerichtet" hinterlassen keine Zeile;
 * ein gescheitertes Ziel genau eine je Aenderung (ab Werk EIN darf kein Protokoll vollaufen). Text und Token stehen nie im Protokoll.
 */
function fw_ansage_senden($jetzt = null)
{
    $d = fw_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $fh = @fopen($d . '/ansage_senden.lock', 'c');
    if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) { return 'laeuft'; }
    $erg = 'nichts';
    $l = fw_ansage_warte(function ($alt) { return array(array(), $alt); });
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $frisch = array();
    foreach (is_array($l) ? $l : array() as $e) {
        if (is_array($e) && isset($e['zeit']) && $jetzt - (int) $e['zeit'] <= FW_ANSAGE_HOECHSTALTER) { $frisch[] = $e; }
    }
    if ($frisch) {     // 1.0.32: die Eintraege der Meldung an SignalBot, dann die der Ansage
        fw_meld_senden(fw_meld_weg($frisch, 'm'), fw_config(false));
        $frisch = fw_meld_weg($frisch, 'a');
    }
    if ($frisch) {
        $conf = fw_config(false);
        fw_ansage_token_pflegen($conf);
        $conf = fw_config(false);
        $a = ansage_regel_sprechen($frisch, fw_tts($conf), fw_ansage_regeln($conf), fw_ansage_k());
        $erg = $a['regel'] !== '' ? $a['regel'] : 'gesprochen';
        if ($a['r'] !== null) {
            $merk = $d . '/ansage_lage.txt';
            $lage = $a['r']['stand'] === 1 ? 'gesprochen' : ansage_kurz($a['r']);
            if ($a['r']['stand'] === 1 || (string) @file_get_contents($merk) !== $lage) { fw_log('Ansage: ' . ansage_regel_kurz($a)); }
            @file_put_contents($merk, $lage);
        } elseif ($a['regel'] !== 'AUS' && $a['regel'] !== 'KEIN_ZIEL') {
            fw_log('Ansage entfaellt: ' . ansage_regel_kurz($a));
        }
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $erg;
}

/** 1.0.32: die Eintraege eines Weges ('a' Ansage, 'm' Meldung an SignalBot); ohne 'weg' (vor dem Update gelegt) fuer beide. */
function fw_meld_weg(array $l, $weg)
{
    $aus = array();
    foreach ($l as $e) {
        if (!is_array($e)) { continue; }
        $w = isset($e['weg']) ? (string) $e['weg'] : '';
        if ($w === '' || $w === $weg) { unset($e['weg']); $aus[] = $e; }
    }
    return $aus;
}

/** Der Block tts, vervollstaendigt (ein nie gespeicherter kommt aus den Vorgaben: ab Werk EIN). */
function fw_tts($cfg = null)
{
    $cfg = is_array($cfg) ? $cfg : fw_config(false);
    list($t) = ansage_vervollstaendigen(isset($cfg['tts']) && is_array($cfg['tts']) ? $cfg['tts'] : array(), 'aus');
    return $t;
}

/** Ist eine Ausgabeart gewaehlt? */
function fw_ansage_an($cfg = null)
{
    $t = fw_tts($cfg);
    return is_string($t['mode']) && $t['mode'] !== 'aus' && in_array($t['mode'], fw_ansage_modi(), true);
}

/** Der Kontext des Moduls: Webport, Kopfzeile, Datenordner, Texte. */
function fw_ansage_k()
{
    $p = fw_paths();
    return array(
        'werk' => ansage_werk_modus(),
        'port'   => ansage_webport($p['home'] !== '' ? $p['home'] . '/config/system/general.json' : ''),
        'kopf'   => array('User-Agent: LoxBerry Funkwacht'),
        'ordner' => ($p['datadir'] !== '' && @is_dir($p['datadir'])) ? $p['datadir'] : '',
        't'      => function ($s) { return fw_t($s); },
        /* Gruppe 44 (1.0.17, Modul 1.2.1): hoechstens 20 s je Ansage, in JEDEM Weg (auch mit einem Ziel). Der
         * Waechter wartet auf bin/fw_ansage.php 30 s (funkwacht_dienst.py, ansagen()); der Knopf im Reiter Test
         * ist eine Webanfrage - 20 s passen in beide. */
        'frist'  => 20,
        /* K_TTS_EINTRAG: den Satz bringt das Modul seit 1.1.2 selbst mit; die Umlenkung auf
         * DURCHSAGE.SICH_EINTRAG ist seit 1.0.13 gestrichen (X-10). Ab Werk aus - kein 'werk'. */
    );
}

/**
 * Eine Ansage auf Zuruf des Waechters (bin/fw_ansage.php). $eingabe: JSON {"anlass": "gestoert"|"wieder",
 * "nr": Zeilennummer, "name": Name des Sticks}. Rueckgabe array(Rueckgabewert, Zeile fuer stdout):
 * 0 gesendet, 1 gescheitert, 3 nichts gesendet ohne Fehler (aus, abgewaehlt, gesperrt), 2 Aufruf falsch.
 * Die Zeile ist ASCII und traegt weder Text noch Token.
 */
function fw_ansage_ausfuehren($eingabe, $jetzt = null)
{
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $d = is_string($eingabe) ? json_decode($eingabe, true) : null;
    $anl = fw_ansage_anlaesse();
    if (!is_array($d) || !isset($d['anlass']) || !is_string($d['anlass']) || !isset($anl[$d['anlass']])
        || !isset($d['nr']) || !is_int($d['nr']) || $d['nr'] < 1 || $d['nr'] > 99
        || !isset($d['name']) || !is_string($d['name']) || trim($d['name']) === ''
        || (isset($d['grund']) && !is_string($d['grund']))) {     // 1.0.32: der Grund (wahlfrei) fuer die Meldung an SignalBot
        return array(2, 'ANSAGE;STAND=0;KENNUNG=AUFRUF');
    }
    $cfg = fw_config(false);
    /* 1.0.32 (Signal-Welle): Ansage (Ausgabeart und ihr Haken, wie bisher) und Meldung an SignalBot (signal_ein, mit dem
     * Grund) je mit eigenem Eintrag ('weg' a|m); der Sender trennt sie (fw_meld_weg()). Ohne beides wie bisher: nichts. */
    $will_a = fw_ansage_an($cfg) && !empty($cfg[$anl[$d['anlass']]]);
    $will_m = meld_anlass_an(fw_meld_block($cfg), $d['anlass']);
    if (!fw_ansage_an($cfg) && !fw_meld_aktiv($cfg)) {
        return array(3, 'ANSAGE;STAND=-2;KENNUNG=AUS');
    }
    if (!$will_a && !$will_m) {
        return array(3, 'ANSAGE;STAND=-1;KENNUNG=ABGEWAEHLT');
    }
    $p = fw_paths();
    if ($p['datadir'] === '' || !@is_dir($p['datadir'])) {
        return array(1, 'ANSAGE;STAND=0;KENNUNG=DATENORDNER');
    }
    /* 1.0.19: Sperre, Nachtruhe und Schalter stehen im Stamm. Gestoert spricht auch nachts; "wieder da" nur nach einem gesprochenen "gestoert". */
    $satz = trim(html_entity_decode(strip_tags(sprintf(fw_t('DURCHSAGE.TEXT_' . strtoupper($d['anlass'])),
                                                       trim($d['name']))), ENT_QUOTES, 'UTF-8'));
    $e = array('schluessel' => $d['anlass'] . '|' . $d['nr'], 'text' => $satz, 'dringend' => $d['anlass'] === 'gestoert');
    if ($d['anlass'] === 'wieder') { $e['nur_nach'] = 'gestoert|' . $d['nr']; }
    $l = array();
    if ($will_a) { $l[] = $e + array('weg' => 'a'); }
    if ($will_m) { $l[] = array('text' => fw_meld_satz($satz, $d), 'weg' => 'm') + $e; }     // 1.0.32: Satz mit Grund
    fw_ansage_token_pflegen($cfg);
    fw_ansage_legen($l, $jetzt);
    return array(0, 'ANSAGE;STAND=1;KENNUNG=GELEGT');
}

/** Die Zeile der Selbstpruefung: true Haken, false Kreuz, null Strich (aus). Klartext (die Ausgabe maskiert). */
function fw_pruefe_ansage($cfg = null)
{
    $k = fw_ansage_k();
    $k['e'] = function ($s) { return (string) $s; };
    list($st, $text) = ansage_pruefzeile(fw_ansage_pruefblock(), true, $k);
    return array($st === 1 ? true : ($st === -2 ? null : false), $text);
}

/* ==================================================================
 * Sprache - Englisch ist die Rueckfallebene, nicht Deutsch
 * ================================================================== */

function fw_sprache()
{
    $s = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $s = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $s = getenv('LBLANG');
    }
    $s = strtolower(substr((string) $s, 0, 2));
    return in_array($s, array('de', 'en'), true) ? $s : 'en';
}

/**
 * Der Ordner mit den Sprachdateien.
 * Gesucht wird der Ordner, der wirklich eine language_de.ini enthaelt - nicht
 * ein anderer, aus dem man auf ihn schliessen koennte.
 */
function fw_langdir()
{
    static $gefunden = null;
    if ($gefunden !== null) { return $gefunden; }
    $p = fw_paths();
    $k = array();
    /* Kein fester Ordnername und kein Weg ueber das Archiv hinaus: bis 1.0.6
     * standen hier templates/plugins/funkwacht/lang (Sprachdateien eines
     * gleichnamigen FREMDEN Plugins, wenn diese Installation funkwacht01
     * heisst) und <ueber dem Archiv>/templates/lang (Faelle B7, B8). */
    if ($p['home'] !== '') {
        $k[] = $p['home'] . '/templates/plugins/' . $p['plugin'] . '/lang';
    }
    if (fw_archivlage()) { $k[] = dirname(dirname(__DIR__)) . '/templates/lang'; }
    foreach ($k as $d) {
        if (is_file($d . '/language_de.ini') || is_file($d . '/language_en.ini')) {
            $gefunden = $d;
            return $gefunden;
        }
    }
    $gefunden = '';
    return $gefunden;
}

function fw_sprache_fehlt() { return fw_langdir() === ''; }

function fw_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $pfad = fw_langdir();
        $texte = $pfad !== ''
            ? @parse_ini_file($pfad . '/language_' . fw_sprache() . '.ini', true, INI_SCANNER_RAW)
            : array();
        if (!is_array($texte)) { $texte = array(); }
        $rueck = $pfad !== ''
            ? @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW) : array();
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) { $texte[$ab][$s] = trim((string) $w, '"'); }
        }
    }
    $teile = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$teile[0]][$teile[1]]) ? $texte[$teile[0]][$teile[1]] : $schluessel;
}

/* ==================================================================
 * Gruppe 44 (1.0.17): Hausform - Gateway V2, Befehlsbruecke 1.0.2,
 * endpunkt_ein, Zigbee-Vorlage erkennt den Dienst (E8), Healthcheck.
 * Bauvorschrift Pruefung-Durchgang-2026-09-29/wz_bild/GRUPPE44_BAUVORSCHRIFT.md,
 * Vorlage Weissware Cloud 0.9.44 (ww_v2_*, ww_befehl_annehmen(),
 * ww_bruecke_*, ww_gesund_*) und BYD Autos 0.9.29.
 * ================================================================== */

/**
 * Ueberwacht diese Anlage etwas? Mindestens eine Zeile mit Namen, die eingeschaltet ist. Ohne sie misst der
 * Waechter nichts - "noch nicht eingerichtet" (Gateway V2, Healthcheck).
 */
function fw_eingerichtet($cfg = null)
{
    if (!is_array($cfg)) {
        $cfg = fw_config(false);
    }
    foreach ((isset($cfg['geraete']) && is_array($cfg['geraete']) ? $cfg['geraete'] : array()) as $g) {
        if (is_array($g) && trim((string) (isset($g['name']) ? $g['name'] : '')) !== '' && !empty($g['aktiv'])) {
            return true;
        }
    }
    return false;
}

/* ---------------- E8: Zigbee2MQTT oder Zigbee2MqttNG? ---------------- */

/**
 * Welchen Zigbee-Dienst gibt es hier? Rueckgabe array('dienst' => 'zigbee2mqttng'|'zigbee2mqtt', 'ordner' =>
 * '/opt/zigbee2mqttng'|'/opt/zigbee2mqtt', 'db' => true|false|null, 'systemd' => 'loaded'|'not-found'|...|'').
 * Zigbee2MqttNG gilt als installiert, wenn die Plugin-Datenbank (data/system/plugindatabase.json) einen Eintrag mit
 * dem Ordner zigbee2mqttng fuehrt ODER systemd die Einheit zigbee2mqttng.service kennt (LoadState loaded; Gegenprobe).
 * Sonst der Dienst des Originals, zigbee2mqtt. Liest nur. $db und $systemd nur fuer Proben (sonst gelesen).
 */
function fw_zigbee_dienst($db = null, $systemd = null)
{
    if ($db === null) {
        $p = fw_paths();
        $f = $p['home'] !== '' ? $p['home'] . '/data/system/plugindatabase.json' : '';
        $d = ($f !== '' && is_file($f)) ? json_decode((string) @file_get_contents($f), true) : null;
        if (is_array($d)) {
            $db = false;
            $liste = (isset($d['plugins']) && is_array($d['plugins'])) ? $d['plugins'] : $d;
            foreach ($liste as $e) {
                if (is_array($e) && isset($e['folder']) && (string) $e['folder'] === 'zigbee2mqttng') {
                    $db = true;
                    break;
                }
            }
        }
    }
    if ($systemd === null) {
        $systemd = '';
        if (function_exists('exec') && (is_file('/bin/systemctl') || is_file('/usr/bin/systemctl'))) {
            $aus = array();
            $rc = 1;
            @exec('systemctl show -p LoadState --value zigbee2mqttng.service 2>/dev/null', $aus, $rc);
            $systemd = ((int) $rc === 0 && isset($aus[0]) && preg_match('/^[a-z-]{1,20}$/', trim($aus[0])))
                ? trim($aus[0]) : '';
        }
    }
    $ng = ($db === true) || ($systemd === 'loaded');
    return array('dienst' => $ng ? 'zigbee2mqttng' : 'zigbee2mqtt',
                 'ordner' => $ng ? '/opt/zigbee2mqttng' : '/opt/zigbee2mqtt',
                 'db' => $db, 'systemd' => (string) $systemd);
}

/**
 * B10 (30.10.2026): Pfad der Protokolldatei fuer die Vorlage "Zigbee2MQTT oder Zigbee2MqttNG als Paket" - aus der
 * configuration.yaml des Dienstes (<ordner>/data/configuration.yaml, advanced.log_directory und advanced.log_file).
 * Am Geraet gemessen (30.10.2026): Zigbee2MqttNG traegt log_directory: log und log_file: zigbee2mqtt.log, der Dienst
 * laeuft mit WorkingDirectory <ordner>, und <ordner>/log verweist auf den Logordner des Plugins. Den bisherigen
 * Vorschlag <ordner>/data/log/log.txt gab es dort nicht ("nie gesehen", es haette nie geheilt).
 * Regeln: ein relativer log_directory gilt gegen <ordner>; enthaelt er %TIMESTAMP%, gilt das JUENGSTE passende
 * Verzeichnis (so legt Zigbee2MQTT je Start eines an; Vorgabe data/log/%TIMESTAMP%), ebenso fuer log_file (Vorgabe
 * log.log). Ohne lesbare yaml bleibt der bisherige Vorschlag. Der Waechter misst einen festen Pfad - ein Verzeichnis
 * mit Zeitstempel wechselt bei jedem Neustart, dann die Vorlage neu setzen (Hinweis G44.Z_HINWEIS).
 * Liest nur. $yaml nur fuer Proben (Text der Datei; sonst gelesen).
 */
function fw_zigbee_logpfad($ordner, $yaml = null)
{
    $ordner = rtrim((string) $ordner, '/');
    $alt = $ordner . '/data/log/log.txt';
    if ($yaml === null) {
        $f = $ordner . '/data/configuration.yaml';
        $yaml = (is_file($f) && is_readable($f)) ? (string) @file_get_contents($f) : '';
    }
    if ((string) $yaml === '') {
        return $alt;
    }
    $werte = array('log_directory' => '', 'log_file' => '');
    $im_advanced = false;
    foreach (preg_split('/\r?\n/', (string) $yaml) as $z) {
        if (preg_match('/^\s*(#|$)/', $z)) {
            continue;
        }
        if (preg_match('/^\S/', $z)) {
            $im_advanced = (bool) preg_match('/^advanced:\s*(#.*)?$/', $z);
            continue;
        }
        if ($im_advanced && preg_match('/^\s+(log_directory|log_file):\s*(.*)$/', $z, $m)) {
            $v = trim((string) preg_replace('/\s+#.*$/', '', $m[2]));
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
                $v = substr($v, 1, -1);
            }
            $werte[$m[1]] = $v;
        }
    }
    $verz = $werte['log_directory'] !== '' ? $werte['log_directory'] : 'data/log/%TIMESTAMP%';
    $datei = $werte['log_file'] !== '' ? $werte['log_file'] : 'log.log';
    if ($verz[0] !== '/') {
        $verz = $ordner . '/' . $verz;
    }
    $verz = rtrim($verz, '/');
    if (strpos($verz, '%TIMESTAMP%') !== false) {
        $j = fw_zigbee_juengstes($verz, true);
        if ($j === '') {
            return $verz . '/' . $datei;
        }
        $verz = $j;
    }
    $pfad = $verz . '/' . $datei;
    if (strpos($datei, '%TIMESTAMP%') !== false) {
        $j = fw_zigbee_juengstes($pfad, false);
        if ($j !== '') {
            $pfad = $j;
        }
    }
    return $pfad;
}

/** Juengstes Verzeichnis ($verz) bzw. juengste Datei zum Muster mit %TIMESTAMP% - '' wenn keins. Liest nur. */
function fw_zigbee_juengstes($muster, $verz)
{
    $treffer = @glob(str_replace('%TIMESTAMP%', '*', $muster));
    $best = '';
    $best_t = -1;
    foreach (is_array($treffer) ? $treffer : array() as $t) {
        if ($verz ? !is_dir($t) : !is_file($t)) {
            continue;
        }
        $m = (int) @filemtime($t);
        if ($m > $best_t || ($m === $best_t && strcmp($t, $best) > 0)) {
            $best = $t;
            $best_t = $m;
        }
    }
    return $best;
}

/** Der Satz "erkannt: ..." unter der Vorlagentabelle (rein). */
function fw_zigbee_text(array $z)
{
    $db = $z['db'] === true ? fw_t('ALLG.JA') : ($z['db'] === false ? fw_t('ALLG.NEIN') : fw_t('G44.Z_UNLESBAR'));
    $sd = $z['systemd'] !== '' ? $z['systemd'] : fw_t('G44.Z_UNLESBAR');
    return sprintf(fw_t('G44.Z_ERKANNT'), $z['dienst'], $db, $sd);
}

/* ---------------- G1: MQTT-Gateway V2 ---------------- */

/** Der Name, den das MQTT-Gateway vergibt: '/', '%' und Leerzeichen werden '_' (Regeln/07 :69). Rein. */
function fw_gateway_name($thema)
{
    return str_replace(array('/', '%', ' '), '_', (string) $thema);
}

/**
 * Die Retain-Tabelle des Waechters (RETAIN in bin/funkwacht_dienst.py) - die Tabelle, NACH DER er sendet - als
 * Stamm => bool. Gelesen aus der Datei (kein Python-Aufruf: der Healthcheck soll schnell und ohne Kindprozess
 * bleiben). null = nicht lesbar.
 */
function fw_py_retain()
{
    $p = fw_paths();
    $kand = array();
    if ($p['bindir'] !== '') {
        $kand[] = $p['bindir'] . '/funkwacht_dienst.py';
    }
    if (fw_archivlage()) {
        $kand[] = dirname(dirname(__DIR__)) . '/bin/funkwacht_dienst.py';
    }
    foreach ($kand as $datei) {
        if (!is_file($datei)) {
            continue;
        }
        $q = (string) @file_get_contents($datei);
        $a = strpos($q, "\nRETAIN = {");
        $e = $a === false ? false : strpos($q, "\n}", $a);
        if ($e === false) {
            return null;
        }
        $aus = array();
        if (preg_match_all('/"([^"]+)"\s*:\s*([01])\b/', substr($q, $a, $e - $a), $m, PREG_SET_ORDER)) {
            foreach ($m as $t) {
                $aus[$t[1]] = ($t[2] === '1');
            }
        }
        return $aus ? $aus : null;
    }
    return null;
}

/** Die Sticknummern fuer die Hakenliste: die belegten Zeilen; ohne Zeile Stick 1 (Beispiel). */
function fw_v2_sticknummern($cfg = null)
{
    if (!is_array($cfg)) {
        $cfg = fw_config(false);
    }
    $n = array();
    $i = 0;
    foreach ((isset($cfg['geraete']) && is_array($cfg['geraete']) ? $cfg['geraete'] : array()) as $g) {
        $i++;
        if (is_array($g) && trim((string) (isset($g['name']) ? $g['name'] : '')) !== '') {
            $n[] = $i;
        }
    }
    return $n ? $n : array(1);
}

/** Textthemen und Zeitstempel: nicht in der Hakenliste (das Musterprojekt hat keinen Texteingang; ts wechselt je Lauf). */
function fw_v2_ohne()
{
    return array('ts', 'geraetN/name', 'geraetN/grund', 'geraetN/warum', 'geraetN/letzte_tat', 'geraetN/bemerkung');
}

/**
 * Namenstabelle und V2-Hakenliste - EINE Quelle fuer Reiter MQTT, Reiter Test, Healthcheck und Messskript (G1).
 * Die Themen kommen aus RETAIN in bin/funkwacht_dienst.py (fw_py_retain()) - keine zweite Liste. Noncached genau:
 * ok, zaehler, geraetN/ok und status/befehle (Lebenszeichen der Bruecke). Rueckgabe Liste von array(thema, name,
 * noncached, bez); leer -> "nicht feststellbar". $themen und $nummern nur fuer Proben.
 */
function fw_v2_haken($cfg = null, $themen = null, $nummern = null)
{
    if (!is_array($cfg)) {
        $cfg = fw_config(false);
    }
    $pf = trim((string) (isset($cfg['mqtt_topic']) ? $cfg['mqtt_topic'] : ''), '/ ');
    if ($pf === '') {
        $pf = 'funkwacht';
    }
    if ($themen === null) {
        $py = fw_py_retain();
        $themen = is_array($py) ? array_keys($py) : array();
    }
    if (!$themen) {
        return array();
    }
    $bez = fw_mqtt_themen();
    $ohne = fw_v2_ohne();
    $aus = array();
    $je = array();
    foreach ($themen as $th) {
        $th = (string) $th;
        if (in_array($th, $ohne, true)) {
            continue;
        }
        if (strpos($th, 'geraetN/') === 0) {
            $je[] = $th;
            continue;
        }
        $t = $pf . '/' . $th;
        $aus[] = array('thema' => $t, 'name' => fw_gateway_name($t),
                       'noncached' => in_array($th, array('ok', 'zaehler'), true),
                       'bez' => isset($bez[$th]) ? (string) $bez[$th] : '');
    }
    if (!$je) {
        return array();
    }
    foreach (($nummern === null ? fw_v2_sticknummern($cfg) : $nummern) as $nr) {
        foreach ($je as $th) {
            $t = $pf . '/geraet' . (int) $nr . '/' . substr($th, strlen('geraetN/'));
            $aus[] = array('thema' => $t, 'name' => fw_gateway_name($t), 'noncached' => $th === 'geraetN/ok',
                           'bez' => isset($bez[$th]) ? (string) $bez[$th] : '');
        }
    }
    $t = $pf . '/status/befehle';
    $aus[] = array('thema' => $t, 'name' => fw_gateway_name($t), 'noncached' => true, 'bez' => 'BEFEHL.STATUS_BEFEHLE');
    return $aus;
}

/** Die angehakten Datenpunkte aus subscriptions.json (rein): Id => noncached. null = unlesbar. Abschrift ww_v2_abos(). */
function fw_v2_abos($daten)
{
    if (!is_array($daten)) {
        return null;
    }
    $liste = array();
    foreach (array('Subscriptions', 'subscriptions') as $s) {
        if (isset($daten[$s]) && is_array($daten[$s])) {
            $liste = $daten[$s];
            break;
        }
    }
    $abo = array();
    foreach ($liste as $e) {
        if (!is_array($e)) {
            continue;
        }
        $id = '';
        foreach (array('Id', 'id', 'Topic', 'topic') as $k) {
            if (isset($e[$k]) && is_string($e[$k])) {
                $id = $e[$k];
                break;
            }
        }
        if ($id === '') {
            continue;
        }
        $nc = false;
        foreach (array('Noncached', 'noncached') as $k) {
            if (isset($e[$k])) {
                $nc = ($e[$k] === true || in_array(strtolower(trim((string) $e[$k])), array('1', 'true', 'yes', 'on'), true));
            }
        }
        $abo[$id] = $nc;
    }
    return $abo;
}

/** Soll gegen Ist (rein): array(1|0, fehlt[], noncached_falsch[], da, soll). Abschrift ww_v2_haken_abgleich(). */
function fw_v2_haken_abgleich(array $soll, array $abo)
{
    $fehlt = array();
    $nc = array();
    $da = 0;
    foreach ($soll as $e) {
        if (!array_key_exists($e['thema'], $abo)) {
            $fehlt[] = $e['name'];
            continue;
        }
        $da++;
        if ((bool) $abo[$e['thema']] !== (bool) $e['noncached']) {
            $nc[] = $e['name'] . ($e['noncached'] ? ' (Noncached fehlt)' : ' (Noncached zu viel)');
        }
    }
    return array(($fehlt || $nc) ? 0 : 1, $fehlt, $nc, $da, count($soll));
}

/**
 * Pruefzeile "Gateway V2: alle Datenpunkte angehakt?" (einheitliche Pruefzeile, Nachtwelle 29.10.):
 * array(1|0|2, Text) - 2 = grau (nicht feststellbar, Gateway V1, noch nicht eingerichtet). Liest nur.
 * Die Argumente nach $cfg nur fuer Proben; sonst aus funkwacht_dienst.py, general.json und subscriptions.json.
 */
function fw_v2_haken_lage($cfg = null, $soll = null, $abo = false, $fassung = null, $eingerichtet = null)
{
    if (!is_array($cfg)) {
        $cfg = fw_config(false);
    }
    if ($fassung === null) {
        $m = fw_mqtt_zustand();
        $fassung = !empty($m['gefunden']) ? (int) $m['fassung'] : 0;
    }
    if ((int) $fassung <= 0) {
        return array(2, fw_t('PRUEF.V2_UNKLAR'));
    }
    if ((int) $fassung < 2) {
        return array(2, fw_t('PRUEF.V2_V1'));
    }
    if ($soll === null) {
        $soll = fw_v2_haken($cfg);
    }
    if (!$soll) {
        return array(2, fw_t('PRUEF.V2_LEER'));
    }
    if ($eingerichtet === null) {
        $eingerichtet = fw_eingerichtet($cfg);
    }
    if (!$eingerichtet) {
        return array(2, fw_t('PRUEF.V2_NICHT_EINGERICHTET'));
    }
    if ($abo === false) {
        $p = fw_paths();
        $f = $p['home'] !== '' ? $p['home'] . '/config/system/subscriptions.json' : '';
        $abo = ($f !== '' && is_file($f)) ? fw_v2_abos(json_decode((string) @file_get_contents($f), true)) : null;
    }
    if ($abo === null) {
        return array(2, fw_t('PRUEF.V2_UNKLAR'));
    }
    list($ok, $fehlt, $nc, $da, $zahl) = fw_v2_haken_abgleich($soll, $abo);
    $txt = sprintf(fw_t('PRUEF.V2_ZAHL'), $da, $zahl);
    if ($fehlt) {
        $txt .= ' ' . sprintf(fw_t('PRUEF.V2_FEHLT'), implode(', ', array_slice($fehlt, 0, 12))
            . (count($fehlt) > 12 ? ' ...' : ''));
    }
    if ($nc) {
        $txt .= ' ' . sprintf(fw_t('PRUEF.V2_NC'), implode(', ', $nc));
    }
    return array($ok, $txt);
}

/* ---------------- G2: eine Annahmefunktion fuer HTTP und MQTT ---------------- */

/** Die schaltenden Aktionen des Endpunkts (quittieren, wartung). */
function fw_schaltende()
{
    return array('quittieren', 'wartung');
}

/**
 * quittieren bzw. wartung annehmen - DIESELBE Funktion fuer den HTTP-Endpunkt und die Befehlsbruecke (Regeln/07
 * Abschnitt 3a). Pruefungen und Antworten wie der Endpunkt bis 1.0.16 (dort inline; Probe P-G2-5 byte-gleich):
 *   quittieren: nr fehlt = 0 (alle); nr kein 0-99 -> 400 NR; Stick nicht im Stand -> 404 GERAET_UNBEKANNT;
 *               Auftrag nicht schreibbar -> 500 SCHREIBEN; sonst QUITTIERT;OK=1;NR=<n>;WIRKT_IN=<takt>.
 *   wartung:    dauer fehlt = 60; keine ganze Zahl 0-1440 -> 400 DAUER; dieselbe Dauer binnen 60 s (X-7) ->
 *               UNVERAENDERT=1 ohne Auftrag; Merker nicht fuehrbar -> 503 BREMSE_MERKER; Auftrag nicht schreibbar ->
 *               500 SCHREIBEN; sonst WARTUNG;OK=1;DAUER=<min>;WIRKT_IN=<takt>.
 * Beide Wege legen den Auftrag in dieselbe Datei (auftraege.json); der Waechter fuehrt ihn im naechsten Durchlauf
 * aus. MQTT wartet nie (Quittung "uebergeben"). $param: 'nr' bzw. 'dauer' als Rohwert, null = nicht angegeben.
 * Rueckgabe array('ok' => 0|1, 'grund', 'http', 'zeilen' => Zeilen der Endpunktantwort, 'art' =>
 * abgewiesen|gesetzt|unveraendert).
 */
function fw_befehl_annehmen($aktion, array $param, $quelle)
{
    $wo = $quelle === 'mqtt' ? 'MQTT' : 'Endpunkt';
    $raus = function ($grund, $http, $zusatz = '') {
        return array('ok' => 0, 'grund' => $grund, 'http' => $http,
                     'zeilen' => $zusatz === '' ? array('FEHLER;OK=0;GRUND=' . $grund)
                                                : array('FEHLER;OK=0;GRUND=' . $grund, $zusatz),
                     'art' => 'abgewiesen');
    };
    $roh = function ($k, $vorgabe) use ($param) {
        if (!array_key_exists($k, $param) || $param[$k] === null) {
            return $vorgabe;
        }
        return is_string($param[$k]) ? trim($param[$k]) : (is_int($param[$k]) ? (string) $param[$k] : '');
    };
    if ($aktion === 'quittieren') {
        /* Nr. 19 (Welle 4): eine Nummer, die keine ist, wird abgewiesen. Bis 1.0.9 wurde aus nr=abc still 0 - und
         * quittiert wurden ALLE Sticks. */
        $nroh = $roh('nr', '0');
        if (!preg_match('/^[0-9]{1,2}$/', $nroh)) {
            return $raus('NR', 400, 'Erlaubt ist eine Sticknummer von 1 bis 99 oder 0 fuer alle.');
        }
        $nr = (int) $nroh;
        $stand = fw_stand();
        if ($nr > 0 && !isset($stand['geraete'][(string) $nr])) {
            return $raus('GERAET_UNBEKANNT', 404);
        }
        if (!fw_auftrag('quittieren', $nr)) {
            return $raus('SCHREIBEN', 500);
        }
        fw_log('Quittieren angefordert (' . $wo . '), Stick ' . ($nr ?: 'alle') . '.');
        return array('ok' => 1, 'grund' => '', 'http' => 200, 'art' => 'gesetzt',
                     'zeilen' => array('QUITTIERT;OK=1;NR=' . $nr . ';WIRKT_IN=' . fw_auftrag_wartezeit()));
    }
    if ($aktion === 'wartung') {
        /* Nr. 19 (Welle 4): bis 1.0.9 wurde die Dauer still geklemmt, und aus dauer=abc wurde 0. Jetzt 400. */
        $droh = $roh('dauer', '60');
        if (!preg_match('/^[0-9]{1,4}$/', $droh) || (int) $droh > 1440) {
            return $raus('DAUER', 400, 'Erlaubt ist eine ganze Zahl von 0 bis 1440 (Minuten).');
        }
        $dauer = (int) $droh;
        /* X-7 (Entscheidung 19, Welle 4): dieselbe Dauer binnen 60 s geht nicht erneut an den Waechter - fuer
         * HTTP und MQTT EIN Merker (gleichwert.json). Kein 429: eine andere Dauer gilt sofort. */
        list($gw, $seit) = fw_gleichwert_pruefen('wartung', $dauer);
        if ($gw === 'UNVERAENDERT') {
            return array('ok' => 1, 'grund' => '', 'http' => 200, 'art' => 'unveraendert',
                         'zeilen' => array('WARTUNG;OK=1;DAUER=' . $dauer . ';UNVERAENDERT=1;SEIT_S=' . (int) $seit));
        }
        if ($gw === 'MERKER') {
            fw_log('Der Merker der Gleichwert-Unterdrueckung (' . fw_paths()['gleichwert']
                 . ') laesst sich nicht fuehren - wartung aus Loxone wird mit 503 abgewiesen. '
                 . 'Pruefen: Platz und Eigentuemer (loxberry) von data/plugins.');
            return $raus('BREMSE_MERKER', 503);
        }
        if (!fw_auftrag('wartung', 0, $dauer)) {
            fw_gleichwert_vergessen('wartung');
            return $raus('SCHREIBEN', 500);
        }
        fw_log('Wartung angefordert (' . $wo . '): ' . $dauer . ' Minuten.');
        return array('ok' => 1, 'grund' => '', 'http' => 200, 'art' => 'gesetzt',
                     'zeilen' => array('WARTUNG;OK=1;DAUER=' . $dauer . ';WIRKT_IN=' . fw_auftrag_wartezeit()));
    }
    return $raus('AKTION_UNBEKANNT', 400);
}

/** Quittung fuer die Bruecke: array(true, '') bei ok >= 1, sonst array(false, Grund). */
function fw_befehl_quittung($erg)
{
    if (is_array($erg) && isset($erg['ok']) && (int) $erg['ok'] >= 1) {
        return array(true, '');
    }
    return array(false, (is_array($erg) && isset($erg['grund']) && $erg['grund'] !== '') ? (string) $erg['grund'] : 'FEHLER');
}

/* ---------------- G2: Befehlsbruecke fuer die Oberflaeche (Abschrift ww_bruecke_*) ---------------- */

/** Die Bruecke als Bibliothek laden (BB_NUR_LADEN). Rueckgabe true, wenn bb_waechter() danach da ist. */
function fw_bruecke_laden()
{
    if (function_exists('bb_waechter')) {
        return true;
    }
    $p = fw_paths();
    $kand = array();
    if ($p['bindir'] !== '') {
        $kand[] = $p['bindir'] . '/befehlsbruecke.php';
    }
    if (fw_archivlage()) {
        $kand[] = dirname(dirname(__DIR__)) . '/bin/befehlsbruecke.php';
    }
    foreach ($kand as $k) {
        if (is_file($k)) {
            if (!defined('BB_NUR_LADEN')) {
                define('BB_NUR_LADEN', true);
            }
            require_once $k;
            return function_exists('bb_waechter');
        }
    }
    return false;
}

/** Die Befehlsliste der Bruecke (bb_liste()). array(Liste oder null, Fehlertext). Die Befehle stehen unter ['befehle']. */
function fw_bruecke_liste()
{
    if (!fw_bruecke_laden()) {
        return array(null, fw_t('BEFEHL.T_NICHT_DA'));
    }
    list($z, $fehler) = bb_zuordnung_laden();
    if ($z === null) {
        return array(null, $fehler);
    }
    $GLOBALS['bb_zuordnung'] = $z;
    list($fehl) = bb_zuordnung_pruefen($z);
    if ($fehl) {
        return array(null, $fehl[0]);
    }
    return array(bb_liste($z, bb_einstellungen($z)), '');
}

/** Nach dem Speichern im Reiter MQTT: start (Haken an) bzw. stop. array(rc|null, start|stop|'', Ausgabe). */
function fw_bruecke_schalten($cfg = null)
{
    if (!is_array($cfg)) {
        $cfg = fw_config(false);
    }
    if (!fw_bruecke_laden()) {
        return array(1, '', fw_t('BEFEHL.T_NICHT_DA'));
    }
    $p = bb_pfade();
    if (!$p) {
        return array(1, '', 'keine LoxBerry-Installation erkannt');
    }
    if (!empty($cfg['befehle_mqtt_ein'])) {
        list($rc, $aus) = bb_sh($p, 'start');
        return array($rc, 'start', $aus);
    }
    if (!bb_dienste()) {
        return array(null, '', '');
    }
    list($rc, $aus) = bb_sh($p, 'stop');
    return array($rc, 'stop', $aus);
}

/** Lage der Bruecke (Reiter Test, Reiter MQTT, Healthcheck): array(true|false|null, Text); null = aus (Haken). */
function fw_bruecke_lage()
{
    if (!fw_bruecke_laden()) {
        return array(false, fw_t('BEFEHL.T_NICHT_DA'));
    }
    list($soll, $grund) = bb_soll_ermitteln();
    if ($soll === 1) {
        return array(null, fw_t('BEFEHL.T_AUS'));
    }
    if ($soll !== 0) {
        return array(false, sprintf(fw_t('BEFEHL.T_DEFEKT'), $grund));
    }
    $zu = bb_zustand_lesen();
    $pids = bb_dienste();
    $alter = (is_array($zu) && isset($zu['ts'])) ? time() - (int) $zu['ts'] : null;
    if ($pids && $alter !== null && $alter <= BB_WACHE_GRENZE && !empty($zu['abo'])) {
        $zl = (isset($zu['zaehler']) && is_array($zu['zaehler'])) ? $zu['zaehler'] : array();
        $txt = sprintf(fw_t('BEFEHL.T_LAEUFT'), count($pids),
            isset($zl['angenommen']) ? (int) $zl['angenommen'] : 0, isset($zl['abgelehnt']) ? (int) $zl['abgelehnt'] : 0);
        $lz = isset($zu['letzter']) && is_array($zu['letzter']) ? $zu['letzter'] : null;
        if ($lz !== null && isset($lz['name'])) {
            $txt .= ' ' . sprintf(fw_t('BEFEHL.T_LETZTER'), (string) $lz['name'],
                isset($lz['ergebnis']) ? (string) $lz['ergebnis'] : '-',
                isset($lz['zeit']) ? max(0, time() - (int) $lz['zeit']) : 0);
        } else {
            $txt .= ' ' . fw_t('BEFEHL.T_KEINER');
        }
        return array(true, $txt);
    }
    $wie = !$pids ? 'kein Dienst' : ($alter === null ? 'kein Zustand' : ($alter > BB_WACHE_GRENZE
        ? 'Zustand ' . $alter . ' s alt' : 'ohne Abo'));
    return array(false, sprintf(fw_t('BEFEHL.T_STEHT'), $wie));
}

/** befehlsbruecke.php --pruefen: array(rc, Zeilen). */
function fw_bruecke_pruefen()
{
    if (!fw_bruecke_laden()) {
        return array(1, array(fw_t('BEFEHL.T_NICHT_DA')));
    }
    $p = bb_pfade();
    /* Installiert der Ordner der Bruecke; nur im ausgepackten Archiv dessen bin/ (fw_archivlage()). */
    $skript = $p ? $p['bin'] . '/befehlsbruecke.php'
        : (fw_archivlage() ? dirname(dirname(__DIR__)) . '/bin/befehlsbruecke.php' : '');
    if ($skript === '' || !is_file($skript)) {
        return array(1, array(fw_t('BEFEHL.T_NICHT_DA')));
    }
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
    $aus = array();
    $rc = 1;
    @exec(escapeshellarg($php) . ' ' . escapeshellarg($skript) . ' --pruefen 2>&1', $aus, $rc);
    return array((int) $rc, $aus);
}

/** Der Rechnername fuer UDP-Adressen in der Brueckenvorlage: nie die Rueckschleife (Regeln/07). */
function fw_rechnername()
{
    $h = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST']) : '';
    if (substr_count($h, ':') === 1) {
        $h = substr($h, 0, strpos($h, ':'));
    }
    if (in_array(strtolower($h), array('', '127.0.0.1', 'localhost', '::1', '[::1]'), true)) {
        $g = gethostname();
        $h = ($g !== false && $g !== '') ? $g : 'loxberry';
    }
    return $h;
}

/** Die Vorlage der Befehlsbruecke fuer Loxone (bb_vorlage(), CRLF, UDP an den LoxBerry). array(Name, Inhalt) oder null. */
function fw_vorlage_befehle()
{
    list($l) = fw_bruecke_liste();
    if ($l === null) {
        return null;
    }
    $bp = bb_pfade();
    $port = $bp ? bb_broker($bp)['udpin'] : 11884;
    return array('VQ_funkwacht_mqtt.xml', bb_vorlage($l, fw_rechnername(), $port));
}

/* ---------------- Healthcheck (Form Weissware 0.9.44 / BYD 0.9.29) ---------------- */

/**
 * Das Urteil des LoxBerry-Healthchecks (bin/healthcheck), rein. Regeln/03 :73 (3 Fehler, 4 Warnung, 5 in Ordnung,
 * 6 Hinweis), Regeln/04 :809 (geurteilt wird ueber das ALTER, nie ueber eine einzelne Messung).
 * $f: eingerichtet (bool), aktualisierung (bool, Marke .upgrade_laeuft juenger als 1 h), pid (int; 0 = kein Waechter),
 * start_alter_s, mess_alter_s (stand.json des Waechters; -1 = keins), takt_s, sticks, krank, global_aus,
 * mqtt_zeilen (bool, eine Zeile misst ueber MQTT), mithoerer_pid, mqtt_ein, gateway, v2 (array(lage, Text)),
 * bruecke_soll, bruecke_laeuft (bool|null). Rueckgabe array(status, Text); der Text nennt keine Namen, Pfade,
 * Dienste oder Zugangsdaten.
 */
function fw_gesund_urteil(array $f)
{
    $f += array('eingerichtet' => false, 'aktualisierung' => false, 'pid' => 0, 'start_alter_s' => -1,
                'mess_alter_s' => -1, 'takt_s' => 60, 'sticks' => 0, 'krank' => 0, 'global_aus' => false,
                'mqtt_zeilen' => false, 'mithoerer_pid' => 0, 'mqtt_ein' => false, 'gateway' => array(),
                'v2' => array(null, ''), 'bruecke_soll' => false, 'bruecke_laeuft' => null);
    if (!$f['eingerichtet']) {
        return array(6, 'nicht eingerichtet - im Reiter Einstellungen eine Zeile ausfuellen (Name, Art, Pfad)');
    }
    if ($f['aktualisierung'] && (int) $f['pid'] <= 0) {
        return array(6, 'Aktualisierung laeuft - der Waechter startet danach von selbst');
    }
    $befunde = array();
    $status = 5;
    $setze = function ($s, $t) use (&$status, &$befunde) {
        if ($s < $status) {
            $status = $s;
        }
        $befunde[] = $t;
    };
    $takt = max(15, (int) $f['takt_s']);
    $grenze = max(600, 3 * $takt);
    $pid = (int) $f['pid'];
    $a = (int) $f['mess_alter_s'];
    if ($pid <= 0) {
        $setze(3, 'Waechter laeuft nicht (der Minutentakt startet ihn neu; bleibt es so, Reiter Logdateien)');
    } elseif ($a < 0) {
        $s0 = (int) $f['start_alter_s'];
        if ($s0 >= 0 && $s0 > $grenze) {
            $setze(3, sprintf('Waechter laeuft seit %d min, hat aber noch nie gemessen', (int) round($s0 / 60)));
        } else {
            $setze(4, 'Waechter laeuft, hat aber noch keine Messung abgelegt');
        }
    } elseif ($a > $grenze) {
        $setze(3, sprintf('letzte Messung vor %d min (erwartet hoechstens %d min)', (int) round($a / 60),
            (int) round($grenze / 60)));
    }
    if ((int) $f['krank'] > 0) {
        $setze(4, sprintf('%d von %d ueberwachten Zeilen gestoert', (int) $f['krank'], (int) $f['sticks']));
    }
    if ($f['mqtt_zeilen'] && (int) $f['mithoerer_pid'] <= 0) {
        $setze(4, 'eine Zeile misst ueber MQTT, aber der MQTT-Mithoerer laeuft nicht');
    }
    if ($f['mqtt_ein']) {
        $gw = is_array($f['gateway']) ? $f['gateway'] : array();
        if (empty($gw['gefunden']) || empty($gw['udpport'])) {
            $setze(4, 'MQTT eingeschaltet, aber kein MQTT-Gateway mit UDP-Eingang eingerichtet - es wird nichts gesendet');
        } elseif (empty($gw['autostart'])) {
            $setze(4, 'MQTT eingeschaltet, aber das MQTT-Gateway steht nicht auf Autostart');
        }
    }
    if (is_array($f['v2']) && isset($f['v2'][0]) && $f['v2'][0] === 0) {
        $setze(4, 'Gateway V2: ' . (isset($f['v2'][1]) ? (string) $f['v2'][1] : ''));
    }
    if ($f['bruecke_soll'] && $f['bruecke_laeuft'] === false) {
        $setze(4, 'Befehle ueber MQTT eingeschaltet, aber die Befehlsbruecke steht');
    }
    if (!$befunde) {
        return array(5, sprintf('in Ordnung - Waechter misst, %d Zeile(n) ueberwacht, letzte Messung vor %d s%s',
            (int) $f['sticks'], max(0, $a), $f['global_aus'] ? ', Heilen ist ausgeschaltet (nur melden)' : ''));
    }
    return array($status, implode('; ', $befunde));
}

/** Die Fakten des Healthchecks - nur lesend: kein Netz, kein Broker, kein Protokoll, keine Datei geschrieben. */
function fw_gesund_fakten(array $cfg)
{
    clearstatcache();
    $p = fw_paths();
    $jetzt = time();
    $f = array();
    $f['eingerichtet'] = (bool) fw_eingerichtet($cfg);
    $marke = $p['datadir'] !== '' ? $p['datadir'] . '.upgrade_laeuft' : '';
    $f['aktualisierung'] = $marke !== '' && is_file($marke) && ($jetzt - (int) @filemtime($marke)) < 3600;
    $f['pid'] = (int) fw_dienst_pid();
    $pf = $p['datadir'] . '/dienst.pid';
    $f['start_alter_s'] = ($f['pid'] > 0 && is_file($pf)) ? max(0, $jetzt - (int) @filemtime($pf)) : -1;
    $f['mess_alter_s'] = (int) fw_alter();
    $f['takt_s'] = isset($cfg['takt']) ? (int) $cfg['takt'] : 60;
    $sticks = 0;
    $mqtt_zeilen = false;
    foreach ((isset($cfg['geraete']) && is_array($cfg['geraete']) ? $cfg['geraete'] : array()) as $g) {
        if (!is_array($g) || trim((string) (isset($g['name']) ? $g['name'] : '')) === '' || empty($g['aktiv'])) {
            continue;
        }
        $sticks++;
        if ((isset($g['art']) && $g['art'] === 'mqtt') || (isset($g['art2']) && $g['art2'] === 'mqtt')) {
            $mqtt_zeilen = true;
        }
    }
    $f['sticks'] = $sticks;
    $f['mqtt_zeilen'] = $mqtt_zeilen;
    $f['mithoerer_pid'] = $mqtt_zeilen ? (int) fw_mithoerer_pid() : 0;
    $st = fw_stand();
    $f['krank'] = ($f['pid'] > 0 && isset($st['krank']) && is_numeric($st['krank'])) ? (int) $st['krank'] : 0;
    $f['global_aus'] = !empty($cfg['global_aus']);
    $f['mqtt_ein'] = !empty($cfg['mqtt_ein']);
    $f['gateway'] = fw_mqtt_zustand();
    $f['v2'] = fw_v2_haken_lage($cfg);
    $f['bruecke_soll'] = !empty($cfg['befehle_mqtt_ein']);
    $f['bruecke_laeuft'] = null;
    if ($f['bruecke_soll'] && fw_bruecke_laden()) {
        $l = fw_bruecke_lage();
        $f['bruecke_laeuft'] = $l[0] === true;
    }
    return $f;
}

/**
 * 1.0.32 (Entscheidung des Hausherrn 23.11.2026 "Nur gemeinsame Bereiche", Regeln/07): Zeile im Reiter Test -
 * faellt das gespeicherte Themen-Praefix auf einen gemeinsamen Bereich unter haus/? Speichern und Zurueckspielen
 * weisen ein solches Praefix ab; ein schon gespeichertes bleibt in Betrieb und ist hier rot, grau bei ausgeschaltetem MQTT.
 * Dazu der Vergleich der Liste mit der, die der Dienst in seinen Zustand schreibt (haus_bereiche).
 * Liste und Pruefung aus dem gemeinsamen Modul haus_bereiche.php. Rueckgabe array(stand, html).
 */
function fw_pruef_praefix_haus($cfg)
{
    $p = (trim((string) $cfg['mqtt_topic'], '/') !== '' ? trim((string) $cfg['mqtt_topic'], '/') : 'funkwacht');
    $b = hb_bereich($p);
    if ($b !== '') {
        if (!(!empty($cfg['mqtt_ein']))) {
            return array(-1, sprintf(fw_klartext('HAUSBEREICH.A_AUS'), strval($p), strval($b)));
        }
        return array(0, sprintf(fw_klartext('HAUSBEREICH.A_NEIN'), strval($p), strval($b)));
    }
    $z = (is_array(fw_stand()) && isset(fw_stand()['haus_bereiche'])) ? fw_stand()['haus_bereiche'] : null;
    if (!is_array($z)) {
        return array(-1, sprintf(fw_klartext('HAUSBEREICH.A_UNBEKANNT'), strval($p)));
    }
    if ($z !== hb_bereiche()) {
        return array(0, sprintf(fw_klartext('HAUSBEREICH.A_LISTE'), strval(implode(', ', hb_bereiche())),
            strval(implode(', ', array_map('strval', $z)))));
    }
    return array(1, sprintf(fw_klartext('HAUSBEREICH.A_JA'), strval($p)));
}

if (!function_exists('fw_meld_block')) {

/* ---------------- Statusmeldungen an SignalBot (1.0.32, gemeinsames Modul meldung.php) ----------------
 *
 * Auftrag des Hausherrn 23.11.2026, Entscheidungen 23./24.11.2026: dieselben Anlaesse wie die Ansage, eigener Haken je
 * Anlass, ab Werk aus; Sperre, Bremse und Versand im Modul. Die Erkennung laeuft auch ohne Ausgabeart, wenn eine Meldung
 * eingeschaltet ist (fw_meld_aktiv()), und legt einen Anlass hin, wenn Ansage ODER Meldung ihn will
 * (fw_meld_will()); der Sender trennt die Wege wieder (fw_meld_nur_ansage()).
 */

/** Die meldbaren Anlaesse: Name => Beschriftung (dieselben wie die Ansage). */
function fw_meld_anlaesse()
{
    return array('gestoert' => fw_t('DURCHSAGE.A_GESTOERT'), 'wieder' => fw_t('DURCHSAGE.A_WIEDER'));
}

/** Die ab-Werk-Haken der Ansage dieser Linie: so stehen auch die Haken der Meldung, solange der Block nie gespeichert ist. */
function fw_meld_haken_vorgabe()
{
    return array('gestoert' => 1, 'wieder' => 1);
}

/** Ist der Haken dieses Anlasses fuer die ANSAGE gesetzt? */
function fw_ansage_anlass_sprache($a, $cfg = null)
{
    if ($cfg === null) { $cfg = fw_config(false); }
    $anl = fw_ansage_anlaesse(); return isset($anl[$a]) && is_array($cfg) && !empty($cfg[$anl[$a]]);
}

/** Der wirksame Block 'meldung' fuer den Betrieb (ungueltig gespeichert gilt als aus und wird im Formular genannt). */
function fw_meld_block($cfg = null)
{
    if ($cfg === null) { $cfg = fw_config(false); }
    list($b) = meld_block(fw_meld_aus_cfg($cfg), fw_meld_anlaesse(), fw_meld_haken_vorgabe());
    return $b;
}

/** Die Beanstandungen eines gespeicherten Blocks (leer = gueltig). */
function fw_meld_bean($cfg = null)
{
    if ($cfg === null) { $cfg = fw_config(false); }
    list(, $f) = meld_block(fw_meld_aus_cfg($cfg), fw_meld_anlaesse(), fw_meld_haken_vorgabe());
    return $f;
}

/** Ist irgendein Anlass zur Meldung eingeschaltet? Dann laeuft die Erkennung auch ohne Ausgabeart. */
function fw_meld_aktiv($cfg = null)
{
    return meld_irgendein_an(fw_meld_block($cfg));
}

/** Will die Ansage ODER die Meldung diesen Anlass? */
function fw_meld_will($a, $cfg = null)
{
    if ($cfg === null) { $cfg = fw_config(false); }
    return fw_ansage_anlass_sprache($a, $cfg) || meld_anlass_an(fw_meld_block($cfg), (string) $a);
}

/** Der Kontext des Moduls: Datenordner (data/plugins/<ordner>, daraus der Absender) und Texte - wie bei der Ansage. */
function fw_meld_k()
{
    return fw_ansage_k();
}

/** Fuer die Ansage nur Eintraege, deren Ansage-Haken gesetzt ist; ein Eintrag ohne bekannten Anlass bleibt wie bisher. */
function fw_meld_nur_ansage(array $l, $cfg = null)
{
    if ($cfg === null) { $cfg = fw_config(false); }
    $anl = fw_meld_anlaesse();
    $aus = array();
    foreach ($l as $e) {
        if (is_array($e) && array_key_exists(meld_anlass_von($e), $anl) && !fw_ansage_anlass_sprache(meld_anlass_von($e), $cfg)) { continue; }
        $aus[] = $e;
    }
    return $aus;
}

/** Die Eintraege an SignalBot melden. Protokoll: eine Zeile, wenn gesendet oder gescheitert (nie der Text). */
function fw_meld_senden(array $l, $cfg = null)
{
    if ($cfg === null) { $cfg = fw_config(false); }
    $b = fw_meld_block($cfg);
    if (empty($b['ein']) || !$l) { return null; }
    $a = meld_regel_senden($l, $b, fw_meld_k());
    if ($a['regel'] === '' || in_array($a['regel'], array('BREMSE', 'STAND', 'ORDNER'), true)) {
        fw_log(meld_regel_kurz($a));
    }
    return $a;
}

/** Der Knopf "Testmeldung senden": array(angenommen, Satz). Geht an Haken, Sperre und Bremse vorbei. */
function fw_meld_test()
{
    $r = meld_testmeldung(fw_meld_k());
    fw_log('Testmeldung an SignalBot: ' . ($r[0] ? 'angenommen' : 'nicht angenommen'));
    return $r;
}

/** Der Block im Einstellungsformular (unter den Ansage-Regeln, im selben Formular), mit Lage und Knopf. */
function fw_meld_form_html($cfg = null)
{
    if ($cfg === null) { $cfg = fw_config(false); }
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $k = fw_meld_k();
    $o = array();
    $o[] = '<h3>' . $e(meld_t('TITEL', $k)) . '</h3>';
    $o[] = meld_formular_html(fw_meld_block($cfg), fw_meld_anlaesse(), array(), $k);
    list(, $lage) = meld_pruefzeile(fw_meld_block($cfg), fw_meld_bean($cfg), $k);
    $o[] = '<div class="sm-hilfe">' . $e(meld_t('F_TEST', $k) . ' ' . $lage) . '</div>';
    $o[] = '<div class="sm-knopfreihe"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="meld_test" value="1">'
         . $e(meld_t('K_TEST', $k)) . '</button></div>';
    return implode("\n", $o) . "\n";
}
}
