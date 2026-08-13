<?php
/**
 * Funkwacht - Bedienoberflaeche
 *
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * Diese Datei ist NUR Oberflaeche. Das Pruefen und Heilen laeuft im Dienst
 * (bin/funkwacht_dienst.py), der Miniserver spricht mit
 * webfrontend/html/index.php.
 *
 * Praefix 'fw_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Die Bibliothek liegt im UNANGEMELDETEN Bereich. Der Weg dorthin sieht im
 * Archiv anders aus als installiert - deshalb eine Kandidatenliste und keine
 * Rechnung. Genau daran ist das Intercom-Plugin mit HTTP 500 gescheitert. */
$fw_gefunden = false;
foreach (array(
    dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/fw_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/fw_lib.php',
    dirname(__DIR__) . '/html/fw_lib.php',
) as $fw_kandidat) {
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
}

/* Die Reiter, an EINER Stelle. Leiste, Pruefausdruck und die serverseitige
 * Klasse sm-active entstehen daraus - vergessen kann man nichts mehr. */
$fw_reiter = array(
    'settings' => 'REITER.EINSTELLUNGEN',
    'mqtt'     => null,
    'loxone'   => 'REITER.LOXONE',
    'test'     => 'REITER.TEST',
    'log'      => 'REITER.LOG',
);
$fw_muster = '/^tab-(' . implode('|', array_map(function ($k) {
    return preg_quote($k, '/');
}, array_keys($fw_reiter))) . ')$/';
$fw_tab = 'tab-settings';
if (isset($_POST['activetab']) && preg_match($fw_muster, (string) $_POST['activetab'])) {
    $fw_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && preg_match($fw_muster, 'tab-' . (string) $_GET['form'])) {
    $fw_tab = 'tab-' . (string) $_GET['form'];
}

$fw_meldungen = array();
$fw_fehler = array();
$fw_testausgabe = '';
$fw_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';

/* ---------------- Vorlage herunterladen ---------------- */
if ($fw_post && isset($_POST['vorlage'])) {
    list($fw_name, $fw_inhalt) = fw_vorlage();
    if ($fw_inhalt === '') {
        $fw_fehler[] = fw_t('LOX.FEHLER_VORLAGE');
        $fw_tab = 'tab-loxone';
    } else {
        header('Content-Type: application/xml; charset=utf-8');
        // Anfuehrungszeichen um den Dateinamen: ohne sie bricht jeder Name
        // mit einem Leerzeichen darin.
        header('Content-Disposition: attachment; filename="' . $fw_name . '"');
        echo $fw_inhalt;
        exit;
    }
}

