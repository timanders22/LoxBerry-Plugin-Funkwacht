<?php
/**
 * Funkwacht - gemeinsame Bibliothek
 *
 * Liegt unter webfrontend/html/, weil der Miniserver-Endpunkt sie ebenso
 * braucht wie die Oberflaeche.
 *
 * Praefix 'fw_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
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

define('FW_GERAETE', 8);          // so viele Zeilen fuehrt die Oberflaeche
define('FW_STUFEN_TEXT', 'keine|dienst|sysfs|uhubctl');

function fw_paths()
{
    static $p = null;
    if ($p !== null) { return $p; }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) { $home = lb_wurzel_ermitteln(); }
    $dir = getenv('LBPPLUGINDIR');
    if (!$dir) { $dir = basename(dirname(__FILE__)); }
    if ($dir === '' || $dir === '.' || $dir === '/' || $dir === 'html' || $dir === 'plugins') {
        $dir = 'funkwacht';
    }
    $basis = $home !== '' ? $home : dirname(dirname(__DIR__));
    $p = array(
        'home'      => $home,
        'plugin'    => $dir,
        'configdir' => $basis . '/config/plugins/' . $dir,
        'config'    => $basis . '/config/plugins/' . $dir . '/funkwacht.json',
        'sicherung' => $basis . '/config/plugins/' . $dir . '.backup.json',
        'datadir'   => $basis . '/data/plugins/' . $dir,
        'logdir'    => $basis . '/log/plugins/' . $dir,
        'log'       => $basis . '/log/plugins/' . $dir . '/funkwacht.log',
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
        'name' => '', 'aktiv' => 1, 'art' => 'datei', 'pfad' => '', 'thema' => '',
        'hoechstalter' => 300, 'heilen' => 1, 'dienst' => '', 'container' => '',
        'usb_pfad' => '', 'hub' => '', 'port' => 0, 'hoechststufe' => 2,
        'ruhe_s' => 120, 'abstand_s' => 600, 'je_tag' => 6,
    );
}

function fw_vorgaben()
{
    return array(
        'geraete'      => array(),
        'takt'         => 60,
        'mqtt_ein'     => 1,
        'mqtt_topic'   => 'funkwacht',
        'aktionstoken' => '',
    );
}

function fw_arten()
{
    return array(
        'datei'     => 'FW_ART.DATEI',
        'mqtt'      => 'FW_ART.MQTT',
        'http'      => 'FW_ART.HTTP',
        'seriell'   => 'FW_ART.SERIELL',
        'bluetooth' => 'FW_ART.BLUETOOTH',
    );
}

function fw_json_lesen($pfad)
{
    if (!is_file($pfad)) { return array(); }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : array();
}

/**
 * Erst in eine Nebendatei, dann umbenennen.
 *
 * Die Nebendatei traegt die Prozessnummer und einen Zufallsanteil: Dienst und
 * Oberflaeche koennen im selben Augenblick schreiben, und zwei Schreiber in
 * derselben .tmp ergaeben eine Mischung aus zwei Dokumenten - also keines.
 * Das Ergebnis von json_encode wird geprueft: bei ungueltigem UTF-8 liefert
 * es false, und file_put_contents macht daraus eine LEERE Datei mit Rueckgabe
 * 0 - also nicht false. Ungeprueft waere der Verlust als Erfolg gemeldet.
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

function fw_config()
{
    $p = fw_paths();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if (($roh === '' || $roh === '{}') && is_file($p['sicherung'])) {
        @mkdir($p['configdir'], 0775, true);
        @copy($p['sicherung'], $p['config']);
    }
    $cfg = array_merge(fw_vorgaben(), fw_json_lesen($p['config']));
    if (!is_array($cfg['geraete'])) { $cfg['geraete'] = array(); }
    $arten = fw_arten();
    for ($i = 0; $i < FW_GERAETE; $i++) {
        $g = isset($cfg['geraete'][$i]) && is_array($cfg['geraete'][$i]) ? $cfg['geraete'][$i] : array();
        $g += fw_geraet_vorgabe();
        $g['name'] = trim((string) $g['name']);
        if (!isset($arten[$g['art']])) { $g['art'] = 'datei'; }
        $g['aktiv']  = empty($g['aktiv']) ? 0 : 1;
        $g['heilen'] = empty($g['heilen']) ? 0 : 1;
        $g['hoechstalter'] = max(10, min(86400, (int) $g['hoechstalter']));
        $g['hoechststufe'] = max(0, min(3, (int) $g['hoechststufe']));
        $g['ruhe_s']    = max(10, min(3600, (int) $g['ruhe_s']));
        $g['abstand_s'] = max(30, min(86400, (int) $g['abstand_s']));
        $g['je_tag']    = max(0, min(50, (int) $g['je_tag']));
        $g['port']      = max(0, min(99, (int) $g['port']));
        $cfg['geraete'][$i] = $g;
    }
    $cfg['takt']     = max(15, min(3600, (int) $cfg['takt']));
    $cfg['mqtt_ein'] = empty($cfg['mqtt_ein']) ? 0 : 1;
    $t = preg_replace('#[^A-Za-z0-9_/\-]#', '', (string) $cfg['mqtt_topic']);
    $cfg['mqtt_topic'] = trim($t, '/') !== '' ? trim($t, '/') : 'funkwacht';
    return $cfg;
}

function fw_config_speichern($cfg)
{
    $p = fw_paths();
    if (!fw_json_schreiben($p['config'], $cfg, 0600)) { return false; }
    @copy($p['config'], $p['sicherung']);
    @chmod($p['sicherung'], 0600);
    return true;
}

/** Nur die Zeilen mit Namen. 1-basiert - so wie sie der Dienst zaehlt. */
function fw_geraete()
{
    $out = array();
    $n = 0;
    foreach (fw_config()['geraete'] as $g) {
        if (trim((string) $g['name']) === '') { continue; }
        $n++;
        $g['nr'] = $n;
        $out[$n] = $g;
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
 *
 * Ohne sie koennen zwei gleichzeitige Aufrufe je ein eigenes Token erzeugen
 * und nacheinander speichern. Der zuerst angezeigte Wert waere dann schon
 * ueberholt, und die daraus gebaute Loxone-Vorlage traege ein Token, das
 * nicht mehr gilt.
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

/* ==================================================================
 * Zustand und Protokoll
 * ================================================================== */

function fw_stand() { return fw_json_lesen(fw_paths()['datadir'] . '/stand.json'); }

function fw_alter()
{
    $s = fw_stand();
    return isset($s['zeit']) && (int) $s['zeit'] > 0 ? max(0, time() - (int) $s['zeit']) : -1;
}

function fw_log($text)
{
    $p = fw_paths();
    if (!is_dir($p['logdir'])) { @mkdir($p['logdir'], 0775, true); }
    /* log/plugins liegt auf einer Ramdisk - eine unbegrenzt wachsende Datei
     * frisst Arbeitsspeicher, nicht Plattenplatz. */
    if (is_file($p['log']) && filesize($p['log']) > 512000) {
        $rest = array_slice(file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -400);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

/**
 * Die letzten Zeilen einer Datei, neueste zuerst - rueckwaerts mit fseek.
 * Gemessen an 12.000 Zeilen: file() 0,37 ms und 2 MB, exec("tail") 2,17 ms,
 * fseek 0,05 ms und 0 kB. Ein Prozessstart kostet mehr, als das Einlesen je
 * gespart hat.
 */
function fw_log_ende($datei, $anzahl = 400, $block = 8192)
{
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
 * Dienst
 * ================================================================== */

function fw_dienst_pid()
{
    $f = fw_paths()['datadir'] . '/dienst.pid';
    if (!is_file($f)) { return 0; }
    $pid = (int) trim((string) @file_get_contents($f));
    if ($pid <= 0) { return 0; }
    /* Argumentweise pruefen, nicht per grep ueber die ganze Befehlszeile:
     * ein grep auf 'funkwacht' findet auch die eigene Suche und jeden
     * Editor, in dem die Datei offen ist. */
    $cmd = @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($cmd === false) { return 0; }
    foreach (explode("\0", $cmd) as $teil) {
        if (basename($teil) === 'funkwacht_dienst.py') { return $pid; }
    }
    return 0;
}

function fw_faehigkeiten()
{
    return fw_json_lesen(fw_paths()['datadir'] . '/faehigkeit.json');
}

/* ==================================================================
 * MQTT
 * ================================================================== */

function fw_mqtt_zustand()
{
    $p = fw_paths();
    $leer = array('gefunden' => 0, 'autostart' => 0, 'udpport' => 0);
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
    );
}

/** Dieselbe Saeuberung wie im Dienst - fuer die Selbstpruefung. */
function fw_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

function fw_mqtt_themen()
{
    return array(
        'ok'                  => 'FW_MQTT.OK',
        'krank'               => 'FW_MQTT.KRANK',
        'geraete'             => 'FW_MQTT.GERAETE',
        'geheilt_gesamt'      => 'FW_MQTT.GEHEILT',
        'alter'               => 'FW_MQTT.ALTER',
        'alarm'               => 'FW_MQTT.ALARM',
        'geraetN/name'        => 'FW_MQTT.G_NAME',
        'geraetN/ok'          => 'FW_MQTT.G_OK',
        'geraetN/grund'       => 'FW_MQTT.G_GRUND',
        'geraetN/stufe'       => 'FW_MQTT.G_STUFE',
        'geraetN/heilungen'   => 'FW_MQTT.G_HEILUNGEN',
    );
}

/* ==================================================================
 * Loxone-Vorlage
 *
 * Geprueefter PHP-Nachbau des LoxoneTemplateBuilder - Attributreihenfolge,
 * CRLF und der Tabulator vor den Kindelementen entsprechen dem Original.
 * ================================================================== */

function fw_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . fw_x($kopf['title']) . '" ';
    $o .= 'Comment="' . fw_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . fw_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . fw_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
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
        $o .= 'MaxVal="' . fw_x(isset($c['max']) ? $c['max'] : '100') . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Die Felder je Geraet: Kuerzel, Einheit, Grenzen, Sprachschluessel.
 *
 * ALTER geht ausdruecklich bis -1 hinunter. -1 heisst "noch nie ein
 * Lebenszeichen" und ist etwas anderes als 0 ("gerade eben gehoert").
 * Stuende hier 0 als Untergrenze, machte der Miniserver aus dem einen
 * stillschweigend das andere - und ein nie angeschlossener Stick saehe im
 * Baustein aus wie ein kerngesunder.
 */
function fw_felder()
{
    return array(
        'OK'        => array('',  0,  1,     'FW_FELD.OK'),
        'STUFE'     => array('',  0,  3,     'FW_FELD.STUFE'),
        'ALTER'     => array('s', -1, 86400, 'FW_FELD.G_ALTER'),
        'HEILUNGEN' => array('',  0,  9999,  'FW_FELD.HEILUNGEN'),
    );
}

function fw_klartext($schluessel)
{
    return trim(strip_tags(html_entity_decode(fw_t($schluessel), ENT_QUOTES, 'UTF-8')));
}

function fw_endpunkt()
{
    $p = fw_paths();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    return 'http://' . $host . '/plugins/' . $p['plugin'] . '/index.php';
}

function fw_vorlage()
{
    $cmds = array();
    foreach (fw_geraete() as $nr => $g) {
        $kurz = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $g['name']));
        if ($kurz === '') { $kurz = 'STICK' . $nr; }
        $kurz = substr($kurz, 0, 12);
        foreach (fw_felder() as $feld => $info) {
            $cmds[] = array(
                'title'   => 'FW_' . $kurz . '_' . $feld,
                'comment' => $g['name'] . ': ' . fw_klartext($info[3])
                             . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
                'check'   => '\iG' . $nr . $feld . '=\i\v',
                'min'     => $info[1],
                'max'     => $info[2],
            );
        }
    }
    foreach (array(
        'OK'      => array('',  0, 1,     'FW_FELD.SUM_OK'),
        'KRANK'   => array('',  0, 99,    'FW_FELD.SUM_KRANK'),
        'GERAETE' => array('',  0, 99,    'FW_FELD.SUM_GERAETE'),
        'GEHEILT' => array('',  0, 99999, 'FW_FELD.SUM_GEHEILT'),
        'ALTER'   => array('s', 0, 86400, 'FW_FELD.SUM_ALTER'),
    ) as $feld => $info) {
        $cmds[] = array(
            'title'   => 'FW_' . $feld,
            'comment' => fw_klartext($info[3]) . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
            'check'   => '\i' . $feld . '=\i\v',
            'min'     => $info[1],
            'max'     => $info[2],
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

/** Die Statuszeile fuer den Miniserver. */
function fw_zeile($stand)
{
    $w = function ($v) { return ($v === null || !is_numeric($v)) ? '-' : (string) (0 + $v); };
    $o = sprintf("FUNKWACHT;OK=%d;KRANK=%d;GERAETE=%d;GEHEILT=%d;ALTER=%d\n",
        isset($stand['ok']) ? (int) $stand['ok'] : 0,
        isset($stand['krank']) ? (int) $stand['krank'] : 0,
        isset($stand['geraete']) ? count($stand['geraete']) : 0,
        isset($stand['geheilt_gesamt']) ? (int) $stand['geheilt_gesamt'] : 0,
        fw_alter());
    foreach ((array) (isset($stand['geraete']) ? $stand['geraete'] : array()) as $nr => $e) {
        $o .= sprintf("STICK%d;G%dOK=%s;G%dSTUFE=%s;G%dALTER=%s;G%dHEILUNGEN=%s\n",
            (int) $nr, (int) $nr, $w(isset($e['ok']) ? $e['ok'] : null),
            (int) $nr, $w(isset($e['stufe']) ? $e['stufe'] : null),
            (int) $nr, $w(isset($e['alter']) ? $e['alter'] : null),
            (int) $nr, $w(isset($e['heilungen']) ? $e['heilungen'] : null));
    }
    return $o;
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
 *
 * Gesucht wird der Ordner, der wirklich eine language_de.ini enthaelt - nicht
 * ein anderer, aus dem man auf ihn schliessen koennte. Genau daran ist das
 * Kodi-Plugin gescheitert: dort wurde vom Konfigurations- auf den
 * Vorlagenordner geschlossen, und die ganze Oberflaeche stand unbeschriftet
 * da, ohne dass irgendwo ein Fehler auftauchte.
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
