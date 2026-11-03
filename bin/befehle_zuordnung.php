<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Funkwacht (Gruppe 44, 1.0.17).
 *
 * Die Befehlsbruecke (bin/befehlsbruecke.php und .sh, byte-gleich aus der Stammfassung Werkzeuge/gemeinsam/, 1.0.2)
 * abonniert <mqtt_topic>/befehl/# und gibt jeden gueltigen Befehl an tun() dieser Tabelle. tun() ruft DIESELBE
 * Funktion wie der HTTP-Endpunkt webfrontend/html/index.php - fw_befehl_annehmen() in fw_lib.php, Quelle 'mqtt'
 * (Pruefungen wie dort: Sticknummer, Stick im Stand, Dauer 0-1440, X-7, Auftrag schreibbar) - und gibt
 * array(ok, grund) fuer die Quittung zurueck (fw_befehl_quittung()).
 * Ein Token gibt es auf diesem Weg nicht; an seiner Stelle steht der Haken "Befehle ueber MQTT annehmen"
 * (befehle_mqtt_ein, ab Werk und beim Update aus; UDP 11884 ist im Heimnetz ohne Anmeldung erreichbar). Einen zweiten
 * Haken gibt es nicht (Entscheidung E4): beide Befehle SCHALTEN NICHTS - quittieren erlaubt dem Waechter, wieder
 * selbst zu urteilen, wartung haelt das Heilen an. Einen Heilbefehl gibt es auch ueber MQTT nicht.
 *
 * Quittungsart "uebergeben" (E3): die Bruecke wartet nie - angenommen heisst, der Auftrag liegt in auftraege.json;
 * der Waechter fuehrt ihn im naechsten Durchlauf aus (spaetestens nach einem Takt).
 *
 * Befehle (E3): quittieren (puls, alle Sticks), {nr}/quittieren (puls, ein Stick, Zeile 1-24), wartung (ganz,
 * Minuten 0-1440, 0 = aus). Sperrzeiten: Auftraege (quittieren) 60 s (Vorgabe der Bruecke); der Zustand wartung 0 s -
 * die 60-s-Sollwertbremse X-7 (gleichwert.json, gemeinsam mit dem HTTP-Weg) bleibt die eine Pruefung.
 * Die Datei setzt keine Konfiguration und schreibt nichts; wie der unangemeldete Endpunkt heilt die Bruecke nichts
 * (fw_config(false)).
 */
if (!function_exists('fw_befehl_annehmen')) {
    $fw_kand = array();
    $fw_kand[] = 'REPLACELBPHTMLDIR/fw_lib.php';
    $fw_kand[] = dirname(__DIR__) . '/webfrontend/html/fw_lib.php';
    if (basename(dirname(__DIR__)) === 'plugins') {
        $fw_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/fw_lib.php';
    }
    foreach ($fw_kand as $fw_k) {
        if (strpos($fw_k, 'REPLACE') !== 0 && is_file($fw_k)) {
            require_once $fw_k;
            break;
        }
    }
}
return array(
    'fassung' => 1,
    'linie'   => 'funkwacht',
    'plugin'  => 'Funkwacht',
    'geladen' => function_exists('fw_befehl_annehmen') && function_exists('fw_befehl_quittung')
                 && function_exists('fw_config'),
    'einstellungen' => function () {
        $c = fw_config(false);   // wie der unangemeldete Endpunkt: nichts heilen, nichts anlegen
        $p = trim((string) $c['mqtt_topic'], '/ ');
        return array('praefix'     => $p === '' ? 'funkwacht' : $p,
                     'annehmen'    => !empty($c['befehle_mqtt_ein']),
                     'eingreifend' => false,
                     'mqtt_ein'    => !empty($c['mqtt_ein']));
    },
    'protokoll' => function ($stufe, $text) {
        // 1.0.19: bb_log() setzt "Befehlsbruecke: " schon vor den Text - bis 1.0.18 stand es doppelt (Nachzug 01.11.2026, wie MGiSmart 1.1.30).
        fw_log('(' . $stufe . ') ' . $text);
    },
    /* Die Zeilennummer des Sticks wie im Reiter Einstellungen (FW_GERAETE_MAX = 24). */
    'teile' => array('nr' => '[1-9]|1[0-9]|2[0-4]'),
    'befehle' => array(
        'quittieren' => array('art' => 'puls', 'eingreifend' => false, 'titel' => 'Funkwacht Quittieren (MQTT)',
            'kachel' => 'BEFEHL.K_QUITTIEREN', 'quittung' => 'uebergeben',
            'tun' => function ($w, $t) {
                return fw_befehl_quittung(fw_befehl_annehmen('quittieren', array('nr' => '0'), 'mqtt'));
            }),
        '{nr}/quittieren' => array('art' => 'puls', 'eingreifend' => false,
            'titel' => 'Funkwacht Stick N quittieren (MQTT)',
            'kachel' => 'BEFEHL.K_QUITTIEREN_NR', 'quittung' => 'uebergeben',
            'tun' => function ($w, $t) {
                return fw_befehl_quittung(fw_befehl_annehmen('quittieren',
                    array('nr' => isset($t['nr']) ? (string) (int) $t['nr'] : ''), 'mqtt'));
            }),
        'wartung' => array('art' => 'ganz', 'min' => 0, 'max' => 1440, 'eingreifend' => false,
            'sperre_s' => 0,
            'begruendung' => 'Ein Zustand: die 60-s-Sollwertbremse X-7 (gleichwert.json, gemeinsam mit dem HTTP-Weg) '
                           . 'bleibt die eine Pruefung (Bauvorschrift Gruppe 44, G2).',
            'titel' => 'Funkwacht Wartung Minuten (MQTT)',
            'kachel' => 'BEFEHL.K_WARTUNG', 'quittung' => 'uebergeben',
            'tun' => function ($w, $t) {
                return fw_befehl_quittung(fw_befehl_annehmen('wartung', array('dauer' => (string) $w), 'mqtt'));
            }),
    ),
);
