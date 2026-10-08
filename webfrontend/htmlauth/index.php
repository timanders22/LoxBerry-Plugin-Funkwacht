<?php
/**
 * Funkwacht - Bedienoberflaeche
 *
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * Diese Datei ist NUR Oberflaeche. Das Pruefen und Heilen laeuft im Waechter
 * (bin/funkwacht_dienst.py), der Miniserver spricht mit
 * webfrontend/html/index.php.
 *
 * Praefix 'fw_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * WAS HIER SEIT 0.9.4 GILT
 * ------------------------
 * 1. JEDER REITER HAT SEINEN EIGENEN SPEICHER-HANDLER. Bis 0.9.3 trugen beide
 *    Formulare denselben Knopfnamen: Speichern im Reiter MQTT loeschte alle
 *    Stick-Zeilen, Speichern in den Einstellungen schaltete MQTT ab.
 * 2. EIN WACHPOSTEN GEGEN FREMDE FORMULARE, vor allen Handlern.
 * 3. REITERLEISTE, POSITIVLISTE UND KNOPFKLASSEN STEHEN AUSGESCHRIEBEN -
 *    erzeugt man sie in einer Schleife, findet hausstandard_pruefen.py nichts
 *    und setzt die Spalte auf "-", was sich beim Ueberfliegen wie ein Haken
 *    liest. Dazu die Kongruenzprobe im Reiter Test.
 * 4. DER WAECHTER LAESST SICH SCHALTEN.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Die Bibliothek liegt im UNANGEMELDETEN Bereich. Der Weg dorthin sieht im
 * Archiv anders aus als installiert - deshalb eine Kandidatenliste und keine
 * Rechnung. Genau daran ist das Intercom-Plugin mit HTTP 500 gescheitert.
 *
 * Jede der beiden Lagen gilt nur, wenn der EIGENE Ort sie traegt: installiert
 * webfrontend/htmlauth/plugins/<ordner>/ -> webfrontend/html/plugins/<ordner>/,
 * im Archiv webfrontend/htmlauth/ -> webfrontend/html/. Bis 1.0.6 standen drei
 * Wege nacheinander, jeder in jeder Lage: im Archiv kamen die beiden
 * installierten zuerst und luden eine fremde fw_lib.php aus
 * <ueber dem Archiv>/html/plugins/htmlauth/, installiert ohne eigene
 * Bibliothek die eines Plugins mit dem Ordner "html"
 * (Pruefung-Funkwacht-1.0.7, messe_nachlese.sh, Faelle B1-B4). */
$fw_gefunden = false;
$fw_kandidaten = array();
if (basename(dirname(__DIR__)) === 'plugins' && basename(dirname(dirname(__DIR__))) === 'htmlauth') {
    $fw_kandidaten[] = dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/fw_lib.php';
} elseif (basename(__DIR__) === 'htmlauth' && basename(dirname(__DIR__)) === 'webfrontend') {
    $fw_kandidaten[] = dirname(__DIR__) . '/html/fw_lib.php';
}
foreach ($fw_kandidaten as $fw_kandidat) {
    if (is_file($fw_kandidat)) { require_once $fw_kandidat; $fw_gefunden = true; break; }
}
if (!$fw_gefunden) {
    echo '<p><b>Fehler:</b> fw_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p>';
    exit;
}
require_once __DIR__ . '/fw_test.php';

$fw_p = fw_paths();
if ($fw_p['home'] !== '' && is_file($fw_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $fw_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $fw_p['home'] . '/libs/phplib/loxberry_web.php';
    $fw_p = fw_paths(true);      // nach dem Einbinden neu holen, siehe Geruest
}

/* Die Reiter, ausgeschrieben. Positivliste, Leiste und die id der Bereiche
 * tragen dieselben Namen - dass sie auseinanderlaufen KOENNEN, ist der Preis
 * dafuer, dass das Pruefwerkzeug sie ueberhaupt sieht. Dagegen steht keine
 * Hoffnung, sondern die Kongruenzprobe im Reiter Test. */
$fw_reiter = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');
$fw_tab = 'tab-settings';
if (isset($_POST['activetab']) && in_array((string) $_POST['activetab'], $fw_reiter, true)) {
    $fw_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form'])
          && in_array('tab-' . (string) $_GET['form'], $fw_reiter, true)) {
    $fw_tab = 'tab-' . (string) $_GET['form'];
}

$fw_meldungen = array();
$fw_fehler = array();
$fw_testausgabe = '';
$fw_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';
/* U9: Knoepfe, die nichts speichern, melden ihr Scheitern in einer eigenen
 * Liste - nicht unter "Nichts wurde gespeichert" (Regeln/04). */
$fw_misslungen = array();

/* X-2 (Regeln/04, Welle 4, 01.10.2026): die eingetippten Werte EINES
 * beanstandeten Formulars (settings oder mqtt). Sie reisen mit der
 * Einmalmeldung und fuellen beim GET das Formular; das beanstandete Feld
 * traegt sm-beanstandet und aria-invalid. Nie Geheimnisse: das
 * Broker-Kennwort wird gar nicht gesammelt, sein Feld bleibt leer. */
$fw_eingaben = null;
$fw_falsch = array();
function fw_eingaben_sammeln($form, array $falsch)
{
    $str = function ($v) {
        return is_string($v) ? substr(preg_replace('/[\x00-\x1F\x7F]/', '', $v), 0, 4096) : '';
    };
    $e = array('form' => $form, 'falsch' => array_values(array_unique($falsch)),
               'werte' => array(), 'haken' => array());
    if ($form === 'settings') {
        $zeilen = isset($_POST['g_name']) && is_array($_POST['g_name']) ? array_keys($_POST['g_name']) : array();
        foreach (array('g_name', 'g_art', 'g_pfad', 'g_thema', 'g_kennung', 'g_art2', 'g_pfad2',
                       'g_thema2', 'g_verkn', 'g_dienst', 'g_container', 'g_usb', 'g_hub', 'g_port',
                       'g_stufe', 'g_alter', 'g_folge', 'g_ruhe', 'g_abstand', 'g_tag') as $n) {
            if (!isset($_POST[$n]) || !is_array($_POST[$n])) { continue; }
            foreach ($_POST[$n] as $i => $v) {
                if (is_int($i)) { $e['werte'][$n . '[' . $i . ']'] = $str($v); }
            }
        }
        foreach (array('g_aktiv', 'g_heilen', 'g_wachstum', 'g_dienstnach', 'g_lernen') as $n) {
            foreach ($zeilen as $i) {
                if (!is_int($i)) { continue; }
                $e['haken'][$n . '[' . $i . ']'] = isset($_POST[$n]) && is_array($_POST[$n])
                                                   && !empty($_POST[$n][$i]);
            }
        }
        foreach (array('takt', 'anlauf_s', 'ruhe_von', 'ruhe_bis', 'log_kb', 'verlauf_tage',
                       'signal_url') as $n) {
            if (isset($_POST[$n])) { $e['werte'][$n] = $str($_POST[$n]); }
        }
        foreach (array('global_aus', 'melden_aktiv', 'signal_ein') as $n) {
            $e['haken'][$n] = !empty($_POST[$n]);
        }
        /* Nr. 36 b: Felder und Anlaesse der Ansage - nie die Sprechtoken (ansage_x2_felder()). */
        foreach (ansage_x2_felder(array('modi' => fw_ansage_modi())) as $n) {
            if (substr($n, -9) === '_loeschen') { $e['haken'][$n] = !empty($_POST[$n]); }
            elseif (isset($_POST[$n])) { $e['werte'][$n] = $str($_POST[$n]); }
        }
        foreach (fw_ansage_anlaesse() as $n) { $e['haken'][$n] = !empty($_POST[$n]); }
    } elseif ($form === 'mqtt') {
        foreach (array('mqtt_topic', 'broker_host', 'broker_port', 'broker_user', 'broker_id') as $n) {
            if (isset($_POST[$n])) { $e['werte'][$n] = $str($_POST[$n]); }
        }
        $e['haken']['mqtt_ein'] = !empty($_POST['mqtt_ein']);
    }
    return $e;
}
/* Beim GET: Wert, Haken und Markierung - nach einer Beanstandung aus den
 * eingetippten Werten, sonst aus der Konfiguration. */
function fw_eingabe($form)
{
    global $fw_eingaben;
    return (is_array($fw_eingaben) && isset($fw_eingaben['form']) && $fw_eingaben['form'] === $form)
         ? $fw_eingaben : null;
}
function fw_ew($form, $k, $gespeichert)
{
    $e = fw_eingabe($form);
    if ($e !== null && isset($e['werte'][$k]) && is_string($e['werte'][$k])) { return $e['werte'][$k]; }
    return (string) $gespeichert;
}
function fw_eh($form, $k, $gespeichert)
{
    $e = fw_eingabe($form);
    if ($e !== null && isset($e['haken']) && is_array($e['haken']) && array_key_exists($k, $e['haken'])) {
        return !empty($e['haken'][$k]);
    }
    return (bool) $gespeichert;
}
function fw_ek($form, $k)
{
    $e = fw_eingabe($form);
    $ja = ($e !== null && isset($e['falsch']) && is_array($e['falsch']) && in_array($k, $e['falsch'], true));
    return $ja ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}

/* U8: das Ergebnis des vorigen POST - NUR beim GET gelesen (Regeln/04). */
if (!$fw_post) {
    $fw_einmal = fw_einmal_lesen();
    if ($fw_einmal) {
        $fw_meldungen = $fw_einmal['meldungen'];
        $fw_fehler = $fw_einmal['fehler'];
        $fw_misslungen = $fw_einmal['misslungen'];
        $fw_testausgabe = $fw_einmal['test'];
        $fw_eingaben = $fw_einmal['eingaben'];      // X-2
    }
}

/* ---------------- Wachposten gegen fremde Formulare ----------------
 * EINE Pruefung, VOR allen Handlern. Einen einzelnen Handler kann man beim
 * Erweitern vergessen, einen Wachposten am Eingang nicht. */
fw_token();
$fw_merkmal = fw_formtoken();
if ($fw_post) {
    $fw_mit = isset($_POST['fmt']) ? (string) $_POST['fmt'] : '';
    if ($fw_merkmal === '' || !hash_equals($fw_merkmal, $fw_mit)) {
        $fw_post = false;
        $fw_fehler[] = fw_t('FEHLER.FREMDES_FORMULAR');
        fw_log('Ein Formular ohne gueltiges Merkmal wurde abgewiesen.');
    }
}

/* ---------------- Vorlagen herunterladen ---------------- */
if ($fw_post && isset($_POST['vorlage'])) {
    list($fw_name, $fw_inhalt) = ((string) $_POST['vorlage'] === 'vq')
        ? fw_vorlage_vo() : fw_vorlage();
    if ($fw_inhalt === '') {
        $fw_misslungen[] = fw_t('LOX.FEHLER_VORLAGE');
        $fw_tab = 'tab-loxone';
    } else {
        header('Content-Type: application/x-download');
        // Anfuehrungszeichen um den Dateinamen: ohne sie bricht jeder Name
        // mit einem Leerzeichen darin.
        header('Content-Disposition: attachment; filename="' . $fw_name . '"');
        echo $fw_inhalt;
        exit;
    }
}

/* ---------------- Einstellungen sichern ---------------- */
if ($fw_post && isset($_POST['sichern'])) {
    list($fw_name, $fw_inhalt) = fw_sicherung_bauen();
    if ($fw_inhalt === false) {
        $fw_misslungen[] = fw_t('SICH.FEHL');
        $fw_tab = 'tab-settings';
    } else {
        header('Content-Type: application/x-download');
        header('Content-Disposition: attachment; filename="' . $fw_name . '"');
        echo $fw_inhalt;
        exit;
    }
}

/* Die Helfer, die alle Speicher-Handler brauchen. Nr. 16/19 (Welle 4,
 * 01.10.2026): nichts wird mehr still zurechtgebogen. Bis 1.0.9 entfernte
 * $fw_sauber Anfuehrungs- und Steuerzeichen und speicherte den Rest, und ein
 * leeres Zahlfeld behielt still den alten Wert. Jetzt ist beides eine
 * Beanstandung: nichts gespeichert, das Feld markiert ($fw_falsch), die
 * Eingabe kommt zurueck (X-2). Still bleibt nur Leerraum am Rand. */
$fw_text = function ($roh, $bez, $feld) use (&$fw_fehler, &$fw_falsch) {
    if (!is_string($roh)) {
        $fw_fehler[] = sprintf(fw_t('FEHLER.KEIN_TEXT'), $bez);
        $fw_falsch[] = $feld;
        return null;
    }
    $roh = trim($roh);
    if (preg_match('/[\x00-\x1F\x7F"\']/', $roh)) {
        $fw_fehler[] = sprintf(fw_t('FEHLER.ZEICHEN'), $bez, preg_replace('/[\x00-\x1F\x7F]/', '?', $roh));
        $fw_falsch[] = $feld;
        return null;
    }
    return $roh;
};
$fw_zahl = function ($roh, $von, $bis, $bez, $feld) use (&$fw_fehler, &$fw_falsch) {
    /* Eine Zahl pruefen statt sie stillschweigend zurechtzubiegen - mit
     * derselben Pruefstelle wie das Zurueckspielen. Bis 1.0.8 wurde hier
     * gerundet: 60.4 wurde still 60 (U6, Pruefung 29.09.2026). */
    $roh = is_string($roh) ? trim($roh) : null;
    if ($roh === '') {
        $fw_fehler[] = sprintf(fw_t('FEHLER.FELD_LEER'), $bez);
        $fw_falsch[] = $feld;
        return null;
    }
    list($w, $grund) = $roh === null ? array(null, 'keine_zahl')
                                     : fw_ganzzahl_pruefen($roh, $von, $bis);
    if ($grund === 'keine_zahl') {
        $fw_fehler[] = sprintf(fw_t('FEHLER.KEINE_ZAHL'), $bez, (string) $roh);
    } elseif ($grund === 'keine_ganzzahl') {
        $fw_fehler[] = sprintf(fw_t('FEHLER.KEINE_GANZZAHL'), $bez, $roh);
    } elseif ($grund === 'ausserhalb') {
        $fw_fehler[] = sprintf(fw_t('FEHLER.AUSSERHALB'), $bez, $roh, $von, $bis);
    }
    if ($grund !== '') { $fw_falsch[] = $feld; }
    return $w;
};
$fw_zeit = function ($roh, $bez, $feld) use (&$fw_fehler, &$fw_falsch) {
    $roh = is_string($roh) ? trim($roh) : null;
    if ($roh === '') { return ''; }
    if ($roh === null || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $roh)) {
        $fw_fehler[] = sprintf(fw_t('FEHLER.KEINE_UHRZEIT'), $bez, (string) $roh);
        $fw_falsch[] = $feld;
        return null;
    }
    return $roh;
};
/* Welches Formularfeld gehoert zu welchem Schluessel einer Stick-Zeile?
 * Fuer die Markierung (X-2) der Beanstandungen aus fw_geraet_pruefen(). */