/* ---------------- Speichern ---------------- */
if ($fw_post && isset($_POST['speichern'])) {
    $fw_cfg = fw_config();

    /* Nur Steuerzeichen und Anfuehrungszeichen entfernen - ein hartes
     * preg_replace auf eine Positivliste zerstoert eingefuegte Werte
     * (belegt am ACTi-Plugin am 26.07.2026). */
    $fw_sauber = function ($s) {
        return trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $s));
    };
    $fw_feld = function ($name, $i) use ($fw_sauber) {
        $a = isset($_POST[$name]) ? (array) $_POST[$name] : array();
        return isset($a[$i]) ? $fw_sauber($a[$i]) : '';
    };
    /* Eine Zahl pruefen statt sie stillschweigend zurechtzubiegen. */
    $fw_zahl = function ($roh, $von, $bis, $bez) use (&$fw_fehler) {
        $roh = str_replace(',', '.', trim((string) $roh));
        if ($roh === '') { return null; }
        if (!is_numeric($roh)) {
            $fw_fehler[] = sprintf(fw_t('FEHLER.KEINE_ZAHL'), $bez, $roh);
            return null;
        }
        $w = (int) round((float) $roh);
        if ($w < $von || $w > $bis) {
            $fw_fehler[] = sprintf(fw_t('FEHLER.AUSSERHALB'), $bez, $roh, $von, $bis);
            return null;
        }
        return $w;
    };

    $fw_arten = fw_arten();
    $fw_neu = array();
    for ($fw_i = 0; $fw_i < FW_GERAETE; $fw_i++) {
        $g = fw_geraet_vorgabe();
        $g['name']      = $fw_feld('g_name', $fw_i);
        $g['art']       = $fw_feld('g_art', $fw_i);
        $g['pfad']      = $fw_feld('g_pfad', $fw_i);
        $g['thema']     = $fw_feld('g_thema', $fw_i);
        $g['dienst']    = $fw_feld('g_dienst', $fw_i);
        $g['container'] = $fw_feld('g_container', $fw_i);
        $g['usb_pfad']  = $fw_feld('g_usb', $fw_i);
        $g['hub']       = $fw_feld('g_hub', $fw_i);
        $g['aktiv']     = !empty($_POST['g_aktiv'][$fw_i]) ? 1 : 0;
        $g['heilen']    = !empty($_POST['g_heilen'][$fw_i]) ? 1 : 0;
        if (!isset($fw_arten[$g['art']])) { $g['art'] = 'datei'; }

        $bez = fw_t('EINST.GERAET') . ' ' . ($fw_i + 1);
        foreach (array(
            'hoechstalter' => array('g_alter', 10, 86400),
            'hoechststufe' => array('g_stufe', 0, 3),
            'ruhe_s'       => array('g_ruhe', 10, 3600),
            'abstand_s'    => array('g_abstand', 30, 86400),
            'je_tag'       => array('g_tag', 0, 50),
            'port'         => array('g_port', 0, 99),
        ) as $fw_f => $fw_d) {
            $w = $fw_zahl($fw_feld($fw_d[0], $fw_i), $fw_d[1], $fw_d[2],
                          $bez . ' / ' . fw_t('EINST.L_' . strtoupper($fw_f)));
            if ($w !== null) { $g[$fw_f] = $w; }
        }

        $leer = ($g['name'] === '' && $g['pfad'] === '' && $g['thema'] === '');
        if (!$leer) {
            if ($g['name'] === '') {
                $fw_fehler[] = sprintf(fw_t('FEHLER.NAME_FEHLT'), $fw_i + 1);
            }
            if ($g['art'] === 'mqtt' && $g['thema'] === '') {
                $fw_fehler[] = sprintf(fw_t('FEHLER.THEMA_FEHLT'), $fw_i + 1);
            }
            if ($g['art'] !== 'mqtt' && $g['pfad'] === '') {
                $fw_fehler[] = sprintf(fw_t('FEHLER.PFAD_FEHLT'), $fw_i + 1);
            }
            /* Heilen ohne einen einzigen Hebel ist ein eingeschalteter
             * Schalter, der nichts tut. Lieber jetzt sagen. */
            if ($g['heilen'] && $g['hoechststufe'] > 0
                && $g['dienst'] === '' && $g['container'] === ''
                && $g['usb_pfad'] === '' && $g['hub'] === '') {
                $fw_fehler[] = sprintf(fw_t('FEHLER.KEIN_HEBEL'), $fw_i + 1);
            }
            if ($g['hoechststufe'] >= 3 && ($g['hub'] === '' || $g['port'] <= 0)) {
                $fw_fehler[] = sprintf(fw_t('FEHLER.UHUBCTL_UNVOLLSTAENDIG'), $fw_i + 1);
            }
        }
        $fw_neu[$fw_i] = $g;
    }
    $fw_cfg['geraete'] = $fw_neu;

    $w = $fw_zahl(isset($_POST['takt']) ? $_POST['takt'] : '', 15, 3600, fw_t('EINST.L_TAKT'));
    if ($w !== null) { $fw_cfg['takt'] = $w; }
    $fw_cfg['mqtt_ein'] = !empty($_POST['mqtt_ein']) ? 1 : 0;
    $fw_thema = strtolower($fw_sauber(isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : ''));
    $fw_thema = trim($fw_thema, '/');
    if ($fw_thema === '') {
        $fw_cfg['mqtt_topic'] = 'funkwacht';
    } elseif (!preg_match('#^[a-z0-9_\-/]+$#', $fw_thema)) {
        // Ein Thema mit + oder # ist ein Filtermuster und als Ziel unbrauchbar.
        $fw_fehler[] = sprintf(fw_t('FEHLER.THEMA'), $fw_thema);
    } else {
        $fw_cfg['mqtt_topic'] = $fw_thema;
    }

    if (!$fw_fehler) {
        if (fw_config_speichern($fw_cfg)) {
            $fw_meldungen[] = fw_t('ALLG.GESPEICHERT');
            fw_log('Einstellungen gespeichert.');
        } else {
            $fw_fehler[] = fw_t('FEHLER.SPEICHERN');
        }
    }
}

