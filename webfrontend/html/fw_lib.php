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
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
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
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) { $home = lb_wurzel_ermitteln(); }
    $dir = getenv('LBPPLUGINDIR');
    if (!$dir) { $dir = basename(dirname(__FILE__)); }
    $basis = $home !== '' ? $home : dirname(dirname(__DIR__));
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
                 'global_aus' => 9);
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
            'werte' => array('art' => 'mqtt', 'thema' => 'zigbee2mqtt/bridge/state',
                             'art2' => 'usb', 'pfad2' => '', 'verkn' => 'oder',
                             'dienst' => 'zigbee2mqtt',
                             'hoechstalter' => 900, 'hoechststufe' => 2),
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
    $ok = ftruncate($fh, 0) && fwrite($fh, $json) !== false;
    fflush($fh);
    fclose($fh);
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
                fw_json_schreiben($p['config'], $roh, 0600);
                fw_log('Konfiguration aus der Zweitschrift wiederhergestellt.');
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
    foreach (array('mqtt_ein', 'global_aus', 'melden_aktiv', 'signal_ein') as $k) {
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
    fw_json_schreiben($p['sicherung'], $zurueck, 0600);
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

function fw_dienst_pid($datei = 'dienst.pid', $prozess = 'funkwacht_dienst.py')
{
    $f = fw_paths()['datadir'] . '/' . $datei;
    if (!is_file($f)) { return 0; }
    $pid = (int) trim((string) @file_get_contents($f));
    if ($pid <= 0) { return 0; }
    /* Argumentweise pruefen, nicht per grep ueber die ganze Befehlszeile. */
    $cmd = @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($cmd === false) { return 0; }
    foreach (explode("\0", $cmd) as $teil) {
        if (basename($teil) === $prozess) { return $pid; }
    }
    return 0;
}

function fw_mithoerer_pid() { return fw_dienst_pid('mithoerer.pid', 'fw_mqtt.py'); }

function fw_dienst_skript()
{
    $p = fw_paths();
    foreach (array($p['bindir'] . '/dienst.sh',
                   dirname(dirname(__DIR__)) . '/bin/dienst.sh') as $k) {
        if (is_file($k)) { return $k; }
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
        return array(0, 'unbekannt');
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
    return array($ok ? 1 : 0, $text === '' ? ('Rueckgabewert ' . (int) $rc) : $text);
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
    foreach (array($p['bindir'] . '/' . $datei,
                   dirname(dirname(__DIR__)) . '/bin/' . $datei) as $k) {
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
    set_error_handler(function () { return true; });
    $text = file_get_contents($url, false, $ctx);
    restore_error_handler();
    $code = 0;
    /* $http_response_header entsteht im Geltungsbereich DIESER Funktion. */
    if (isset($http_response_header[0]) &&
        preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $code = (int) $m[1];
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
        'WARUMNR'   => array('',  0,  9,          'FW_FELD.WARUMNR'),
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
    $cfg['_erzeugt'] = date('c');
    $cfg['_fassung'] = 'Funkwacht';
    return array('funkwacht_' . date('Ymd_His') . '.json',
                 json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                                   | JSON_UNESCAPED_SLASHES));
}

/**
 * Eine hochgeladene Sicherung pruefen und uebernehmen.
 *
 * Abgewiesen wird, was nicht passt - nie zurechtgebogen. Rueckgabe:
 * array(ok, Text).
 */
function fw_sicherung_lesen($roh)
{
    if (!is_string($roh) || trim($roh) === '') {
        return array(0, fw_t('SICH.LEER'));
    }
    if (strlen($roh) > 1048576) {
        return array(0, fw_t('SICH.ZU_GROSS'));
    }
    $d = json_decode($roh, true);
    if (!is_array($d)) {
        return array(0, sprintf(fw_t('SICH.KEIN_JSON'), json_last_error_msg()));
    }
    if (!isset($d['geraete']) || !is_array($d['geraete'])) {
        return array(0, fw_t('SICH.KEIN_FUNKWACHT'));
    }
    $neu = array_merge(fw_vorgaben(), $d);
    unset($neu['_erzeugt'], $neu['_fassung'], $neu['kaputt']);
    $anzahl = 0;
    foreach ($neu['geraete'] as $i => $g) {
        if (!is_array($g)) { return array(0, sprintf(fw_t('SICH.ZEILE_KAPUTT'), $i + 1)); }
        $neu['geraete'][$i] = fw_geraet_geradebiegen($g);
        if (trim((string) $neu['geraete'][$i]['name']) !== '') { $anzahl++; }
    }
    if (!fw_config_speichern($neu)) {
        return array(0, fw_t('FEHLER.SPEICHERN'));
    }
    fw_log('Einstellungen aus einer Sicherung zurueckgespielt.');
    return array(1, sprintf(fw_t('SICH.OK'), $anzahl));
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
    if ($p['home'] !== '') {
        $k[] = $p['home'] . '/templates/plugins/' . $p['plugin'] . '/lang';
        $k[] = $p['home'] . '/templates/plugins/funkwacht/lang';
    }
    $k[] = dirname(dirname(__DIR__)) . '/templates/lang';
    $k[] = dirname(dirname(dirname(__DIR__))) . '/templates/lang';
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