$fw_formnamen = array('name' => 'g_name', 'art' => 'g_art', 'pfad' => 'g_pfad',
    'thema' => 'g_thema', 'kennung' => 'g_kennung', 'art2' => 'g_art2', 'pfad2' => 'g_pfad2',
    'thema2' => 'g_thema2', 'verkn' => 'g_verkn', 'dienst' => 'g_dienst',
    'container' => 'g_container', 'usb_pfad' => 'g_usb', 'hub' => 'g_hub', 'port' => 'g_port',
    'hoechststufe' => 'g_stufe', 'hoechstalter' => 'g_alter', 'reihenfolge' => 'g_folge',
    'ruhe_s' => 'g_ruhe', 'abstand_s' => 'g_abstand', 'je_tag' => 'g_tag', 'heilen' => 'g_heilen');

/* ---------------- Speichern: Sticks und Betrieb ---------------- */
if ($fw_post && isset($_POST['speichern_geraete'])) {
    $fw_cfg = fw_config();
    $fw_anzahl = count($fw_cfg['geraete']);
    $fw_feld = function ($name, $i, $label) use ($fw_text) {
        $a = isset($_POST[$name]) && is_array($_POST[$name]) ? $_POST[$name] : array();
        if (!array_key_exists($i, $a)) { return ''; }
        $w = $fw_text($a[$i], fw_t('EINST.GERAET') . ' ' . ($i + 1) . ' / ' . fw_t('EINST.' . $label),
                      $name . '[' . $i . ']');
        /* Beanstandet: gespeichert wird ohnehin nichts. Die Zeile wird mit
         * dem getippten Wert weitergeprueft, damit kein Folgesatz ("kein
         * Name") entsteht, der nicht stimmt. */
        if ($w === null) { return is_string($a[$i]) ? trim($a[$i]) : ''; }
        return $w;
    };
    $fw_roh_i = function ($name, $i) {
        return isset($_POST[$name]) && is_array($_POST[$name]) && array_key_exists($i, $_POST[$name])
             ? $_POST[$name][$i] : '';
    };
    $fw_arten_w = fw_arten();
    /* a1 (Welle 4): die Systemgeraete, die der Waechter zuletzt gemessen hat. */
    $fw_st = fw_stand();
    $fw_tabu = isset($fw_st['tabu']) && is_array($fw_st['tabu']) ? $fw_st['tabu'] : array();
    $fw_vhinweise = array();
    $fw_neu = array();
    for ($fw_i = 0; $fw_i < $fw_anzahl; $fw_i++) {
        /* Was das Formular gar nicht mitgeschickt hat, wird NICHT angefasst.
         * Das ist derselbe Satz wie bei den beiden Reitern - nur eine Ebene
         * tiefer: kommt zwischen Aufbau und Absenden eine Zeile dazu, hat
         * das Formular fuer sie kein einziges Feld, und ohne diese Zeile
         * fielen ihre Haken still auf 0. */
        if (!isset($_POST['g_name'][$fw_i])) {
            $fw_neu[$fw_i] = $fw_cfg['geraete'][$fw_i];
            continue;
        }
        $g = fw_geraet_vorgabe();
        $g['name']      = $fw_feld('g_name', $fw_i, 'L_NAME');
        $g['art']       = $fw_feld('g_art', $fw_i, 'L_ART');
        $g['pfad']      = $fw_feld('g_pfad', $fw_i, 'L_PFAD');
        $g['thema']     = $fw_feld('g_thema', $fw_i, 'L_THEMA');
        /* Nr. 19: nicht mehr still kleingeschrieben - der Waechter vergleicht
         * ohne Ruecksicht auf Gross/Klein. */
        $g['kennung']   = $fw_feld('g_kennung', $fw_i, 'L_KENNUNG');
        $g['art2']      = $fw_feld('g_art2', $fw_i, 'L_ART2');
        $g['pfad2']     = $fw_feld('g_pfad2', $fw_i, 'L_PFAD2');
        $g['thema2']    = $fw_feld('g_thema2', $fw_i, 'L_THEMA2');
        $g['verkn']     = $fw_feld('g_verkn', $fw_i, 'L_VERKN');
        $g['dienst']    = $fw_feld('g_dienst', $fw_i, 'L_DIENST');
        $g['container'] = $fw_feld('g_container', $fw_i, 'L_CONTAINER');
        $g['usb_pfad']  = $fw_feld('g_usb', $fw_i, 'L_USB_PFAD');
        $g['hub']       = $fw_feld('g_hub', $fw_i, 'L_HUB');
        $g['reihenfolge'] = $fw_feld('g_folge', $fw_i, 'L_FOLGE');
        $g['aktiv']       = !empty($_POST['g_aktiv'][$fw_i]) ? 1 : 0;
        $g['heilen']      = !empty($_POST['g_heilen'][$fw_i]) ? 1 : 0;
        $g['wachstum']    = !empty($_POST['g_wachstum'][$fw_i]) ? 1 : 0;
        $g['dienst_nach'] = !empty($_POST['g_dienstnach'][$fw_i]) ? 1 : 0;
        $g['lernen']      = !empty($_POST['g_lernen'][$fw_i]) ? 1 : 0;

        $bez = fw_t('EINST.GERAET') . ' ' . ($fw_i + 1);
        /* Nr. 19 (Welle 4): ein Wert ausserhalb der Auswahl wird beanstandet.
         * Bis 1.0.9 setzte fw_geraet_geradebiegen() ihn still auf die
         * Vorgabe (Art -> datei, Verknuepfung -> oder). */
        foreach (array('art' => array('g_art', array_keys($fw_arten_w), 'L_ART'),
                       'art2' => array('g_art2', array_keys(fw_arten2()), 'L_ART2'),
                       'verkn' => array('g_verkn', array('und', 'oder'), 'L_VERKN'),
                       'reihenfolge' => array('g_folge', array('normal', 'usb_zuerst'), 'L_FOLGE'))
                 as $fw_k => $fw_a) {
            if (!in_array($g[$fw_k], $fw_a[1], true)) {
                $fw_fehler[] = sprintf(fw_t('FEHLER.AUSWAHL'), $bez . ' / ' . fw_t('EINST.' . $fw_a[2]),
                                       $g[$fw_k]);
                $fw_falsch[] = $fw_a[0] . '[' . $fw_i . ']';
            }
        }
        foreach (fw_geraet_zahlen() as $fw_f => $fw_d) {
            $w = $fw_zahl($fw_roh_i($fw_d[0], $fw_i), $fw_d[1], $fw_d[2],
                          $bez . ' / ' . fw_t('EINST.L_' . strtoupper($fw_f)),
                          $fw_d[0] . '[' . $fw_i . ']');
            if ($w !== null) { $g[$fw_f] = $w; }
        }

        /* Die Zusammenhaenge der Zeile - dieselbe Pruefstelle wie beim
         * Zurueckspielen (fw_geraet_pruefen in fw_lib.php, U3), dazu der
         * Dienst- und Containername (D5), die Kennung (a3) und die Felder
         * zum Markieren (X-2). */
        $fw_ff = array();
        $fw_fehler = array_merge($fw_fehler, fw_geraet_pruefen($g, $fw_i + 1, $fw_ff));
        foreach ($fw_ff as $fw_k) {
            if (isset($fw_formnamen[$fw_k])) { $fw_falsch[] = $fw_formnamen[$fw_k] . '[' . $fw_i . ']'; }
        }
        /* a1 (Welle 4): wuerde der Waechter Stufe 3 an diesem Verteiler
         * ablehnen? Ein Hinweis, keine Beanstandung - Stufe 1 und 2 bleiben
         * moeglich, gespeichert wird trotzdem. */
        if ($g['name'] !== '' && $g['heilen'] && $g['hoechststufe'] >= 3
            && $g['hub'] !== '' && $g['port'] > 0) {
            $fw_vs = fw_verteiler_sperre($g['hub'], $g['port'], $fw_tabu);
            if ($fw_vs !== '') {
                $fw_vhinweise[] = sprintf(fw_t('EINST.VERTEILER_HINWEIS'), $fw_i + 1, $g['hub'], $fw_vs);
            }
        }
        /* Durch dieselbe Normalisierung wie beim Lesen - und damit durch
         * genau EINE Stelle. Ohne sie stand in der Datei ein leerer Wert,
         * wo ein Auswahlfeld nicht mitgekommen war: gelesen wurde er zwar
         * richtig ergaenzt, aber jedes spaetere Speichern verschob dann
         * Werte, die niemand angefasst hatte. Gefunden hat das
         * Werkzeuge/wirkungstest.py, nicht das Lesen. */
        $fw_neu[$fw_i] = fw_geraet_geradebiegen($g);
    }
    $fw_cfg['geraete'] = $fw_neu;

    foreach (fw_betrieb_zahlen() as $fw_k => $fw_d) {
        $w = $fw_zahl(isset($_POST[$fw_d[0]]) ? $_POST[$fw_d[0]] : '',
                      $fw_d[1], $fw_d[2], fw_t('EINST.L_' . strtoupper($fw_k)), $fw_d[0]);
        if ($w !== null) { $fw_cfg[$fw_k] = $w; }
    }
    /* Bezeichnung: bis 1.0.9 stand hier EINST.L_RUHE_VON bzw. _BIS - die
     * gibt es nicht, und die Beanstandung zeigte den Schluesselnamen. */
    foreach (array('ruhe_von', 'ruhe_bis') as $fw_k) {
        $w = $fw_zeit(isset($_POST[$fw_k]) ? $_POST[$fw_k] : '',
                      fw_t('EINST.L_RUHEFENSTER'), $fw_k);
        if ($w !== null) { $fw_cfg[$fw_k] = $w; }
    }
    /* Nr. 36 b (Stufe 2): die Ansage. Jede Beanstandung verhindert das Speichern (Nr. 16); kein
     * Sprechtoken steht in einer Meldung, ein leeres Tokenfeld heisst "behalten". */
    $fw_tmangel = array();
    $fw_tbean = array();
    $fw_tneu = ansage_formular_lesen($_POST, fw_tts($fw_cfg), $fw_tmangel, $fw_tbean,
                                     array('modi' => fw_ansage_modi()), fw_ansage_k());
    foreach ($fw_tmangel as $fw_tm) { $fw_fehler[] = $fw_tm['text']; }
    foreach ($fw_tbean as $fw_tb) { $fw_falsch[] = $fw_tb; }
    if (!$fw_tmangel) { $fw_cfg['tts'] = $fw_tneu; }
    foreach (fw_ansage_anlaesse() as $fw_ak) { $fw_cfg[$fw_ak] = !empty($_POST[$fw_ak]) ? 1 : 0; }
    $fw_cfg['global_aus']   = !empty($_POST['global_aus']) ? 1 : 0;
    $fw_cfg['melden_aktiv'] = !empty($_POST['melden_aktiv']) ? 1 : 0;
    $fw_cfg['signal_ein']   = !empty($_POST['signal_ein']) ? 1 : 0;
    $fw_sig = isset($_POST['signal_url']) && is_string($_POST['signal_url']) ? trim($_POST['signal_url']) : '';
    if ($fw_sig !== '' && !preg_match('#^https?://[^\s"\']+$#', $fw_sig)) {
        $fw_fehler[] = sprintf(fw_t('FEHLER.SIGNAL_URL'), $fw_sig);
        $fw_falsch[] = 'signal_url';
    } else {
        $fw_cfg['signal_url'] = $fw_sig;
    }
    if ($fw_cfg['signal_ein'] && $fw_cfg['signal_url'] === '') {
        $fw_fehler[] = fw_t('FEHLER.SIGNAL_LEER');
        $fw_falsch[] = 'signal_url';
    }

    /* a1: der Hinweis kommt in jedem Fall - auch wenn eine Beanstandung
     * das Speichern verhindert, gehoert er zu derselben Eingabe. */
    $fw_meldungen = array_merge($fw_meldungen, $fw_vhinweise);
    if (!$fw_fehler) {
        if (fw_config_speichern($fw_cfg)) {
            $fw_meldungen[] = fw_t('ALLG.GESPEICHERT');
            fw_log('Einstellungen gespeichert.');
        } else {
            $fw_fehler[] = fw_t('FEHLER.SPEICHERN');
        }
    } else {
        $fw_eingaben = fw_eingaben_sammeln('settings', $fw_falsch);     // X-2
    }
    $fw_tab = 'tab-settings';
}

