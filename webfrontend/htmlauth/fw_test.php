<?php
/**
 * Funkwacht - die Aktionen des Reiters Test
 *
 * Jeder Test gibt Klartext zurueck, keine Rueckgabewerte zum Auswerten:
 * gelesen wird das von einem Menschen.
 *
 * DIE SELBSTPRUEFUNG ist eine Reihe von Fragen mit Haken, Kreuz - oder dem
 * dritten Zustand: "hier laesst sich nichts messen". Der dritte ist wichtig.
 * Ein rotes Kreuz, das nichts bedeutet, ist schlimmer als keine Pruefung,
 * denn man sucht dann dort.
 *
 * SCHALTENDE KNOEPFE gibt es seit 1.0.0 zwei: den Heilversuch von Hand und
 * das Quittieren. Beide stehen in der Oberflaeche unter einer eigenen
 * Ueberschrift, sind orange, und darueber steht, dass sie sofort wirken.
 * Ohne sie liess sich das Heilen nur durch einen echten Ausfall pruefen -
 * bei einem Plugin, dessen einzige Aufgabe das Heilen ist, war das die
 * groesste Luecke.
 */

function fw_test_ausfuehren($welcher, $wert = '')
{
    switch ($welcher) {
        case 'selbstpruefung': return fw_test_selbstpruefung();
        case 'trocken':     return fw_test_trocken();
        case 'heilen':      return fw_test_heilen($wert);
        case 'usb':         return fw_test_usb();
        case 'dienste':     return fw_test_dienste();
        case 'container':   return fw_test_container();
        case 'journal':     return fw_test_journal($wert);
        case 'messen1':     return fw_test_messen1($wert);
        case 'mqttprobe':   return fw_test_mqttprobe();
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

function fw_dienst_datei($name = 'funkwacht_dienst.py')
{
    $p = fw_paths();
    foreach (array($p['bindir'] . '/' . $name,
                   dirname(dirname(__DIR__)) . '/bin/' . $name) as $k) {
        if (is_file($k)) { return $k; }
    }
    return '';
}

/* ==================================================================
 * Selbstpruefung - Fragen mit Haken oder Kreuz
 * ================================================================== */

/**
 * Eine Zeile der Selbstpruefung.
 *
 * Der Zaehler steht HIER und nicht am Ende der Selbstpruefung. Dort stand er
 * zuerst, als substr_count() ueber den fertigen Text - und zaehlte die
 * Kopfzeile mit, die die drei Zeichen selbst erklaert. Aus 7/4/2 wurden
 * 9/5/2; der Messwert war richtig, der Auswerter nicht.
 *
 * Aufruf mit $frage === null gibt den Stand zurueck und setzt ihn zurueck.
 */
function fw_pruefzeile($frage, $zustand = null, $antwort = '')
{
    static $zahl = array(0, 0, 0);
    if ($frage === null) {
        $stand = $zahl;
        $zahl = array(0, 0, 0);
        return $stand;
    }
    if ($zustand === true)       { $z = '[ ok ]'; $zahl[0]++; }
    elseif ($zustand === false)  { $z = '[ !! ]'; $zahl[1]++; }
    else                         { $z = '[ -- ]'; $zahl[2]++; }
    return $z . ' ' . $frage . "\n         " . $antwort;
}

/** Stimmen Reiterleiste, Bereiche und Positivliste ueberein? */
function fw_probe_reiter()
{
    $d = __DIR__ . '/index.php';
    if (!is_file($d)) { return array(null, fw_klartext('TEST.P_KEINE_DATEI')); }
    $s = (string) @file_get_contents($d);
    preg_match_all('/data-ziel="(tab-[a-z0-9]+)"/', $s, $a);
    preg_match_all('/class="sm-seite[^"]*"[^>]*id="(tab-[a-z0-9]+)"/', $s, $b);
    preg_match_all("/'(tab-[a-z0-9]+)'/", $s, $c);
    $leiste = array_values(array_unique($a[1]));
    $bereiche = array_values(array_unique($b[1]));
    $liste = array_values(array_unique($c[1]));
    sort($leiste); sort($bereiche); sort($liste);
    $ok = ($leiste === $bereiche && $leiste === $liste && count($leiste) > 0);
    return array($ok, sprintf(fw_klartext('TEST.P_REITER'),
        count($leiste), count($bereiche), count($liste), implode(', ', $leiste)));
}

/** Setzt der Server die Klasse sm-active selbst? */
function fw_probe_smactive()
{
    $d = __DIR__ . '/index.php';
    if (!is_file($d)) { return array(null, fw_klartext('TEST.P_KEINE_DATEI')); }
    $s = (string) @file_get_contents($d);
    $anzahl   = preg_match_all('/data-ziel="tab-[a-z0-9]+"/', $s);
    $leiste   = preg_match_all('/class="sm-tab<\?=[^>]*sm-active/', $s);
    $bereiche = preg_match_all('/class="sm-seite<\?=[^>]*sm-active/', $s);
    $ok = ($anzahl > 0 && $leiste >= $anzahl && $bereiche >= $anzahl);
    return array($ok, sprintf(fw_klartext('TEST.P_SMACTIVE'), $leiste, $bereiche, $anzahl));
}

/**
 * Uebersteht der Bestand die naechste Aktualisierung?
 *
 * Der Installer raeumt data/plugins/<x>/ bei JEDEM Update ab (gemessen an
 * sbin/plugininstall.pl: purge_installation im Upgrade-Zweig). Zaehler und
 * Verlauf ueberleben nur, weil preupgrade.sh sie in den Nachbarordner legt -
 * und das setzt voraus, dass dort geschrieben werden darf. Diese Frage laesst
 * sich JETZT beantworten statt beim naechsten Update.
 */
function fw_probe_bestand()
{
    $p = fw_paths();
    $wurzel = dirname($p['datadir']);
    if (!is_dir($wurzel)) {
        return array(null, fw_klartext('TEST.P_BESTAND_KEIN_ORDNER'));
    }
    if (!is_writable($wurzel)) {
        return array(false, sprintf(fw_klartext('TEST.P_BESTAND_GESPERRT'), $wurzel));
    }
    if (!is_dir($p['bestand'])) {
        return array(true, fw_klartext('TEST.P_BESTAND_BEREIT'));
    }
    $was = array();
    foreach (array('historie.json', 'verlauf') as $n) {
        if (file_exists($p['bestand'] . '/' . $n)) { $was[] = $n; }
    }
    return array(true, sprintf(fw_klartext('TEST.P_BESTAND_DA'),
                               $was ? implode(', ', $was) : fw_klartext('ALLG.KEINE'),
                               date('d.m.Y H:i', (int) @filemtime($p['bestand']))));
}

/** Zaehlen Oberflaeche und Waechter die Sticks gleich? */
function fw_probe_nummern()
{
    $stand = fw_stand();
    if (empty($stand['geraete'])) {
        return array(null, fw_klartext('TEST.P_KEINE_MESSUNG'));
    }
    $hier = array_map('strval', array_keys(fw_geraete()));
    $dort = array_map('strval', array_keys((array) $stand['geraete']));
    sort($hier); sort($dort);
    return array($hier === $dort, sprintf(fw_klartext('TEST.P_NUMMERN'),
        implode(', ', $hier) ?: '-', implode(', ', $dort) ?: '-'));
}

/** Kennen Sprachdatei und Rechenkern dieselben Gruende? */
function fw_probe_gruende()
{
    $fehlt = array();
    $n_grund = 0;
    $n_warum = 0;
    foreach (array_keys(fw_grund_nr()) as $g) {
        $n_grund++;
        if (fw_t('GRUND.' . strtoupper($g)) === 'GRUND.' . strtoupper($g)) {
            $fehlt[] = 'GRUND.' . strtoupper($g);
        }
    }
    foreach (array_keys(fw_warum_nr()) as $w) {
        if ($w === '') { continue; }
        $n_warum++;
        if (fw_t('WARUM.' . strtoupper($w)) === 'WARUM.' . strtoupper($w)) {
            $fehlt[] = 'WARUM.' . strtoupper($w);
        }
    }
    /* Gemeldet wird, wie viele Stellen wirklich angesehen wurden. */
    return array(empty($fehlt), $fehlt
        ? sprintf(fw_klartext('TEST.P_GRUENDE_FEHLT'), implode(', ', $fehlt))
        : sprintf(fw_klartext('TEST.P_GRUENDE_OK'), $n_grund, $n_warum));
}

/** Braucht diese Anlage einen Mithoerer, und laeuft er? */
function fw_probe_mithoerer()
{
    $noetig = false;
    foreach (fw_geraete() as $g) {
        if ($g['art'] === 'mqtt' || $g['art2'] === 'mqtt') { $noetig = true; break; }
    }
    if (!$noetig) {
        return array(null, fw_klartext('TEST.P_MITH_UNNOETIG'));
    }
    $pid = fw_mithoerer_pid();
    if (!$pid) {
        return array(false, fw_klartext('TEST.P_MITH_STEHT'));
    }
    $stand = fw_json_lesen(fw_paths()['mqttstand']);
    unset($stand['#letztes']);
    $still = array();
    foreach (fw_geraete() as $nr => $g) {
        foreach (array('thema', 'thema2') as $feld) {
            $t = trim((string) $g[$feld]);
            $art = $feld === 'thema' ? $g['art'] : $g['art2'];
            if ($art === 'mqtt' && $t !== '' && !isset($stand[$t])) { $still[] = $t; }
        }
    }
    if ($still) {
        return array(null, sprintf(fw_klartext('TEST.P_MITH_STILL'),
                                   $pid, implode(', ', $still)));
    }
    return array(true, sprintf(fw_klartext('TEST.P_MITH_OK'), $pid, count($stand)));
}

function fw_test_selbstpruefung()
{
    fw_pruefzeile(null);                 // Zaehler auf null
    $o = array(fw_klartext('TEST.P_KOPF'), '');
    $p = fw_paths();
    $cfg = fw_config();
    $ja = fw_klartext('ALLG.JA');
    $nein = fw_klartext('ALLG.NEIN');

    /* 1. Der eigene Endpunkt - drei Ausgaenge, nicht zwei. */
    $url = fw_endpunkt() . '?token=' . fw_token() . '&aktion=status';
    list($antwort, $code) = fw_http_holen($url, 8);
    if ($antwort === false) {
        $o[] = fw_pruefzeile(fw_klartext('TEST.P_ENDPUNKT'), null,
                             fw_klartext('TEST.P_ENDPUNKT_STUMM'));
    } elseif ($code === 200 && strpos((string) $antwort, 'FUNKWACHT;') === 0) {
        $o[] = fw_pruefzeile(fw_klartext('TEST.P_ENDPUNKT'), true,
                             sprintf(fw_klartext('TEST.P_ENDPUNKT_OK'), $code));
    } else {
        $o[] = fw_pruefzeile(fw_klartext('TEST.P_ENDPUNKT'), false,
                             sprintf(fw_klartext('TEST.P_ENDPUNKT_FEHL'), $code,
                                     substr((string) $antwort, 0, 120)));
    }

    /* 2. Weist er ein falsches Wortzeichen ab? */
    list($falsch, $fcode) = fw_http_holen(fw_endpunkt() . '?token=falsch&aktion=status', 8);
    if ($falsch === false) {
        $o[] = fw_pruefzeile(fw_klartext('TEST.P_GEGENPROBE'), null,
                             fw_klartext('TEST.P_ENDPUNKT_STUMM'));
    } elseif (strpos((string) $falsch, 'GRUND=TOKEN') !== false) {
        /* Der Rumpf allein genuegt nicht: eine Abweisung, die als HTTP 200
         * herausgeht, kommt beim Miniserver als Erfolg an. */
        $o[] = fw_pruefzeile(fw_klartext('TEST.P_GEGENPROBE'), $fcode === 403,
                             sprintf(fw_klartext('TEST.EP_ABGEWIESEN_CODE'), $fcode));
    } elseif (strpos((string) $falsch, 'FUNKWACHT;') !== false) {
        $o[] = fw_pruefzeile(fw_klartext('TEST.P_GEGENPROBE'), false,
            sprintf(fw_klartext('TEST.EP_OFFEN'), substr((string) $falsch, 0, 120)));
    } else {
        /* Irgendetwas hat geantwortet, aber nicht dieses Plugin - etwa der
         * Webserver mit einer Fehlerseite. Daraus "der Endpunkt steht offen"
         * zu machen waere ein Kreuz, das etwas Falsches behauptet. */
        $o[] = fw_pruefzeile(fw_klartext('TEST.P_GEGENPROBE'), null,
            sprintf(fw_klartext('TEST.P_GEGENPROBE_FREMD'), substr((string) $falsch, 0, 120)));
    }

    /* 3. Loest ?selftest=1 wirklich nichts aus? */
    list($st, ) = fw_http_holen(fw_endpunkt() . '?selftest=1&token=' . fw_token()
                                . '&aktion=quittieren', 8);
    if ($st === false) {
        $o[] = fw_pruefzeile(fw_klartext('TEST.P_SELFTEST'), null,
                             fw_klartext('TEST.P_ENDPUNKT_STUMM'));
    } else {
        $ok = strpos((string) $st, 'WIRKUNG=0') !== false
              && strpos((string) $st, 'QUITTIERT') === false;
        $o[] = fw_pruefzeile(fw_klartext('TEST.P_SELFTEST'), $ok,
            $ok ? fw_klartext('TEST.P_SELFTEST_OK')
                : sprintf(fw_klartext('TEST.P_SELFTEST_FEHL'), substr((string) $st, 0, 120)));
    }

    /* 4.-6. Die Stellen, die ein Werkzeug an dieser Oberflaeche nicht misst. */
    list($ok, $txt) = fw_probe_reiter();
    $o[] = fw_pruefzeile(fw_klartext('TEST.P_F_REITER'), $ok, $txt);
    list($ok, $txt) = fw_probe_smactive();
    $o[] = fw_pruefzeile(fw_klartext('TEST.P_F_SMACTIVE'), $ok, $txt);
    list($ok, $txt) = fw_felder_kongruent();
    $o[] = fw_pruefzeile(fw_klartext('TEST.P_F_FELDER'),
                         $ok === 1 ? true : ($ok === 0 ? false : null), $txt);

    /* 7. Zeigen Oberflaeche und Waechter auf dieselben Sticks? */
    list($ok, $txt) = fw_probe_nummern();
    $o[] = fw_pruefzeile(fw_klartext('TEST.P_F_NUMMERN'), $ok, $txt);

    /* 8. Kennt die Sprachdatei jeden Grund? */
    list($ok, $txt) = fw_probe_gruende();
    $o[] = fw_pruefzeile(fw_klartext('TEST.P_F_GRUENDE'), $ok, $txt);

    /* 9. Laeuft der Waechter, und wie alt ist seine Messung? */
    $pid = fw_dienst_pid();
    $alter = fw_alter();
    $o[] = fw_pruefzeile(fw_klartext('TEST.P_F_DIENST'), $pid > 0,
        $pid > 0 ? sprintf(fw_klartext('TEST.P_DIENST_OK'), $pid)
                 : fw_klartext('TEST.P_DIENST_STEHT'));
    $frisch = ($alter >= 0 && $alter < max(180, 5 * (int) $cfg['takt']));
    $o[] = fw_pruefzeile(fw_klartext('TEST.P_F_MESSUNG'),
        $alter < 0 ? null : $frisch,
        $alter < 0 ? fw_klartext('TEST.P_KEINE_MESSUNG')
                   : sprintf(fw_klartext('TEST.P_MESSUNG'), $alter, (int) $cfg['takt']));

    /* 10. Der Mithoerer. */
    list($ok, $txt) = fw_probe_mithoerer();
    $o[] = fw_pruefzeile(fw_klartext('TEST.P_F_MITHOERER'), $ok, $txt);

    /* 11. Die Rechte - je Befehl einzeln, nicht pauschal. */
    $f = fw_faehigkeiten();
    $lage = isset($f['sudo_lage']) && is_array($f['sudo_lage']) ? $f['sudo_lage'] : array();
    if (!$lage) {
        $o[] = fw_pruefzeile(fw_klartext('TEST.P_F_RECHTE'), null,
                             fw_klartext('TEST.P_RECHTE_UNBEKANNT'));
    } else {
        $teile = array();
        foreach (array('systemctl', 'docker', 'uhubctl', 'tee') as $k) {
            $w = isset($lage[$k]) ? (int) $lage[$k] : -1;
            $teile[] = $k . '=' . ($w === 1 ? $ja : ($w === 0 ? $nein : '?'));
        }
        $alle = isset($lage['alle']) ? (int) $lage['alle'] : -1;
        $o[] = fw_pruefzeile(fw_klartext('TEST.P_F_RECHTE'),
            $alle === 1 ? true : ($alle === 0 ? false : null), implode('  ', $teile));
    }

    /* 12. Zweitschrift. */
    $o[] = fw_pruefzeile(fw_klartext('TEST.P_F_ZWEITSCHRIFT'), is_file($p['sicherung']),
        is_file($p['sicherung'])
            ? sprintf(fw_klartext('TEST.P_ZWEITSCHRIFT_OK'), basename($p['sicherung']))
            : fw_klartext('TEST.P_ZWEITSCHRIFT_FEHLT'));

    /* 13. Traegt jeder Stick einen Hebel, mit dem geheilt werden kann? */
    $ohne = array();
    foreach (fw_geraete() as $nr => $g) {
        if (!$g['heilen'] || $g['hoechststufe'] < 1) { continue; }
        if ($g['dienst'] === '' && $g['container'] === ''
            && $g['usb_pfad'] === '' && $g['hub'] === '') {
            $ohne[] = $nr . ' ' . $g['name'];
        }
    }
    $o[] = fw_pruefzeile(fw_klartext('TEST.P_F_HEBEL'), empty($ohne),
        $ohne ? sprintf(fw_klartext('TEST.P_HEBEL_OHNE'), implode(', ', $ohne))
              : fw_klartext('TEST.P_HEBEL_OK'));

    /* 14. Ist die erzeugte Vorlage wohlgeformt? Beide. */
    $vorher = libxml_use_internal_errors(true);
    list($vn, $vx) = fw_vorlage();
    $sx = simplexml_load_string($vx);
    list($qn, $qx) = fw_vorlage_vo();
    $sq = simplexml_load_string($qx);
    libxml_clear_errors();
    libxml_use_internal_errors($vorher);
    $o[] = fw_pruefzeile(fw_klartext('TEST.P_F_VORLAGE'), ($sx !== false && $sq !== false),
        ($sx !== false && $sq !== false)
            ? sprintf(fw_klartext('TEST.P_VORLAGE_OK'), count($sx->children()),
                      count($sq->children()))
            : fw_klartext('TEST.P_VORLAGE_FEHL'));

    /* 15. Steht gerade eine Sperre? Das ist kein Fehler, aber es erklaert,
     *     warum nichts geschieht - und genau danach sucht man sonst lange. */
    $stand = fw_stand();
    $sperre = (string) (isset($stand['sperre']) ? $stand['sperre'] : '');
    $o[] = fw_pruefzeile(fw_klartext('TEST.P_F_SPERRE'), $sperre === '' ? true : null,
        $sperre === '' ? fw_klartext('TEST.P_SPERRE_KEINE')
                       : sprintf(fw_klartext('TEST.P_SPERRE'),
                                 fw_klartext('WARUM.' . strtoupper($sperre))));

    list($b_ok, $b_txt) = fw_probe_bestand();
    $o[] = fw_pruefzeile(fw_klartext('TEST.P_F_BESTAND'), $b_ok, $b_txt);

    list($n_ok, $n_kreuz, $n_ohne) = fw_pruefzeile(null);
    $o[] = '';
    $o[] = sprintf(fw_klartext('TEST.P_ERGEBNIS'), $n_ok, $n_kreuz, $n_ohne,
                   $n_ok + $n_kreuz + $n_ohne);
    return implode("\n", $o);
}

/* ==================================================================
 * Trockenlauf und Heilversuch von Hand
 * ================================================================== */

function fw_test_trocken()
{
    $d = fw_dienst_datei();
    if ($d === '') { return fw_t('TEST.M_KEIN_DIENST'); }
    $aus = array();
    @exec(escapeshellcmd(fw_python()) . ' ' . escapeshellarg($d) . ' --trocken 2>&1', $aus);
    return implode("\n", $aus);
}

/**
 * Eine Stufe von Hand ausfuehren.
 *
 * $wert ist "N" oder "N:S". Das SCHALTET WIRKLICH - der Knopf steht deshalb
 * unter einer eigenen Ueberschrift und ist orange. Gemeldet wird die
 * Wirkung: der Waechter misst nach dem Versuch noch einmal.
 */
function fw_test_heilen($wert)
{
    if (!preg_match('/^[0-9]{1,2}(:[0-3])?$/', (string) $wert)) {
        return fw_t('TEST.H_UNGUELTIG');
    }
    $d = fw_dienst_datei();
    if ($d === '') { return fw_t('TEST.M_KEIN_DIENST'); }
    $aus = array();
    @exec(escapeshellcmd(fw_python()) . ' ' . escapeshellarg($d)
          . ' --heile ' . escapeshellarg((string) $wert) . ' 2>&1', $aus);
    fw_log('Heilversuch von Hand: ' . $wert);
    return implode("\n", $aus);
}

/* ==================================================================
 * Suchhilfe
 * ================================================================== */

function fw_suche_json($argumente)
{
    list($rc, $text) = fw_bin_aufruf('fw_suche.py', $argumente, 60);
    if ($rc === 127) { return array(null, fw_t('TEST.S_KEIN_WERKZEUG')); }
    $d = json_decode($text, true);
    if (!is_array($d)) { return array(null, $text); }
    return array($d, '');
}

function fw_test_usb()
{
    list($d, $fehler) = fw_suche_json(array('--usb'));
    if ($d === null) { return $fehler; }
    $liste = isset($d['usb']) ? $d['usb'] : array();
    if (!$liste) { return fw_klartext('TEST.S_KEIN_USB'); }
    $o = array(fw_klartext('TEST.S_USB_KOPF'), '');
    $o[] = sprintf('%-10s %-12s %-9s %-4s %-28s %s',
                   'Kennung', 'Hersteller', 'Verteiler', 'Port', 'Produkt', 'Geraetedatei');
    $o[] = str_repeat('-', 100);
    foreach ($liste as $g) {
        $o[] = sprintf('%-10s %-12s %-9s %-4s %-28s %s',
            $g['pfad'], $g['kennung'], $g['hub'], $g['port'],
            substr(trim($g['hersteller'] . ' ' . $g['produkt']), 0, 28),
            $g['knoten'] ? implode(' ', $g['knoten']) : '-')
            . ($g['system'] ? '   << ' . fw_klartext('TEST.S_SYSTEM') : '');
    }
    $o[] = '';
    $o[] = fw_klartext('TEST.S_USB_FUSS');
    return implode("\n", $o);
}

function fw_test_dienste()
{
    list($d, $fehler) = fw_suche_json(array('--dienste'));
    if ($d === null) { return $fehler; }
    $liste = isset($d['dienste']) ? $d['dienste'] : array();
    if (!$liste) { return fw_klartext('TEST.S_KEIN_DIENST'); }
    $o = array(fw_klartext('TEST.S_DIENSTE_KOPF'), '');
    foreach ($liste as $g) {
        $o[] = sprintf('  %-32s %-10s %s', $g['name'], $g['aktiv'], $g['zustand']);
    }
    $o[] = '';
    $o[] = fw_klartext('TEST.S_DIENSTE_FUSS');
    return implode("\n", $o);
}

function fw_test_container()
{
    list($d, $fehler) = fw_suche_json(array('--container'));
    if ($d === null) { return $fehler; }
    $liste = isset($d['container']) ? $d['container'] : array();
    if (!$liste) { return fw_klartext('TEST.S_KEIN_CONTAINER'); }
    $o = array(fw_klartext('TEST.S_CONTAINER_KOPF'), '');
    foreach ($liste as $g) {
        $o[] = sprintf('  %-26s %-10s %-28s %s',
                       $g['name'], $g['zustand'], substr($g['abbild'], 0, 28), $g['text']);
    }
    return implode("\n", $o);
}

/** Ein einzelnes Geraet messen - der Knopf neben der Zeile. */
function fw_test_messen1($nr)
{
    $nr = (int) $nr;
    list($d, $fehler) = fw_suche_json(array('--messen', $nr));
    if ($d === null) { return $fehler; }
    $m = isset($d['messung']) ? $d['messung'] : array();
    if (empty($m['ok'])) {
        return sprintf(fw_klartext('TEST.S_MESS_FEHL'), $nr,
                       isset($m['fehler']) ? $m['fehler'] : '?');
    }
    $o = array();
    $o[] = sprintf(fw_klartext('TEST.S_MESS_KOPF'), $m['nr'], $m['name'], $m['art']);
    $o[] = sprintf(fw_klartext('TEST.S_MESS_ALTER'),
                   $m['alter'] < 0 ? fw_klartext('GRUND.NIE_GESEHEN') : $m['alter'] . ' s',
                   $m['hoechstalter']);
    if (!empty($m['art2'])) {
        $o[] = sprintf(fw_klartext('TEST.S_MESS_ZWEI'),
                       $m['alter1'] < 0 ? '-' : $m['alter1'] . ' s',
                       $m['alter2'] < 0 ? '-' : $m['alter2'] . ' s');
    }
    if (!empty($m['bemerkung'])) {
        $o[] = sprintf(fw_klartext('TEST.S_MESS_BEM'), $m['bemerkung']);
    }
    if ((int) $m['neustarts'] >= 0) {
        $o[] = sprintf(fw_klartext('TEST.S_MESS_NEUSTARTS'), (int) $m['neustarts']);
    }
    $o[] = sprintf(fw_klartext('TEST.S_MESS_URTEIL'),
        $m['gesund'] ? fw_klartext('ALLG.GESUND') : fw_klartext('ALLG.GESTOERT'),
        fw_klartext('GRUND.' . strtoupper($m['grund'])));
    $o[] = '';
    $o[] = fw_klartext('TEST.S_MESS_FUSS');
    return implode("\n", $o);
}

/** Das Protokoll des geheilten Dienstes - dorthin fuehrte bisher kein Weg. */
function fw_test_journal($nr)
{
    $nr = (int) $nr;
    $ger = fw_geraete();
    if (!isset($ger[$nr])) { return sprintf(fw_klartext('TEST.J_UNBEKANNT'), $nr); }
    $g = $ger[$nr];
    if ($g['container'] !== '') {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]*$/', $g['container'])) {
            return fw_klartext('TEST.J_NAME_UNGUELTIG');
        }
        $aus = array();
        @exec('docker logs --tail 60 ' . escapeshellarg($g['container']) . ' 2>&1', $aus);
        $kopf = sprintf(fw_klartext('TEST.J_CONTAINER'), $g['container']);
    } elseif ($g['dienst'] !== '') {
        if (!preg_match('/^[A-Za-z0-9@:._\-]+$/', $g['dienst'])) {
            return fw_klartext('TEST.J_NAME_UNGUELTIG');
        }
        $aus = array();
        @exec('journalctl -u ' . escapeshellarg($g['dienst'])
              . ' -n 60 --no-pager 2>&1', $aus);
        $kopf = sprintf(fw_klartext('TEST.J_DIENST'), $g['dienst']);
    } else {
        return sprintf(fw_klartext('TEST.J_NICHTS'), $g['name']);
    }
    /* Farbcodes entfernen: ein Programmprotokoll bringt sie mit, und im
     * Browser stehen sie als unlesbare Zeichenfolgen. */
    $text = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', implode("\n", $aus));
    return $kopf . "\n\n" . ($text !== '' ? $text : fw_klartext('TEST.J_LEER'));
}

