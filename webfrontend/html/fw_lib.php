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
/* Gemeinsame Sprachausgabe (Abschrift von Werkzeuge/gemeinsam/sprachausgabe.php, Nr. 36 b). Liegt
 * neben dieser Datei; die Datei schuetzt sich selbst gegen doppeltes Laden. */
require_once __DIR__ . '/sprachausgabe.php';
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
        foreach (array('configdir', 'config', 'sicherung', 'datadir', 'stand', 'historie',
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
        'broker_pass'  => '',
        'broker_id'    => '',
        /* Nr. 36 b (Stufe 2): Ansage ueber die gemeinsame Sprachausgabe - ab Werk keine Ausgabeart
         * ('aus'); die Anlaesse sind an, wirken aber erst mit einer Ausgabeart. */
        'ansage_gestoert' => 1,
        'ansage_wieder'   => 1,
        'tts'          => ansage_vorgaben('aus'),
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
function fw_vorlagen()
{
    return array(
        'z2m_paket' => array(
            'text' => 'VORL.Z2M_PAKET',
            'werte' => array('art' => 'datei',
                             'pfad' => '/opt/zigbee2mqtt/data/log/log.txt',
                             'wachstum' => 1, 'dienst' => 'zigbee2mqtt',
                             'hoechstalter' => 900, 'hoechststufe' => 2),
        ),
        'z2m_mqtt' => array(
            'text' => 'VORL.Z2M_MQTT',
            /* M2 (Pruefung 29.09.2026): bridge/state ist retained und kommt
             * nur bei einer Aenderung - als Lebenszeichen taugt es nicht.
             * bridge/health sendet Zigbee2MQTT 2.x im Takt von health.interval
             * (Vorgabe 10 Minuten, am Geraet gemessen); 1500 s lassen zwei
             * Takte Luft.
             * S3 (Pruefung 29.09.2026): kein zweites Kriterium. Bis Stufe 1
             * stand hier art2 'usb' mit leerem pfad2 - eine Zeile, die das
             * Formular beanstandet und die das Zurueckspielen abweist. */
            /* c1 (Entscheidung 16, Welle 4): zunaechst NUR MELDEN - Heilen
             * ab Werk aus. Eingeschaltet wird es nach einer Woche
             * Beobachtung von Hand; der Dienst steht schon da. */
            'werte' => array('art' => 'mqtt', 'thema' => 'zigbee2mqtt/bridge/health',
                             'art2' => '', 'pfad2' => '', 'verkn' => 'oder',
                             'dienst' => 'zigbee2mqtt', 'heilen' => 0,
                             'hoechstalter' => 1500, 'hoechststufe' => 2),
        ),
        /* Koordinator 08.10.2026 (z2mng_hausstandard F-1): Zigbee2MqttNG laeuft als Dienst zigbee2mqttng
         * und sendet unter denselben Themen; der alte Dienst zigbee2mqtt ist dort entfernt. Wie z2m_mqtt
         * zunaechst NUR MELDEN. */
        'z2mng_mqtt' => array(
            'text' => 'VORL.Z2MNG_MQTT',
            'werte' => array('art' => 'mqtt', 'thema' => 'zigbee2mqtt/bridge/health',
                             'art2' => '', 'pfad2' => '', 'verkn' => 'oder',
                             'dienst' => 'zigbee2mqttng', 'heilen' => 0,
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
    foreach (array('mqtt_ein', 'global_aus', 'melden_aktiv', 'signal_ein', 'ansage_gestoert', 'ansage_wieder') as $k) {
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
function fw_config_speichern($cfg)
{
    $p = fw_paths();
    if (!fw_json_schreiben($p['config'], $cfg, 0600)) { return false; }
    list($zurueck, $zustand) = fw_json_lesen_geprueft($p['config']);
    if ($zustand !== 'ok') {
        fw_log('Die Konfiguration liess sich nach dem Schreiben nicht zuruecklesen. '
             . 'Die Zweitschrift bleibt unangetastet.');
        return false;
    }
    if (!fw_json_schreiben($p['sicherung'], $zurueck, 0600)) {
        fw_log('Die Zweitschrift liess sich nicht erneuern; die Konfiguration selbst ist geschrieben.');
    }
    return true;
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
    foreach ($argumente as $a) { $befehl .= ' ' . escapeshellarg((string) $a); }
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
        'wartung' => 0, 'ts' => 0,
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
        'ALTER'     => array('s', -1, 86400,      'FW_FELD.G_ALTER'),
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
        'ALTER'    => array('s', 0, 86400,      'FW_FELD.SUM_ALTER'),
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
 * Die Baustein-Liste fuer den Reiter "Einbindung in Loxone" (Nachzug G2,
 * 02.10.2026; bis 1.0.10 fertiges HTML in der Sprachdatei).
 *
 * Nummern und Verweise werden GERECHNET, nie getippt: kommt eine Zeile dazu,
 * verschoebe sich sonst jeder Verweis lautlos. Die Namen der Eingaenge kommen
 * aus derselben Quelle wie die Importvorlage (fw_vorlage()): Summenfelder als
 * 'FW_' . Feld, Felder je Stick ueber fw_titel() des ERSTEN eingetragenen
 * Sticks. Regel A4 (Regeln/04): ein ODER belegt hoechstens zwei Eingaenge,
 * jede Zeile verweist nur auf kleinere Nummern.
 *
 * Rueckgabe: 'zeilen' (Nummer, Typ, Name, Parameter, Eingaenge - fertiges
 * HTML: Texte aus der Sprachdatei, Titel maskiert), 'hinweise' (Verweis,
 * Satz), 'je_stick' (Satz).
 */
function fw_bausteinliste()
{
    $sum = fw_summenfelder();
    $gf = fw_felder();
    $geraete = fw_geraete();
    $erster = $geraete ? reset($geraete) : null;
    $mono = function ($titel) { return "<span class='sm-mono'>" . fw_e($titel) . '</span>'; };
    $s = function ($feld) use ($sum, $mono) {
        return $mono(isset($sum[$feld]) ? 'FW_' . $feld : '?' . $feld);
    };
    $g = function ($feld) use ($gf, $erster, $mono) {
        if (!isset($gf[$feld])) { return $mono('?' . $feld); }
        return $mono($erster ? fw_titel($erster, (int) $erster['nr'], $feld) : 'FW_NAME_' . $feld);
    };
    $z = array();
    $nr = array();
    $neu = function ($k, $typ, $name, $param, $eing) use (&$z, &$nr) {
        $nr[$k] = count($z) + 1;
        $z[] = array($nr[$k], $typ, $name, $param, $eing);
    };
    $r = function ($k) use (&$nr) { return '#' . $nr[$k]; };
    $opt = function ($typ) { return sprintf(fw_t('LOX.BS_OPTIONAL'), fw_t($typ)); };

    $neu('zustand', fw_t('LOX.BS_T_STATUS'), fw_t('LOX.BS_N_ZUSTAND'), fw_t('LOX.BS_P_KEINE'), $s('OK'));
    $neu('krank', fw_t('LOX.BS_T_AWV'), fw_t('LOX.BS_N_KRANK'), fw_t('LOX.BS_P_KRANK'), $s('KRANK'));
    $neu('alarm', fw_t('LOX.BS_T_STATUS'), fw_t('LOX.BS_N_ALARM'), fw_t('LOX.BS_P_KEINE'), $s('ALARM'));
    $neu('tot', fw_t('LOX.BS_T_AWV'), fw_t('LOX.BS_N_TOT'), fw_t('LOX.BS_P_TOT'), $s('ALTER'));
    $neu('oder1', fw_t('LOX.BS_T_ODER'), fw_t('LOX.BS_N_ODER1'), fw_t('LOX.BS_P_KEINE'),
         sprintf(fw_t('LOX.BS_E_ZWEI'), $r('krank'), $r('alarm')));
    $neu('sammel', fw_t('LOX.BS_T_ODER'), fw_t('LOX.BS_N_SAMMEL'), fw_t('LOX.BS_P_KEINE'),
         sprintf(fw_t('LOX.BS_E_ZWEI'), $r('oder1'), $r('tot')));
    $neu('meldung', fw_t('LOX.BS_T_BENACHR'), fw_t('LOX.BS_N_MELDUNG'), fw_t('LOX.BS_P_MELDUNG'),
         sprintf(fw_t('LOX.BS_E_NUR'), $r('sammel')));
    $neu('gesund', fw_t('LOX.BS_T_MERKER'), fw_t('LOX.BS_N_GESUND'), fw_t('LOX.BS_P_GESUND'), $g('OK'));
    $neu('verschleiss', fw_t('LOX.BS_T_AWV'), fw_t('LOX.BS_N_VERSCHLEISS'), fw_t('LOX.BS_P_VERSCHLEISS'),
         $g('HEIL7T'));
    $neu('heilung', fw_t('LOX.BS_T_AWV'), fw_t('LOX.BS_N_HEILUNG'), fw_t('LOX.BS_P_HEILUNG'),
         sprintf(fw_t('LOX.BS_E_FORMEL'), $s('VERSUCHE'), $s('GEHEILT')));
    $neu('flattert', fw_t('LOX.BS_T_AWV'), fw_t('LOX.BS_N_FLATTERT'), fw_t('LOX.BS_P_FLATTERT'), $g('NEUSTARTS'));
    $neu('warum', $opt('LOX.BS_T_STATUS'), fw_t('LOX.BS_N_WARUM'), fw_t('LOX.BS_P_WARUM'), $g('WARUMNR'));
    $neu('gesperrt', $opt('LOX.BS_T_MERKER'), fw_t('LOX.BS_N_GESPERRT'), fw_t('LOX.BS_P_KEINE'), $s('GESPERRT'));
    $neu('statistik', fw_t('LOX.BS_T_STATISTIK'), fw_t('LOX.BS_N_STATISTIK'), fw_t('LOX.BS_P_STATISTIK'),
         $s('GEHEILT'));
    $neu('quitt', $opt('LOX.BS_T_VA'), fw_t('LOX.BS_N_QUITT'), fw_t('LOX.BS_P_QUITT'), fw_t('LOX.BS_E_TASTER'));

    $und = function ($a, $b) { return sprintf(fw_t('LOX.BS_UND'), $a, $b); };
    $hinweise = array(
        array($und($r('krank'), $r('tot')), fw_t('LOX.BS_H_EVZ')),
        array($r('alarm'), fw_t('LOX.BS_H_ALARM')),
        array($und($r('oder1'), $r('sammel')), sprintf(fw_t('LOX.BS_H_ODER'), $r('sammel'))),
        array($r('meldung'), fw_t('LOX.BS_H_BENACHR')),
        array($r('verschleiss'), fw_t('LOX.BS_H_VERSCHLEISS')),
        array($r('heilung'), fw_t('LOX.BS_H_HEILUNG')),
        array($r('flattert'), fw_t('LOX.BS_H_FLATTERT')),
        array($r('gesperrt'), fw_t('LOX.BS_H_GESPERRT')),
    );
    $je = array($r('gesund'), $r('verschleiss'), $r('flattert'));
    $stick = $erster ? sprintf(fw_t('LOX.BS_STICK_ERSTER'), fw_e($erster['name']))
                     : fw_t('LOX.BS_STICK_KEINER');
    $je_stick = sprintf(fw_t('LOX.BS_JE_STICK'), $und(implode(', ', $je), $r('warum')), $stick);
    return array('zeilen' => $z, 'hinweise' => $hinweise, 'je_stick' => $je_stick);
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
     * Sprechtoken in einer Sicherungsdatei weist fw_sicherung_lesen() vorher ab. */
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
    if (in_array($k, array('mqtt_ein', 'global_aus', 'melden_aktiv', 'signal_ein', 'ansage_gestoert', 'ansage_wieder'), true)) {
        return in_array($w, array(0, 1, true, false), true)
            ? array() : array(sprintf(fw_t('SICH.W_HAKEN'), $wo));
    }
    if ($k === 'aktionstoken') {
        return fw_token_gueltig($w) ? array() : array(sprintf(fw_t('SICH.W_TOKEN'), $wo));
    }
    if ($k === 'broker_port' && is_int($w)) { $w = (string) $w; }
    if (!is_string($w)) { return array(sprintf(fw_t('SICH.W_TEXT'), $wo)); }
    if ($k === 'mqtt_topic') {
        return (preg_match('#^[a-z0-9_\-/]+$#', $w) && trim($w, '/') === $w)
            ? array() : array(sprintf(fw_t('SICH.W_THEMA'), $wo));
    }
    if ($k === 'ruhe_von' || $k === 'ruhe_bis') {
        return ($w === '' || preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $w))
            ? array() : array(sprintf(fw_t('SICH.W_UHRZEIT'), $wo));
    }
    if ($k === 'signal_url') {
        return ($w === '' || preg_match('#^https?://[^\s"\']+$#', $w))
            ? array() : array(sprintf(fw_t('SICH.W_ADRESSE'), $wo));
    }
    if ($k === 'broker_port') {
        return ($w === '' || (preg_match('/^[0-9]{1,5}$/', $w) && (int) $w >= 1 && (int) $w <= 65535))
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
    foreach (array('aktionstoken', 'broker_pass') as $k) {
        $s = is_string($cfg[$k]) ? $cfg[$k] : '';
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
 * Die Einstellungen als JSON - MIT Wortzeichen.
 *
 * Eine Sicherung ohne Wortzeichen waere nach dem Zurueckspielen wertlos: die
 * Adressen im Miniserver wuerden alle ungueltig. Wer die Datei weitergibt,
 * gibt damit auch das Wortzeichen und die Broker-Zugangsdaten weiter - das
 * steht in der Oberflaeche ueber dem Knopf.
 */
function fw_sicherung_bauen()
{
    $cfg = fw_config();
    /* X-3 (Welle 4): besteht die eigene Sicherung das eigene Zurueckspielen?
     * Geliefert wird sie trotzdem; _warnung nennt nur Schluesselnamen, nie
     * Werte. Das Zurueckspielen ueberliest Schluessel mit _. */
    list(, $fw_namen) = fw_sicherung_pruefen($cfg);
    /* Nr. 36 b: die Sprechtoken der Sprachausgabe gehen nie in eine Sicherung. */
    if (isset($cfg['tts']) && is_array($cfg['tts'])) { $cfg['tts'] = ansage_sicherung_bereinigen($cfg['tts']); }
    $cfg['_erzeugt'] = date('c');
    $cfg['_fassung'] = 'Funkwacht';
    if ($fw_namen) {
        $cfg['_warnung'] = 'Diese Sicherung liesse sich so nicht zurueckspielen. Betroffen: '
                         . implode(', ', $fw_namen);
    }
    return array('funkwacht_' . date('Ymd_His') . '.json',
                 json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                                   | JSON_UNESCAPED_SLASHES));
}

/**
 * X-3 (Welle 4): die Pruefung des Zurueckspielens als eigene Funktion -
 * dieselbe Stelle fuer das Zurueckspielen, die Warnung beim Sichern und den
 * _warnung-Kopf. $d ist die gelesene Datei bzw. die Konfiguration.
 * Rueckgabe: array(Beanstandungen, betroffene Schluesselnamen).
 */
function fw_sicherung_pruefen($d)
{
    $vorgaben = fw_vorgaben();
    $mangel = array();
    $namen = array();
    foreach ($d as $k => $w) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') { continue; }      // lesbarer Kopf
        if (!array_key_exists($k, $vorgaben)) {
            $mangel[] = sprintf(fw_t('SICH.FREMD'), sprintf(fw_t('SICH.SCHLUESSEL'), $k));
            $namen[] = $k;
            continue;
        }
        $m = fw_einstellung_pruefen($k, $w);
        if ($m) {
            $mangel = array_merge($mangel, $m);
            $namen[] = $k;
        }
    }
    $fehlt = array();
    foreach (array_keys($vorgaben) as $k) {
        /* Nr. 36 b: eine Sicherung von vor 1.0.11 kennt die Ansage nicht - die geltenden Werte bleiben
         * (fw_sicherung_lesen() sagt es). */
        if (!array_key_exists($k, $d) && in_array($k, fw_ansage_schluessel(), true)) { continue; }
        if (!array_key_exists($k, $d)) { $fehlt[] = $k; }
    }
    if ($fehlt) {
        $mangel[] = sprintf(fw_t('SICH.FEHLT'), implode(', ', $fehlt));
        $namen = array_merge($namen, $fehlt);
    }
    if (!$mangel && !empty($d['signal_ein']) && $d['signal_url'] === '') {
        $mangel[] = fw_t('FEHLER.SIGNAL_LEER');
        $namen[] = 'signal_url';
    }
    return array($mangel, array_values(array_unique($namen)));
}

/** X-3: die Beanstandungen, die das Zurueckspielen der eigenen Sicherung haette. */
function fw_sicherung_selbstpruefung()
{
    list($m, ) = fw_sicherung_pruefen(fw_config());
    return $m;
}

/**
 * Eine hochgeladene Sicherung pruefen und uebernehmen.
 *
 * Abgewiesen wird, was nicht passt - nie zurechtgebogen (U3, Pruefung
 * 29.09.2026). Jeder Schluessel und jeder Wert geht durch dieselben
 * Pruefstellen wie das Formular; fremde und fehlende Schluessel werden
 * beanstandet; alle Beanstandungen werden gesammelt, und eine halb gueltige
 * Datei aendert GAR NICHTS. Der lesbare Kopf (_erzeugt, _fassung) wird
 * ueberlesen. Rueckgabe: array(ok, Text, Liste der Beanstandungen).
 */
function fw_sicherung_lesen($roh)
{
    if (!is_string($roh) || trim($roh) === '') {
        return array(0, fw_t('SICH.LEER'), array());
    }
    if (strlen($roh) > 1048576) {
        return array(0, fw_t('SICH.ZU_GROSS'), array());
    }
    $d = json_decode($roh, true);
    if (!is_array($d)) {
        return array(0, sprintf(fw_t('SICH.KEIN_JSON'), json_last_error_msg()), array());
    }
    if (!array_key_exists('geraete', $d) || ($d !== array() && array_values($d) === $d)) {
        return array(0, fw_t('SICH.KEIN_FUNKWACHT'), array());
    }
    $vorgaben = fw_vorgaben();
    /* Nr. 36 b (Stufe 2): eine Sicherung dieses Plugins traegt nie ein Sprechtoken - traegt die Datei
     * eines, wird sie abgewiesen; das geltende Token bleibt. */
    if (array_key_exists('tts', $d)) {
        $fw_tm = ansage_sicherung_mangel($d['tts']);
        if ($fw_tm) {
            return array(0, fw_t('SICH.ABGEWIESEN'), array(sprintf(fw_t('DURCHSAGE.SICH_TOKEN'), implode(', ', $fw_tm))));
        }
    }
    list($mangel, ) = fw_sicherung_pruefen($d);
    if ($mangel) {
        return array(0, fw_t('SICH.ABGEWIESEN'), $mangel);
    }
    /* Alles geprueft: uebernommen wird genau der Inhalt der Datei, nur in
     * der Schreibweise der Konfiguration (true -> 1, Zahltext -> Zahl). */
    $neu = array();
    $fw_jetzt = fw_config(false);
    $fw_behalten = array();
    foreach (array_keys($vorgaben) as $k) {
        if (!array_key_exists($k, $d)) {      // nur die Ansage (fw_sicherung_pruefen())
            $neu[$k] = $fw_jetzt[$k];
            $fw_behalten[] = $k;
            continue;
        }
        $neu[$k] = $d[$k];
    }
    /* Nr. 36 b: der Block tts vervollstaendigt, die geltenden Sprechtoken behalten. */
    if (!in_array('tts', $fw_behalten, true)) {
        $fw_tj = fw_tts($fw_jetzt);
        $fw_tg = '';
        list($fw_tv) = ansage_vervollstaendigen(ansage_wert_pruefen($d['tts'], $fw_tg, fw_ansage_modi()) + $fw_tj);
        $neu['tts'] = ansage_sicherung_tokens_behalten($fw_tv, $fw_tj);
    }
    $anzahl = 0;
    foreach ($neu['geraete'] as $i => $g) {
        $neu['geraete'][$i] = fw_geraet_geradebiegen($g);
        if ($neu['geraete'][$i]['name'] !== '') { $anzahl++; }
    }
    foreach (array_keys(fw_betrieb_zahlen()) as $k) { $neu[$k] = (int) $neu[$k]; }
    $neu['zeilen'] = (int) $neu['zeilen'];
    foreach (array('mqtt_ein', 'global_aus', 'melden_aktiv', 'signal_ein', 'ansage_gestoert', 'ansage_wieder') as $k) {
        $neu[$k] = $neu[$k] ? 1 : 0;
    }
    $neu['broker_port'] = (string) $neu['broker_port'];
    if (!fw_config_speichern($neu)) {
        return array(0, fw_t('FEHLER.SPEICHERN'), array());
    }
    fw_log('Einstellungen aus einer Sicherung zurueckgespielt.');
    return array(1, sprintf(fw_t('SICH.OK'), $anzahl)
                    . ($fw_behalten ? ' ' . sprintf(fw_t('DURCHSAGE.SICH_BEHALTEN'), implode(', ', $fw_behalten)) : ''),
                 array());
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

/** Erlaubte Ausgabearten: alle des Moduls ausser 'audioserver' (kein Antwortweg zu Loxone im Waechter). */
function fw_ansage_modi()
{
    return array('aus', 'musicserver', 'ms4h', 'custom', 'alexang', 'cc4lox');
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

/** Der Block tts, vervollstaendigt (ab Werk 'aus'). */
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
        'port'   => ansage_webport($p['home'] !== '' ? $p['home'] . '/config/system/general.json' : ''),
        'kopf'   => array('User-Agent: LoxBerry Funkwacht'),
        'ordner' => ($p['datadir'] !== '' && @is_dir($p['datadir'])) ? $p['datadir'] : '',
        't'      => function ($s) { return fw_t($s); },
        /* Zu dieser Kennung hat das Modul (1.0.2) keinen Satz; linieneigen, bis der Modulschluessel
         * mit Stufe 2 kommt (Entwurf, Stufe 2). */
        'schluessel' => array('K_TTS_EINTRAG' => 'DURCHSAGE.SICH_EINTRAG'),
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
        || !isset($d['name']) || !is_string($d['name']) || trim($d['name']) === '') {
        return array(2, 'ANSAGE;STAND=0;KENNUNG=AUFRUF');
    }
    $cfg = fw_config(false);
    if (!fw_ansage_an($cfg)) {
        return array(3, 'ANSAGE;STAND=-2;KENNUNG=AUS');
    }
    if (empty($cfg[$anl[$d['anlass']]])) {
        return array(3, 'ANSAGE;STAND=-1;KENNUNG=ABGEWAEHLT');
    }
    $p = fw_paths();
    if ($p['datadir'] === '' || !@is_dir($p['datadir'])) {
        return array(1, 'ANSAGE;STAND=0;KENNUNG=DATENORDNER');
    }
    $wer = ($d['anlass'] === 'gestoert' ? 'Stoerung' : 'Entwarnung') . ' Zeile ' . $d['nr'];
    $fh = @fopen($p['datadir'] . '/ansage.lock', 'c');
    if ($fh === false || !@flock($fh, LOCK_EX)) {
        if ($fh !== false) { @fclose($fh); }
        return array(1, 'ANSAGE;STAND=0;KENNUNG=SPERRDATEI');
    }
    $datei = $p['datadir'] . '/ansage.json';
    $m = fw_json_lesen($datei);
    $sperre = (is_array($m) && isset($m['sperre']) && is_array($m['sperre'])) ? $m['sperre'] : array();
    $schl = $d['anlass'] . '|' . $d['nr'];
    $zuletzt = isset($sperre[$schl]) ? (int) $sperre[$schl] : 0;
    if ($zuletzt > 0 && ($jetzt - $zuletzt) < FW_ANSAGE_SPERRE_S && ($jetzt - $zuletzt) >= -300) {
        @flock($fh, LOCK_UN);
        @fclose($fh);
        fw_log('Ansage: ' . $wer . ' innerhalb von 30 min nach der letzten Ansage dieses Anlasses - '
               . 'nicht angesagt (Wiederholsperre).');
        return array(3, 'ANSAGE;STAND=-1;KENNUNG=SPERRE');
    }
    $sperre[$schl] = $jetzt;
    foreach ($sperre as $kk => $t) {
        if (!is_string($kk) || ($jetzt - (int) $t) > 86400 || ($jetzt - (int) $t) < -86400) { unset($sperre[$kk]); }
    }
    if (!fw_json_schreiben($datei, array('sperre' => $sperre), 0600)) {
        fw_log('WARNUNG: Der Merker fuer die Ansage liess sich nicht schreiben (' . $datei . ').');
    }
    @flock($fh, LOCK_UN);
    @fclose($fh);
    $satz = trim(html_entity_decode(strip_tags(sprintf(fw_t('DURCHSAGE.TEXT_' . strtoupper($d['anlass'])),
                                                       trim($d['name']))), ENT_QUOTES, 'UTF-8'));
    $k = fw_ansage_k();
    $r = ansage_sprechen($satz, fw_tts($cfg), $k);
    if ($r['stand'] === 1) {
        fw_log('Ansage: ' . $wer . ' angesagt (' . ansage_kurz($r) . ').');
    } else {
        fw_log('Ansage: ' . $wer . ' nicht angesagt: ' . ansage_kennung_text($r['kennung'], $k)
               . '. LoxBerry-Meldung, SignalBot, MQTT und Endpunkt sind davon nicht betroffen; es wird nicht wiederholt.');
    }
    $z = 'ANSAGE;' . preg_replace('/[^\x20-\x7E]/', '?', str_replace(' ', ';', ansage_kurz($r)));
    return array($r['stand'] === 1 ? 0 : ($r['stand'] === -1 ? 3 : 1), $z);
}

/** Die Zeile der Selbstpruefung: true Haken, false Kreuz, null Strich (aus). Klartext (die Ausgabe maskiert). */
function fw_pruefe_ansage($cfg = null)
{
    $k = fw_ansage_k();
    $k['e'] = function ($s) { return (string) $s; };
    list($st, $text) = ansage_pruefzeile(fw_tts($cfg), true, $k);
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
