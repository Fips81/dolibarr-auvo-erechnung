<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de> — fork, renamed and modified as AuVo E-Rechnung
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Page de configuration du module AuVo E-Rechnung
 */

// Charger l'environnement Dolibarr
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once dol_buildpath('/auvoerechnung/core/lib/auvoerechnung.lib.php');

// Sécurité
if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(["admin", "auvoerechnung@auvoerechnung"]);

// Auto-correctif : sous Dolibarr 23 les extrafields Chorus pouvaient être créés
// avec printable!=0 et polluaient la note du PDF (cf auvoerechnung_fix_chorus_*).
// On corrige à l'ouverture de la config — pas besoin de réactiver le module.
auvoerechnung_fix_chorus_extrafields_display($db);

$action = GETPOST('action', 'aZ09');

// Conditions de règlement actives (dictionnaire c_payment_term), pour la
// configuration des paliers Skonto.
$paymentTerms = [];
$resql = $db->query("SELECT rowid, code, libelle, libelle_facture, type_cdr, nbjour FROM ".MAIN_DB_PREFIX."c_payment_term WHERE active = 1 AND entity IN (".getEntity('c_payment_term').") ORDER BY sortorder, rowid");
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$paymentTerms[$obj->code] = $obj;
	}
}

// Les valeurs par défaut des mentions légales BR-FR sont définies dans la lib
// (AUVOERECHNUNG_DEFAULT_NOTE_*) pour rester synchronisées avec le builder XML.

