<?php
/**
 * Funkwacht - Konfiguration vervollstaendigen, Kommandozeile (seit 1.0.25)
 *
 * Aufruf:  php fw_vervollstaendigen.php <pluginordner>
 * Aufgerufen vom Waechter beim Start (funkwacht_dienst.py, vor dem ersten
 * Lesen der Konfiguration) und von postupgrade.sh nach dem Wegraeumen der
 * Marke. Der Waechter ist in Python geschrieben und schreibt funkwacht.json
 * nicht selbst; geschrieben wird ausschliesslich ueber
 * fw_config_vervollstaendigen() in fw_lib.php (ein Schreiber fuer die Datei).
 * Bauform: fw_ansage.php, BLE-Scanner 1.3.40 (bl_vervollstaendigen.php).
 *
 * Ausgabe genau eine Zeile, nur Schluesselnamen, nie ein Wert:
 *   VERVOLLSTAENDIGEN;STAND=<ruht|ohne_datei|ohne_merkwort|vollstaendig|ergaenzt|fehler|ohne_wurzel>;SCHLUESSEL=a,b
 *   VERVOLLSTAENDIGEN;STAND=0;KENNUNG=<NUR_KOMMANDOZEILE|AUFRUF|BIBLIOTHEK>
 * Rueckgabe 0 bei ruht, ohne_datei, ohne_merkwort, vollstaendig, ergaenzt; sonst 1.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=NUR_KOMMANDOZEILE\n";
    exit(1);
}
$fw_o = isset($argv[1]) ? (string) $argv[1] : '';
if (count($argv) !== 2 || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $fw_o) !== 1) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=AUFRUF\n";
    exit(1);
}

/* Die Bibliothek: installiert unter <home>/webfrontend/html/plugins/<ordner>/, im ausgepackten Archiv
 * unter webfrontend/html/. Welche Lage gilt, entscheidet der eigene Ablageort (wie fw_ansage.php). */
if (basename(dirname(__DIR__)) === 'plugins') {
    $fw_lib = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/fw_lib.php';
} else {
    $fw_lib = dirname(__DIR__) . '/webfrontend/html/fw_lib.php';
}
if (!is_file($fw_lib)) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=BIBLIOTHEK\n";
    exit(1);
}
/* Der Ordnername kommt vom Aufrufer; die Wurzel bestimmt fw_paths() selbst
 * (LBHOMEDIR mit config/plugins und data/plugins, sonst die Suche). */
putenv('LBPPLUGINDIR=' . $fw_o);
require_once $fw_lib;

$fw_e = fw_config_vervollstaendigen();
echo 'VERVOLLSTAENDIGEN;STAND=' . $fw_e['stand'] . ';SCHLUESSEL=' . implode(',', $fw_e['schluessel']) . "\n";
exit(in_array($fw_e['stand'], array('ruht', 'ohne_datei', 'ohne_merkwort', 'vollstaendig', 'ergaenzt'), true) ? 0 : 1);