/** Zehn Sekunden am Broker zuhoeren. */
function fw_test_mqttprobe()
{
    $d = fw_dienst_datei('fw_mqtt.py');
    if ($d === '') { return fw_t('TEST.M_KEIN_MITHOERER'); }
    $aus = array();
    @exec(escapeshellcmd(fw_python()) . ' ' . escapeshellarg($d) . ' --probe 10 2>&1', $aus);
    return implode("\n", $aus);
}

/* ==================================================================
 * Die uebrigen Tests
 * ================================================================== */

/** Die Rechenkerne gegen die hinterlegten Faelle. */
function fw_test_selbsttest()
{
    $o = array();
    foreach (array('funkwacht_dienst.py' => '--selbsttest',
                   'fw_mqtt.py' => '--selbsttest',
                   'fw_suche.py' => '--selbsttest') as $datei => $schalter) {
        $d = fw_dienst_datei($datei);
        if ($d === '') { continue; }
        $aus = array();
        $rc = 0;
        @exec(escapeshellcmd(fw_python()) . ' ' . escapeshellarg($d) . ' ' . $schalter
              . ' 2>&1', $aus, $rc);
        $o[] = implode("\n", $aus);
        $o[] = '';
        $o[] = ($rc === 0 ? fw_t('TEST.M_SELBSTTEST_OK') : fw_t('TEST.M_SELBSTTEST_FEHL'));
        $o[] = str_repeat('=', 70);
    }
    if (!$o) { return fw_t('TEST.M_KEIN_DIENST'); }
    return implode("\n", $o);
}

