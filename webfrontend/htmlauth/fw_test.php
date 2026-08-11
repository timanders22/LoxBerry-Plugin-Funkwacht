<?php
/**
 * Funkwacht - die Aktionen des Reiters Test
 *
 * Jeder Test gibt Klartext zurueck, keine Rueckgabewerte zum Auswerten:
 * gelesen wird das von einem Menschen.
 */

function fw_test_ausfuehren($welcher)
{
    switch ($welcher) {
        case 'selbsttest':  return fw_test_selbsttest();
        case 'faehigkeit':  return fw_test_faehigkeit();
        case 'messen':      return fw_test_messen();
        case 'zeile':       return fw_test_zeile();
        case 'mqtt':        return fw_test_mqtt();
        case 'endpunkt':    return fw_test_endpunkt();
    }
    return fw_t('TEST.M_UNBEKANNT');
}

function fw_python()
{
    /* Nur der Name, nicht der Pfad: welches python3 gilt, entscheidet die
     * Umgebung. Ein fest verdrahteter Pfad ginge auf einem Debian 13 schief. */
    return 'python3';
}

function fw_dienst_datei()
{
    $p = fw_paths();
    foreach (array($p['bindir'] . '/funkwacht_dienst.py',
                   dirname(dirname(__DIR__)) . '/bin/funkwacht_dienst.py') as $k) {
        if (is_file($k)) { return $k; }
    }
    return '';
}

/** Der Rechenkern gegen die hinterlegten Faelle. */
function fw_test_selbsttest()
{
    $d = fw_dienst_datei();
    if ($d === '') { return fw_t('TEST.M_KEIN_DIENST'); }
    $aus = array();
    $rc = 0;
    @exec(escapeshellcmd(fw_python()) . ' ' . escapeshellarg($d) . ' --selbsttest 2>&1', $aus, $rc);
    $text = implode("\n", $aus);
    return $text . "\n\n" . ($rc === 0
        ? fw_t('TEST.M_SELBSTTEST_OK')
        : fw_t('TEST.M_SELBSTTEST_FEHL'));
}

/**
 * Was kann dieses Geraet wirklich?
 *
 * Das ist der wichtigste Test dieses Plugins. uhubctl schaltet nur an Hubs,
 * die Portstrom koennen - der eingebaute Hub des Raspberry Pi 4 kann es
 * nicht. Ein Knopf, der nichts bewirkt, ist schlimmer als keiner.
 */
function fw_test_faehigkeit()
{
    $d = fw_dienst_datei();
    if ($d === '') { return fw_t('TEST.M_KEIN_DIENST'); }
    $aus = array();
    @exec(escapeshellcmd(fw_python()) . ' ' . escapeshellarg($d) . ' --faehigkeit 2>&1', $aus);
    $roh = implode("\n", $aus);
    $f = json_decode($roh, true);
    if (!is_array($f)) { return $roh; }

    fw_json_schreiben(fw_paths()['datadir'] . '/faehigkeit.json', $f);

    $o = array();
    $ja = fw_klartext('ALLG.JA');
    $nein = fw_klartext('ALLG.NEIN');
    $o[] = sprintf(fw_klartext('TEST.F_SUDO'), $f['sudo'] ? $ja : $nein);
    $o[] = sprintf(fw_klartext('TEST.F_SYSTEMCTL'), $f['systemctl'] ? $ja : $nein);
    $o[] = sprintf(fw_klartext('TEST.F_DOCKER'), $f['docker'] ? $ja : $nein);
    $o[] = sprintf(fw_klartext('TEST.F_SYSFS'), $f['sysfs'] ? $ja : $nein);
    $o[] = sprintf(fw_klartext('TEST.F_UHUBCTL'), $f['uhubctl'] ? $ja : $nein);
    $o[] = sprintf(fw_klartext('TEST.F_HAEHNE'),
        $f['uhubctl_haehne'] ? implode(', ', $f['uhubctl_haehne']) : fw_klartext('ALLG.KEINE'));
    if (!empty($f['meldung'])) { $o[] = ''; $o[] = $f['meldung']; }
    $o[] = '';
    $o[] = sprintf(fw_klartext('TEST.F_SYSTEMGERAETE'),
        !empty($f['systemgeraete']) ? implode(', ', $f['systemgeraete']) : fw_klartext('ALLG.KEINE'));
    if (!$f['sudo']) {
        $o[] = '';
        $o[] = fw_klartext('TEST.F_OHNE_SUDO');
    }
    return implode("\n", $o);
}

