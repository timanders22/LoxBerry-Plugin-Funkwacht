<?php
/**
 * Funkwacht - der Endpunkt fuer den Miniserver
 *
 * Liegt im unangemeldeten Bereich und ist deshalb durch ein Wortzeichen
 * geschuetzt. Verglichen wird mit hash_equals, also in gleichbleibender Zeit -
 * ein einfaches == liesse sich ueber die Antwortzeit Zeichen fuer Zeichen
 * erraten.
 *
 *   ?token=<TOKEN>&aktion=status          alle Werte als Textzeilen
 *   ?token=<TOKEN>&aktion=json            dasselbe als JSON, mit den Texten
 *   ?token=<TOKEN>&aktion=geraet&nr=2     nur ein Stick
 *   ?token=<TOKEN>&aktion=quittieren[&nr=2]   die Bremse zuruecksetzen
 *   ?token=<TOKEN>&aktion=wartung&dauer=60    Wartung fuer 60 Minuten (0 = aus)
 *   ?selftest=1&token=<TOKEN>             antwortet, OHNE etwas auszuloesen
 *
 * ES GIBT KEINEN HEILBEFEHL VON AUSSEN. Ein solcher waere ein Hebel, mit dem
 * sich der USB-Bus des LoxBerry aus dem Netz zuruecksetzen liesse - das
 * Wortzeichen steht in der Adresse und ist damit im Netz sichtbar. Fuer eine
 * Auskunft reicht das, fuer einen Hebel nicht.
 *
 * WAS SEIT 1.0.0 GEHT, UND WARUM ES ETWAS ANDERES IST: quittieren und
 * Wartung. Beide SCHALTEN NICHTS - das eine erlaubt dem Waechter, wieder
 * selbst zu urteilen, das andere haelt ihn an. Ein Stick auf "Tagesgrenze"
 * liess sich vorher weder aus Loxone noch aus der Oberflaeche zuruecksetzen;
 * das war eine Sackgasse ohne Ausgang.
 *
 * UND ER LEGT NICHTS AN. fw_config(false) liest nur. Gemessen an 0.9.3: ein
 * einziger Aufruf OHNE Token, korrekt mit 403 beantwortet, hinterliess den
 * Konfigordner und eine frisch geschriebene funkwacht.json.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/fw_lib.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$fw_cfg = fw_config(false);          // NUR lesen - siehe Kopf
$fw_soll = (string) $fw_cfg['aktionstoken'];
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
$fw_erlaubt = array('status', 'json', 'geraet', 'quittieren', 'wartung');
if (!in_array($fw_aktion, $fw_erlaubt, true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=AKTION_UNBEKANNT\n";
    echo "Erlaubt sind: " . implode(', ', $fw_erlaubt) . "\n";
    exit;
}

/* ---------------- Selbstprobe ----------------
 * Hausstandard: jeder Endpunkt, der etwas ausloesen kann, beantwortet
 * ?selftest=1 - OHNE es auszuloesen. Damit laesst sich die Kette
 * Miniserver -> Adresse -> Wortzeichen pruefen, ohne dass dabei eine
 * Wartung beginnt oder eine Bremse faellt. */
if (isset($_GET['selftest'])) {
    $fw_stand = fw_stand();
    echo "SELFTEST;OK=1\n";
    echo "AKTION=" . $fw_aktion . "\n";
    echo "WIRKUNG=0\n";
    echo "GERAETE=" . (isset($fw_stand['geraete']) ? count($fw_stand['geraete']) : 0) . "\n";
    echo "ALTER=" . fw_alter() . "\n";
    echo "SCHREIBBAR=" . (is_dir(fw_paths()['datadir']) && is_writable(fw_paths()['datadir'])
                          ? 1 : 0) . "\n";
    exit;
}

$fw_stand = fw_stand();

/* ---------------- Quittieren ----------------
 * Setzt Bremse und Eskalationsstand zurueck, damit der Waechter wieder
 * urteilen darf. Der Auftrag geht ueber eine Datei an den Waechter: er ist
 * der einzige Schreiber der Historie. Gemeldet wird deshalb, WAS geschrieben
 * wurde und WANN es spaetestens wirkt - nicht ein Erfolg, den hier niemand
 * nachmessen kann. */
if ($fw_aktion === 'quittieren') {
    $nr = isset($_GET['nr']) ? max(0, min(99, (int) $_GET['nr'])) : 0;
    if ($nr > 0 && !isset($fw_stand['geraete'][(string) $nr])) {
        http_response_code(404);
        echo "FEHLER;OK=0;GRUND=GERAET_UNBEKANNT\n";
        exit;
    }
    if (!fw_auftrag('quittieren', $nr)) {
        http_response_code(500);
        echo "FEHLER;OK=0;GRUND=SCHREIBEN\n";
        exit;
    }
    fw_log('Quittieren angefordert (Endpunkt), Stick ' . ($nr ?: 'alle') . '.');
    echo "QUITTIERT;OK=1;NR=" . $nr . ";WIRKT_IN=" . fw_auftrag_wartezeit() . "\n";
    exit;
}

/* ---------------- Wartung ----------------
 * Haelt das Heilen fuer eine Weile an - und geht von selbst wieder aus. Ein
 * Schalter, den jemand von Hand setzt, wird vergessen; einer mit Ablauf
 * nicht. dauer=0 beendet sie sofort. */
if ($fw_aktion === 'wartung') {
    $dauer = isset($_GET['dauer']) ? max(0, min(1440, (int) $_GET['dauer'])) : 60;
    if (!fw_auftrag('wartung', 0, $dauer)) {
        http_response_code(500);
        echo "FEHLER;OK=0;GRUND=SCHREIBEN\n";
        exit;
    }
    fw_log('Wartung angefordert (Endpunkt): ' . $dauer . ' Minuten.');
    echo "WARTUNG;OK=1;DAUER=" . $dauer . ";WIRKT_IN=" . fw_auftrag_wartezeit() . "\n";
    exit;
}

if ($fw_aktion === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    /* Das Alter gehoert in die Antwort, aber nicht in die Datei: es ist im
     * Augenblick der Frage zu rechnen, sonst waere es immer null. */
    $fw_stand['alter_stand'] = fw_alter();
    $j = json_encode($fw_stand, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($j === false) {
        /* json_encode gibt bei ungueltigem UTF-8 false zurueck. Ungeprueft
         * waere die Antwort eine leere Seite mit Status 200 - und eine leere
         * Antwort mit Erfolgsmeldung ist das Schlechteste, was eine
         * Schnittstelle liefern kann. */
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
