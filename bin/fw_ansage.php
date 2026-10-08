<?php
/**
 * Funkwacht - Ansage auf Zuruf des Waechters (Nr. 36 b, Stufe 2, seit 1.0.11)
 *
 * Aufruf:  php fw_ansage.php    mit {"anlass": "gestoert"|"wieder", "nr": <Zeile>, "name": "<Stick>"}
 *          auf der STANDARDEINGABE - nie auf der Kommandozeile, dort stuende der Name in der Prozessliste.
 * Antwort: eine Zeile ANSAGE;... (ASCII, ohne Text und Token). Rueckgabewert 0 gesendet, 1 gescheitert,
 *          3 nichts gesendet ohne Fehler (Ausgabeart aus, Anlass abgewaehlt, Wiederholsperre), 2 Aufruf falsch.
 *
 * Der Waechter ist in Python geschrieben; die Sprachausgabe gibt es nur in PHP (eine Stammfassung, ENTWURF
 * Abschnitt 4). Entschieden, gesprochen und protokolliert wird in fw_lib.php (fw_ansage_ausfuehren()).
 * Bauform: fw_notify.php. Der Waechter haelt NICHT an, wenn es nicht geht.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Die Bibliothek: installiert unter <home>/webfrontend/html/plugins/<ordner>/, im ausgepackten Archiv
 * unter webfrontend/html/. Welche Lage gilt, entscheidet der eigene Ablageort. */
if (basename(dirname(__DIR__)) === 'plugins') {
    $fw_lib = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/fw_lib.php';
} else {
    $fw_lib = dirname(__DIR__) . '/webfrontend/html/fw_lib.php';
}
if (!is_file($fw_lib)) {
    echo "ANSAGE;STAND=0;KENNUNG=BIBLIOTHEK\n";
    exit(1);
}
require_once $fw_lib;

list($fw_rc, $fw_zeile) = fw_ansage_ausfuehren((string) stream_get_contents(STDIN));
echo $fw_zeile, "\n";
exit($fw_rc);