/**
 * Was kann dieses Geraet wirklich?
 *
 * Die Rechte werden EINZELN gemessen. Ein pauschales "sudo -n true"
 * beantwortet eine andere Frage: die mitgelieferte Rechtedatei erlaubt genau
 * fuenf Befehle und kein "true".
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
    $unklar = fw_klartext('ALLG.UNBEKANNT');
    $lage = isset($f['sudo_lage']) && is_array($f['sudo_lage']) ? $f['sudo_lage'] : array();
    $w = function ($k) use ($lage, $ja, $nein, $unklar) {
        $v = isset($lage[$k]) ? (int) $lage[$k] : -1;
        return $v === 1 ? $ja : ($v === 0 ? $nein : $unklar);
    };
    $o[] = fw_klartext('TEST.F_H_RECHTE');
    $o[] = sprintf(fw_klartext('TEST.F_R_SYSTEMCTL'), $w('systemctl'));
    $o[] = sprintf(fw_klartext('TEST.F_R_DOCKER'), $w('docker'));
    $o[] = sprintf(fw_klartext('TEST.F_R_UHUBCTL'), $w('uhubctl'));
    $o[] = sprintf(fw_klartext('TEST.F_R_TEE'), $w('tee'));
    if (!empty($lage['text'])) { $o[] = $lage['text']; }
    $o[] = '';
    $o[] = fw_klartext('TEST.F_H_WERKZEUGE');
    $o[] = sprintf(fw_klartext('TEST.F_SYSTEMCTL'), $f['systemctl'] ? $ja : $nein);
    $o[] = sprintf(fw_klartext('TEST.F_DOCKER'), $f['docker'] ? $ja : $nein);
    $o[] = sprintf(fw_klartext('TEST.F_JOURNALCTL'),
                   !empty($f['journalctl']) ? $ja : $nein);
    $o[] = sprintf(fw_klartext('TEST.F_PHP'), !empty($f['php']) ? $ja : $nein);
    $o[] = sprintf(fw_klartext('TEST.F_SYSFS'), $f['sysfs'] ? $ja : $nein);
    $o[] = sprintf(fw_klartext('TEST.F_UHUBCTL'), $f['uhubctl'] ? $ja : $nein);
    $o[] = sprintf(fw_klartext('TEST.F_UHUBCTL_ROOT'),
        !empty($f['uhubctl_root']) ? $ja : $nein);
    $o[] = sprintf(fw_klartext('TEST.F_HAEHNE'),
        !empty($f['uhubctl_haehne']) ? implode(', ', $f['uhubctl_haehne'])
                                     : fw_klartext('ALLG.KEINE'));
    if (!empty($f['meldung'])) { $o[] = ''; $o[] = $f['meldung']; }
    $o[] = '';
    $o[] = sprintf(fw_klartext('TEST.F_BETRIEBSDAUER'),
        isset($f['betriebsdauer']) && $f['betriebsdauer'] >= 0
            ? (int) $f['betriebsdauer'] : -1);
    $o[] = sprintf(fw_klartext('TEST.F_SYSTEMGERAETE'),
        !empty($f['systemgeraete']) ? implode(', ', $f['systemgeraete']) : fw_klartext('ALLG.KEINE'));
    if (isset($lage['alle']) && (int) $lage['alle'] !== 1) {
        $o[] = '';
        $o[] = fw_klartext('TEST.F_OHNE_SUDO');
    }
    return implode("\n", $o);
}

/** Die letzte Messung ansehen, ohne zu heilen. */
function fw_test_messen()
{
    $g = fw_geraete();
    if (!$g) { return fw_t('TEST.M_KEINE_GERAETE'); }
    $stand = fw_stand();
    $o = array();
    $o[] = sprintf(fw_klartext('TEST.M_STAND_ALTER'), fw_alter());
    if (!empty($stand['sperre'])) {
        $o[] = sprintf(fw_klartext('TEST.M_SPERRE'),
                       fw_klartext('WARUM.' . strtoupper($stand['sperre'])));
    }
    $o[] = '';
    foreach ($g as $nr => $gg) {
        $e = isset($stand['geraete'][(string) $nr]) ? $stand['geraete'][(string) $nr] : null;
        $o[] = sprintf('%d  %-22s %-10s %s', $nr, $gg['name'], $gg['art'],
            $e === null ? fw_klartext('TEST.M_NOCH_NICHT')
                        : sprintf(fw_klartext('TEST.M_ZEILE'),
                            $e['ok'] ? fw_klartext('ALLG.GESUND') : fw_klartext('ALLG.GESTOERT'),
                            fw_klartext('GRUND.' . strtoupper($e['grund'])),
                            (int) $e['alter'], (int) $e['stufe'],
                            (int) (isset($e['heilungen']) ? $e['heilungen'] : 0),
                            (int) (isset($e['versuche']) ? $e['versuche'] : 0)));
        if ($e !== null && !empty($e['warum'])) {
            $o[] = '     ' . fw_klartext('WARUM.' . strtoupper($e['warum']));
        }
        if ($e !== null && !empty($e['bemerkung'])) {
            $o[] = '     ' . $e['bemerkung'];
        }
        if ($e !== null && !empty($e['letzte_tat'])) {
            $o[] = '     ' . $e['letzte_tat'];
        }
        if ($e !== null && !empty($e['statistik'])) {
            $t = array();
            foreach ($e['statistik'] as $s => $v) {
                $t[] = sprintf(fw_klartext('TEST.M_STUFE_STAT'),
                               fw_t('STUFE.' . (int) $s), (int) $v[1], (int) $v[0]);
            }
            $o[] = '     ' . implode(', ', $t);
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
    $o[] = sprintf(fw_klartext('TEST.MQ_FASSUNG'),
        $z['fassung'] > 0 ? (string) $z['fassung'] : fw_klartext('ALLG.UNBEKANNT'));
    $o[] = sprintf(fw_klartext('TEST.MQ_EIN'), $cfg['mqtt_ein'] ? $ja : $nein);
    $o[] = sprintf(fw_klartext('TEST.MQ_BROKER'),
        trim((string) $cfg['broker_host']) !== '' ? $cfg['broker_host']
            : ($z['broker'] !== '' ? $z['broker'] . ' ' . fw_klartext('TEST.MQ_AUS_LB')
                                   : '127.0.0.1'));
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
    list($text, $code) = fw_http_holen($url, 10);
    if ($text === false) {
        $o[] = fw_klartext('TEST.EP_FEHL');
        return implode("\n", $o);
    }
    $o[] = sprintf(fw_klartext('TEST.EP_CODE'), $code);
    $o[] = $text;
    /* Ein falsches Wortzeichen MUSS abgewiesen werden. Wird das hier nicht
     * bestaetigt, steht der Endpunkt offen - und das ist wichtiger als jedes
     * andere Ergebnis auf dieser Seite. */
    $o[] = '';
    $o[] = fw_klartext('TEST.EP_GEGENPROBE');
    list($falsch, $fcode) = fw_http_holen(fw_endpunkt() . '?token=falsch&aktion=status', 10);
    $o[] = ($falsch !== false && strpos((string) $falsch, 'GRUND=TOKEN') !== false
            && $fcode === 403)
        ? sprintf(fw_klartext('TEST.EP_ABGEWIESEN_CODE'), $fcode)
        : sprintf(fw_klartext('TEST.EP_OFFEN'), substr((string) $falsch, 0, 200));
    return implode("\n", $o);
}