/* ---------------- Zeile hinzufuegen / leeren ----------------
 * Eine geleerte Zeile behaelt ihren PLATZ. Wuerde sie herausfallen, ruecken
 * alle folgenden nach - und der virtuelle Eingang im Miniserver zeigte still
 * auf einen anderen Stick. Genau dieser Fehler war der schwerste Befund an
 * 0.9.3. */
if ($fw_post && isset($_POST['zeile_dazu'])) {
    $fw_cfg = fw_config();
    if (count($fw_cfg['geraete']) >= FW_GERAETE_MAX) {
        $fw_fehler[] = sprintf(fw_t('FEHLER.ZU_VIELE'), FW_GERAETE_MAX);
    } else {
        $fw_cfg['zeilen'] = count($fw_cfg['geraete']) + 1;
        $fw_cfg['geraete'][] = fw_geraet_vorgabe();
        /* U5: die Wirkung melden - bis 1.0.8 kam die Erfolgsmeldung auch
         * bei schreibgeschuetzter Konfiguration. */
        if (fw_config_speichern($fw_cfg)) {
            $fw_meldungen[] = sprintf(fw_t('EINST.ZEILE_DAZU_OK'), $fw_cfg['zeilen']);
        } else {
            $fw_fehler[] = fw_t('FEHLER.SPEICHERN');
        }
    }
    $fw_tab = 'tab-settings';
}
if ($fw_post && isset($_POST['zeile_leeren'])) {
    $fw_i = (int) $_POST['zeile_leeren'] - 1;
    $fw_cfg = fw_config();
    if (isset($fw_cfg['geraete'][$fw_i])) {
        $fw_name = $fw_cfg['geraete'][$fw_i]['name'];
        $fw_cfg['geraete'][$fw_i] = fw_geraet_vorgabe();
        if (fw_config_speichern($fw_cfg)) {
            fw_log('Zeile ' . ($fw_i + 1) . ' geleert (' . $fw_name . ').');
            $fw_meldungen[] = sprintf(fw_t('EINST.ZEILE_LEER_OK'), $fw_i + 1);
        } else {
            $fw_fehler[] = fw_t('FEHLER.SPEICHERN');
        }
    }
    $fw_tab = 'tab-settings';
}

/* ---------------- Eine Vorlage in eine Zeile setzen ---------------- */
if ($fw_post && isset($_POST['vorlage_setzen'])) {
    /* Zeile und Vorlage kommen aus ZWEI Feldern desselben Formulars und
     * werden serverseitig zusammengefuehrt. Ein onclick, das den Knopfwert
     * umschreibt, waere ohne JavaScript wirkungslos - und die Seite soll
     * auch ohne bedienbar bleiben. */
    $fw_i = (int) (isset($_POST['vorlage_zeile']) ? $_POST['vorlage_zeile'] : 0) - 1;
    $fw_v = (string) $_POST['vorlage_setzen'];
    $fw_alle = fw_vorlagen();
    $fw_cfg = fw_config();
    if (!isset($fw_alle[$fw_v]) || !isset($fw_cfg['geraete'][$fw_i])) {
        $fw_fehler[] = fw_t('VORL.UNBEKANNT');
    } else {
        $g = $fw_cfg['geraete'][$fw_i];
        if (trim((string) $g['name']) === '') {
            $g['name'] = fw_klartext($fw_alle[$fw_v]['text']);
        }
        foreach ($fw_alle[$fw_v]['werte'] as $k => $w) { $g[$k] = $w; }
        /* Ohne einen einzigen Hebel (Dienst, Container, USB-Pfad, Verteiler)
         * kann die Zeile nicht heilen - dann Heilen aus, sonst beanstandet die
         * eigene Pruefung (FEHLER.KEIN_HEBEL) die Zeile, und die Sicherung
         * liesse sich nicht mehr zurueckspielen (Vorlage "seriell",
         * Pruefung 29.09.2026). Eine Zeile MIT Hebel bleibt unberuehrt. */
        if ((string) $g['dienst'] === '' && (string) $g['container'] === ''
            && (string) $g['usb_pfad'] === '' && (string) $g['hub'] === '') {
            $g['heilen'] = 0;
        }
        $fw_cfg['geraete'][$fw_i] = fw_geraet_geradebiegen($g);
        if (fw_config_speichern($fw_cfg)) {
            $fw_meldungen[] = sprintf(fw_t('VORL.GESETZT'), $fw_i + 1,
                                      fw_klartext($fw_alle[$fw_v]['text']));
            $fw_meldungen[] = fw_t('VORL.PRUEFEN');
        } else {
            $fw_fehler[] = fw_t('FEHLER.SPEICHERN');
        }
    }
    $fw_tab = 'tab-settings';
}

/* ---------------- Speichern: MQTT ---------------- */
if ($fw_post && isset($_POST['speichern_mqtt'])) {
    $fw_cfg = fw_config();
    /* M5: das bisherige Praefix und der bisherige Schalter - wechselt das
     * Praefix oder geht MQTT aus, raeumt der Waechter darunter ab. */
    $fw_alt_praefix = (string) $fw_cfg['mqtt_topic'];
    $fw_alt_ein = (int) $fw_cfg['mqtt_ein'];
    $fw_cfg['mqtt_ein'] = !empty($_POST['mqtt_ein']) ? 1 : 0;
    /* Nr. 19 (Welle 4): still bleibt nur das Kleinschreiben und der
     * Leerraum am Rand. Bis 1.0.9 wurde ein leeres Thema still
     * "funkwacht", ein Schraegstrich am Rand still entfernt und ein
     * Anfuehrungszeichen still geloescht. */
    $fw_thema = isset($_POST['mqtt_topic']) && is_string($_POST['mqtt_topic'])
              ? strtolower(trim($_POST['mqtt_topic'])) : null;
    if ($fw_thema === null) {
        $fw_fehler[] = sprintf(fw_t('FEHLER.KEIN_TEXT'), fw_t('MQTT.THEMA'));
        $fw_falsch[] = 'mqtt_topic';
    } elseif ($fw_thema === '') {
        $fw_fehler[] = fw_t('FEHLER.THEMA_LEER');
        $fw_falsch[] = 'mqtt_topic';
    } elseif (!preg_match('#^[a-z0-9_\-/]+$#', $fw_thema)) {
        // Ein Thema mit + oder # ist ein Filtermuster und als Ziel unbrauchbar.
        $fw_fehler[] = sprintf(fw_t('FEHLER.THEMA'), $fw_thema);
        $fw_falsch[] = 'mqtt_topic';
    } elseif (trim($fw_thema, '/') !== $fw_thema) {
        $fw_fehler[] = sprintf(fw_t('FEHLER.THEMA_RAND'), $fw_thema);
        $fw_falsch[] = 'mqtt_topic';
    } else {
        $fw_cfg['mqtt_topic'] = $fw_thema;
    }
    foreach (array('broker_host' => 'L_HOST', 'broker_user' => 'L_USER', 'broker_id' => 'L_ID')
             as $fw_k => $fw_l) {
        $w = $fw_text(isset($_POST[$fw_k]) ? $_POST[$fw_k] : '', fw_t('MQTT.' . $fw_l), $fw_k);
        if ($w !== null) { $fw_cfg[$fw_k] = $w; }
    }
    $fw_bp = isset($_POST['broker_port']) && is_string($_POST['broker_port']) ? trim($_POST['broker_port']) : '';
    if ($fw_bp !== '' && (!ctype_digit($fw_bp) || (int) $fw_bp < 1 || (int) $fw_bp > 65535)) {
        $fw_fehler[] = sprintf(fw_t('FEHLER.BROKER_PORT'), $fw_bp);
        $fw_falsch[] = 'broker_port';
    } else {
        $fw_cfg['broker_port'] = $fw_bp;
    }
    /* Ein leeres Kennwortfeld LOESCHT NICHTS - sonst verliert jedes Speichern
     * das Kennwort, weil der Browser es nicht zurueckschickt. Geloescht wird
     * nur ueber den ausdruecklichen Haken. */
    $fw_bpw = isset($_POST['broker_pass']) && is_string($_POST['broker_pass']) ? $_POST['broker_pass'] : '';
    if (!empty($_POST['broker_pass_weg'])) {
        $fw_cfg['broker_pass'] = '';
    } elseif (trim($fw_bpw) !== '') {
        /* U7 (Pruefung 29.09.2026): ein Kennwort wird NIE veraendert. Bis
         * 1.0.8 entfernte $fw_sauber Anfuehrungszeichen - aus geheim"123
         * wurde geheim123, und der Mithoerer meldete sich mit dem falschen
         * an. Anfuehrungszeichen und Leerzeichen traegt der Weg zum Broker
         * (paho 1.6.1 gemessen, proben/u7); abgewiesen werden nur
         * Steuerzeichen. */
        if (fw_steuerzeichen($fw_bpw)) {
            $fw_fehler[] = fw_t('FEHLER.KENNWORT_ZEICHEN');
        } else {
            $fw_cfg['broker_pass'] = $fw_bpw;
        }
    }
    if (!$fw_fehler) {
        if (fw_config_speichern($fw_cfg)) {
            $fw_meldungen[] = fw_t('ALLG.GESPEICHERT');
            $fw_meldungen[] = fw_t('MQTT.NEUSTART_NOETIG');
            fw_log('MQTT-Einstellungen gespeichert.');
            if ($fw_alt_praefix !== '' && ($fw_cfg['mqtt_topic'] !== $fw_alt_praefix
                                           || ($fw_alt_ein && !$fw_cfg['mqtt_ein']))) {
                if (fw_altpraefix_merken($fw_alt_praefix)) {
                    $fw_meldungen[] = sprintf(fw_t('MQTT.ALT_ABRAEUMEN'), $fw_alt_praefix);
                } else {
                    $fw_misslungen[] = sprintf(fw_t('MQTT.ALT_FEHL'), $fw_alt_praefix);
                }
            }
        } else {
            $fw_fehler[] = fw_t('FEHLER.SPEICHERN');
        }
    } else {
        $fw_eingaben = fw_eingaben_sammeln('mqtt', $fw_falsch);     // X-2
    }
    $fw_tab = 'tab-mqtt';
}

/* ---------------- Zurueckspielen ---------------- */
if ($fw_post && isset($_POST['zurueckspielen'])) {
    /* Ohne Haekchen geschieht nichts - und zwar bevor die Datei ueberhaupt
     * angesehen wird, damit kein halb zurueckgespielter Zustand entstehen
     * kann. */
    if (empty($_POST['sicher_zurueck'])) {
        $fw_fehler[] = fw_t('ALLG.OHNE_HAKEN');
        $fw_tab = 'tab-settings';
    } elseif (!isset($_FILES['sicherungsdatei']) || !is_uploaded_file($_FILES['sicherungsdatei']['tmp_name'])) {
        $fw_fehler[] = fw_t('SICH.KEINE_DATEI');
    } else {
        $fw_roh = (string) @file_get_contents($_FILES['sicherungsdatei']['tmp_name']);
        list($fw_ok, $fw_text, $fw_liste) = fw_sicherung_lesen($fw_roh);
        if ($fw_ok) {
            $fw_meldungen[] = $fw_text;
            /* U10: den Waechter nachziehen und sagen, was geschah. Er liest
             * die Konfiguration zwar in jedem Durchlauf neu, der Mithoerer
             * aber nicht die Broker-Angaben. */
            if (fw_dienst_pid() > 0) {
                list($fw_ok2, $fw_text2) = fw_dienst_schalten('restart');
                if ($fw_ok2) {
                    $fw_meldungen[] = sprintf(fw_t('SICH.DIENST_NEU'), fw_dienst_pid());
                } else {
                    $fw_misslungen[] = sprintf(fw_t('SICH.DIENST_FEHL'), $fw_text2);
                }
                fw_log('Nach dem Zurueckspielen: Waechter neu gestartet -> ' . ($fw_ok2 ? 'ok' : 'fehl'));
            } else {
                $fw_meldungen[] = fw_t('SICH.DIENST_LIEF_NICHT');
            }
        } else {
            $fw_fehler[] = $fw_text;
            foreach ($fw_liste as $fw_z) { $fw_fehler[] = $fw_z; }
        }
    }
    $fw_tab = 'tab-settings';
}

/* ---------------- Neues Wortzeichen ---------------- */
if ($fw_post && isset($_POST['token_neu']) && empty($_POST['sicher_token'])) {
    $fw_fehler[] = fw_t('ALLG.OHNE_HAKEN');
    $fw_tab = 'tab-loxone';
} elseif ($fw_post && isset($_POST['token_neu'])) {
    $fw_cfg = fw_config();
    $fw_cfg['aktionstoken'] = fw_token_erzeugen();
    if (fw_config_speichern($fw_cfg)) {
        $fw_merkmal = fw_formtoken();   // das Merkmal haengt daran und wechselt mit
        $fw_meldungen[] = fw_t('LOX.TOKEN_NEU_OK');
        fw_log('Neues Wortzeichen erzeugt.');
    } else {
        $fw_fehler[] = fw_t('FEHLER.SPEICHERN');
    }
    $fw_tab = 'tab-loxone';
}

/* ---------------- Den Waechter schalten ---------------- */
if ($fw_post && isset($_POST['dienst'])) {
    list($fw_ok, $fw_text) = fw_dienst_schalten((string) $_POST['dienst']);
    /* Die Wirkung melden, nicht die Absicht: fw_dienst_schalten() liest die
     * Prozessnummer nach dem Schalten neu. */
    if ($fw_ok) {
        $fw_meldungen[] = sprintf(fw_t('DIENST.OK'),
            fw_t('DIENST.' . strtoupper((string) $_POST['dienst'])));
    } else {
        $fw_misslungen[] = sprintf(fw_t('DIENST.FEHL'),
            fw_t('DIENST.' . strtoupper((string) $_POST['dienst'])), $fw_text);
    }
    fw_log('Waechter geschaltet: ' . (string) $_POST['dienst'] . ' -> ' . ($fw_ok ? 'ok' : 'fehl'));
    $fw_tab = 'tab-settings';
}