/* ---------------- Neues Wortzeichen ---------------- */
if ($fw_post && isset($_POST['token_neu'])) {
    $fw_cfg = fw_config();
    $fw_cfg['aktionstoken'] = fw_token_erzeugen();
    fw_config_speichern($fw_cfg);
    $fw_meldungen[] = fw_t('LOX.TOKEN_NEU_OK');
    fw_log('Neues Wortzeichen erzeugt.');
    $fw_tab = 'tab-loxone';
}

/* ---------------- Protokoll leeren ---------------- */
if ($fw_post && isset($_POST['log_leeren'])) {
    @file_put_contents($fw_p['log'], '');
    fw_log('Protokoll geleert.');
    $fw_meldungen[] = fw_t('LOG.GELEERT');
    $fw_tab = 'tab-log';
}

/* ---------------- Test ---------------- */
if ($fw_post && isset($_POST['test'])) {
    $fw_testausgabe = fw_test_ausfuehren((string) $_POST['test']);
    $fw_tab = 'tab-test';
}

/* ================= Werte fuer die Anzeige ================= */
$fw_cfg = fw_config();
$fw_stand = fw_stand();
$fw_geraete = fw_geraete();
$fw_mqtt = fw_mqtt_zustand();
$fw_faehig = fw_faehigkeiten();
$fw_pid = fw_dienst_pid();
$fw_logzeilen = fw_log_ende($fw_p['log'], 400);

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
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. Die
   Hover-Farben unten sind kein Feinschliff, sondern Pflicht: fehlen sie, kommt
   der Hover-Zustand vom Rahmen und ist unlesbar. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
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
   vollstaendig leer, sobald das Skript nicht laeuft - genau das war bis
   07.08.2026 der Fall. Die Klasse gehoert deshalb schon ins ausgelieferte
   HTML, siehe die Reiterleiste weiter unten. */
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
.sm-log { background: #1e1e1e; color: #ddd; font-family: monospace; font-size: 0.82em;
  padding: 10px; border-radius: 6px; max-height: 460px; overflow: auto; white-space: pre-wrap; }
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

<div class="sm-kacheln">
  <div class="sm-kachel"><?= fw_e(fw_t('ALLG.DIENST')) ?>
    <b class="<?= $fw_pid ? 'sm-an' : 'sm-aus' ?>"><?= $fw_pid ? fw_e(fw_t('ALLG.LAEUFT')) : fw_e(fw_t('ALLG.GESTOPPT')) ?></b>
    <span class="sm-hilfe"><?= $fw_pid ? 'PID ' . (int) $fw_pid : fw_e(fw_t('ALLG.KEINE_PID')) ?></span>
  </div>
  <div class="sm-kachel"><?= fw_e(fw_t('ALLG.GERAETE')) ?>
    <b><?= count($fw_geraete) ?></b>
    <span class="sm-hilfe"><?= fw_e(fw_t('ALLG.UEBERWACHT')) ?></span>
  </div>
  <div class="sm-kachel"><?= fw_e(fw_t('ALLG.GESTOERT')) ?>
    <b class="<?= !empty($fw_stand['krank']) ? 'sm-aus' : 'sm-an' ?>"><?= isset($fw_stand['krank']) ? (int) $fw_stand['krank'] : 0 ?></b>
    <span class="sm-hilfe"><?= fw_e(fw_t('ALLG.GERADE')) ?></span>
  </div>
  <div class="sm-kachel"><?= fw_e(fw_t('ALLG.GEHEILT')) ?>
    <b><?= isset($fw_stand['geheilt_gesamt']) ? (int) $fw_stand['geheilt_gesamt'] : 0 ?></b>
    <span class="sm-hilfe"><?= fw_e(fw_t('ALLG.SEIT_BEGINN')) ?></span>
  </div>
  <div class="sm-kachel"><?= fw_e(fw_t('ALLG.LETZTE_PRUEFUNG')) ?>
    <b><?= fw_alter() < 0 ? '&ndash;' : (int) fw_alter() ?></b>
    <span class="sm-hilfe"><?= fw_alter() < 0 ? fw_e(fw_t('ALLG.NIE')) : fw_e(fw_t('ALLG.SEKUNDEN')) ?></span>
  </div>