// Sauvegarde des paramètres
if ($action == 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	// CSRF : vérifier le token courant (pas newToken() qui génère un futur token)
	if (GETPOST('token', 'alpha') !== currentToken()) {
		accessforbidden('Bad value for CSRF token');
	}
	$error = 0;

	// Mode d'injection : on n'accepte que les 3 valeurs connues (défaut auto).
	$injMode = strtolower(trim(GETPOST('AUVOERECHNUNG_INJECTION_MODE', 'alpha')));
	if (!in_array($injMode, ['auto', 'inprocess', 'subprocess'], true)) {
		$injMode = 'auto';
	}

	$updates = [
		['AUVOERECHNUNG_INJECTION_MODE', $injMode, 'chaine'],
		['AUVOERECHNUNG_BANK_ACCOUNT', GETPOSTINT('AUVOERECHNUNG_BANK_ACCOUNT'),    'int'],
		['AUVOERECHNUNG_PAYMENT_MEANS',trim(GETPOST('AUVOERECHNUNG_PAYMENT_MEANS', 'alpha')), 'chaine'],
		['AUVOERECHNUNG_STRICT_MODE',  GETPOSTINT('AUVOERECHNUNG_STRICT_MODE'),     'int'],
		['AUVOERECHNUNG_BR_CHECK',     GETPOSTINT('AUVOERECHNUNG_BR_CHECK'),        'int'],
		['AUVOERECHNUNG_UPDATE_CHECK', GETPOSTINT('AUVOERECHNUNG_UPDATE_CHECK') ? 1 : 0, 'int'],
		['AUVOERECHNUNG_CHORUS_ENABLED', GETPOSTINT('AUVOERECHNUNG_CHORUS_ENABLED'), 'int'],
		['AUVOERECHNUNG_PHP_CLI_PATH', trim(GETPOST('AUVOERECHNUNG_PHP_CLI_PATH', 'alphanohtml')), 'chaine'],
		['AUVOERECHNUNG_VERAPDF_PATH', trim(GETPOST('AUVOERECHNUNG_VERAPDF_PATH', 'alphanohtml')), 'chaine'],
		['AUVOERECHNUNG_XRECHNUNG_VERSION', preg_match('/^\d+\.\d+$/', trim(GETPOST('AUVOERECHNUNG_XRECHNUNG_VERSION', 'alphanohtml'))) ? trim(GETPOST('AUVOERECHNUNG_XRECHNUNG_VERSION', 'alphanohtml')) : '3.0', 'chaine'],
		['AUVOERECHNUNG_CONTACT_NAME', trim(GETPOST('AUVOERECHNUNG_CONTACT_NAME', 'alphanohtml')), 'chaine'],
		['AUVOERECHNUNG_CONTACT_PHONE', trim(GETPOST('AUVOERECHNUNG_CONTACT_PHONE', 'alphanohtml')), 'chaine'],
		['AUVOERECHNUNG_CONTACT_EMAIL', trim(GETPOST('AUVOERECHNUNG_CONTACT_EMAIL', 'email')), 'chaine'],
		['AUVOERECHNUNG_VAT_DUE_DATE', in_array(GETPOST('AUVOERECHNUNG_VAT_DUE_DATE', 'aZ09'), ['auto', 'none', '5', '72'], true) ? GETPOST('AUVOERECHNUNG_VAT_DUE_DATE', 'aZ09') : 'auto', 'chaine'],
		['AUVOERECHNUNG_XRECHNUNG_SYNTAX', GETPOST('AUVOERECHNUNG_XRECHNUNG_SYNTAX', 'aZ09') === 'ubl' ? 'ubl' : 'cii', 'chaine'],
		['AUVOERECHNUNG_XR_EMBED_PDF', GETPOSTINT('AUVOERECHNUNG_XR_EMBED_PDF') ? 1 : 0, 'int'],
		['AUVOERECHNUNG_XR_EMBED_FILES', GETPOSTINT('AUVOERECHNUNG_XR_EMBED_FILES') ? 1 : 0, 'int'],
		['AUVOERECHNUNG_MAIL_ATTACH_XR', GETPOSTINT('AUVOERECHNUNG_MAIL_ATTACH_XR') ? 1 : 0, 'int'],
		['AUVOERECHNUNG_OZGRE_EMAIL', isValidEmail(trim(GETPOST('AUVOERECHNUNG_OZGRE_EMAIL', 'email'))) ? trim(GETPOST('AUVOERECHNUNG_OZGRE_EMAIL', 'email')) : '', 'chaine'],
		['AUVOERECHNUNG_NOTE_PMD',     trim(GETPOST('AUVOERECHNUNG_NOTE_PMD', 'restricthtml')),    'chaine'],
		['AUVOERECHNUNG_NOTE_PMT',     trim(GETPOST('AUVOERECHNUNG_NOTE_PMT', 'restricthtml')),    'chaine'],
		['AUVOERECHNUNG_NOTE_AAB',     trim(GETPOST('AUVOERECHNUNG_NOTE_AAB', 'restricthtml')),    'chaine'],
		['AUVOERECHNUNG_NOTES_IN_FOOTER', GETPOSTINT('AUVOERECHNUNG_NOTES_IN_FOOTER'), 'int'],
		['AUVOERECHNUNG_NOTES_OVERWRITE', GETPOSTINT('AUVOERECHNUNG_NOTES_OVERWRITE'), 'int'],
	];
	// Paliers Skonto par condition de règlement (2 maximum), stockés en JSON.
	// Une ligne incomplète ou hors bornes est ignorée avec un avertissement.
	$skonto = [];
	$skontoDays = GETPOST('skonto_days', 'array');
	$skontoPct = GETPOST('skonto_pct', 'array');
	foreach ($paymentTerms as $ptCode => $pt) {
		for ($i = 0; $i < 2; $i++) {
			$d = trim((string) ($skontoDays[$ptCode][$i] ?? ''));
			$p = str_replace(',', '.', trim((string) ($skontoPct[$ptCode][$i] ?? '')));
			if ($d === '' && $p === '') {
				continue;
			}
			if (!preg_match('/^\d{1,3}$/', $d) || (int) $d < 1 || (int) $d > 365 || !is_numeric($p) || (float) $p <= 0 || (float) $p >= 100) {
				setEventMessages($langs->trans('AuvoERechnungSkontoInvalid', $pt->libelle), null, 'warnings');
				continue;
			}
			$skonto[$ptCode][] = ['days' => (int) $d, 'percent' => round((float) $p, 2)];
		}
	}
	$updates[] = ['AUVOERECHNUNG_SKONTO', empty($skonto) ? '' : json_encode($skonto), 'chaine'];

	// Le bloc PA (suffixe d'endpoint BT-34) n'est affiché que pour un vendeur
	// FR : ne pas écraser la valeur enregistrée quand le champ est absent du POST.
	if (GETPOSTISSET('AUVOERECHNUNG_ENDPOINT_SUFFIX_SELLER')) {
		$updates[] = ['AUVOERECHNUNG_ENDPOINT_SUFFIX_SELLER', trim(GETPOST('AUVOERECHNUNG_ENDPOINT_SUFFIX_SELLER', 'alphanohtml')), 'chaine'];
	}
	foreach ($updates as $u) {
		if (dolibarr_set_const($db, $u[0], $u[1], $u[2], 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	// Toggle « recopier les mentions en pied » à Oui → on pousse nos mentions
	// dans la « Mention complémentaire » de la facture (INVOICE_FREE_TEXT, celle
	// que le PDF imprime). Champ vide = on met nos mentions ; sinon on AJOUTE à
	// la suite, sauf si l'option « écraser » est active (alors on remplace).
	$footerAdded = 0;
	if (!$error && GETPOSTINT('AUVOERECHNUNG_NOTES_IN_FOOTER')) {
		$footerAdded = auvoerechnung_append_notes_to_footer($db, GETPOSTINT('AUVOERECHNUNG_NOTES_OVERWRITE') === 1);
	}

	// Police du PDF (constante globale Dolibarr MAIN_PDF_FORCE_FONT). On la pose
	// en visible=1 pour qu'elle reste gérable (Config > Divers ET ce menu). Vide
	// = on retire le forçage (police par défaut Dolibarr). On valide la valeur
	// contre la liste réelle des polices pour ne rien poser d'invalide.
	if (!$error) {
		$pdfFont = GETPOST('MAIN_PDF_FORCE_FONT', 'alpha');
		$availableFonts = auvoerechnung_list_pdf_fonts();
		if ($pdfFont === '' || isset($availableFonts[$pdfFont])) {
			dolibarr_set_const($db, 'MAIN_PDF_FORCE_FONT', $pdfFont, 'chaine', 1, '', $conf->entity);
		}
	}

	if (!$error) {
		$msg = $langs->trans("SetupSaved");
		if ($footerAdded > 0) {
			$msg .= ' — '.$langs->trans('AuvoERechnungCopyNotesDone', $footerAdded);
		}
		setEventMessages($msg, null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}

// Correction en un clic : pose MAIN_PDF_FORCE_FONT = pdfahelvetica (PDF/A-3).
// Réglage global Dolibarr (toutes les éditions PDF), pas seulement le module.
if ($action == 'setforcefont') {
	if (GETPOST('token', 'alpha') !== currentToken()) {
		accessforbidden('Bad value for CSRF token');
	}
	if (dolibarr_set_const($db, 'MAIN_PDF_FORCE_FONT', 'pdfahelvetica', 'chaine', 1, '', $conf->entity) > 0) {
		setEventMessages($langs->trans("AuvoERechnungForceFontSet"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}

// Testrechnung : XML ZUGFeRD et XRechnung à partir des données réelles de
// la société et d'un client fictif, contrôlés (XSD + règles), sans rien
// enregistrer. Permet de vérifier la configuration avant la première facture.
$testResults = null;
if ($action == 'testinvoice') {
	if (GETPOST('token', 'alpha') !== currentToken()) {
		accessforbidden('Bad value for CSRF token');
	}
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	require_once dol_buildpath('/auvoerechnung/core/lib/auvoerechnung_rules.php');
	$testInvoice = new Facture($db);
	$testBuyer = new Societe($db);
	auvoerechnung_sample_invoice($testInvoice, $testBuyer, $mysoc);
	// Comme une vraie facture : contact XRechnung de repli = créateur (ici l'utilisateur connecté)
	$testInvoice->fk_user_author = $user->id;
	$testResults = [];
	foreach (['zugferd', 'xrechnung', 'xrechnung_ubl'] as $label) {
		$bw = [];
		if ($label === 'zugferd') {
			$xmlTest = $ciiTest = auvoerechnung_build_xml($testInvoice, $mysoc, $bw);
		} else {
			$xr = auvoerechnung_build_xrechnung($testInvoice, $mysoc, $bw, [], $label === 'xrechnung_ubl' ? 'ubl' : 'cii');
			$xmlTest = $xr['xml'];
			$ciiTest = $xr['cii'];
		}
		$errors = [];
		$xsdErr = auvoerechnung_validate_xsd($xmlTest, dirname(__DIR__));
		if ($xsdErr !== null) {
			$errors[] = $xsdErr;
		}
		$errors = array_merge($errors, auvoerechnung_validate_business_rules($ciiTest));
		if ($label !== 'zugferd') {
			$errors = array_merge(auvoerechnung_check_xrechnung($testInvoice, $mysoc), $errors);
		}
		$testResults[$label] = ['xml' => $xmlTest, 'errors' => $errors, 'warnings' => $bw];
	}
}

// Affichage
llxHeader('', $langs->trans("AuvoERechnungSetup"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("AuvoERechnungSetup"), $linkback, 'title_setup');

// Bandeau "Nouvelle version disponible" si le check GitHub remonte une version > locale
require_once dirname(__DIR__).'/core/modules/modAuvoERechnung.class.php';
$modDesc = new modAuvoERechnung($db);
$updateInfo = auvoerechnung_check_latest_release($db, $modDesc->version);
if ($updateInfo !== null) {
	print '<div class="warning" style="margin:8px 0;padding:10px;border-left:4px solid #e67e22;background:#fff3e0;">';
	print '<strong>'.$langs->trans("AuvoERechnungUpdateAvailable").'</strong> : ';
	print $langs->trans("AuvoERechnungUpdateAvailableMsg", dol_escape_htmltag($updateInfo['version']), dol_escape_htmltag($modDesc->version));
	print ' <a href="'.dol_escape_htmltag($updateInfo['url']).'" target="_blank" rel="noopener">'.$langs->trans("AuvoERechnungUpdateSeeRelease").'</a>';
	print '</div>';
}

print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';

// ---- Bloc 1 : Facturation ----
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("AuvoERechnungSecBilling").'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("AuvoERechnungBankAccount").'</td><td>';
$currentBankAccount = getDolGlobalInt('AUVOERECHNUNG_BANK_ACCOUNT');
$resql = $db->query("SELECT rowid, label, iban_prefix, bic FROM ".MAIN_DB_PREFIX."bank_account WHERE clos = 0 AND entity IN (".getEntity('bank_account').") ORDER BY label");
print '<select name="AUVOERECHNUNG_BANK_ACCOUNT" class="flat minwidth300">';
print '<option value="0">-- '.$langs->trans("Select").' --</option>';
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$infoIban = !empty($obj->iban_prefix) ? ' ('.auvoerechnung_iban_short($obj->iban_prefix).')' : ' ('.$langs->trans("AuvoERechnungNoIban").')';
		print '<option value="'.$obj->rowid.'"'.($currentBankAccount == $obj->rowid ? ' selected' : '').'>'.dol_escape_htmltag($obj->label.$infoIban).'</option>';
	}
}
print '</select></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("AuvoERechnungPaymentMeans").'<br><span class="opacitymedium small">'.$langs->trans("AuvoERechnungPaymentMeansHint").'</span></td><td>';
$currentMeans = getDolGlobalString('AUVOERECHNUNG_PAYMENT_MEANS', '30');
print '<select name="AUVOERECHNUNG_PAYMENT_MEANS" class="flat">';
foreach (array('30'=>'PaymentMeans30','58'=>'PaymentMeans58','59'=>'PaymentMeans59','49'=>'PaymentMeans49') as $code=>$k) {
	print '<option value="'.$code.'"'.($currentMeans == $code ? ' selected' : '').'>'.$code.' - '.$langs->trans($k).'</option>';
}
print '</select></td></tr>';

// ---- Bloc 2 : Mentions légales ----
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("AuvoERechnungLegalNotes").'</td></tr>';
// Les défauts PMD/PMT/AAB sont des mentions du droit français : pour un vendeur
// établi hors de France les champs restent vides tant qu'il n'a pas saisi les
// siennes (cf. auvoerechnung_resolve_legal_notes).
$legalNotes = auvoerechnung_resolve_legal_notes(auvoerechnung_seller_is_fr($mysoc));
if (!auvoerechnung_seller_is_fr($mysoc)) {
	print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium small">'.$langs->trans("AuvoERechnungLegalNotesForeignHint").'</span></td></tr>';
}
foreach ([
	'PMD' => 'AuvoERechnungNotePMD',
	'PMT' => 'AuvoERechnungNotePMT',
	'AAB' => 'AuvoERechnungNoteAAB',
] as $code => $transKey) {
	$val = $legalNotes[$code];
	print '<tr class="oddeven"><td>'.$langs->trans($transKey).'</td><td><textarea name="AUVOERECHNUNG_NOTE_'.$code.'" class="flat minwidth500" rows="3">'.dol_escape_htmltag($val).'</textarea></td></tr>';
}
print '<tr class="oddeven"><td>'.$langs->trans("AuvoERechnungNotesInFooter").'<br><span class="opacitymedium small">'.$langs->trans("AuvoERechnungNotesInFooterHint").'</span></td><td>';
$notesFooter = getDolGlobalInt('AUVOERECHNUNG_NOTES_IN_FOOTER', 0);
print '<select name="AUVOERECHNUNG_NOTES_IN_FOOTER" class="flat">';
print '<option value="0"'.(!$notesFooter ? ' selected' : '').'>'.$langs->trans("No").'</option>';
print '<option value="1"'.($notesFooter ? ' selected' : '').'>'.$langs->trans("Yes").'</option>';
print '</select></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("AuvoERechnungNotesOverwrite").'<br><span class="opacitymedium small">'.$langs->trans("AuvoERechnungNotesOverwriteHint").'</span></td><td>';
$notesOverwrite = getDolGlobalInt('AUVOERECHNUNG_NOTES_OVERWRITE', 0);
print '<select name="AUVOERECHNUNG_NOTES_OVERWRITE" class="flat">';
print '<option value="0"'.(!$notesOverwrite ? ' selected' : '').'>'.$langs->trans("No").' ('.$langs->trans("AuvoERechnungNotesAppend").')</option>';
print '<option value="1"'.($notesOverwrite ? ' selected' : '').'>'.$langs->trans("Yes").' ('.$langs->trans("AuvoERechnungNotesReplace").')</option>';
print '</select></td></tr>';

// ---- Bloc 2b : XRechnung (Allemagne, CIUS KoSIT) ----
// Contact vendeur BG-6 (obligatoire en XRechnung) et version de la spécification.
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("AuvoERechnungSecXRechnung").'</td></tr>';
print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium small">'.$langs->trans("AuvoERechnungXrInfo").'</span></td></tr>';
foreach ([
	'AUVOERECHNUNG_CONTACT_NAME'  => 'AuvoERechnungXrContactName',
	'AUVOERECHNUNG_CONTACT_PHONE' => 'AuvoERechnungXrContactPhone',
	'AUVOERECHNUNG_CONTACT_EMAIL' => 'AuvoERechnungXrContactEmail',
] as $constName => $transKey) {
	print '<tr class="oddeven"><td>'.$langs->trans($transKey);
	if ($constName === 'AUVOERECHNUNG_CONTACT_NAME') {
		print '<br><span class="opacitymedium small">'.$langs->trans("AuvoERechnungXrContactHint").'</span>';
	}
	print '</td><td><input type="text" name="'.$constName.'" class="flat minwidth300" value="'.dol_escape_htmltag(getDolGlobalString($constName, '')).'"></td></tr>';
}
print '<tr class="oddeven"><td>'.$langs->trans("AuvoERechnungXrVersion").'<br><span class="opacitymedium small">'.$langs->trans("AuvoERechnungXrVersionHint").'</span></td><td>';
print '<input type="text" name="AUVOERECHNUNG_XRECHNUNG_VERSION" class="flat width75" value="'.dol_escape_htmltag(getDolGlobalString('AUVOERECHNUNG_XRECHNUNG_VERSION', '3.0')).'"></td></tr>';
// BT-8 : exigibilité de la TVA (Soll-/Ist-Versteuerung)
$vatDue = getDolGlobalString('AUVOERECHNUNG_VAT_DUE_DATE', 'auto');
print '<tr class="oddeven"><td>'.$langs->trans("AuvoERechnungVatDueDate").'<br><span class="opacitymedium small">'.$langs->trans("AuvoERechnungVatDueDateHint").'</span></td><td>';
print '<select name="AUVOERECHNUNG_VAT_DUE_DATE" class="flat">';
foreach (['auto' => 'AuvoERechnungVatDueDateAuto', 'none' => 'AuvoERechnungVatDueDateNone', '5' => 'AuvoERechnungVatDueDateInvoice', '72' => 'AuvoERechnungVatDueDatePayment'] as $val => $key) {
	print '<option value="'.$val.'"'.($vatDue === (string) $val ? ' selected' : '').'>'.$langs->trans($key).'</option>';
}
print '</select></td></tr>';
// Syntaxe de la XRechnung par défaut (CII ou UBL), modifiable par client
$xrSyntax = getDolGlobalString('AUVOERECHNUNG_XRECHNUNG_SYNTAX', 'cii') === 'ubl' ? 'ubl' : 'cii';
print '<tr class="oddeven"><td>'.$langs->trans("AuvoERechnungXrSyntax").'<br><span class="opacitymedium small">'.$langs->trans("AuvoERechnungXrSyntaxHint").'</span></td><td>';
print '<select name="AUVOERECHNUNG_XRECHNUNG_SYNTAX" class="flat">';
print '<option value="cii"'.($xrSyntax === 'cii' ? ' selected' : '').'>CII (UN/CEFACT)</option>';
print '<option value="ubl"'.($xrSyntax === 'ubl' ? ' selected' : '').'>UBL 2.1 (OASIS)</option>';
print '</select></td></tr>';
// Adresse e-mail individuelle du compte OZG-RE (canal e-mail de la plateforme
// du Bund) : active l'entrée « XRechnung an OZG-RE senden » de la fiche facture.
print '<tr class="oddeven"><td>'.$langs->trans("AuvoERechnungOzgReEmail").'<br><span class="opacitymedium small">'.$langs->trans("AuvoERechnungOzgReEmailHint").'</span></td><td>';
print '<input type="text" name="AUVOERECHNUNG_OZGRE_EMAIL" class="flat minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('AUVOERECHNUNG_OZGRE_EMAIL', '')).'"></td></tr>';
// Oui/Non : pièces jointes BG-24 et pièce jointe automatique au formulaire e-mail
foreach ([
	'AUVOERECHNUNG_XR_EMBED_PDF'   => ['AuvoERechnungXrEmbedPdf', 0],
	'AUVOERECHNUNG_XR_EMBED_FILES' => ['AuvoERechnungXrEmbedFiles', 0],
	'AUVOERECHNUNG_MAIL_ATTACH_XR' => ['AuvoERechnungMailAttachXr', 1],
] as $constName => [$transKey, $default]) {
	$cur = getDolGlobalInt($constName, $default);
	print '<tr class="oddeven"><td>'.$langs->trans($transKey).'<br><span class="opacitymedium small">'.$langs->trans($transKey.'Hint').'</span></td><td>';
	print '<select name="'.$constName.'" class="flat">';
	print '<option value="1"'.($cur ? ' selected' : '').'>'.$langs->trans("Yes").'</option>';
	print '<option value="0"'.(!$cur ? ' selected' : '').'>'.$langs->trans("No").'</option>';
	print '</select></td></tr>';
}

// ---- Bloc 2c : Skonto (escompte) par condition de règlement ----
// Émis en BT-20 au format KoSIT #SKONTO#TAGE=n#PROZENT=n.nn# (BR-DE-18), dans
// la XRechnung comme dans le ZUGFeRD. Le libellé de la condition n'est
// qu'une suggestion (placeholder) : rien n'est appliqué sans saisie.
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("AuvoERechnungSecSkonto").'</td></tr>';
print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium small">'.$langs->trans("AuvoERechnungSkontoInfo").'</span></td></tr>';
foreach ($paymentTerms as $ptCode => $pt) {
	$ptLabel = $langs->trans('PaymentConditionShort'.$ptCode) !== 'PaymentConditionShort'.$ptCode ? $langs->trans('PaymentConditionShort'.$ptCode) : $pt->libelle;
	$terms = auvoerechnung_get_skonto_terms($ptCode);
	$guess = empty($terms) ? auvoerechnung_guess_skonto($pt->libelle.' '.$pt->libelle_facture) : null;
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($ptLabel);
	if ((int) $pt->nbjour > 0) {
		print ' <span class="opacitymedium small">('.$langs->trans('AuvoERechnungSkontoNetDays', (int) $pt->nbjour).')</span>';
	}
	if ($guess) {
		print '<br><span class="opacitymedium small">'.$langs->trans('AuvoERechnungSkontoGuess', $guess['days'], price($guess['percent'])).'</span>';
	}
	foreach ($terms as $t) {
		if ((int) $pt->type_cdr === 0 && (int) $pt->nbjour > 1 && $t['days'] >= (int) $pt->nbjour) {
			print '<br><span class="warning small">'.$langs->trans('AuvoERechnungSkontoAfterDue', $t['days'], (int) $pt->nbjour).'</span>';
		}
	}
	print '</td><td class="nowraponall">';
	for ($i = 0; $i < 2; $i++) {
		$t = $terms[$i] ?? null;
		$phDays = ($i === 0 && $guess) ? ' placeholder="'.$guess['days'].'"' : '';
		$phPct = ($i === 0 && $guess) ? ' placeholder="'.dol_escape_htmltag(price($guess['percent'])).'"' : '';
		print '<input type="text" name="skonto_days['.dol_escape_htmltag($ptCode).']['.$i.']" class="flat width50 right" value="'.($t ? $t['days'] : '').'"'.$phDays.'> '.$langs->trans('AuvoERechnungSkontoDays').' ';
		print '<input type="text" name="skonto_pct['.dol_escape_htmltag($ptCode).']['.$i.']" class="flat width50 right" value="'.($t ? dol_escape_htmltag(price($t['percent'])) : '').'"'.$phPct.'> %';
		print $i === 0 ? ' &nbsp; &nbsp; ' : '';
	}
	print '</td></tr>';
}

// ---- Bloc 3 : Secteur public (Chorus Pro) ----
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("AuvoERechnungSecPublic").'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("AuvoERechnungChorusEnabled").'<br><span class="opacitymedium small">'.$langs->trans("AuvoERechnungChorusEnabledHint").'</span></td><td>';
$chorusEnabled = getDolGlobalInt('AUVOERECHNUNG_CHORUS_ENABLED', 0);
print '<select name="AUVOERECHNUNG_CHORUS_ENABLED" class="flat">';
print '<option value="0"'.(!$chorusEnabled ? ' selected' : '').'>'.$langs->trans("No").'</option>';
print '<option value="1"'.($chorusEnabled ? ' selected' : '').'>'.$langs->trans("Yes").'</option>';
print '</select></td></tr>';

// ---- Bloc 4 : Validation & conformité ----
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("AuvoERechnungSecValidation").'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("AuvoERechnungBrCheck").'<br><span class="opacitymedium small">'.$langs->trans("AuvoERechnungBrCheckHint").'</span></td><td>';
$brCheck = getDolGlobalInt('AUVOERECHNUNG_BR_CHECK', 1);
print '<select name="AUVOERECHNUNG_BR_CHECK" class="flat">';
print '<option value="1"'.($brCheck ? ' selected' : '').'>'.$langs->trans("Yes").'</option>';
print '<option value="0"'.(!$brCheck ? ' selected' : '').'>'.$langs->trans("No").'</option>';
print '</select></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("AuvoERechnungUpdateCheck").'<br><span class="opacitymedium small">'.$langs->trans("AuvoERechnungUpdateCheckHint").'</span></td><td>';
$updCheck = getDolGlobalInt('AUVOERECHNUNG_UPDATE_CHECK', 1);
print '<select name="AUVOERECHNUNG_UPDATE_CHECK" class="flat">';
print '<option value="1"'.($updCheck ? ' selected' : '').'>'.$langs->trans("Yes").'</option>';
print '<option value="0"'.(!$updCheck ? ' selected' : '').'>'.$langs->trans("No").'</option>';
print '</select></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("AuvoERechnungStrictMode").'<br><span class="opacitymedium small">'.$langs->trans("AuvoERechnungStrictModeHint").'</span></td><td>';
$strict = getDolGlobalInt('AUVOERECHNUNG_STRICT_MODE', 0);
print '<select name="AUVOERECHNUNG_STRICT_MODE" class="flat">';
print '<option value="0"'.($strict == 0 ? ' selected' : '').'>'.$langs->trans("AuvoERechnungStrictModeBestEffort").'</option>';
print '<option value="1"'.($strict == 1 ? ' selected' : '').'>'.$langs->trans("AuvoERechnungStrictModeStrict").'</option>';
print '</select></td></tr>';

// Police du PDF (MAIN_PDF_FORCE_FONT) : liste les polices du Dolibarr, marquées
// conformes Factur-X (embarquées ✓) ou non (base-14 ⚠). Conformes en premier.
print '<tr class="oddeven"><td>'.$langs->trans("AuvoERechnungPdfFont").'<br><span class="opacitymedium small">'.$langs->trans("AuvoERechnungPdfFontHint").'</span></td><td>';
$curFont = getDolGlobalString('MAIN_PDF_FORCE_FONT', '');
$pdfFonts = auvoerechnung_list_pdf_fonts();
print '<select name="MAIN_PDF_FORCE_FONT" class="flat minwidth300">';
print '<option value="">'.$langs->trans("AuvoERechnungPdfFontDefault").'</option>';
foreach (array(true, false) as $wantEmbedded) {
	foreach ($pdfFonts as $fname => $isEmbedded) {
		if ($isEmbedded !== $wantEmbedded) {
			continue;
		}
		$tag = $isEmbedded ? $langs->trans("AuvoERechnungPdfFontOk") : $langs->trans("AuvoERechnungPdfFontKo");
		print '<option value="'.dol_escape_htmltag($fname).'"'.($curFont === $fname ? ' selected' : '').'>'.dol_escape_htmltag($fname.' — '.$tag).'</option>';
	}
}
print '</select></td></tr>';

// ---- Bloc 5 : Technique (avancé) ----
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("AuvoERechnungSecTech").'</td></tr>';

// Mode d'injection du XML dans le PDF. In-process (sans exec) par défaut :
// fonctionne sur les hébergements mutualisés où exec() est désactivé. Le
// sous-process (historique) reste disponible en repli/forçage.
$injModeCur = strtolower(trim(getDolGlobalString('AUVOERECHNUNG_INJECTION_MODE', 'auto')));
if (!in_array($injModeCur, ['auto', 'inprocess', 'subprocess'], true)) {
	$injModeCur = 'auto';
}
print '<tr class="oddeven"><td>'.$langs->trans("AuvoERechnungInjectionMode").'<br><span class="opacitymedium small">'.$langs->trans("AuvoERechnungInjectionModeHint").'</span></td><td>';
print '<select name="AUVOERECHNUNG_INJECTION_MODE" class="flat minwidth300">';
foreach (['auto' => 'AuvoERechnungInjectionModeAuto', 'inprocess' => 'AuvoERechnungInjectionModeInProcess', 'subprocess' => 'AuvoERechnungInjectionModeSubprocess'] as $injVal => $injKey) {
	print '<option value="'.$injVal.'"'.($injModeCur === $injVal ? ' selected' : '').'>'.$langs->trans($injKey).'</option>';
}
print '</select></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("AuvoERechnungPhpCliPath").'<br><span class="opacitymedium small">'.$langs->trans("AuvoERechnungPhpCliPathHint").'</span></td><td>';
print '<input type="text" name="AUVOERECHNUNG_PHP_CLI_PATH" class="flat minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('AUVOERECHNUNG_PHP_CLI_PATH', '')).'" placeholder="'.$langs->trans("AuvoERechnungPhpCliAutoPlaceholder").'"></td></tr>';
// veraPDF : outil EXTERNE optionnel, non embarqué et non installé par défaut.
// Vide = pas de post-validation (le contrôle XSD + règles BR internes reste actif).
print '<tr class="oddeven"><td>'.$langs->trans("AuvoERechnungVeraPdfPath").'<br><span class="opacitymedium small">'.$langs->trans("AuvoERechnungVeraPdfPathHint").'</span></td><td>';
print '<input type="text" name="AUVOERECHNUNG_VERAPDF_PATH" class="flat minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('AUVOERECHNUNG_VERAPDF_PATH', '')).'" placeholder="'.$langs->trans("AuvoERechnungVeraPdfPlaceholder").'"></td></tr>';

// ---- Bloc 6 : Adressage Plateforme Agréée (PA) ----
// Suffixe d'endpoint VENDEUR (BT-34). Certaines PA exigent que l'adresse
// électronique du vendeur ne soit pas le SIREN nu mais un endpoint suffixé
// (ex Hubtimize : "<SIREN>_Status"). Vide = SIREN nu. L'adresse acheteur
// (BT-49) reste toujours le SIREN nu (la PA destinataire route).
// Propre à la réforme française : masqué pour un vendeur établi hors de France.
if (auvoerechnung_seller_is_fr($mysoc)) {
	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("AuvoERechnungSecPA").'</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans("AuvoERechnungEndpointSuffixSeller").'<br><span class="opacitymedium small">'.$langs->trans("AuvoERechnungEndpointSuffixSellerHint").'</span></td><td>';
	print '<input type="text" name="AUVOERECHNUNG_ENDPOINT_SUFFIX_SELLER" class="flat minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('AUVOERECHNUNG_ENDPOINT_SUFFIX_SELLER', '')).'" placeholder="'.$langs->trans("AuvoERechnungEndpointSuffixSellerPlaceholder").'"></td></tr>';
}

print '</table>';
print '<br><div class="center"><input type="submit" class="button button-save" value="'.$langs->trans("Save").'"></div>';
print '</form>';

// Info
print '<br>';
print '<div class="info">';
print $langs->trans("AuvoERechnungInfo");
print '</div>';

// === Diagnostic des infos obligatoires ===
print '<br>';
print load_fiche_titre($langs->trans("AuvoERechnungTestTitle"), '', '');
print '<div class="opacitymedium" style="margin-bottom:8px;">'.$langs->trans("AuvoERechnungTestInfo").'</div>';
print '<a class="butAction" style="margin-left:0;" href="'.$_SERVER['PHP_SELF'].'?action=testinvoice&token='.newToken().'#testinvoice">'.$langs->trans("AuvoERechnungTestButton").'</a>';
if ($testResults !== null) {
	print '<table class="noborder centpercent" id="testinvoice" style="margin-top:10px;">';
	print '<tr class="liste_titre"><td>'.$langs->trans("AuvoERechnungTestFormat").'</td><td>'.$langs->trans("AuvoERechnungTestResult").'</td><td></td></tr>';
	foreach ($testResults as $label => $res) {
		$name = ['zugferd' => 'ZUGFeRD (EN16931)', 'xrechnung' => 'XRechnung (CII)', 'xrechnung_ubl' => 'XRechnung (UBL)'][$label];
		print '<tr class="oddeven"><td class="nowraponall">'.$name.'</td><td>';
		if (empty($res['errors'])) {
			print '<span style="color:green;"><strong>'.$langs->trans("AuvoERechnungTestOk").'</strong></span>';
		} else {
			print '<span style="color:#b00;"><strong>'.$langs->trans("AuvoERechnungTestKo", count($res['errors'])).'</strong></span><ul style="margin:4px 0;">';
			foreach ($res['errors'] as $e) {
				print '<li>'.dol_escape_htmltag(dol_string_nohtmltag((string) $e)).'</li>';
			}
			print '</ul>';
		}
		foreach ($res['warnings'] as $wmsg) {
			print '<div class="opacitymedium small">⚠ '.dol_escape_htmltag(dol_string_nohtmltag((string) $wmsg)).'</div>';
		}
		print '<details><summary class="small">'.$langs->trans("AuvoERechnungTestShowXml").'</summary><pre style="max-height:400px;overflow:auto;font-size:11px;">'.dol_escape_htmltag($res['xml']).'</pre></details>';
		print '</td><td class="right nowraponall"><a href="data:application/xml;base64,'.base64_encode($res['xml']).'" download="TEST-'.['zugferd' => 'ZUGFeRD', 'xrechnung' => 'XRECHNUNG-CII', 'xrechnung_ubl' => 'XRECHNUNG-UBL'][$label].'.xml">'.img_picto('', 'download', 'class="pictofixedwidth"').$langs->trans("Download").'</a></td></tr>';
	}
	print '</table>';
	print '<div class="opacitymedium small" style="margin-top:4px;">'.$langs->trans("AuvoERechnungTestValidatorHint").'</div>';
}
print '<br>';

print load_fiche_titre($langs->trans("AuvoERechnungDiagTitle"), '', '');

$diagErrors = [];
$diagWarnings = []; // points recommandés mais NON bloquants (orange, pas rouge)
$diagOk = [];

/**
 * Ajoute une ligne au diag : OK si la valeur est non vide, sinon en erreur.
 * Le suffixe (références BR-FR-xx) est ajouté au libellé d'erreur uniquement.
 * $fixUrl pointe vers la page Dolibarr permettant de corriger ce point précis.
 */
$diagCheck = function ($transKey, $value, $okFormatted = null, $errorSuffix = '', $fixUrl = '/admin/company.php') use ($langs, &$diagOk, &$diagErrors) {
	$label = $langs->trans($transKey);
	if (empty($value)) {
		$diagErrors[] = ['msg' => $label.($errorSuffix !== '' ? ' '.$errorSuffix : ''), 'fix' => $fixUrl];
		return;
	}
	$diagOk[] = $label.' : '.($okFormatted !== null ? $okFormatted : dol_escape_htmltag($value));
};

// Modules Dolibarr requis
if (!isModEnabled('banque')) {
	$diagErrors[] = ['msg' => $langs->trans("AuvoERechnungDiagModuleBankDisabled"), 'fix' => '/admin/modules.php'];
} else {
	$diagOk[] = $langs->trans("AuvoERechnungDiagModuleBankEnabled");
}
if (!isModEnabled('facture')) {
	$diagErrors[] = ['msg' => $langs->trans("AuvoERechnungDiagModuleInvoiceDisabled"), 'fix' => '/admin/modules.php'];
} else {
	$diagOk[] = $langs->trans("AuvoERechnungDiagModuleInvoiceEnabled");
}

$diagCheck('AuvoERechnungDiagSellerName', $mysoc->name);

$hasAddr = !empty($mysoc->address) && !empty($mysoc->zip) && !empty($mysoc->town);
$diagCheck('AuvoERechnungDiagSellerAddress', $hasAddr ? '1' : '', dol_escape_htmltag($mysoc->zip).' '.dol_escape_htmltag($mysoc->town));

// TVA intra : en franchise en base (293 B CGI, auto-entrepreneurs), l'absence de
// numéro est normale — le SIREN est publié comme identifiant fiscal (schemeID="FC")
// par le générateur. On n'affiche donc pas d'erreur, cohérent avec check_mandatory().
$isFranchise = isset($mysoc->tva_assuj) && (int) $mysoc->tva_assuj === 0;
$vatRaw = trim((string) ($mysoc->tva_intra ?? ''));
if ($vatRaw !== '' && !auvoerechnung_is_vat_id($vatRaw)) {
	// Saisie sans préfixe pays (ex. Steuernummer dans le champ TVA intra) :
	// non publiée en BT-31 (BR-CO-09).
	$diagErrors[] = ['msg' => $langs->trans("AuvoERechnungWarnSellerVATInvalid", $vatRaw), 'fix' => '/admin/company.php'];
} elseif ($isFranchise && empty($mysoc->tva_intra)) {
	$diagOk[] = $langs->trans("AuvoERechnungDiagSellerVAT").' : '.$langs->trans("AuvoERechnungDiagSellerVATFranchise");
} else {
	$diagCheck('AuvoERechnungDiagSellerVAT', $mysoc->tva_intra);
}

// Identifiants légaux : SIREN/SIRET pour un vendeur FR (réforme française),
// Steuernummer pour un vendeur DE. Ailleurs, la TVA intra contrôlée plus haut
// suffit (BR-CO-26) : rien de plus à afficher.
$diagSellerIsFR = auvoerechnung_seller_is_fr($mysoc);
$diagSellerCountry = strtoupper((string) ($mysoc->country_code ?? ''));
if ($diagSellerIsFR) {
	if (empty($mysoc->idprof2)) {
		$diagErrors[] = ['msg' => $langs->trans("AuvoERechnungDiagSellerSIRET").' (BR-FR-10)', 'fix' => '/admin/company.php'];
	} else {
		$siren = auvoerechnung_extract_siren($mysoc->idprof2);
		$diagOk[] = $langs->trans("AuvoERechnungDiagSellerSIRET").' : SIREN '.dol_escape_htmltag($siren).' (SIRET '.dol_escape_htmltag($mysoc->idprof2).')';
	}
} elseif ($diagSellerCountry === 'DE') {
	// § 14 Abs. 4 Nr. 2 UStG / BR-CO-26 : Steuernummer OU USt-IdNr. Sans
	// USt-IdNr. (typiquement Kleinunternehmer), la Steuernummer devient obligatoire.
	$steuernummer = auvoerechnung_party_steuernummer($mysoc);
	if ($steuernummer !== '') {
		$diagOk[] = $langs->trans("AuvoERechnungDiagSellerSteuernummer").' : '.dol_escape_htmltag($steuernummer);
	} elseif (auvoerechnung_party_vat_id($mysoc) !== '') {
		$diagOk[] = $langs->trans("AuvoERechnungDiagSellerSteuernummer").' : '.$langs->trans("AuvoERechnungDiagSellerSteuernummerNotNeeded");
	} else {
		$diagErrors[] = ['msg' => $langs->trans("AuvoERechnungDiagSellerTaxIdMissing"), 'fix' => '/admin/company.php'];
	}
}

$diagCheck('AuvoERechnungDiagSellerEmail', $mysoc->email, null, $diagSellerIsFR ? '(BR-FR-13 / BT-34)' : '(BT-34)');

// Banque : lien "Corriger" vers la liste des comptes (compta/bank/list.php), pas la fiche société.
$bankFixUrl = '/compta/bank/list.php?mainmenu=bank';
$bankId = getDolGlobalInt('AUVOERECHNUNG_BANK_ACCOUNT');
if ($bankId <= 0) {
	$diagErrors[] = ['msg' => $langs->trans("AuvoERechnungDiagBankNotSet"), 'fix' => $bankFixUrl];
} else {
	$bankCheck = new Account($db);
	if ($bankCheck->fetch($bankId) <= 0) {
		$diagErrors[] = ['msg' => $langs->trans("AuvoERechnungDiagBankNotFound"), 'fix' => $bankFixUrl];
	} else {
		$diagCheck('AuvoERechnungDiagIBAN', $bankCheck->iban, dol_escape_htmltag(auvoerechnung_iban_short($bankCheck->iban)), '', $bankFixUrl);
		$diagCheck('AuvoERechnungDiagBIC', $bankCheck->bic, null, '', $bankFixUrl);
	}
}

// PDF/A-3 : police embarquée forcée (sinon veraPDF échoue sur les polices base-14)
$forceFont = getDolGlobalString('MAIN_PDF_FORCE_FONT', '');
if ($forceFont === '') {
	$diagErrors[] = [
		'msg' => $langs->trans("AuvoERechnungDiagForceFontMissing"),
		'fix' => '/custom/auvoerechnung/admin/setup.php?action=setforcefont&token='.newToken(),
		'fixlabel' => $langs->trans("AuvoERechnungDiagFixForceFont"),
	];
} elseif (auvoerechnung_pdf_font_embedded($forceFont) === false) {
	$diagErrors[] = [
		'msg' => $langs->trans("AuvoERechnungDiagForceFontNotEmbedded", dol_escape_htmltag($forceFont)),
		'fix' => '/custom/auvoerechnung/admin/setup.php?action=setforcefont&token='.newToken(),
		'fixlabel' => $langs->trans("AuvoERechnungDiagFixForceFont"),
	];
} else {
	$diagOk[] = $langs->trans("AuvoERechnungDiagForceFontOk").' : '.dol_escape_htmltag($forceFont);
}

// Pied de facture : mentions visibles sur le PDF. Recommandé mais NON
// bloquant — le XML embarqué reste conforme BR-FR-05 dans tous les cas. On
// l'affiche en avertissement (orange) et PUREMENT informatif : le diagnostic
// constate, c'est le toggle « recopier les mentions en pied » du bloc Mentions
// légales qui règle (comme les autres items, pas de contrôle dans le diag).
// On lit la BONNE constante (INVOICE_FREE_TEXT depuis Dolibarr 17, celle que
// le PDF imprime) — pas l'ancienne FACTURE_FREE_TEXT qui n'était plus lue.
$freeTextDiag = getDolGlobalString(auvoerechnung_invoice_freetext_const(), '');
if (trim($freeTextDiag) === '') {
	$diagWarnings[] = ['msg' => $langs->trans("AuvoERechnungDiagFreeTextMissing")];
} else {
	$diagOk[] = $langs->trans("AuvoERechnungDiagFreeTextOk");
}

// Diagnostic d'injection, dépendant du mode choisi. L'injection in-process n'a
// lieu qu'en mode 'inprocess' (forcé) ou en mode 'auto' sur un serveur SANS
// exec() → là, exec()/PHP CLI ne sont pas requis (mutualisés durcis). Sinon
// (mode 'auto' avec exec, ou mode 'subprocess'), c'est le sous-process — process
// PHP isolé, le chemin le plus sûr — qui injecte : exec() + PHP CLI valide requis.
$diagInjMode = strtolower(trim(getDolGlobalString('AUVOERECHNUNG_INJECTION_MODE', 'auto')));
if (!in_array($diagInjMode, ['auto', 'inprocess', 'subprocess'], true)) {
	$diagInjMode = 'auto';
}
$execAvailable = function_exists('exec');
// tcpdi actif (défaut Dolibarr) = un « class FPDF extends TCPDF » est chargé à
// chaque génération PDF (pdf_getInstance) → incompatible avec l'injection
// in-process. MAIN_DISABLE_TCPDI=1 désactive ce moteur (et la fusion PDF de
// Dolibarr) mais débloque l'in-process.
$tcpdiActive = !getDolGlobalString('MAIN_DISABLE_TCPDI');

if ($diagInjMode === 'inprocess') {
	if ($tcpdiActive) {
		$diagErrors[] = ['msg' => $langs->trans("AuvoERechnungDiagTcpdiConflict"), 'fix' => '/admin/const.php'];
	} else {
		$diagOk[] = $langs->trans("AuvoERechnungDiagInjInProcess");
	}
} elseif ($diagInjMode === 'subprocess') {
	if (!$execAvailable) {
		$diagErrors[] = ['msg' => $langs->trans("AuvoERechnungDiagExecRequiredSub"), 'fix' => '/custom/auvoerechnung/admin/setup.php'];
	} else {
		$diagOk[] = $langs->trans("AuvoERechnungDiagInjSubprocess");
	}
} else { // auto
	if ($execAvailable) {
		$diagOk[] = $langs->trans("AuvoERechnungDiagInjAuto");
	} elseif ($tcpdiActive) {
		// auto sans exec → in-process, mais tcpdi actif → échec garanti : on guide.
		$diagErrors[] = ['msg' => $langs->trans("AuvoERechnungDiagTcpdiConflict"), 'fix' => '/admin/const.php'];
	} else {
		$diagOk[] = $langs->trans("AuvoERechnungDiagInjAutoNoExec");
	}
}

// Binaire PHP CLI : pertinent uniquement quand le sous-process peut servir
// (mode subprocess, ou mode auto avec exec dispo). Sinon, sans objet.
if ($execAvailable && $diagInjMode !== 'inprocess') {
	$phpCliManual = trim(getDolGlobalString('AUVOERECHNUNG_PHP_CLI_PATH', ''));
	// 'php' nu = pas une vraie surcharge (cohérent avec auvoerechnung_resolve_php_cli) → auto.
	$phpCliIsAuto = ($phpCliManual === '' || $phpCliManual === 'php');
	$phpCliResolved = auvoerechnung_resolve_php_cli($db);
	$phpCliLabel = $phpCliResolved.($phpCliIsAuto ? ' ('.$langs->trans("AuvoERechnungDiagPhpCliAuto").')' : '');
	if (auvoerechnung_php_cli_is_valid($phpCliResolved)) {
		$diagOk[] = $langs->trans("AuvoERechnungDiagPhpCliOk").' : '.dol_escape_htmltag($phpCliLabel);
	} else {
		// Mode subprocess OU auto-avec-exec : le sous-process est le chemin
		// d'injection effectif → un CLI invalide est bloquant.
		$diagErrors[] = ['msg' => $langs->trans("AuvoERechnungDiagPhpCliNotFound", dol_escape_htmltag($phpCliResolved)), 'fix' => '/custom/auvoerechnung/admin/setup.php'];
	}
}

// Multidevise : avertissement informatif (les factures en devise étrangère sont ignorées)
if (isModEnabled('multicurrency')) {
	$diagOk[] = $langs->trans("AuvoERechnungDiagMulticurrencyNote");
}

// XRechnung : données du vendeur requises par la CIUS KoSIT (vendeur allemand).
// Les données propres à chaque facture (Leitweg-ID, réf. client, adresse du
// client) sont contrôlées à la génération.
if (strtoupper((string) ($mysoc->country_code ?? '')) === 'DE') {
	$xrPrefix = $langs->trans("AuvoERechnungSecXRechnung").' — ';
	$xrName = trim(getDolGlobalString('AUVOERECHNUNG_CONTACT_NAME', ''));
	if ($xrName !== '') {
		$diagOk[] = $xrPrefix.$langs->trans("AuvoERechnungXrContactName").' : '.dol_escape_htmltag($xrName);
	} else {
		$diagWarnings[] = ['msg' => $xrPrefix.$langs->trans("AuvoERechnungDiagXrContactFromAuthor")];
	}
	$xrPhone = trim(getDolGlobalString('AUVOERECHNUNG_CONTACT_PHONE', '')) ?: trim((string) ($mysoc->phone ?? ''));
	if (preg_match('/([0-9].*){3,}/', $xrPhone)) {
		$diagOk[] = $xrPrefix.$langs->trans("AuvoERechnungXrContactPhone").' : '.dol_escape_htmltag($xrPhone);
	} else {
		$diagWarnings[] = ['msg' => $xrPrefix.$langs->trans("AuvoERechnungDiagXrPhoneMissing")];
	}
	$xrEmail = trim(getDolGlobalString('AUVOERECHNUNG_CONTACT_EMAIL', '')) ?: trim((string) ($mysoc->email ?? ''));
	if ($xrEmail !== '') {
		$diagOk[] = $xrPrefix.$langs->trans("AuvoERechnungXrContactEmail").' : '.dol_escape_htmltag($xrEmail);
	} else {
		$diagErrors[] = ['msg' => $xrPrefix.$langs->trans("AuvoERechnungDiagXrEmailMissing"), 'fix' => '/admin/company.php'];
	}
	$diagOk[] = $xrPrefix.$langs->trans("AuvoERechnungDiagXrVersion", dol_escape_htmltag(auvoerechnung_xrechnung_cius_id()));
	$sqlXr = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."societe_extrafields ef";
	$sqlXr .= " INNER JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = ef.fk_object AND s.entity IN (".getEntity('societe').")";
	$sqlXr .= " WHERE (ef.lfxleitwegid IS NOT NULL AND ef.lfxleitwegid <> '' AND (ef.lfxeformat IS NULL OR ef.lfxeformat <> 'zugferd'))";
	$sqlXr .= " OR ef.lfxeformat = 'xrechnung'";
	$resXr = $db->query($sqlXr);
	$nbXr = ($resXr && ($o = $db->fetch_object($resXr))) ? (int) $o->nb : 0;
	$diagOk[] = $xrPrefix.$langs->trans("AuvoERechnungDiagXrCustomers", $nbXr);
	if (getDolGlobalString('AUVOERECHNUNG_OZGRE_EMAIL') !== '') {
		$diagOk[] = $xrPrefix.$langs->trans("AuvoERechnungDiagOzgReOn", dol_escape_htmltag(getDolGlobalString('MAIN_MAIL_EMAIL_FROM', getDolGlobalString('MAIN_INFO_SOCIETE_MAIL'))));
	} elseif ($nbXr > 0) {
		$diagWarnings[] = ['msg' => $xrPrefix.$langs->trans("AuvoERechnungDiagOzgReOff")];
	}
}

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("AuvoERechnungDiagResults").'</td></tr>';

foreach ($diagOk as $ok) {
	print '<tr class="oddeven"><td><span style="color: green;">&#10004;</span> '.$ok.'</td><td></td></tr>';
}
foreach ($diagErrors as $err) {
	$fixLabel = !empty($err['fixlabel']) ? $err['fixlabel'] : $langs->trans("AuvoERechnungDiagFixLink");
	print '<tr class="oddeven"><td><span style="color: red;">&#10008;</span> <strong>'.$err['msg'].'</strong></td>';
	print '<td><a href="'.DOL_URL_ROOT.$err['fix'].'">'.$fixLabel.'</a></td></tr>';
}
// Avertissements : recommandés, non bloquants → pastille orange, pas de croix
// rouge. Purement informatifs (constat), le réglage se fait dans les options ;
// un lien éventuel n'est affiché que s'il est explicitement fourni.
foreach ($diagWarnings as $warn) {
	print '<tr class="oddeven"><td><span style="color: #e67e22;">&#9888;</span> '.$warn['msg'].'</td><td>';
	if (!empty($warn['fix'])) {
		$fixLabel = !empty($warn['fixlabel']) ? $warn['fixlabel'] : $langs->trans("AuvoERechnungDiagFixLink");
		print '<a href="'.DOL_URL_ROOT.$warn['fix'].'">'.$fixLabel.'</a>';
	}
	print '</td></tr>';
}

if (empty($diagErrors)) {
	// Pas de blocage : message vert. S'il reste des avertissements (recommandés),
	// on le dit autrement pour ne pas laisser croire que tout est parfait.
	$allOkKey = empty($diagWarnings) ? "AuvoERechnungDiagAllOk" : "AuvoERechnungDiagNoBlocker";
	print '<tr class="oddeven"><td colspan="2"><span style="color: green;"><strong>'.$langs->trans($allOkKey).'</strong></span></td></tr>';
}

print '</table>';

// === Bloc "À propos" ===
print '<div style="margin:30px 0;padding:20px 25px;border:1px solid #e0e0e0;border-left:4px solid #FFD21F;border-radius:6px;background:linear-gradient(135deg,#fffef7 0%,#fafafa 100%);">';
print '<h3 style="margin:0 0 10px 0;color:#333;">'.$langs->trans("AuvoERechnungAboutTitle").'</h3>';
print '<p style="margin:0;color:#555;">'.$langs->trans("AuvoERechnungAboutIntro").' '.$langs->trans("AuvoERechnungAboutLocation").'</p>';
print '</div>';

llxFooter();
$db->close();