/* ---------------- Quittieren, Wartung, Statistik ---------------- */
if ($fw_post && isset($_POST['quittieren'])) {
    $fw_nr = (int) $_POST['quittieren'];
    if (fw_auftrag('quittieren', $fw_nr)) {
        $fw_meldungen[] = sprintf(fw_t('AUFTR.QUITTIERT'),
            $fw_nr ? sprintf(fw_t('AUFTR.STICK'), $fw_nr) : fw_t('AUFTR.ALLE'),
            fw_auftrag_wartezeit());
    } else {
        $fw_misslungen[] = fw_t('AUFTR.FEHL');
    }
    $fw_tab = 'tab-test';
}
if ($fw_post && isset($_POST['wartung'])) {
    /* Nr. 19 (Welle 4): eine Dauer ausserhalb 0..1440 wird nicht mehr still
     * geklemmt, sondern abgewiesen. */
    $fw_wroh = is_string($_POST['wartung']) ? trim($_POST['wartung']) : '';
    if (!preg_match('/^[0-9]{1,4}$/', $fw_wroh) || (int) $fw_wroh > 1440) {
        $fw_misslungen[] = sprintf(fw_t('AUFTR.DAUER_FALSCH'), $fw_wroh);
    } elseif (fw_auftrag('wartung', 0, (int) $fw_wroh)) {
        $fw_dauer = (int) $fw_wroh;
        /* X-7: von Hand geschaltet - der Gleichwert-Merker des Endpunkts
         * gilt nicht mehr, sonst unterdrueckte er den naechsten echten
         * Wechsel aus Loxone. */
        fw_gleichwert_vergessen('wartung');
        $fw_meldungen[] = $fw_dauer
            ? sprintf(fw_t('AUFTR.WARTUNG_EIN'), $fw_dauer, fw_auftrag_wartezeit())
            : sprintf(fw_t('AUFTR.WARTUNG_AUS'), fw_auftrag_wartezeit());
    } else {
        $fw_misslungen[] = fw_t('AUFTR.FEHL');
    }
    $fw_tab = 'tab-test';
}
if ($fw_post && isset($_POST['statistik_weg'])) {
    if (empty($_POST['sicher_statistik'])) {
        $fw_fehler[] = fw_t('ALLG.OHNE_HAKEN');
    } elseif (fw_auftrag('statistik_zuruecksetzen')) {
        $fw_meldungen[] = sprintf(fw_t('AUFTR.STATISTIK'), fw_auftrag_wartezeit());
    } else {
        $fw_misslungen[] = fw_t('AUFTR.FEHL');
    }
    $fw_tab = 'tab-test';
}

/* ---------------- Protokoll leeren ---------------- */
if ($fw_post && isset($_POST['log_leeren']) && empty($_POST['sicher_log'])) {
    /* b1 (Welle 4, Regeln/04 "Formregeln fuer einen loeschenden Knopf"):
     * ohne Haekchen geschieht nichts. */
    $fw_fehler[] = fw_t('ALLG.OHNE_HAKEN');
    $fw_tab = 'tab-log';
} elseif ($fw_post && isset($_POST['log_leeren'])) {
    /* U11: die Wirkung pruefen - bis 1.0.8 hiess es "geleert", auch wenn
     * das Schreiben scheiterte. */
    $fw_leer_ok = $fw_p['log'] !== '' && @file_put_contents($fw_p['log'], '') !== false;
    clearstatcache(true, $fw_p['log']);
    if ($fw_leer_ok && is_file($fw_p['log']) && filesize($fw_p['log']) === 0) {
        fw_log('Protokoll geleert.');
        $fw_meldungen[] = fw_t('LOG.GELEERT');
    } else {
        $fw_misslungen[] = sprintf(fw_t('LOG.LEEREN_FEHL'), $fw_p['log']);
    }
    $fw_tab = 'tab-log';
}

/* ---------------- Testansage (Nr. 36 b) ----------------
 * Ins Protokoll nur die Kurzform ohne Text und Token. */
if ($fw_post && isset($_POST['ansage_test'])) {
    $fw_ak = fw_ansage_k();
    $fw_ar = ansage_testansage(fw_tts(fw_config()), $fw_ak);
    fw_log('Testansage: ' . ansage_kurz($fw_ar));
    if ($fw_ar['stand'] === 1) {
        $fw_meldungen[] = fw_t('DURCHSAGE.M_TEST_OK');
    } elseif ($fw_ar['stand'] === -1) {
        $fw_meldungen[] = sprintf(fw_t('DURCHSAGE.M_TEST_NICHTS'), ansage_kennung_text($fw_ar['kennung'], $fw_ak));
    } else {
        $fw_misslungen[] = sprintf(fw_t('DURCHSAGE.M_TEST_FEHL'), ansage_kennung_text($fw_ar['kennung'], $fw_ak));
    }
    $fw_tab = 'tab-test';
}

/* ---------------- Test ---------------- */
if ($fw_post && isset($_POST['test'])) {
    /* Nummer und Stufe kommen aus zwei Feldern und werden hier
     * zusammengefuehrt - siehe die Begruendung bei den Vorlagen. */
    $fw_wert = isset($_POST['testwert']) ? (string) $_POST['testwert'] : '';
    if ((string) $_POST['test'] === 'heilen') {
        $fw_stufe = isset($_POST['heilstufe']) ? trim((string) $_POST['heilstufe']) : '';
        if ($fw_stufe !== '' && preg_match('/^[1-3]$/', $fw_stufe)) {
            $fw_wert .= ':' . $fw_stufe;
        }
    }
    $fw_testausgabe = fw_test_ausfuehren((string) $_POST['test'], $fw_wert);
    $fw_tab = 'tab-test';
}

/* ---------------- Nach dem POST: umleiten (U8) ----------------
 * Regeln/04: jeder POST-Handler endet mit 303 auf index.php?form=<reiter>,
 * das Ergebnis reist als Einmalmeldung. Bis 1.0.8 wurde direkt nach dem
 * POST gerendert; F5 wiederholte jede Handlung (Zeilen 8 -> 9 -> 10, zwei
 * Quittier-Auftraege, zweiter Heilversuch), und nach dem Zurueckspielen
 * trug die Seite noch das alte Formularmerkmal (U4). Downloads haben
 * oben schon mit exit geantwortet. Scheitert die Einmalmeldung, wird
 * ohne Umleitung gerendert und das gesagt. Ein vom Wachposten
 * abgewiesenes Formular hat nichts ausgeloest und rendert direkt. */
if ($fw_post) {
    if (fw_einmal_schreiben($fw_meldungen, $fw_fehler, $fw_misslungen, $fw_testausgabe, $fw_eingaben)) {
        header('Location: index.php?form=' . rawurlencode(substr($fw_tab, 4)), true, 303);
        exit;
    }
    $fw_misslungen[] = fw_t('ALLG.EINMAL_FEHL');
}

/* ================= Werte fuer die Anzeige ================= */
$fw_cfg = fw_config();
$fw_stand = fw_stand();
$fw_geraete = fw_geraete();
$fw_mqtt = fw_mqtt_zustand();
$fw_faehig = fw_faehigkeiten();
$fw_pid = fw_dienst_pid();
$fw_mpid = fw_mithoerer_pid();
$fw_logzeilen = fw_log_ende($fw_p['log'], 400);
$fw_arten_wahl = fw_arten();
$fw_arten_zwei = fw_arten2();
$fw_hist = fw_historie();
$fw_anzahl = count($fw_cfg['geraete']);
$fw_braucht_mithoerer = false;
foreach ($fw_geraete as $fw_g) {
    if ($fw_g['art'] === 'mqtt' || $fw_g['art2'] === 'mqtt') { $fw_braucht_mithoerer = true; }
}

$fw_rahmen = class_exists('LBWeb', false);
if ($fw_rahmen) {
    LBWeb::lbheader(fw_t('ALLG.TITEL'), 'https://wiki.loxberry.de/', 'help.html');
}
?>
<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
/* Bedienelemente werden von jQuery Mobile umgebaut und bekommen einen eigenen
   Behaelter. Begrenzt man das Feld selbst, bleibt der Behaelter breit - man
   sieht ein schmales Feld in einem breiten weissen Kasten. Und beim
   Auswahlfeld liegt das unsichtbare <select> ueber dem Knopf und faengt die
   Klicks ab; wer es gestaltet, schiebt es weg. Deshalb wird ausschliesslich
   der Behaelter begrenzt. */
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
/* Eine Tabelle mit Eingabefeldern oder mit mehr als sechs Spalten kommt in
   einen eigenen Rollbehaelter. Ohne ihn ist auf einem schmalen Schirm die
   letzte Spalte unerreichbar. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. Die
   Hover-Farben unten sind kein Feinschliff, sondern Pflicht. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
/* Ein kleiner Knopf in einer Tabellenzeile - er soll die Zeile nicht sprengen. */
.sm-wrap button.sm-klein { min-width: 0 !important; padding: 3px 8px !important;
    font-size: 0.82em !important; }
/* Statuskacheln — bewusst ein anderer Name als sm-knopfreihe.
   Beide zu verwechseln hat am 26.07.2026 die Statusanzeige zerlegt. */
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }

.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
/* Eigene Hover- und Fokusfarben je Gruppe - sonst uebernimmt der Rahmen. */
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Reiterinhalte: nur der aktive ist sichtbar.
   Ohne diese zwei Zeilen stehen alle fuenf Reiter untereinander.
   MIT ihnen und OHNE serverseitiges sm-active ist die Seite dagegen
   vollstaendig leer, sobald das Skript nicht laeuft. */
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }

/* Nachgetragene Definitionen (CSS-Luecken-Durchgang 13.08.2026):
   benutzt, aber nie definiert - wortgleich aus der Hausstandard-Vorlage
   bzw. der Referenzimplementierung uebernommen. */
/* sm-haken: eigenes Kuerzel dieser Linie - das Bestaetigungshaekchen vor
   einem Knopf, der etwas Unwiederbringliches tut. Steht neben dem Knopf und
   nicht darueber, damit beide zusammen gelesen werden. */