/** Einmal messen, ohne zu heilen. */
function fw_test_messen()
{
    $g = fw_geraete();
    if (!$g) { return fw_t('TEST.M_KEINE_GERAETE'); }
    $stand = fw_stand();
    $o = array();
    $o[] = sprintf(fw_klartext('TEST.M_STAND_ALTER'), fw_alter());
    $o[] = '';
    foreach ($g as $nr => $gg) {
        $e = isset($stand['geraete'][(string) $nr]) ? $stand['geraete'][(string) $nr] : null;
        $o[] = sprintf('%d  %-22s %-10s %s', $nr, $gg['name'], $gg['art'],
            $e === null ? fw_klartext('TEST.M_NOCH_NICHT')
                        : sprintf(fw_klartext('TEST.M_ZEILE'),
                            $e['ok'] ? fw_klartext('ALLG.GESUND') : fw_klartext('ALLG.GESTOERT'),
                            fw_klartext('GRUND.' . strtoupper($e['grund'])),
                            (int) $e['alter'], (int) $e['stufe'], (int) $e['heilungen']));
        if ($e !== null && !empty($e['warum'])) {
            $o[] = '     ' . fw_klartext('WARUM.' . strtoupper($e['warum']));
        }
        if ($e !== null && !empty($e['letzte_tat'])) {
            $o[] = '     ' . $e['letzte_tat'];
        }
    }
    if (!empty($stand['tabu'])) {
        $o[] = '';
        $o[] = sprintf(fw_klartext('TEST.M_TABU'), implode(', ', $stand['tabu']));
    }
    return implode("\n", $o);
}

function fw_test_zeile()
{
    return fw_klartext('TEST.M_ZEILE_KOPF') . "\n\n" . rtrim(fw_zeile(fw_stand()));
}

function fw_test_mqtt()
{
    $cfg = fw_config();
    $z = fw_mqtt_zustand();
    $ja = fw_klartext('ALLG.JA');
    $nein = fw_klartext('ALLG.NEIN');
    $o = array();
    $o[] = sprintf(fw_klartext('TEST.MQ_GEFUNDEN'), $z['gefunden'] ? $ja : $nein);
    $o[] = sprintf(fw_klartext('TEST.MQ_AUTOSTART'), $z['autostart'] ? $ja : $nein);
    $o[] = sprintf(fw_klartext('TEST.MQ_PORT'), $z['udpport'] ? (string) $z['udpport'] : '-');
    $o[] = sprintf(fw_klartext('TEST.MQ_EIN'), $cfg['mqtt_ein'] ? $ja : $nein);
    $o[] = '';
    $o[] = fw_klartext('TEST.MQ_THEMEN');
    $praefix = trim((string) $cfg['mqtt_topic'], '/');
    foreach (fw_mqtt_themen() as $k => $unbenutzt) {
        $o[] = '  ' . $praefix . '/' . $k;
    }
    $o[] = '';
    $o[] = sprintf(fw_klartext('TEST.MQ_SAEUBERUNG'),
        fw_mqtt_wert_saeubern("Zeile eins\nZeile zwei\tmit Tabulator"));
    return implode("\n", $o);
}

/** Den eigenen Endpunkt aufrufen - so, wie der Miniserver es taete. */
function fw_test_endpunkt()
{
    $url = fw_endpunkt() . '?token=' . fw_token() . '&aktion=status';
    $o = array(sprintf(fw_klartext('TEST.EP_AUFRUF'), $url), '');
    $ctx = stream_context_create(array('http' => array('timeout' => 10, 'ignore_errors' => true)));
    $text = @file_get_contents($url, false, $ctx);
    if ($text === false) {
        $o[] = fw_klartext('TEST.EP_FEHL');
        return implode("\n", $o);
    }
    $o[] = $text;
    /* Ein falsches Wortzeichen MUSS abgewiesen werden. Wird das hier nicht
     * bestaetigt, steht der Endpunkt offen - und das ist wichtiger als jedes
     * andere Ergebnis auf dieser Seite. */
    $o[] = '';
    $o[] = fw_klartext('TEST.EP_GEGENPROBE');
    $falsch = @file_get_contents(fw_endpunkt() . '?token=falsch&aktion=status', false, $ctx);
    $o[] = ($falsch !== false && strpos((string) $falsch, 'GRUND=TOKEN') !== false)
        ? fw_klartext('TEST.EP_ABGEWIESEN')
        : sprintf(fw_klartext('TEST.EP_OFFEN'), substr((string) $falsch, 0, 200));
    return implode("\n", $o);
}
