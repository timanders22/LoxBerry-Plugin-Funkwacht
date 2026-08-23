<?php
/**
 * Funkwacht - Meldung in den LoxBerry-Benachrichtigungsbereich legen
 *
 * Aufruf:  php fw_notify.php <Schwere 1-7> <Text> [Pluginordner]
 *
 * Der Waechter ist in Python geschrieben; fuer Benachrichtigungen gibt es
 * dort keine LoxBerry-Schnittstelle. Deshalb dieses Zwischenstueck, das
 * dieselbe Funktion notify_ext() aufruft wie ein PHP-Plugin. Dasselbe Muster
 * fuehrt das APC-UPS-Plugin dieses Hauses seit 1.1.6.
 *
 * Der Pluginordner wird als drittes Argument uebergeben, weil der Dienst
 * seine Umgebung verlieren kann. Ohne ihn fiele dieses Skript auf den fest
 * eingetragenen Namen zurueck - wer das Plugin in einen anderen Ordner
 * installiert hat, faende seine Warnung dann unter einem Paketnamen, den
 * LoxBerry nicht kennt, und damit gar nicht.
 *
 * Rueckgabewert 0 = abgelegt, 1 = nicht moeglich. Der Waechter wertet ihn
 * aus, haelt aber NICHT an, wenn es nicht geht: die Werte gehen ohnehin ueber
 * MQTT und ueber die Adresse hinaus.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Der Block steht VOR seinem Aufruf. PHP zieht Funktionen, die in einem
 * if-Block stehen, nicht vor: sie entstehen erst, wenn die Zeile ausgefuehrt
 * wird. Am APC-UPS-Plugin stand derselbe Block bis 1.1.6 am Dateiende und
 * endete deshalb mit "Call to undefined function", sobald LBHOMEDIR leer war.
 */
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

$fw_home = getenv('LBHOMEDIR');
if (!$fw_home) {
    $fw_home = lb_wurzel_ermitteln();
}
$fw_sdk = $fw_home . '/libs/phplib/loxberry_log.php';
if (!$fw_home || !file_exists($fw_sdk)) {
    fwrite(STDERR, "LoxBerry-Bibliothek nicht gefunden: " . $fw_sdk . "\n");
    exit(1);
}
require_once $fw_home . '/libs/phplib/loxberry_system.php';
require_once $fw_sdk;

/* Schwere nach der Skala von LoxBerry: 3 = Fehler, 4 = Warnung, 6 = Hinweis. */
$fw_schwere = isset($argv[1]) && preg_match('/^[0-9]+$/', (string) $argv[1])
    ? (int) $argv[1] : 4;
if ($fw_schwere < 1 || $fw_schwere > 7) { $fw_schwere = 4; }

$fw_text = isset($argv[2]) ? (string) $argv[2] : '';
if (trim($fw_text) === '') {
    fwrite(STDERR, "Kein Text angegeben.\n");
    exit(1);
}

$fw_paket = isset($argv[3]) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $argv[3]) : '';
if ($fw_paket === '') { $fw_paket = (string) getenv('LBPPLUGINDIR'); }
if (!$fw_paket) { $fw_paket = 'funkwacht'; }

/* Die Wache ist Pflicht, nicht Vorsicht: notify_ext() gibt es nicht in jeder
 * LoxBerry-Fassung, und ein "Call to undefined function" waere ein fataler
 * Abbruch mitten im Waechterlauf. */
if (!function_exists('notify_ext')) {
    fwrite(STDERR, "notify_ext() steht in dieser LoxBerry-Fassung nicht bereit.\n");
    exit(1);
}

notify_ext(array(
    'PACKAGE'  => $fw_paket,
    'NAME'     => 'Funkwacht',
    'MESSAGE'  => $fw_text,
    'SEVERITY' => $fw_schwere,
));

exit(0);