.sm-wrap label.sm-haken { display: inline-flex; align-items: center; gap: 6px;
    font-size: 0.9em; color: #555; margin: 0 4px 0 0; white-space: nowrap; }
.sm-wrap label.sm-haken input { margin: 0; }
.sm-log { background: #1e1e1e; color: #ddd; font-family: monospace; font-size: 0.82em;
  padding: 10px; border-radius: 6px; max-height: 460px; overflow: auto; white-space: pre-wrap; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. Nachgezogen am
   05.09.2026 nach Regeln/04; Wortlaut aus VORLAGE_hausstandard.css.html.

   Am Geraet gemessen (LoxBerry 4.0.0.15, components.css): die Rahmen-CSS
   zeichnet seit der neuen Oberflaeche selbst einen Pfeil - Regel
   ".lb-content select". Darauf kann sich eine Plugin-Oberflaeche nicht
   verlassen: die Regel gibt es erst seit dieser Fassung, und die eigene
   Feldregel loescht sie, sobald sie die Kurzform "background:" benutzt.
   Dann steht ein Auswahlfeld da, das aussieht wie ein Textfeld.

   Die Raute im SVG wird als %23 geschrieben: eine rohe Raute beendet in
   einer CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
/* Ergaenzung (Welle 4, 01.10.2026, nicht aus der Vorlage): X-2 markiert das
   beanstandete Feld. background-color statt der Kurzform - die Kurzform
   loeschte den Pfeil des Auswahlfelds (siehe oben). */
.sm-wrap input.sm-beanstandet, .sm-wrap select.sm-beanstandet {
    border: 2px solid #b00000 !important; background-color: #fff5f4 !important; }

</style>

<div class="sm-wrap">

<?php if (fw_sprache_fehlt()) { ?>
<!-- Bewusst fest im Quelltext: wenn diese Meldung noetig ist, kann fw_t()
     nichts uebersetzen. -->
<div class="sm-warnung"><b>Die Sprachdateien wurden nicht gefunden.</b>
  Unten stehen deshalb nur die Schl&uuml;ssel statt der Texte. Erwartet werden sie unter
  <span class="sm-mono">&lt;LoxBerry&gt;/templates/plugins/<?= fw_e($fw_p['plugin']) ?>/lang/</span>.
  Meist hilft ein erneutes Installieren des Plugins.</div>
<?php } ?>

<?php if ($fw_meldungen) { ?>
<div class="sm-hinweis"><?= implode('<br>', array_map('fw_e', $fw_meldungen)) ?></div>
<?php } ?>
<?php if ($fw_fehler) { ?>
<div class="sm-warnung"><b><?= fw_e(fw_t('ALLG.BEANSTANDUNG')) ?></b><br><?= implode('<br>', array_map('fw_e', $fw_fehler)) ?></div>
<?php } ?>
<?php if ($fw_misslungen) { ?>
<div class="sm-warnung"><b><?= fw_e(fw_t('ALLG.MISSLUNGEN')) ?></b><br><?= implode('<br>', array_map('fw_e', $fw_misslungen)) ?></div>
<?php } ?>

<div class="sm-kacheln">
  <div class="sm-kachel"><?= fw_e(fw_t('ALLG.DIENST')) ?>
    <b id="fw_k_dienst" class="<?= $fw_pid ? 'sm-an' : 'sm-aus' ?>"><?= $fw_pid ? fw_e(fw_t('ALLG.LAEUFT')) : fw_e(fw_t('ALLG.GESTOPPT')) ?></b>
    <span class="sm-hilfe" id="fw_k_pid"><?= $fw_pid ? 'PID ' . (int) $fw_pid : fw_e(fw_t('ALLG.KEINE_PID')) ?></span>
  </div>
  <div class="sm-kachel"><?= fw_e(fw_t('ALLG.GERAETE')) ?>
    <b><?= count($fw_geraete) ?></b>
    <span class="sm-hilfe"><?= fw_e(fw_t('ALLG.UEBERWACHT')) ?></span>
  </div>
  <div class="sm-kachel"><?= fw_e(fw_t('ALLG.GESTOERT')) ?>
    <b id="fw_k_krank" class="<?= !empty($fw_stand['krank']) ? 'sm-aus' : 'sm-an' ?>"><?= isset($fw_stand['krank']) ? (int) $fw_stand['krank'] : 0 ?></b>
    <span class="sm-hilfe"><?= fw_e(fw_t('ALLG.GERADE')) ?></span>
  </div>
  <div class="sm-kachel"><?= fw_e(fw_t('ALLG.GEHEILT')) ?>
    <b id="fw_k_geheilt"><?= isset($fw_stand['geheilt_gesamt']) ? (int) $fw_stand['geheilt_gesamt'] : 0 ?></b>
    <span class="sm-hilfe"><?= fw_e(fw_t('ALLG.SEIT_BEGINN')) ?></span>
  </div>
  <div class="sm-kachel"><?= fw_e(fw_t('ALLG.VERSUCHE')) ?>
    <b id="fw_k_versuche"><?= isset($fw_stand['versuche_gesamt']) ? (int) $fw_stand['versuche_gesamt'] : 0 ?></b>
    <span class="sm-hilfe"><?= fw_e(fw_t('ALLG.DAVON')) ?></span>
  </div>
  <div class="sm-kachel"><?= fw_e(fw_t('ALLG.LETZTE_PRUEFUNG')) ?>
    <b id="fw_k_alter"><?= fw_alter() < 0 ? '&ndash;' : (int) fw_alter() ?></b>
    <span class="sm-hilfe"><?= fw_alter() < 0 ? fw_e(fw_t('ALLG.NIE')) : fw_e(fw_t('ALLG.SEKUNDEN')) ?></span>
  </div>
</div>

<?php if (!empty($fw_stand['sperre'])) { ?>
<div class="sm-warnung" id="fw_sperre"><?= sprintf(fw_t('EINST.SPERRE_LAEUFT'),
    fw_e(fw_t('WARUM.' . strtoupper($fw_stand['sperre'])))) ?></div>
<?php } ?>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. So bleibt jeder
     Reiter verlinkbar und die Seite ohne Skript bedienbar. Welcher Reiter
     offen ist, entscheidet der SERVER. Ausgeschrieben, nicht erzeugt. -->
<div class="sm-tabs">
	<a class="sm-tab<?= $fw_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
	   href="index.php?form=settings"><?= fw_e(fw_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $fw_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"
	   href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab<?= $fw_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
	   href="index.php?form=loxone"><?= fw_e(fw_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $fw_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
	   href="index.php?form=test"><?= fw_e(fw_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $fw_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
	   href="index.php?form=log"><?= fw_e(fw_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $fw_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<h2><?= fw_e(fw_t('EINST.H_LAGE')) ?></h2>
<div class="sm-step"><?= fw_t('EINST.LAGE_ERKLAERUNG') ?></div>

<?php if (!empty($fw_stand['geraete'])) { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= fw_e(fw_t('TAB.NR')) ?></th><th><?= fw_e(fw_t('TAB.GERAET')) ?></th><th><?= fw_e(fw_t('TAB.ZUSTAND')) ?></th>
    <th><?= fw_e(fw_t('TAB.ALTER')) ?></th><th><?= fw_e(fw_t('TAB.STUFE')) ?></th>
    <th><?= fw_e(fw_t('TAB.HEILUNGEN')) ?></th><th><?= fw_e(fw_t('TAB.VERSUCHE')) ?></th>
    <th><?= fw_e(fw_t('TAB.LETZTE_TAT')) ?></th></tr>
<?php foreach ($fw_stand['geraete'] as $fw_nr => $fw_ee) { ?>
<tr>
  <td><?= (int) $fw_nr ?></td>
  <td><b><?= fw_e($fw_ee['name']) ?></b></td>
  <td><b class="<?= $fw_ee['ok'] ? 'sm-an' : 'sm-aus' ?>"><?= $fw_ee['ok'] ? fw_e(fw_t('ALLG.GESUND')) : fw_e(fw_t('ALLG.GESTOERT')) ?></b>
      <br><span class="sm-hilfe"><?= fw_e(fw_t('GRUND.' . strtoupper($fw_ee['grund']))) ?></span></td>
  <td><?= (int) $fw_ee['alter'] < 0 ? '&ndash;' : (int) $fw_ee['alter'] . ' s' ?>
      <?php if (!empty($fw_ee['seit'])) { ?><br><span class="sm-hilfe"><?= sprintf(fw_e(fw_t('TAB.SEIT')), (int) $fw_ee['seit']) ?></span><?php } ?></td>
  <td><?= fw_e(fw_t('STUFE.' . (int) $fw_ee['stufe'])) ?></td>
  <td><?= (int) (isset($fw_ee['heilungen']) ? $fw_ee['heilungen'] : 0) ?>
      <?php if (!empty($fw_ee['heil7t'])) { ?><br><span class="sm-hilfe"><?= sprintf(fw_e(fw_t('TAB.HEIL7T')), (int) $fw_ee['heil7t']) ?></span><?php } ?></td>
  <td><?= (int) (isset($fw_ee['versuche']) ? $fw_ee['versuche'] : 0) ?><?php
      if (!empty($fw_ee['abgelehnt'])) { ?><br><span class="sm-hilfe"><?= sprintf(fw_e(fw_t('TAB.ABGELEHNT')), (int) $fw_ee['abgelehnt']) ?></span><?php } ?></td>
  <td><span class="sm-hilfe"><?= fw_e($fw_ee['letzte_tat']) ?>
    <?php if (!empty($fw_ee['warum'])) { ?><br><?= fw_e(fw_t('WARUM.' . strtoupper($fw_ee['warum']))) ?><?php } ?>
    <?php if (!empty($fw_ee['bemerkung'])) { ?><br><?= fw_e($fw_ee['bemerkung']) ?><?php } ?>
  </span></td>
</tr>
<?php } ?>
</table>
</div>
<?php if (!empty($fw_stand['tabu'])) { ?>
<div class="sm-hinweis"><?= sprintf(fw_t('EINST.TABU'), fw_e(implode(', ', $fw_stand['tabu']))) ?></div>
<?php } ?>
<?php } else { ?>
<div class="sm-hinweis"><?= fw_t('EINST.NOCH_NICHTS') ?></div>
<?php } ?>

<?php if ($fw_faehig) { ?>
<div class="sm-<?= empty($fw_faehig['uhubctl_haehne']) ? 'warnung' : 'hinweis' ?>">
<?= sprintf(fw_t('EINST.FAEHIGKEIT'),
    fw_e(!empty($fw_faehig['sudo']) ? fw_t('ALLG.JA') : fw_t('ALLG.NEIN')),
    fw_e(!empty($fw_faehig['uhubctl']) ? fw_t('ALLG.JA') : fw_t('ALLG.NEIN')),
    fw_e(!empty($fw_faehig['uhubctl_haehne']) ? implode(', ', $fw_faehig['uhubctl_haehne']) : fw_t('ALLG.KEINE'))) ?>
</div>
<?php } ?>

<?php
/* Docker-2 (Welle 4): Zeilen der Art "Docker" brauchen Docker und den
 * Zugriff darauf (docker inspect ohne sudo). Fehlt eines, sagt es die Seite
 * hier - getrennt nach "fehlt" und "kein Zugriff" - und verweist auf
 * Docker NG. Die Funkwacht legt selbst keine Container an. */
$fw_dzeilen = array();
foreach ($fw_geraete as $fw_nr => $fw_g) {
    if ($fw_g['art'] === 'docker' || $fw_g['art2'] === 'docker') { $fw_dzeilen[] = (int) $fw_nr; }
}
if ($fw_dzeilen) {
    list($fw_dlage, $fw_dgrund) = fw_docker_lage(5);
    if ($fw_dlage === 'fehlt') { ?>
<div class="sm-warnung" id="fw_docker"><?= sprintf(fw_t('EINST.DOCKER_FEHLT'), fw_e(implode(', ', $fw_dzeilen))) ?></div>
<?php } elseif ($fw_dlage === 'kein_zugriff') { ?>
<div class="sm-warnung" id="fw_docker"><?= sprintf(fw_t('EINST.DOCKER_KEIN_ZUGRIFF'), fw_e(implode(', ', $fw_dzeilen)), fw_e($fw_dgrund)) ?></div>
<?php }
} ?>

<h3><?= fw_e(fw_t('DIENST.H')) ?></h3>
<p class="sm-hilfe"><?= fw_t('DIENST.ERKLAERUNG') ?>
<?php if ($fw_braucht_mithoerer) { ?>
<br><?= sprintf(fw_t('DIENST.MITHOERER'),
    $fw_mpid ? fw_e(fw_t('ALLG.LAEUFT')) . ' (PID ' . (int) $fw_mpid . ')'
             : fw_e(fw_t('ALLG.GESTOPPT'))) ?>
<?php } ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= fw_t('LEGENDE.LESEN_START') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= fw_t('LEGENDE.AKTION_DIENST') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dienst" value="start"><?= fw_e(fw_t('DIENST.K_START')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="restart"><?= fw_e(fw_t('DIENST.K_RESTART')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="stop"><?= fw_e(fw_t('DIENST.K_STOP')) ?></button>
  </form>
</div>

<h3><?= fw_e(fw_t('VORL.H')) ?></h3>
<div class="sm-step"><?= fw_t('VORL.ERKLAERUNG') ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= fw_e(fw_t('VORL.SP_FALL')) ?></th><th><?= fw_e(fw_t('VORL.SP_WERTE')) ?></th>
    <th><?= fw_e(fw_t('VORL.SP_ZEILE')) ?></th></tr>
<?php foreach (fw_vorlagen() as $fw_vk => $fw_vv) { ?>
<tr>
  <td><?= fw_e(fw_t($fw_vv['text'])) ?></td>
  <td><span class="sm-hilfe"><?php
      $fw_t2 = array();
      foreach ($fw_vv['werte'] as $fw_wk => $fw_wv) {
          if ($fw_wv === '' || $fw_wv === 0) { continue; }
          $fw_t2[] = $fw_wk . '=' . $fw_wv;
      }
      echo fw_e(implode('  ', $fw_t2)); ?></span></td>
  <td><form action="index.php" method="post" style="display:inline-flex;gap:4px;align-items:center;">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <select data-role="none" name="vorlage_zeile">
<?php for ($fw_i = 1; $fw_i <= $fw_anzahl; $fw_i++) { ?>
      <option value="<?= $fw_i ?>"><?= $fw_i ?></option>
<?php } ?>
    </select>
    <input data-role="none" type="hidden" name="aktion" value="vorlage_setzen"><button data-role="none" class="sm-btn sm-b-technik sm-klein" type="submit"
            name="vorlage_setzen" value="<?= fw_e($fw_vk) ?>"><?= fw_e(fw_t('VORL.K_SETZEN')) ?></button>
  </form></td>
</tr>
<?php } ?>
</table>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= fw_t('LEGENDE.TECHNIK_VORLAGE') ?></span>
</div>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="<?= fw_e($fw_tab) ?>">
<input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">

<h2><?= fw_e(fw_t('EINST.H_GERAETE')) ?></h2>
<div class="sm-step"><?= fw_t('EINST.GERAETE_ERKLAERUNG') ?></div>

<?php for ($fw_i = 0; $fw_i < $fw_anzahl; $fw_i++) { $g = $fw_cfg['geraete'][$fw_i]; ?>
<h3><?= fw_e(fw_t('EINST.GERAET')) ?> <?= $fw_i + 1 ?><?= $g['name'] !== '' ? ': ' . fw_e($g['name']) : '' ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<tr>
  <td><label><?= fw_e(fw_t('EINST.L_NAME')) ?><br>
    <input data-role="none" type="text" size="16" name="g_name[<?= $fw_i ?>]" value="<?= fw_e(fw_ew('settings', "g_name[$fw_i]", $g['name'])) ?>"<?= fw_ek('settings', "g_name[$fw_i]") ?>></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_ART')) ?><br>
    <select data-role="none" name="g_art[<?= $fw_i ?>]"<?= fw_ek('settings', "g_art[$fw_i]") ?>>
<?php foreach ($fw_arten_wahl as $fw_a => $fw_as) { ?>
      <option value="<?= fw_e($fw_a) ?>"<?= fw_ew('settings', "g_art[$fw_i]", $g['art']) === (string) $fw_a ? ' selected' : '' ?>><?= fw_e(fw_t($fw_as)) ?></option>
<?php } ?>
    </select></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_PFAD')) ?><br>
    <input data-role="none" type="text" size="26" name="g_pfad[<?= $fw_i ?>]" value="<?= fw_e(fw_ew('settings', "g_pfad[$fw_i]", $g['pfad'])) ?>"<?= fw_ek('settings', "g_pfad[$fw_i]") ?>></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_THEMA')) ?><br>
    <input data-role="none" type="text" size="22" name="g_thema[<?= $fw_i ?>]" value="<?= fw_e(fw_ew('settings', "g_thema[$fw_i]", $g['thema'])) ?>"<?= fw_ek('settings', "g_thema[$fw_i]") ?>></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_HOECHSTALTER')) ?><br>
    <input data-role="none" type="text" size="5" name="g_alter[<?= $fw_i ?>]" value="<?= fw_e(fw_ew('settings', "g_alter[$fw_i]", (int) $g['hoechstalter'])) ?>"<?= fw_ek('settings', "g_alter[$fw_i]") ?>></label></td>
</tr>
<tr>
  <td><label><?= fw_e(fw_t('EINST.L_ART2')) ?><br>
    <select data-role="none" name="g_art2[<?= $fw_i ?>]"<?= fw_ek('settings', "g_art2[$fw_i]") ?>>
<?php foreach ($fw_arten_zwei as $fw_a => $fw_as) { ?>
      <option value="<?= fw_e($fw_a) ?>"<?= fw_ew('settings', "g_art2[$fw_i]", $g['art2']) === (string) $fw_a ? ' selected' : '' ?>><?= fw_e(fw_t($fw_as)) ?></option>
<?php } ?>
    </select></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_VERKN')) ?><br>
    <select data-role="none" name="g_verkn[<?= $fw_i ?>]"<?= fw_ek('settings', "g_verkn[$fw_i]") ?>>
      <option value="oder"<?= fw_ew('settings', "g_verkn[$fw_i]", $g['verkn']) === 'oder' ? ' selected' : '' ?>><?= fw_e(fw_t('EINST.V_ODER')) ?></option>
      <option value="und"<?= fw_ew('settings', "g_verkn[$fw_i]", $g['verkn']) === 'und' ? ' selected' : '' ?>><?= fw_e(fw_t('EINST.V_UND')) ?></option>
    </select></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_PFAD2')) ?><br>
    <input data-role="none" type="text" size="26" name="g_pfad2[<?= $fw_i ?>]" value="<?= fw_e(fw_ew('settings', "g_pfad2[$fw_i]", $g['pfad2'])) ?>"<?= fw_ek('settings', "g_pfad2[$fw_i]") ?>></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_THEMA2')) ?><br>
    <input data-role="none" type="text" size="22" name="g_thema2[<?= $fw_i ?>]" value="<?= fw_e(fw_ew('settings', "g_thema2[$fw_i]", $g['thema2'])) ?>"<?= fw_ek('settings', "g_thema2[$fw_i]") ?>></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_KENNUNG')) ?><br>
    <input data-role="none" type="text" size="11" name="g_kennung[<?= $fw_i ?>]" value="<?= fw_e(fw_ew('settings', "g_kennung[$fw_i]", $g['kennung'])) ?>"<?= fw_ek('settings', "g_kennung[$fw_i]") ?>></label></td>
</tr>
<tr>
  <td><label><?= fw_e(fw_t('EINST.L_DIENST')) ?><br>
    <input data-role="none" type="text" size="16" name="g_dienst[<?= $fw_i ?>]" value="<?= fw_e(fw_ew('settings', "g_dienst[$fw_i]", $g['dienst'])) ?>"<?= fw_ek('settings', "g_dienst[$fw_i]") ?>></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_CONTAINER')) ?><br>
    <input data-role="none" type="text" size="14" name="g_container[<?= $fw_i ?>]" value="<?= fw_e(fw_ew('settings', "g_container[$fw_i]", $g['container'])) ?>"<?= fw_ek('settings', "g_container[$fw_i]") ?>></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_USB_PFAD')) ?><br>
    <input data-role="none" type="text" size="14" name="g_usb[<?= $fw_i ?>]" value="<?= fw_e(fw_ew('settings', "g_usb[$fw_i]", $g['usb_pfad'])) ?>"<?= fw_ek('settings', "g_usb[$fw_i]") ?>></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_HUB')) ?><br>
    <input data-role="none" type="text" size="8" name="g_hub[<?= $fw_i ?>]" value="<?= fw_e(fw_ew('settings', "g_hub[$fw_i]", $g['hub'])) ?>"<?= fw_ek('settings', "g_hub[$fw_i]") ?>>
    <input data-role="none" type="text" size="3" name="g_port[<?= $fw_i ?>]" value="<?= fw_e(fw_ew('settings', "g_port[$fw_i]", (int) $g['port'])) ?>"<?= fw_ek('settings', "g_port[$fw_i]") ?>></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_HOECHSTSTUFE')) ?><br>
    <input data-role="none" type="text" size="3" name="g_stufe[<?= $fw_i ?>]" value="<?= fw_e(fw_ew('settings', "g_stufe[$fw_i]", (int) $g['hoechststufe'])) ?>"<?= fw_ek('settings', "g_stufe[$fw_i]") ?>></label></td>
</tr>
<tr>
  <td><label><?= fw_e(fw_t('EINST.L_FOLGE')) ?><br>
    <select data-role="none" name="g_folge[<?= $fw_i ?>]"<?= fw_ek('settings', "g_folge[$fw_i]") ?>>
      <option value="normal"<?= fw_ew('settings', "g_folge[$fw_i]", $g['reihenfolge']) === 'normal' ? ' selected' : '' ?>><?= fw_e(fw_t('EINST.F_NORMAL')) ?></option>
      <option value="usb_zuerst"<?= fw_ew('settings', "g_folge[$fw_i]", $g['reihenfolge']) === 'usb_zuerst' ? ' selected' : '' ?>><?= fw_e(fw_t('EINST.F_USB')) ?></option>
    </select></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_RUHE_S')) ?><br>
    <input data-role="none" type="text" size="5" name="g_ruhe[<?= $fw_i ?>]" value="<?= fw_e(fw_ew('settings', "g_ruhe[$fw_i]", (int) $g['ruhe_s'])) ?>"<?= fw_ek('settings', "g_ruhe[$fw_i]") ?>></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_ABSTAND_S')) ?><br>
    <input data-role="none" type="text" size="6" name="g_abstand[<?= $fw_i ?>]" value="<?= fw_e(fw_ew('settings', "g_abstand[$fw_i]", (int) $g['abstand_s'])) ?>"<?= fw_ek('settings', "g_abstand[$fw_i]") ?>></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_JE_TAG')) ?><br>
    <input data-role="none" type="text" size="3" name="g_tag[<?= $fw_i ?>]" value="<?= fw_e(fw_ew('settings', "g_tag[$fw_i]", (int) $g['je_tag'])) ?>"<?= fw_ek('settings', "g_tag[$fw_i]") ?>></label></td>
  <td></td>
</tr>
<tr>
  <td><label><input data-role="none" type="checkbox" name="g_aktiv[<?= $fw_i ?>]" value="1"<?= fw_eh('settings', "g_aktiv[$fw_i]", $g['aktiv']) ? ' checked' : '' ?>> <?= fw_e(fw_t('EINST.L_AKTIV')) ?></label></td>
  <td><label><input data-role="none" type="checkbox" name="g_heilen[<?= $fw_i ?>]" value="1"<?= fw_ek('settings', "g_heilen[$fw_i]") ?><?= fw_eh('settings', "g_heilen[$fw_i]", $g['heilen']) ? ' checked' : '' ?>> <?= fw_e(fw_t('EINST.L_HEILEN')) ?></label></td>
  <td><label><input data-role="none" type="checkbox" name="g_wachstum[<?= $fw_i ?>]" value="1"<?= fw_eh('settings', "g_wachstum[$fw_i]", $g['wachstum']) ? ' checked' : '' ?>> <?= fw_e(fw_t('EINST.L_WACHSTUM')) ?></label></td>
  <td><label><input data-role="none" type="checkbox" name="g_dienstnach[<?= $fw_i ?>]" value="1"<?= fw_eh('settings', "g_dienstnach[$fw_i]", $g['dienst_nach']) ? ' checked' : '' ?>> <?= fw_e(fw_t('EINST.L_DIENSTNACH')) ?></label></td>
  <td><label><input data-role="none" type="checkbox" name="g_lernen[<?= $fw_i ?>]" value="1"<?= fw_eh('settings', "g_lernen[$fw_i]", $g['lernen']) ? ' checked' : '' ?>> <?= fw_e(fw_t('EINST.L_LERNEN')) ?></label></td>
</tr>
</table>
</div>
<?php } ?>
<p class="sm-hilfe"><?= fw_t('EINST.FELDER_HILFE') ?></p>

<h2><?= fw_e(fw_t('EINST.H_BETRIEB')) ?></h2>
<div class="sm-feld">
  <label for="fw_takt"><?= fw_e(fw_t('EINST.L_TAKT')) ?></label>
  <input data-role="none" type="text" id="fw_takt" name="takt" value="<?= fw_e(fw_ew('settings', 'takt', (int) $fw_cfg['takt'])) ?>"<?= fw_ek('settings', 'takt') ?>>
  <p class="sm-hilfe"><?= fw_t('EINST.H_TAKT') ?></p>
</div>
<div class="sm-feld">
  <label for="fw_anlauf"><?= fw_e(fw_t('EINST.L_ANLAUF_S')) ?></label>
  <input data-role="none" type="text" id="fw_anlauf" name="anlauf_s" value="<?= fw_e(fw_ew('settings', 'anlauf_s', (int) $fw_cfg['anlauf_s'])) ?>"<?= fw_ek('settings', 'anlauf_s') ?>>
  <p class="sm-hilfe"><?= fw_t('EINST.H_ANLAUF') ?></p>
</div>
<div class="sm-feld">
  <label for="fw_ruhevon"><?= fw_e(fw_t('EINST.L_RUHEFENSTER')) ?></label>
  <input data-role="none" type="text" id="fw_ruhevon" name="ruhe_von" size="5" value="<?= fw_e(fw_ew('settings', 'ruhe_von', $fw_cfg['ruhe_von'])) ?>"<?= fw_ek('settings', 'ruhe_von') ?>>
  <input data-role="none" type="text" name="ruhe_bis" size="5" value="<?= fw_e(fw_ew('settings', 'ruhe_bis', $fw_cfg['ruhe_bis'])) ?>"<?= fw_ek('settings', 'ruhe_bis') ?>>
  <p class="sm-hilfe"><?= fw_t('EINST.H_RUHEFENSTER') ?></p>
</div>
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="global_aus" value="1"<?= fw_eh('settings', 'global_aus', $fw_cfg['global_aus']) ? ' checked' : '' ?>> <?= fw_e(fw_t('EINST.L_GLOBAL_AUS')) ?></label>
  <p class="sm-hilfe"><?= fw_t('EINST.H_GLOBAL_AUS') ?></p>
</div>
<div class="sm-feld">
  <label for="fw_logkb"><?= fw_e(fw_t('EINST.L_LOG_KB')) ?></label>
  <input data-role="none" type="text" id="fw_logkb" name="log_kb" value="<?= fw_e(fw_ew('settings', 'log_kb', (int) $fw_cfg['log_kb'])) ?>"<?= fw_ek('settings', 'log_kb') ?>>
  <p class="sm-hilfe"><?= fw_t('EINST.H_LOG_KB') ?></p>
</div>
<div class="sm-feld">
  <label for="fw_vtage"><?= fw_e(fw_t('EINST.L_VERLAUF_TAGE')) ?></label>
  <input data-role="none" type="text" id="fw_vtage" name="verlauf_tage" value="<?= fw_e(fw_ew('settings', 'verlauf_tage', (int) $fw_cfg['verlauf_tage'])) ?>"<?= fw_ek('settings', 'verlauf_tage') ?>>
  <p class="sm-hilfe"><?= fw_t('EINST.H_VERLAUF_TAGE') ?></p>
</div>

<h2><?= fw_e(fw_t('MELD.H')) ?></h2>
<div class="sm-step"><?= fw_t('MELD.ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="melden_aktiv" value="1"<?= fw_eh('settings', 'melden_aktiv', $fw_cfg['melden_aktiv']) ? ' checked' : '' ?>> <?= fw_e(fw_t('MELD.L_ZENTRUM')) ?></label>
</div>
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="signal_ein" value="1"<?= fw_eh('settings', 'signal_ein', $fw_cfg['signal_ein']) ? ' checked' : '' ?>> <?= fw_e(fw_t('MELD.L_SIGNAL')) ?></label>
</div>
<div class="sm-feld">
  <label for="fw_sigurl"><?= fw_e(fw_t('MELD.L_SIGNAL_URL')) ?></label>
  <input data-role="none" type="text" id="fw_sigurl" name="signal_url" size="70" value="<?= fw_e(fw_ew('settings', 'signal_url', $fw_cfg['signal_url'])) ?>"<?= fw_ek('settings', 'signal_url') ?>>
  <p class="sm-hilfe"><?= fw_t('MELD.H_SIGNAL_URL') ?></p>
</div>

<?php /* Nr. 36 b (Stufe 2): Ansage ueber die gemeinsame Sprachausgabe, ab Werk aus. */ ?>
<h2><?= fw_e(fw_t('DURCHSAGE.H')) ?></h2>
<div class="sm-hinweis"><?= fw_e(fw_t('DURCHSAGE.TEXT_HILFE')) ?></div>
<div class="sm-feld">
  <label><?= fw_e(fw_t('DURCHSAGE.L_ANLAESSE')) ?></label>
<?php foreach (fw_ansage_anlaesse() as $fw_ab => $fw_akk) { ?>
  <label style="display:inline-flex;align-items:center;gap:8px;margin-right:14px;">
    <input data-role="none" type="checkbox" name="<?= fw_e($fw_akk) ?>" value="1"<?= fw_eh('settings', $fw_akk, $fw_cfg[$fw_akk]) ? ' checked' : '' ?>>
    <?= fw_e(fw_t('DURCHSAGE.A_' . strtoupper($fw_ab))) ?>
  </label>
<?php } ?>
  <div class="sm-hilfe"><?= fw_e(fw_t('DURCHSAGE.H_ANLAESSE')) ?></div>
</div>
<?= ansage_formular_html(fw_tts($fw_cfg), array(
    'w' => function ($n, $g) { return fw_ew('settings', $n, $g); },
    'm' => function ($n) { return fw_ek('settings', $n); },
    'c' => function ($n, $g) { return fw_eh('settings', $n, $g); },
    'modi' => fw_ansage_modi()), fw_ansage_k()) ?>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= fw_t('LEGENDE.AKTION_SPEICHERN') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern_geraete" value="1"><?= fw_e(fw_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h3><?= fw_e(fw_t('EINST.H_ZEILEN')) ?></h3>
<p class="sm-hilfe"><?= fw_t('EINST.H_ZEILEN_TEXT') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= fw_t('LEGENDE.TECHNIK_ZEILE') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <input data-role="none" type="hidden" name="aktion" value="zeile_dazu"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="zeile_dazu" value="1"><?= fw_e(fw_t('EINST.K_ZEILE_DAZU')) ?></button>
  </form>
<?php foreach ($fw_geraete as $fw_nr => $fw_g) { ?>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="zeile_leeren" value="<?= (int) $fw_nr ?>"><?= sprintf(fw_e(fw_t('EINST.K_ZEILE_LEER')), (int) $fw_nr, fw_e($fw_g['name'])) ?></button>
  </form>
<?php } ?>
</div>

<h3><?= fw_e(fw_t('SICH.H')) ?></h3>
<div class="sm-step"><?= fw_t('SICH.ERKLAERUNG') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= fw_t('LEGENDE.TECHNIK_SICHERN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= fw_t('LEGENDE.AKTION_ZURUECK') ?></span>
</div>
<?php
/* X-3 (Welle 4): besteht die eigene Sicherung das eigene Zurueckspielen?
 * Dieselbe Pruefung (fw_sicherung_pruefen). Eine Warnung - der Knopf
 * liefert die Datei trotzdem, mit _warnung im Kopf. */
$fw_sich_mangel = fw_sicherung_selbstpruefung();
if ($fw_sich_mangel) { ?>
<div class="sm-warnung" id="fw_sich_warnung"><b><?= fw_e(fw_t('SICH.SELBST_WARN')) ?></b><br><?= implode('<br>', array_map('fw_e', $fw_sich_mangel)) ?></div>
<?php } ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <input data-role="none" type="hidden" name="aktion" value="sichern"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="sichern" value="1"><?= fw_e(fw_t('SICH.K_SICHERN')) ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <input data-role="none" type="file" name="sicherungsdatei" accept=".json,application/json">
    <label class="sm-haken"><input data-role="none" type="checkbox" name="sicher_zurueck" value="1"> <?= fw_e(fw_t('SICH.SICHER')) ?></label>
    <input data-role="none" type="hidden" name="aktion" value="zurueckspielen"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="zurueckspielen" value="1"><?= fw_e(fw_t('SICH.K_ZURUECK')) ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $fw_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<h2>MQTT</h2>
<div class="sm-step"><?= fw_t('MQTT.ERKLAERUNG') ?></div>
<?php if (!$fw_mqtt['gefunden']) { ?>
<div class="sm-warnung"><?= fw_t('MQTT.NICHT_GEFUNDEN') ?></div>
<?php } elseif (!$fw_mqtt['autostart']) { ?>
<div class="sm-warnung"><?= fw_t('MQTT.KEIN_AUTOSTART') ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= sprintf(fw_t('MQTT.LAEUFT'), fw_e((string) $fw_mqtt['udpport'])) ?></div>
<?php } ?>

<h3><?= fw_e(fw_t('MQTT.H_ABO')) ?></h3>
<?php if ((int) $fw_mqtt['fassung'] >= 2) { ?>
<div class="sm-hinweis"><?= sprintf(fw_t('MQTT.ABO_V2'), fw_e($fw_cfg['mqtt_topic'])) ?></div>
<?php } elseif ((int) $fw_mqtt['fassung'] === 1) { ?>
<div class="sm-warnung"><?= sprintf(fw_t('MQTT.ABO_V1'), fw_e($fw_cfg['mqtt_topic'])) ?></div>
<?php } else { ?>
<div class="sm-warnung"><?= sprintf(fw_t('MQTT.ABO_V1'), fw_e($fw_cfg['mqtt_topic'])) ?></div>
<div class="sm-hinweis"><?= sprintf(fw_t('MQTT.ABO_V2'), fw_e($fw_cfg['mqtt_topic'])) ?></div>
<div class="sm-hilfe"><?= fw_t('MQTT.ABO_UNBEKANNT') ?></div>
<?php } ?>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= fw_eh('mqtt', 'mqtt_ein', $fw_cfg['mqtt_ein']) ? ' checked' : '' ?>> <?= fw_e(fw_t('MQTT.EIN')) ?></label>
</div>
<div class="sm-feld">
  <label for="fw_thema"><?= fw_e(fw_t('MQTT.THEMA')) ?></label>
  <input data-role="none" type="text" id="fw_thema" name="mqtt_topic" value="<?= fw_e(fw_ew('mqtt', 'mqtt_topic', $fw_cfg['mqtt_topic'])) ?>"<?= fw_ek('mqtt', 'mqtt_topic') ?>>
  <p class="sm-hilfe"><?= fw_t('MQTT.THEMA_HILFE') ?></p>
</div>

<h3><?= fw_e(fw_t('MQTT.H_MITHOERER')) ?></h3>
<div class="sm-step"><?= fw_t('MQTT.MITHOERER_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="fw_bhost"><?= fw_e(fw_t('MQTT.L_HOST')) ?></label>
  <input data-role="none" type="text" id="fw_bhost" name="broker_host" value="<?= fw_e(fw_ew('mqtt', 'broker_host', $fw_cfg['broker_host'])) ?>"<?= fw_ek('mqtt', 'broker_host') ?>>
  <input data-role="none" type="text" name="broker_port" size="6" value="<?= fw_e(fw_ew('mqtt', 'broker_port', $fw_cfg['broker_port'])) ?>"<?= fw_ek('mqtt', 'broker_port') ?>>
  <p class="sm-hilfe"><?= sprintf(fw_t('MQTT.H_HOST'),
      fw_e($fw_mqtt['broker'] !== '' ? $fw_mqtt['broker'] : '127.0.0.1'),
      (int) ($fw_mqtt['brokerport'] ? $fw_mqtt['brokerport'] : 1883)) ?></p>
</div>
<div class="sm-feld">
  <label for="fw_buser"><?= fw_e(fw_t('MQTT.L_USER')) ?></label>
  <input data-role="none" type="text" id="fw_buser" name="broker_user" value="<?= fw_e(fw_ew('mqtt', 'broker_user', $fw_cfg['broker_user'])) ?>"<?= fw_ek('mqtt', 'broker_user') ?>>
</div>
<div class="sm-feld">
  <label for="fw_bpass"><?= fw_e(fw_t('MQTT.L_PASS')) ?></label>
  <input data-role="none" type="password" id="fw_bpass" name="broker_pass" value="" autocomplete="new-password">
  <p class="sm-hilfe"><?= sprintf(fw_t('MQTT.H_PASS'),
      trim((string) $fw_cfg['broker_pass']) !== '' ? fw_e(fw_t('ALLG.JA')) : fw_e(fw_t('ALLG.NEIN'))) ?></p>
  <label><input data-role="none" type="checkbox" name="broker_pass_weg" value="1"> <?= fw_e(fw_t('MQTT.L_PASS_WEG')) ?></label>
</div>
<div class="sm-feld">
  <label for="fw_bid"><?= fw_e(fw_t('MQTT.L_ID')) ?></label>
  <input data-role="none" type="text" id="fw_bid" name="broker_id" value="<?= fw_e(fw_ew('mqtt', 'broker_id', $fw_cfg['broker_id'])) ?>"<?= fw_ek('mqtt', 'broker_id') ?>>
  <p class="sm-hilfe"><?= fw_t('MQTT.H_ID') ?></p>
</div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= fw_t('LEGENDE.AKTION_SPEICHERN') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern_mqtt" value="1"><?= fw_e(fw_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h3><?= fw_e(fw_t('MQTT.H_THEMEN')) ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= fw_e(fw_t('MQTT.SP_THEMA')) ?></th><th><?= fw_e(fw_t('MQTT.SP_BEDEUTUNG')) ?></th><th><?= fw_e(fw_t('MQTT.SP_RETAIN')) ?></th></tr>
<?php $fw_retain = fw_mqtt_retain(); ?>
<?php foreach (fw_mqtt_themen() as $fw_k => $fw_schl) { ?>
<tr><td><span class="sm-mono"><?= fw_e($fw_cfg['mqtt_topic'] . '/' . $fw_k) ?></span></td>
    <td><?= fw_e(fw_t($fw_schl)) ?></td>
    <td><?= fw_e(fw_t(!empty($fw_retain[$fw_k]) ? 'ALLG.JA' : 'ALLG.NEIN')) ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= fw_t('MQTT.GERAETN_HILFE') ?></p>
<p class="sm-hilfe"><?= fw_t('MQTT.RETAIN_HILFE') ?></p>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $fw_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= fw_e(fw_t('LOX.H')) ?></h2>
<div class="sm-step"><?= fw_t('LOX.S1') ?></div>
<div class="sm-step"><?php
    if ((int) $fw_mqtt['fassung'] >= 2) { echo sprintf(fw_t('LOX.S2_V2'), fw_e($fw_cfg['mqtt_topic']));
    } elseif ((int) $fw_mqtt['fassung'] === 1) { echo sprintf(fw_t('LOX.S2_V1'), fw_e($fw_cfg['mqtt_topic']));
    } else { echo sprintf(fw_t('LOX.S2_V1'), fw_e($fw_cfg['mqtt_topic']))
                  . '<br>' . sprintf(fw_t('LOX.S2_V2'), fw_e($fw_cfg['mqtt_topic']))
                  . '<br>' . fw_t('MQTT.ABO_UNBEKANNT'); } ?></div>
<div class="sm-step"><?= fw_t('LOX.S3') ?></div>

<h3><?= fw_e(fw_t('LOX.H_ADRESSE')) ?></h3>
<p class="sm-hilfe"><?= fw_t('LOX.ADRESSE_HILFE') ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= fw_e(fw_t('LOX.SP_ZWECK')) ?></th><th><?= fw_e(fw_t('LOX.SP_ADRESSE')) ?></th></tr>
<tr><td><?= fw_e(fw_t('LOX.Z_STATUS')) ?></td><td><span class="sm-mono"><?= fw_e(fw_endpunkt() . '?token=' . fw_token() . '&aktion=status') ?></span></td></tr>
<tr><td><?= fw_e(fw_t('LOX.Z_JSON')) ?></td><td><span class="sm-mono"><?= fw_e(fw_endpunkt() . '?token=' . fw_token() . '&aktion=json') ?></span></td></tr>
<tr><td><?= fw_e(fw_t('LOX.Z_QUITT')) ?></td><td><span class="sm-mono"><?= fw_e(fw_endpunkt() . '?token=' . fw_token() . '&aktion=quittieren') ?></span></td></tr>
<tr><td><?= fw_e(fw_t('LOX.Z_WARTUNG')) ?></td><td><span class="sm-mono"><?= fw_e(fw_endpunkt() . '?token=' . fw_token() . '&aktion=wartung&dauer=60') ?></span></td></tr>
<tr><td><?= fw_e(fw_t('LOX.Z_SELFTEST')) ?></td><td><span class="sm-mono"><?= fw_e(fw_endpunkt() . '?selftest=1&token=' . fw_token()) ?></span></td></tr>
</table>
</div>
<p class="sm-hilfe"><?= fw_t('LOX.TOKEN_HINWEIS') ?></p>

<h3><?= fw_e(fw_t('LOX.H_FELDER')) ?></h3>
<p class="sm-hilfe"><?= fw_t('LOX.FELDER_HILFE') ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= fw_e(fw_t('LOX.SP_TITEL')) ?></th><th><?= fw_e(fw_t('LOX.SP_EINHEIT')) ?></th>
    <th><?= fw_e(fw_t('LOX.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach ($fw_geraete as $fw_nr => $fw_g) {
        foreach (fw_felder() as $fw_f => $fw_info) { ?>
<tr><td><span class="sm-mono"><?= fw_e(fw_titel($fw_g, $fw_nr, $fw_f)) ?></span></td>
    <td><?= $fw_info[0] !== '' ? fw_e($fw_info[0]) : '&ndash;' ?></td>
    <td><?= fw_e($fw_g['name']) ?>: <?= fw_e(fw_t($fw_info[3])) ?></td></tr>
<?php   }
      } ?>
<?php foreach (fw_summenfelder() as $fw_f => $fw_info) { ?>
<tr><td><span class="sm-mono">FW_<?= fw_e($fw_f) ?></span></td>
    <td><?= $fw_info[0] !== '' ? fw_e($fw_info[0]) : '&ndash;' ?></td>
    <td><?= fw_e(fw_t($fw_info[3])) ?></td></tr>
<?php } ?>
</table>
</div>
<?php if (!$fw_geraete) { ?>
<div class="sm-warnung"><?= fw_t('LOX.KEINE_GERAETE') ?></div>
<?php } ?>

<h3><?= fw_e(fw_t('LOX.H_BEFEHLE')) ?></h3>
<div class="sm-step"><?= fw_t('LOX.BEFEHLE_TEXT') ?></div>

<h3><?= fw_e(fw_t('LOX.H_ALLES')) ?></h3>
<div class="sm-step"><?= fw_t('LOX.ALLES_TEXT') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= fw_t('LEGENDE.TECHNIK_XML') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="vi"><?= fw_e(fw_t('LOX.K_VI')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="vq"><?= fw_e(fw_t('LOX.K_VQ')) ?></button>
  </form>
</div>

<h3><?= fw_e(fw_t('LOX.H_AUSFALL')) ?></h3>
<div class="sm-step"><?= fw_t('LOX.AUSFALL_TEXT') ?></div>

<h3><?= fw_e(fw_t('LOX.H_BAUSTEINE')) ?></h3>
<?php /* Nachzug G2: die Liste kommt aus fw_bausteinliste() (Nummern gerechnet,
   Titel aus der Vorlagenquelle), nicht mehr als fertiges HTML aus der .ini.
   Typ, Name, Parameter und Saetze stammen aus der Sprachdatei und duerfen
   ihre Auszeichnung behalten; Titel sind dort schon maskiert. */
$fw_bl = fw_bausteinliste(); ?>
<div class="sm-breit"><table class="sm-tbl">
<tr><th><?= fw_e(fw_t('LOX.BS_SP_NR')) ?></th><th><?= fw_e(fw_t('LOX.BS_SP_TYP')) ?></th>
    <th><?= fw_e(fw_t('LOX.BS_SP_NAME')) ?></th><th><?= fw_e(fw_t('LOX.BS_SP_PARAM')) ?></th>
    <th><?= fw_e(fw_t('LOX.BS_SP_EING')) ?></th></tr>
<?php foreach ($fw_bl['zeilen'] as $fw_bz) { ?>
<tr><td><?= (int) $fw_bz[0] ?></td><td><?= $fw_bz[1] ?></td><td><?= $fw_bz[2] ?></td>
    <td><?= $fw_bz[3] ?></td><td><?= $fw_bz[4] ?></td></tr>
<?php } ?>
</table></div>
<p class="sm-hilfe"><?= $fw_bl['je_stick'] ?></p>
<div class="sm-hilfe"><?php foreach ($fw_bl['hinweise'] as $fw_bh) {
    echo '<b>' . sprintf(fw_e(fw_t('LOX.BS_ZU')), $fw_bh[0]) . '</b> ' . $fw_bh[1] . ' ';
} ?><?= fw_t('LOX.BS_H_ZEIT') ?></div>

<h3><?= fw_e(fw_t('LOX.H_GEGENPROBE')) ?></h3>
<div class="sm-step"><?= fw_t('LOX.GEGENPROBE_TEXT') ?></div>

<h3><?= fw_e(fw_t('LOX.H_TOKEN')) ?></h3>
<div class="sm-step"><?= fw_t('LOX.TOKEN_TEXT') ?></div>
<div class="sm-hilfe"><?= fw_t('LOX.TOKEN_NICHT') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= fw_t('LEGENDE.AKTION_TOKEN') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <label class="sm-haken"><input data-role="none" type="checkbox" name="sicher_token" value="1"> <?= fw_e(fw_t('LOX.SICHER')) ?></label>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= fw_e(fw_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $fw_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= fw_e(fw_t('TEST.H')) ?></h2>
<div class="sm-step"><?= fw_t('TEST.ERKLAERUNG') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= fw_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= fw_t('LEGENDE.TECHNIK') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="selbstpruefung"><?= fw_e(fw_t('TEST.K_SELBSTPRUEFUNG')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="trocken"><?= fw_e(fw_t('TEST.K_TROCKEN')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="faehigkeit"><?= fw_e(fw_t('TEST.K_FAEHIGKEIT')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="messen"><?= fw_e(fw_t('TEST.K_MESSEN')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="usb"><?= fw_e(fw_t('TEST.K_USB')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="dienste"><?= fw_e(fw_t('TEST.K_DIENSTE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="container"><?= fw_e(fw_t('TEST.K_CONTAINER')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="selbsttest"><?= fw_e(fw_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="zeile"><?= fw_e(fw_t('TEST.K_ZEILE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="mqtt"><?= fw_e(fw_t('TEST.K_MQTT')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="mqttprobe"><?= fw_e(fw_t('TEST.K_MQTTPROBE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="endpunkt"><?= fw_e(fw_t('TEST.K_ENDPUNKT')) ?></button>
  </form>
</div>

<?php if ($fw_geraete) { ?>
<h3><?= fw_e(fw_t('TEST.H_JE_STICK')) ?></h3>
<p class="sm-hilfe"><?= fw_t('TEST.JE_STICK_TEXT') ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= fw_e(fw_t('TAB.NR')) ?></th><th><?= fw_e(fw_t('TAB.GERAET')) ?></th>
    <th><?= fw_e(fw_t('TEST.SP_MESSEN')) ?></th><th><?= fw_e(fw_t('TEST.SP_JOURNAL')) ?></th></tr>
<?php foreach ($fw_geraete as $fw_nr => $fw_g) { ?>
<tr>
  <td><?= (int) $fw_nr ?></td>
  <td><?= fw_e($fw_g['name']) ?><br><span class="sm-hilfe"><?= fw_e(fw_t($fw_arten_wahl[$fw_g['art']])) ?></span></td>
  <td><form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <input data-role="none" type="hidden" name="testwert" value="<?= (int) $fw_nr ?>">
    <button data-role="none" class="sm-btn sm-b-lesen sm-klein" type="submit" name="test" value="messen1"><?= fw_e(fw_t('TEST.K_MESSEN1')) ?></button>
  </form></td>
  <td><form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <input data-role="none" type="hidden" name="testwert" value="<?= (int) $fw_nr ?>">
    <button data-role="none" class="sm-btn sm-b-technik sm-klein" type="submit" name="test" value="journal"><?= fw_e(fw_t('TEST.K_JOURNAL')) ?></button>
  </form></td>
</tr>
<?php } ?>
</table>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= fw_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= fw_t('LEGENDE.TECHNIK') ?></span>
</div>
<?php } ?>

<h3><?= fw_e(fw_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-warnung"><?= fw_t('TEST.SCHALTEN_TEXT') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= fw_t('LEGENDE.AKTION_SCHALTEN') ?></span>
</div>
<div class="sm-knopfreihe">
<?php foreach ($fw_geraete as $fw_nr => $fw_g) { ?>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <select data-role="none" name="heilstufe">
      <option value=""><?= fw_e(fw_t('TEST.STUFE_NAECHSTE')) ?></option>
      <option value="1"><?= fw_e(fw_t('STUFE.1')) ?></option>
      <option value="2"><?= fw_e(fw_t('STUFE.2')) ?></option>
      <option value="3"><?= fw_e(fw_t('STUFE.3')) ?></option>
    </select>
    <input data-role="none" type="hidden" name="testwert" value="<?= (int) $fw_nr ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="heilen"><?= sprintf(fw_e(fw_t('TEST.K_HEILEN')), fw_e($fw_g['name'])) ?></button>
  </form>
<?php } ?>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="quittieren" value="0"><?= fw_e(fw_t('TEST.K_QUITTIEREN')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <input data-role="none" type="hidden" name="aktion" value="wartung"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="wartung" value="60"><?= fw_e(fw_t('TEST.K_WARTUNG_EIN')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <input data-role="none" type="hidden" name="aktion" value="wartung"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="wartung" value="0"><?= fw_e(fw_t('TEST.K_WARTUNG_AUS')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ansage_test" value="1"><?= fw_e(fw_t('DURCHSAGE.K_TEST')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <label class="sm-haken"><input data-role="none" type="checkbox" name="sicher_statistik" value="1"> <?= fw_e(fw_t('TEST.SICHER_STATISTIK')) ?></label>
    <input data-role="none" type="hidden" name="aktion" value="statistik_weg"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="statistik_weg" value="1"><?= fw_e(fw_t('TEST.K_STATISTIK_WEG')) ?></button>
  </form>
</div>

<?php if ($fw_testausgabe !== '') { ?>
<div class="sm-pre"><?= fw_e($fw_testausgabe) ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $fw_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= fw_e(fw_t('LOG.H')) ?></h2>
<p class="sm-hilfe"><?= fw_t('LOG.ERKLAERUNG') ?>
<span class="sm-mono"><?= fw_e($fw_p['log']) ?></span></p>
<?php if ($fw_logzeilen) { ?>
<div class="sm-log"><?= fw_e(implode("\n", $fw_logzeilen)) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= fw_t('LOG.LEER') ?></div>
<?php } ?>

<h3><?= fw_e(fw_t('LOG.H_EREIGNISSE')) ?></h3>
<p class="sm-hilfe"><?= fw_t('LOG.EREIGNISSE_TEXT') ?></p>
<?php $fw_er = isset($fw_hist['ereignisse']) && is_array($fw_hist['ereignisse'])
                 ? array_slice($fw_hist['ereignisse'], 0, 60) : array(); ?>
<?php if ($fw_er) { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= fw_e(fw_t('LOG.SP_ZEIT')) ?></th><th><?= fw_e(fw_t('TAB.GERAET')) ?></th>
    <th><?= fw_e(fw_t('LOG.SP_WAS')) ?></th></tr>
<?php foreach ($fw_er as $fw_e1) { ?>
<tr><td><?= fw_e(date('d.m.Y H:i:s', (int) $fw_e1['zeit'])) ?></td>
    <td><?= fw_e($fw_e1['name'] !== '' ? $fw_e1['name'] : ('#' . (int) $fw_e1['nr'])) ?></td>
    <td><?= fw_e($fw_e1['text']) ?></td></tr>
<?php } ?>
</table>
</div>
<?php } else { ?>
<div class="sm-hinweis"><?= fw_t('LOG.EREIGNISSE_LEER') ?></div>
<?php } ?>

<h3><?= fw_e(fw_t('LOG.H_VERLAUF')) ?></h3>
<p class="sm-hilfe"><?= fw_t('LOG.VERLAUF_TEXT') ?></p>
<?php $fw_kurve = fw_verlauf_tage(30); ?>
<?php if (array_sum($fw_kurve) > 0) { ?>
<?= fw_verlauf_svg($fw_kurve) ?>
<?php } else { ?>
<div class="sm-hinweis"><?= fw_t('LOG.VERLAUF_LEER') ?></div>
<?php } ?>
<?php $fw_dateien = fw_verlaufsdateien(); ?>
<?php if ($fw_dateien) { ?>
<p class="sm-hilfe"><?= sprintf(fw_t('LOG.VERLAUF_DATEIEN'), count($fw_dateien),
    fw_e(dirname($fw_dateien[0]))) ?></p>
<?php } ?>

<h3><?= fw_e(fw_t('LOG.H_START')) ?></h3>
<p class="sm-hilfe"><?= fw_t('LOG.START_ERKLAERUNG') ?>
<span class="sm-mono"><?= fw_e($fw_p['dienstlog']) ?></span></p>
<?php $fw_startzeilen = fw_log_ende($fw_p['dienstlog'], 60); ?>
<?php if ($fw_startzeilen) { ?>
<div class="sm-log"><?= fw_e(implode("\n", $fw_startzeilen)) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= fw_t('LOG.START_LEER') ?></div>
<?php } ?>

<?php $fw_mzeilen = fw_log_ende($fw_p['logdir'] . '/mithoerer.out', 40); ?>
<?php if ($fw_mzeilen) { ?>
<h3><?= fw_e(fw_t('LOG.H_MITHOERER')) ?></h3>
<div class="sm-log"><?= fw_e(implode("\n", $fw_mzeilen)) ?></div>
<?php } ?>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= fw_t('LEGENDE.AKTION_LOG') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <input data-role="none" type="hidden" name="fmt" value="<?= fw_e($fw_merkmal) ?>">
    <label class="sm-haken"><input data-role="none" type="checkbox" name="sicher_log" value="1"> <?= fw_e(fw_t('LOG.SICHER')) ?></label>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="log_leeren" value="1"><?= fw_e(fw_t('LOG.K_LEEREN')) ?></button>
  </form>
</div>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	// Der Server hat sm-active bereits gesetzt; dieser Aufruf richtet nur die
	// versteckten activetab-Felder aus und ist ansonsten wirkungslos.
	zeige(<?= json_encode($fw_tab) ?>);

	// Die Kacheln auffrischen, ohne die Seite neu zu bauen. Faellt der Abruf
	// aus, bleibt einfach der letzte Stand stehen - eine halb gefuellte
	// Anzeige waere schlechter als eine, die sich nicht bewegt.
	var laeuft = true;
	function setz(id, text, klasse) {
		var e = document.getElementById(id);
		if (!e) { return; }
		e.textContent = text;
		if (klasse !== undefined) { e.className = klasse; }
	}
	function frischen() {
		if (!laeuft) { return; }
		fetch('fw_live.php', {cache: 'no-store'}).then(function (r) {
			return r.ok ? r.json() : null;
		}).then(function (d) {
			if (!d || !d.ok) { return; }
			setz('fw_k_dienst', d.dienst ? <?= json_encode(fw_t('ALLG.LAEUFT')) ?>
			                             : <?= json_encode(fw_t('ALLG.GESTOPPT')) ?>,
			     d.dienst ? 'sm-an' : 'sm-aus');
			setz('fw_k_pid', d.dienst ? 'PID ' + d.dienst
			                          : <?= json_encode(fw_t('ALLG.KEINE_PID')) ?>);
			setz('fw_k_krank', String(d.krank), d.krank ? 'sm-aus' : 'sm-an');
			setz('fw_k_geheilt', String(d.geheilt));
			setz('fw_k_versuche', String(d.versuche));
			setz('fw_k_alter', d.alter < 0 ? '–' : String(d.alter));
		}).catch(function () { /* still bleiben - siehe oben */ });
	}
	setInterval(frischen, 5000);
	document.addEventListener('visibilitychange', function () {
		laeuft = !document.hidden;   // im Hintergrund nicht weiterfragen
	});
})();
</script>
<?php
if ($fw_rahmen) {
    LBWeb::lbfooter();
}
