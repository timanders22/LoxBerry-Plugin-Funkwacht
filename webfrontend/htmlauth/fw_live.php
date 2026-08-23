<?php
/**
 * Funkwacht - die Kacheln auffrischen, ohne die Seite neu zu bauen
 *
 * Gibt den Zustand als JSON aus. Die Oberflaeche holt ihn alle paar Sekunden
 * und schreibt die Zahlen in die Kacheln.
 *
 * WARUM IM ANGEMELDETEN BEREICH: Er liefert Geraetenamen, Gruende und den
 * Zustand der Anlage. Das ist keine Auskunft fuer das offene Netz - dafuer
 * gibt es den Endpunkt mit Wortzeichen. Hier schuetzt die Anmeldung des
 * LoxBerry, wie bei jeder anderen Seite dieses Reiters.
 *
 * ER SCHALTET NICHTS UND SCHREIBT NICHTS. Deshalb braucht er auch kein
 * Merkmal gegen fremde Formulare: es gibt nichts auszuloesen. Gelesen wird
 * ausschliesslich stand.json - die Datei, die der Waechter ohnehin in jedem
 * Durchlauf schreibt.
 *
 * WARUM ES IHN GIBT: Beim Beobachten einer laufenden Heilung - und erst
 * recht beim Ausprobieren eines Heilversuchs von Hand - ist der Unterschied
 * zwischen Zusehen und Raten, ob man nach jedem Schritt F5 druecken muss.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Dieselbe Kandidatenliste wie in der index.php: im Archiv liegen html/ und
 * htmlauth/ nebeneinander, auf dem installierten LoxBerry in getrennten
 * Baeumen. */
$fw_gefunden = false;
foreach (array(
    dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/fw_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/fw_lib.php',
    dirname(__DIR__) . '/html/fw_lib.php',
) as $fw_kandidat) {
    if (is_file($fw_kandidat)) { require_once $fw_kandidat; $fw_gefunden = true; break; }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!$fw_gefunden) {
    http_response_code(500);
    echo json_encode(array('ok' => 0, 'fehler' => 'fw_lib.php nicht gefunden'));
    exit;
}

$fw_stand = fw_stand();
$fw_pid = fw_dienst_pid();
$fw_mpid = fw_mithoerer_pid();

$fw_geraete = array();
foreach ((array) (isset($fw_stand['geraete']) ? $fw_stand['geraete'] : array()) as $nr => $e) {
    $fw_geraete[] = array(
        'nr'        => (int) $nr,
        'name'      => (string) (isset($e['name']) ? $e['name'] : ''),
        'ok'        => (int) (isset($e['ok']) ? $e['ok'] : 0),
        'grund'     => fw_klartext('GRUND.' . strtoupper((string) (isset($e['grund']) ? $e['grund'] : ''))),
        'warum'     => empty($e['warum']) ? ''
                       : fw_klartext('WARUM.' . strtoupper((string) $e['warum'])),
        'alter'     => (int) (isset($e['alter']) ? $e['alter'] : -1),
        'stufe'     => (int) (isset($e['stufe']) ? $e['stufe'] : 0),
        'heilungen' => (int) (isset($e['heilungen']) ? $e['heilungen'] : 0),
        'versuche'  => (int) (isset($e['versuche']) ? $e['versuche'] : 0),
        'letzte_tat' => (string) (isset($e['letzte_tat']) ? $e['letzte_tat'] : ''),
    );
}

$fw_antwort = array(
    'ok'        => 1,
    'zeit'      => time(),
    'dienst'    => $fw_pid,
    'mithoerer' => $fw_mpid,
    'alter'     => fw_alter(),
    'gesund'    => (int) (isset($fw_stand['ok']) ? $fw_stand['ok'] : 0),
    'krank'     => (int) (isset($fw_stand['krank']) ? $fw_stand['krank'] : 0),
    'alarm'     => (int) (isset($fw_stand['alarm']) ? $fw_stand['alarm'] : 0),
    'geheilt'   => (int) (isset($fw_stand['geheilt_gesamt']) ? $fw_stand['geheilt_gesamt'] : 0),
    'versuche'  => (int) (isset($fw_stand['versuche_gesamt']) ? $fw_stand['versuche_gesamt'] : 0),
    'sperre'    => (string) (isset($fw_stand['sperre']) ? $fw_stand['sperre'] : ''),
    'wartung'   => (int) (isset($fw_stand['wartung']) ? $fw_stand['wartung'] : 0),
    'geraete'   => $fw_geraete,
);

$fw_json = json_encode($fw_antwort, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($fw_json === false) {
    /* Ungeprueft waere die Antwort eine leere Seite mit Status 200 - und die
     * Gegenstelle hielte sie fuer gueltig. */
    http_response_code(500);
    echo json_encode(array('ok' => 0, 'fehler' => json_last_error_msg()));
    exit;
}
echo $fw_json;
