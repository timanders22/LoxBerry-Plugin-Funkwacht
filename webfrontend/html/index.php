<?php
/**
 * Funkwacht - der Endpunkt fuer den Miniserver
 *
 * Liegt im unangemeldeten Bereich und ist deshalb durch ein Wortzeichen
 * geschuetzt. Verglichen wird mit hash_equals, also in gleichbleibender Zeit -
 * ein einfaches == liesse sich ueber die Antwortzeit Zeichen fuer Zeichen
 * erraten.
 *
 *   ?token=<TOKEN>&aktion=status   alle Werte als Textzeilen (die Vorlage)
 *   ?token=<TOKEN>&aktion=json     dasselbe als JSON
 *   ?token=<TOKEN>&aktion=geraet&nr=2   nur ein Stick
 *
 * DIESER ENDPUNKT SCHALTET NICHTS. Ein Heilbefehl von aussen waere ein
 * Hebel, mit dem sich der USB-Bus des LoxBerry aus dem Netz zuruecksetzen
 * liesse - das ist es nicht wert. Geheilt wird ausschliesslich, wenn der
 * Waechter selbst einen Ausfall feststellt.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/fw_lib.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$fw_soll = (string) fw_config()['aktionstoken'];
$fw_ist = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($fw_soll === '') {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Wortzeichen.\n";
    exit;
}
if (!hash_equals($fw_soll, $fw_ist)) {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=TOKEN\n";
    exit;
}

$fw_aktion = isset($_GET['aktion']) ? strtolower((string) $_GET['aktion']) : 'status';
if (!in_array($fw_aktion, array('status', 'json', 'geraet'), true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=AKTION_UNBEKANNT\n";
    echo "Erlaubt sind: status, json, geraet\n";
    exit;
}

$fw_stand = fw_stand();

if ($fw_aktion === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $j = json_encode($fw_stand, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($j === false) {
        /* json_encode gibt bei ungueltigem UTF-8 false zurueck. Ungeprueft
         * waere die Antwort eine leere Seite mit Status 200 - und eine leere
         * Antwort mit Erfolgsmeldung ist das Schlechteste, was eine
         * Schnittstelle liefern kann: die Gegenstelle haelt sie fuer gueltig. */
        http_response_code(500);
        echo json_encode(array('ok' => 0, 'fehler' => json_last_error_msg()));
        exit;
    }
    echo $j;
    exit;
}

if ($fw_aktion === 'geraet') {
    $nr = isset($_GET['nr']) ? (int) $_GET['nr'] : 0;
    if (!isset($fw_stand['geraete'][(string) $nr])) {
        http_response_code(404);
        echo "FEHLER;OK=0;GRUND=GERAET_UNBEKANNT\n";
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($fw_stand['geraete'][(string) $nr], JSON_UNESCAPED_UNICODE);
    exit;
}

echo fw_zeile($fw_stand);