</div>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. So bleibt jeder
     Reiter verlinkbar und die Seite ohne Skript bedienbar. Welcher Reiter
     offen ist, entscheidet der SERVER. -->
<div class="sm-tabs">
<?php foreach ($fw_reiter as $fw_k => $fw_schl): $fw_id = 'tab-' . $fw_k; ?>
	<a class="sm-tab<?= $fw_tab === $fw_id ? ' sm-active' : '' ?>" data-ziel="<?= fw_e($fw_id) ?>"
	   href="index.php?form=<?= fw_e($fw_k) ?>"><?= $fw_schl === null ? 'MQTT' : fw_e(fw_t($fw_schl)) ?></a>
<?php endforeach; ?>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $fw_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<h2><?= fw_e(fw_t('EINST.H_LAGE')) ?></h2>
<div class="sm-step"><?= fw_t('EINST.LAGE_ERKLAERUNG') ?></div>

<?php if (!empty($fw_stand['geraete'])) { ?>
<table class="sm-tbl">
<tr><th><?= fw_e(fw_t('TAB.GERAET')) ?></th><th><?= fw_e(fw_t('TAB.ZUSTAND')) ?></th>
    <th><?= fw_e(fw_t('TAB.ALTER')) ?></th><th><?= fw_e(fw_t('TAB.STUFE')) ?></th>
    <th><?= fw_e(fw_t('TAB.HEILUNGEN')) ?></th><th><?= fw_e(fw_t('TAB.LETZTE_TAT')) ?></th></tr>
<?php foreach ($fw_stand['geraete'] as $fw_nr => $fw_ee) { ?>
<tr>
  <td><b><?= fw_e($fw_ee['name']) ?></b></td>
  <td><b class="<?= $fw_ee['ok'] ? 'sm-an' : 'sm-aus' ?>"><?= $fw_ee['ok'] ? fw_e(fw_t('ALLG.GESUND')) : fw_e(fw_t('ALLG.GESTOERT')) ?></b>
      <br><span class="sm-hilfe"><?= fw_e(fw_t('GRUND.' . strtoupper($fw_ee['grund']))) ?></span></td>
  <td><?= (int) $fw_ee['alter'] < 0 ? '&ndash;' : (int) $fw_ee['alter'] . ' s' ?></td>
  <td><?= fw_e(fw_t('STUFE.' . (int) $fw_ee['stufe'])) ?></td>
  <td><?= (int) $fw_ee['heilungen'] ?></td>
  <td><span class="sm-hilfe"><?= fw_e($fw_ee['letzte_tat']) ?>
    <?php if (!empty($fw_ee['warum'])) { ?><br><?= fw_e(fw_t('WARUM.' . strtoupper($fw_ee['warum']))) ?><?php } ?>
  </span></td>
</tr>
<?php } ?>
</table>
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

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="<?= fw_e($fw_tab) ?>">

<h2><?= fw_e(fw_t('EINST.H_GERAETE')) ?></h2>
<div class="sm-step"><?= fw_t('EINST.GERAETE_ERKLAERUNG') ?></div>

<?php for ($fw_i = 0; $fw_i < FW_GERAETE; $fw_i++) { $g = $fw_cfg['geraete'][$fw_i]; ?>
<h3><?= fw_e(fw_t('EINST.GERAET')) ?> <?= $fw_i + 1 ?><?= $g['name'] !== '' ? ': ' . fw_e($g['name']) : '' ?></h3>
<table class="sm-tbl">
<tr>
  <td><label><?= fw_e(fw_t('EINST.L_NAME')) ?><br>
    <input data-role="none" type="text" size="16" name="g_name[<?= $fw_i ?>]" value="<?= fw_e($g['name']) ?>"></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_ART')) ?><br>
    <select data-role="none" name="g_art[<?= $fw_i ?>]">
<?php foreach (fw_arten() as $fw_a => $fw_as) { ?>
      <option value="<?= fw_e($fw_a) ?>"<?= $g['art'] === $fw_a ? ' selected' : '' ?>><?= fw_e(fw_t($fw_as)) ?></option>
<?php } ?>
    </select></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_PFAD')) ?><br>
    <input data-role="none" type="text" size="26" name="g_pfad[<?= $fw_i ?>]" value="<?= fw_e($g['pfad']) ?>"></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_THEMA')) ?><br>
    <input data-role="none" type="text" size="22" name="g_thema[<?= $fw_i ?>]" value="<?= fw_e($g['thema']) ?>"></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_HOECHSTALTER')) ?><br>
    <input data-role="none" type="text" size="5" name="g_alter[<?= $fw_i ?>]" value="<?= (int) $g['hoechstalter'] ?>"></label></td>
</tr>
<tr>
  <td><label><?= fw_e(fw_t('EINST.L_DIENST')) ?><br>
    <input data-role="none" type="text" size="16" name="g_dienst[<?= $fw_i ?>]" value="<?= fw_e($g['dienst']) ?>"></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_CONTAINER')) ?><br>
    <input data-role="none" type="text" size="14" name="g_container[<?= $fw_i ?>]" value="<?= fw_e($g['container']) ?>"></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_USB_PFAD')) ?><br>
    <input data-role="none" type="text" size="14" name="g_usb[<?= $fw_i ?>]" value="<?= fw_e($g['usb_pfad']) ?>"></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_HUB')) ?><br>
    <input data-role="none" type="text" size="8" name="g_hub[<?= $fw_i ?>]" value="<?= fw_e($g['hub']) ?>">
    <input data-role="none" type="text" size="3" name="g_port[<?= $fw_i ?>]" value="<?= (int) $g['port'] ?>"></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_HOECHSTSTUFE')) ?><br>
    <input data-role="none" type="text" size="3" name="g_stufe[<?= $fw_i ?>]" value="<?= (int) $g['hoechststufe'] ?>"></label></td>
</tr>
<tr>
  <td><label><input data-role="none" type="checkbox" name="g_aktiv[<?= $fw_i ?>]" value="1"<?= $g['aktiv'] ? ' checked' : '' ?>> <?= fw_e(fw_t('EINST.L_AKTIV')) ?></label></td>
  <td><label><input data-role="none" type="checkbox" name="g_heilen[<?= $fw_i ?>]" value="1"<?= $g['heilen'] ? ' checked' : '' ?>> <?= fw_e(fw_t('EINST.L_HEILEN')) ?></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_RUHE_S')) ?>
    <input data-role="none" type="text" size="5" name="g_ruhe[<?= $fw_i ?>]" value="<?= (int) $g['ruhe_s'] ?>"></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_ABSTAND_S')) ?>
    <input data-role="none" type="text" size="6" name="g_abstand[<?= $fw_i ?>]" value="<?= (int) $g['abstand_s'] ?>"></label></td>
  <td><label><?= fw_e(fw_t('EINST.L_JE_TAG')) ?>
    <input data-role="none" type="text" size="3" name="g_tag[<?= $fw_i ?>]" value="<?= (int) $g['je_tag'] ?>"></label></td>
</tr>
</table>
<?php } ?>
<p class="sm-hilfe"><?= fw_t('EINST.FELDER_HILFE') ?></p>

<h2><?= fw_e(fw_t('EINST.H_BETRIEB')) ?></h2>
<div class="sm-feld">
  <label for="fw_takt"><?= fw_e(fw_t('EINST.L_TAKT')) ?></label>
  <input data-role="none" type="text" id="fw_takt" name="takt" value="<?= (int) $fw_cfg['takt'] ?>">
  <p class="sm-hilfe"><?= fw_t('EINST.H_TAKT') ?></p>
</div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= fw_t('LEGENDE.AKTION_SPEICHERN') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?= fw_e(fw_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
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

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= $fw_cfg['mqtt_ein'] ? ' checked' : '' ?>> <?= fw_e(fw_t('MQTT.EIN')) ?></label>
</div>
<div class="sm-feld">
  <label for="fw_thema"><?= fw_e(fw_t('MQTT.THEMA')) ?></label>
  <input data-role="none" type="text" id="fw_thema" name="mqtt_topic" value="<?= fw_e($fw_cfg['mqtt_topic']) ?>">
  <p class="sm-hilfe"><?= fw_t('MQTT.THEMA_HILFE') ?></p>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= fw_t('LEGENDE.AKTION_SPEICHERN') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?= fw_e(fw_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h3><?= fw_e(fw_t('MQTT.H_THEMEN')) ?></h3>
<table class="sm-tbl">
<tr><th><?= fw_e(fw_t('MQTT.SP_THEMA')) ?></th><th><?= fw_e(fw_t('MQTT.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach (fw_mqtt_themen() as $fw_k => $fw_schl) { ?>
<tr><td><span class="sm-mono"><?= fw_e($fw_cfg['mqtt_topic'] . '/' . $fw_k) ?></span></td>
    <td><?= fw_e(fw_t($fw_schl)) ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= fw_t('MQTT.GERAETN_HILFE') ?></p>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $fw_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= fw_e(fw_t('LOX.H_VORLAGE')) ?></h2>
<div class="sm-step"><?= fw_t('LOX.ERKLAERUNG') ?></div>
<?php if (!$fw_geraete) { ?>
<div class="sm-warnung"><?= fw_t('LOX.KEINE_GERAETE') ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= fw_t('LEGENDE.TECHNIK_XML') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= fw_t('LEGENDE.AKTION_TOKEN') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="vi"><?= fw_e(fw_t('LOX.K_VI')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= fw_e(fw_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>

<h3><?= fw_e(fw_t('LOX.H_ADRESSE')) ?></h3>
<p class="sm-hilfe"><?= fw_t('LOX.ADRESSE_HILFE') ?></p>
<table class="sm-tbl">
<tr><th><?= fw_e(fw_t('LOX.SP_ZWECK')) ?></th><th><?= fw_e(fw_t('LOX.SP_ADRESSE')) ?></th></tr>
<tr><td><?= fw_e(fw_t('LOX.Z_STATUS')) ?></td><td><span class="sm-mono"><?= fw_e(fw_endpunkt() . '?token=' . fw_token() . '&aktion=status') ?></span></td></tr>
<tr><td><?= fw_e(fw_t('LOX.Z_JSON')) ?></td><td><span class="sm-mono"><?= fw_e(fw_endpunkt() . '?token=' . fw_token() . '&aktion=json') ?></span></td></tr>
</table>
<p class="sm-hilfe"><?= fw_t('LOX.TOKEN_HINWEIS') ?></p>

<h3><?= fw_e(fw_t('LOX.H_FELDER')) ?></h3>
<table class="sm-tbl">
<tr><th><?= fw_e(fw_t('LOX.SP_FELD')) ?></th><th><?= fw_e(fw_t('LOX.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach (fw_felder() as $fw_f => $fw_info) { ?>
<tr><td><span class="sm-mono">G&lt;n&gt;<?= fw_e($fw_f) ?></span></td>
    <td><?= fw_e(fw_t($fw_info[3])) ?><?= $fw_info[0] !== '' ? ' [' . fw_e($fw_info[0]) . ']' : '' ?></td></tr>
<?php } ?>
</table>
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
<?php foreach (array(
    'faehigkeit' => array('sm-b-lesen', 'TEST.K_FAEHIGKEIT'),
    'messen'     => array('sm-b-lesen', 'TEST.K_MESSEN'),
    'selbsttest' => array('sm-b-technik', 'TEST.K_SELBSTTEST'),
    'zeile'      => array('sm-b-technik', 'TEST.K_ZEILE'),
    'mqtt'       => array('sm-b-technik', 'TEST.K_MQTT'),
    'endpunkt'   => array('sm-b-technik', 'TEST.K_ENDPUNKT'),
) as $fw_k => $fw_i2) { ?>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn <?= fw_e($fw_i2[0]) ?>" type="submit" name="test" value="<?= fw_e($fw_k) ?>"><?= fw_e(fw_t($fw_i2[1])) ?></button>
  </form>
<?php } ?>
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
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= fw_t('LEGENDE.AKTION_LOG') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
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
})();
</script>
<?php
if ($fw_rahmen) {
    LBWeb::lbfooter();
}
