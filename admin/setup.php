<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de> — renamed/modified for private use as AuvoZugferd
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Page de configuration du module AuvoZugferd
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
require_once dol_buildpath('/auvozugferd/core/lib/auvozugferd.lib.php');

// Sécurité
if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(["admin", "auvozugferd@auvozugferd"]);

// Auto-correctif : sous Dolibarr 23 les extrafields Chorus pouvaient être créés
// avec printable!=0 et polluaient la note du PDF (cf auvozugferd_fix_chorus_*).
// On corrige à l'ouverture de la config — pas besoin de réactiver le module.
auvozugferd_fix_chorus_extrafields_display($db);

$action = GETPOST('action', 'aZ09');

// Les valeurs par défaut des mentions légales BR-FR sont définies dans la lib
// (AUVOZUGFERD_DEFAULT_NOTE_*) pour rester synchronisées avec le builder XML.

// Sauvegarde des paramètres
if ($action == 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	// CSRF : vérifier le token courant (pas newToken() qui génère un futur token)
	if (GETPOST('token', 'alpha') !== currentToken()) {
		accessforbidden('Bad value for CSRF token');
	}
	$error = 0;

	// Mode d'injection : on n'accepte que les 3 valeurs connues (défaut auto).
	$injMode = strtolower(trim(GETPOST('AUVOZUGFERD_INJECTION_MODE', 'alpha')));
	if (!in_array($injMode, ['auto', 'inprocess', 'subprocess'], true)) {
		$injMode = 'auto';
	}

	$updates = [
		['AUVOZUGFERD_INJECTION_MODE', $injMode, 'chaine'],
		['AUVOZUGFERD_BANK_ACCOUNT', GETPOSTINT('AUVOZUGFERD_BANK_ACCOUNT'),    'int'],
		['AUVOZUGFERD_PAYMENT_MEANS',trim(GETPOST('AUVOZUGFERD_PAYMENT_MEANS', 'alpha')), 'chaine'],
		['AUVOZUGFERD_STRICT_MODE',  GETPOSTINT('AUVOZUGFERD_STRICT_MODE'),     'int'],
		['AUVOZUGFERD_BR_CHECK',     GETPOSTINT('AUVOZUGFERD_BR_CHECK'),        'int'],
		['AUVOZUGFERD_CHORUS_ENABLED', GETPOSTINT('AUVOZUGFERD_CHORUS_ENABLED'), 'int'],
		['AUVOZUGFERD_PHP_CLI_PATH', trim(GETPOST('AUVOZUGFERD_PHP_CLI_PATH', 'alphanohtml')), 'chaine'],
		['AUVOZUGFERD_VERAPDF_PATH', trim(GETPOST('AUVOZUGFERD_VERAPDF_PATH', 'alphanohtml')), 'chaine'],
		['AUVOZUGFERD_ENDPOINT_SUFFIX_SELLER', trim(GETPOST('AUVOZUGFERD_ENDPOINT_SUFFIX_SELLER', 'alphanohtml')), 'chaine'],
		['AUVOZUGFERD_NOTE_PMD',     trim(GETPOST('AUVOZUGFERD_NOTE_PMD', 'restricthtml')),    'chaine'],
		['AUVOZUGFERD_NOTE_PMT',     trim(GETPOST('AUVOZUGFERD_NOTE_PMT', 'restricthtml')),    'chaine'],
		['AUVOZUGFERD_NOTE_AAB',     trim(GETPOST('AUVOZUGFERD_NOTE_AAB', 'restricthtml')),    'chaine'],
		['AUVOZUGFERD_NOTES_IN_FOOTER', GETPOSTINT('AUVOZUGFERD_NOTES_IN_FOOTER'), 'int'],
		['AUVOZUGFERD_NOTES_OVERWRITE', GETPOSTINT('AUVOZUGFERD_NOTES_OVERWRITE'), 'int'],
	];
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
	if (!$error && GETPOSTINT('AUVOZUGFERD_NOTES_IN_FOOTER')) {
		$footerAdded = auvozugferd_append_notes_to_footer($db, GETPOSTINT('AUVOZUGFERD_NOTES_OVERWRITE') === 1);
	}

	// Police du PDF (constante globale Dolibarr MAIN_PDF_FORCE_FONT). On la pose
	// en visible=1 pour qu'elle reste gérable (Config > Divers ET ce menu). Vide
	// = on retire le forçage (police par défaut Dolibarr). On valide la valeur
	// contre la liste réelle des polices pour ne rien poser d'invalide.
	if (!$error) {
		$pdfFont = GETPOST('MAIN_PDF_FORCE_FONT', 'alpha');
		$availableFonts = auvozugferd_list_pdf_fonts();
		if ($pdfFont === '' || isset($availableFonts[$pdfFont])) {
			dolibarr_set_const($db, 'MAIN_PDF_FORCE_FONT', $pdfFont, 'chaine', 1, '', $conf->entity);
		}
	}

	if (!$error) {
		$msg = $langs->trans("SetupSaved");
		if ($footerAdded > 0) {
			$msg .= ' — '.$langs->trans('AuvoZugferdCopyNotesDone', $footerAdded);
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
		setEventMessages($langs->trans("AuvoZugferdForceFontSet"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}

// Affichage
llxHeader('', $langs->trans("AuvoZugferdSetup"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("AuvoZugferdSetup"), $linkback, 'title_setup');

// Bandeau "Nouvelle version disponible" si le check GitHub remonte une version > locale
require_once dirname(__DIR__).'/core/modules/modAuvoZugferd.class.php';
$modDesc = new modAuvoZugferd($db);
$updateInfo = auvozugferd_check_latest_release($db, $modDesc->version);
if ($updateInfo !== null) {
	print '<div class="warning" style="margin:8px 0;padding:10px;border-left:4px solid #e67e22;background:#fff3e0;">';
	print '<strong>'.$langs->trans("AuvoZugferdUpdateAvailable").'</strong> : ';
	print $langs->trans("AuvoZugferdUpdateAvailableMsg", dol_escape_htmltag($updateInfo['version']), dol_escape_htmltag($modDesc->version));
	print ' <a href="'.dol_escape_htmltag($updateInfo['url']).'" target="_blank" rel="noopener">'.$langs->trans("AuvoZugferdUpdateSeeRelease").'</a>';
	print '</div>';
}

print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';

// ---- Bloc 1 : Facturation ----
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("AuvoZugferdSecBilling").'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("AuvoZugferdBankAccount").'</td><td>';
$currentBankAccount = getDolGlobalInt('AUVOZUGFERD_BANK_ACCOUNT');
$resql = $db->query("SELECT rowid, label, iban_prefix, bic FROM ".MAIN_DB_PREFIX."bank_account WHERE clos = 0 AND entity IN (".getEntity('bank_account').") ORDER BY label");
print '<select name="AUVOZUGFERD_BANK_ACCOUNT" class="flat minwidth300">';
print '<option value="0">-- '.$langs->trans("Select").' --</option>';
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$infoIban = !empty($obj->iban_prefix) ? ' ('.auvozugferd_iban_short($obj->iban_prefix).')' : ' (pas d\'IBAN)';
		print '<option value="'.$obj->rowid.'"'.($currentBankAccount == $obj->rowid ? ' selected' : '').'>'.dol_escape_htmltag($obj->label.$infoIban).'</option>';
	}
}
print '</select></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("AuvoZugferdPaymentMeans").'<br><span class="opacitymedium small">'.$langs->trans("AuvoZugferdPaymentMeansHint").'</span></td><td>';
$currentMeans = getDolGlobalString('AUVOZUGFERD_PAYMENT_MEANS', '30');
print '<select name="AUVOZUGFERD_PAYMENT_MEANS" class="flat">';
foreach (array('30'=>'PaymentMeans30','58'=>'PaymentMeans58','59'=>'PaymentMeans59','49'=>'PaymentMeans49') as $code=>$k) {
	print '<option value="'.$code.'"'.($currentMeans == $code ? ' selected' : '').'>'.$code.' - '.$langs->trans($k).'</option>';
}
print '</select></td></tr>';

// ---- Bloc 2 : Mentions légales ----
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("AuvoZugferdLegalNotes").'</td></tr>';
// Les défauts PMD/PMT/AAB sont des mentions du droit français : pour un vendeur
// établi hors de France les champs restent vides tant qu'il n'a pas saisi les
// siennes (cf. auvozugferd_resolve_legal_notes).
$legalNotes = auvozugferd_resolve_legal_notes(auvozugferd_seller_is_fr($mysoc));
if (!auvozugferd_seller_is_fr($mysoc)) {
	print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium small">'.$langs->trans("AuvoZugferdLegalNotesForeignHint").'</span></td></tr>';
}
foreach ([
	'PMD' => 'AuvoZugferdNotePMD',
	'PMT' => 'AuvoZugferdNotePMT',
	'AAB' => 'AuvoZugferdNoteAAB',
] as $code => $transKey) {
	$val = $legalNotes[$code];
	print '<tr class="oddeven"><td>'.$langs->trans($transKey).'</td><td><textarea name="AUVOZUGFERD_NOTE_'.$code.'" class="flat minwidth500" rows="3">'.dol_escape_htmltag($val).'</textarea></td></tr>';
}
print '<tr class="oddeven"><td>'.$langs->trans("AuvoZugferdNotesInFooter").'<br><span class="opacitymedium small">'.$langs->trans("AuvoZugferdNotesInFooterHint").'</span></td><td>';
$notesFooter = getDolGlobalInt('AUVOZUGFERD_NOTES_IN_FOOTER', 0);
print '<select name="AUVOZUGFERD_NOTES_IN_FOOTER" class="flat">';
print '<option value="0"'.(!$notesFooter ? ' selected' : '').'>'.$langs->trans("No").'</option>';
print '<option value="1"'.($notesFooter ? ' selected' : '').'>'.$langs->trans("Yes").'</option>';
print '</select></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("AuvoZugferdNotesOverwrite").'<br><span class="opacitymedium small">'.$langs->trans("AuvoZugferdNotesOverwriteHint").'</span></td><td>';
$notesOverwrite = getDolGlobalInt('AUVOZUGFERD_NOTES_OVERWRITE', 0);
print '<select name="AUVOZUGFERD_NOTES_OVERWRITE" class="flat">';
print '<option value="0"'.(!$notesOverwrite ? ' selected' : '').'>'.$langs->trans("No").' ('.$langs->trans("AuvoZugferdNotesAppend").')</option>';
print '<option value="1"'.($notesOverwrite ? ' selected' : '').'>'.$langs->trans("Yes").' ('.$langs->trans("AuvoZugferdNotesReplace").')</option>';
print '</select></td></tr>';

// ---- Bloc 3 : Secteur public (Chorus Pro) ----
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("AuvoZugferdSecPublic").'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("AuvoZugferdChorusEnabled").'<br><span class="opacitymedium small">'.$langs->trans("AuvoZugferdChorusEnabledHint").'</span></td><td>';
$chorusEnabled = getDolGlobalInt('AUVOZUGFERD_CHORUS_ENABLED', 0);
print '<select name="AUVOZUGFERD_CHORUS_ENABLED" class="flat">';
print '<option value="0"'.(!$chorusEnabled ? ' selected' : '').'>'.$langs->trans("No").'</option>';
print '<option value="1"'.($chorusEnabled ? ' selected' : '').'>'.$langs->trans("Yes").'</option>';
print '</select></td></tr>';

// ---- Bloc 4 : Validation & conformité ----
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("AuvoZugferdSecValidation").'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("AuvoZugferdBrCheck").'<br><span class="opacitymedium small">'.$langs->trans("AuvoZugferdBrCheckHint").'</span></td><td>';
$brCheck = getDolGlobalInt('AUVOZUGFERD_BR_CHECK', 1);
print '<select name="AUVOZUGFERD_BR_CHECK" class="flat">';
print '<option value="1"'.($brCheck ? ' selected' : '').'>'.$langs->trans("Yes").'</option>';
print '<option value="0"'.(!$brCheck ? ' selected' : '').'>'.$langs->trans("No").'</option>';
print '</select></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("AuvoZugferdStrictMode").'<br><span class="opacitymedium small">'.$langs->trans("AuvoZugferdStrictModeHint").'</span></td><td>';
$strict = getDolGlobalInt('AUVOZUGFERD_STRICT_MODE', 0);
print '<select name="AUVOZUGFERD_STRICT_MODE" class="flat">';
print '<option value="0"'.($strict == 0 ? ' selected' : '').'>'.$langs->trans("AuvoZugferdStrictModeBestEffort").'</option>';
print '<option value="1"'.($strict == 1 ? ' selected' : '').'>'.$langs->trans("AuvoZugferdStrictModeStrict").'</option>';
print '</select></td></tr>';

// Police du PDF (MAIN_PDF_FORCE_FONT) : liste les polices du Dolibarr, marquées
// conformes Factur-X (embarquées ✓) ou non (base-14 ⚠). Conformes en premier.
print '<tr class="oddeven"><td>'.$langs->trans("AuvoZugferdPdfFont").'<br><span class="opacitymedium small">'.$langs->trans("AuvoZugferdPdfFontHint").'</span></td><td>';
$curFont = getDolGlobalString('MAIN_PDF_FORCE_FONT', '');
$pdfFonts = auvozugferd_list_pdf_fonts();
print '<select name="MAIN_PDF_FORCE_FONT" class="flat minwidth300">';
print '<option value="">'.$langs->trans("AuvoZugferdPdfFontDefault").'</option>';
foreach (array(true, false) as $wantEmbedded) {
	foreach ($pdfFonts as $fname => $isEmbedded) {
		if ($isEmbedded !== $wantEmbedded) {
			continue;
		}
		$tag = $isEmbedded ? $langs->trans("AuvoZugferdPdfFontOk") : $langs->trans("AuvoZugferdPdfFontKo");
		print '<option value="'.dol_escape_htmltag($fname).'"'.($curFont === $fname ? ' selected' : '').'>'.dol_escape_htmltag($fname.' — '.$tag).'</option>';
	}
}
print '</select></td></tr>';

// ---- Bloc 5 : Technique (avancé) ----
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("AuvoZugferdSecTech").'</td></tr>';

// Mode d'injection du XML dans le PDF. In-process (sans exec) par défaut :
// fonctionne sur les hébergements mutualisés où exec() est désactivé. Le
// sous-process (historique) reste disponible en repli/forçage.
$injModeCur = strtolower(trim(getDolGlobalString('AUVOZUGFERD_INJECTION_MODE', 'auto')));
if (!in_array($injModeCur, ['auto', 'inprocess', 'subprocess'], true)) {
	$injModeCur = 'auto';
}
print '<tr class="oddeven"><td>'.$langs->trans("AuvoZugferdInjectionMode").'<br><span class="opacitymedium small">'.$langs->trans("AuvoZugferdInjectionModeHint").'</span></td><td>';
print '<select name="AUVOZUGFERD_INJECTION_MODE" class="flat minwidth300">';
foreach (['auto' => 'AuvoZugferdInjectionModeAuto', 'inprocess' => 'AuvoZugferdInjectionModeInProcess', 'subprocess' => 'AuvoZugferdInjectionModeSubprocess'] as $injVal => $injKey) {
	print '<option value="'.$injVal.'"'.($injModeCur === $injVal ? ' selected' : '').'>'.$langs->trans($injKey).'</option>';
}
print '</select></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("AuvoZugferdPhpCliPath").'<br><span class="opacitymedium small">'.$langs->trans("AuvoZugferdPhpCliPathHint").'</span></td><td>';
print '<input type="text" name="AUVOZUGFERD_PHP_CLI_PATH" class="flat minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('AUVOZUGFERD_PHP_CLI_PATH', '')).'" placeholder="'.$langs->trans("AuvoZugferdPhpCliAutoPlaceholder").'"></td></tr>';
// veraPDF : outil EXTERNE optionnel, non embarqué et non installé par défaut.
// Vide = pas de post-validation (le contrôle XSD + règles BR internes reste actif).
print '<tr class="oddeven"><td>'.$langs->trans("AuvoZugferdVeraPdfPath").'<br><span class="opacitymedium small">'.$langs->trans("AuvoZugferdVeraPdfPathHint").'</span></td><td>';
print '<input type="text" name="AUVOZUGFERD_VERAPDF_PATH" class="flat minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('AUVOZUGFERD_VERAPDF_PATH', '')).'" placeholder="'.$langs->trans("AuvoZugferdVeraPdfPlaceholder").'"></td></tr>';

// ---- Bloc 6 : Adressage Plateforme Agréée (PA) ----
// Suffixe d'endpoint VENDEUR (BT-34). Certaines PA exigent que l'adresse
// électronique du vendeur ne soit pas le SIREN nu mais un endpoint suffixé
// (ex Hubtimize : "<SIREN>_Status"). Vide = SIREN nu. L'adresse acheteur
// (BT-49) reste toujours le SIREN nu (la PA destinataire route).
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("AuvoZugferdSecPA").'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("AuvoZugferdEndpointSuffixSeller").'<br><span class="opacitymedium small">'.$langs->trans("AuvoZugferdEndpointSuffixSellerHint").'</span></td><td>';
print '<input type="text" name="AUVOZUGFERD_ENDPOINT_SUFFIX_SELLER" class="flat minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('AUVOZUGFERD_ENDPOINT_SUFFIX_SELLER', '')).'" placeholder="'.$langs->trans("AuvoZugferdEndpointSuffixSellerPlaceholder").'"></td></tr>';

print '</table>';
print '<br><div class="center"><input type="submit" class="button button-save" value="'.$langs->trans("Save").'"></div>';
print '</form>';

// Info
print '<br>';
print '<div class="info">';
print $langs->trans("AuvoZugferdInfo");
print '</div>';

// === Diagnostic des infos obligatoires ===
print '<br>';
print load_fiche_titre($langs->trans("AuvoZugferdDiagTitle"), '', '');

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
	$diagErrors[] = ['msg' => $langs->trans("AuvoZugferdDiagModuleBankDisabled"), 'fix' => '/admin/modules.php'];
} else {
	$diagOk[] = $langs->trans("AuvoZugferdDiagModuleBankEnabled");
}
if (!isModEnabled('facture')) {
	$diagErrors[] = ['msg' => $langs->trans("AuvoZugferdDiagModuleInvoiceDisabled"), 'fix' => '/admin/modules.php'];
} else {
	$diagOk[] = $langs->trans("AuvoZugferdDiagModuleInvoiceEnabled");
}

$diagCheck('AuvoZugferdDiagSellerName', $mysoc->name);

$hasAddr = !empty($mysoc->address) && !empty($mysoc->zip) && !empty($mysoc->town);
$diagCheck('AuvoZugferdDiagSellerAddress', $hasAddr ? '1' : '', dol_escape_htmltag($mysoc->zip).' '.dol_escape_htmltag($mysoc->town));

// TVA intra : en franchise en base (293 B CGI, auto-entrepreneurs), l'absence de
// numéro est normale — le SIREN est publié comme identifiant fiscal (schemeID="FC")
// par le générateur. On n'affiche donc pas d'erreur, cohérent avec check_mandatory().
$isFranchise = isset($mysoc->tva_assuj) && (int) $mysoc->tva_assuj === 0;
if ($isFranchise && empty($mysoc->tva_intra)) {
	$diagOk[] = $langs->trans("AuvoZugferdDiagSellerVAT").' : '.$langs->trans("AuvoZugferdDiagSellerVATFranchise");
} else {
	$diagCheck('AuvoZugferdDiagSellerVAT', $mysoc->tva_intra);
}

if (empty($mysoc->idprof2)) {
	$diagErrors[] = ['msg' => $langs->trans("AuvoZugferdDiagSellerSIRET").' (BR-FR-10)', 'fix' => '/admin/company.php'];
} else {
	$siren = auvozugferd_extract_siren($mysoc->idprof2);
	$diagOk[] = $langs->trans("AuvoZugferdDiagSellerSIRET").' : SIREN '.dol_escape_htmltag($siren).' (SIRET '.dol_escape_htmltag($mysoc->idprof2).')';
}

$diagCheck('AuvoZugferdDiagSellerEmail', $mysoc->email, null, '(BR-FR-13 / BT-34)');

// Banque : lien "Corriger" vers la liste des comptes (compta/bank/list.php), pas la fiche société.
$bankFixUrl = '/compta/bank/list.php?mainmenu=bank';
$bankId = getDolGlobalInt('AUVOZUGFERD_BANK_ACCOUNT');
if ($bankId <= 0) {
	$diagErrors[] = ['msg' => $langs->trans("AuvoZugferdDiagBankNotSet"), 'fix' => $bankFixUrl];
} else {
	$bankCheck = new Account($db);
	if ($bankCheck->fetch($bankId) <= 0) {
		$diagErrors[] = ['msg' => $langs->trans("AuvoZugferdDiagBankNotFound"), 'fix' => $bankFixUrl];
	} else {
		$diagCheck('AuvoZugferdDiagIBAN', $bankCheck->iban, dol_escape_htmltag(auvozugferd_iban_short($bankCheck->iban)), '', $bankFixUrl);
		$diagCheck('AuvoZugferdDiagBIC', $bankCheck->bic, null, '', $bankFixUrl);
	}
}

// PDF/A-3 : police embarquée forcée (sinon veraPDF échoue sur les polices base-14)
$forceFont = getDolGlobalString('MAIN_PDF_FORCE_FONT', '');
if ($forceFont === '') {
	$diagErrors[] = [
		'msg' => $langs->trans("AuvoZugferdDiagForceFontMissing"),
		'fix' => '/custom/auvozugferd/admin/setup.php?action=setforcefont&token='.newToken(),
		'fixlabel' => $langs->trans("AuvoZugferdDiagFixForceFont"),
	];
} else {
	$diagOk[] = $langs->trans("AuvoZugferdDiagForceFontOk").' : '.dol_escape_htmltag($forceFont);
}

// Pied de facture : mentions visibles sur le PDF. Recommandé mais NON
// bloquant — le XML embarqué reste conforme BR-FR-05 dans tous les cas. On
// l'affiche en avertissement (orange) et PUREMENT informatif : le diagnostic
// constate, c'est le toggle « recopier les mentions en pied » du bloc Mentions
// légales qui règle (comme les autres items, pas de contrôle dans le diag).
// On lit la BONNE constante (INVOICE_FREE_TEXT depuis Dolibarr 17, celle que
// le PDF imprime) — pas l'ancienne FACTURE_FREE_TEXT qui n'était plus lue.
$freeTextDiag = getDolGlobalString(auvozugferd_invoice_freetext_const(), '');
if (trim($freeTextDiag) === '') {
	$diagWarnings[] = ['msg' => $langs->trans("AuvoZugferdDiagFreeTextMissing")];
} else {
	$diagOk[] = $langs->trans("AuvoZugferdDiagFreeTextOk");
}

// Diagnostic d'injection, dépendant du mode choisi. L'injection in-process n'a
// lieu qu'en mode 'inprocess' (forcé) ou en mode 'auto' sur un serveur SANS
// exec() → là, exec()/PHP CLI ne sont pas requis (mutualisés durcis). Sinon
// (mode 'auto' avec exec, ou mode 'subprocess'), c'est le sous-process — process
// PHP isolé, le chemin le plus sûr — qui injecte : exec() + PHP CLI valide requis.
$diagInjMode = strtolower(trim(getDolGlobalString('AUVOZUGFERD_INJECTION_MODE', 'auto')));
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
		$diagErrors[] = ['msg' => $langs->trans("AuvoZugferdDiagTcpdiConflict"), 'fix' => '/admin/const.php'];
	} else {
		$diagOk[] = $langs->trans("AuvoZugferdDiagInjInProcess");
	}
} elseif ($diagInjMode === 'subprocess') {
	if (!$execAvailable) {
		$diagErrors[] = ['msg' => $langs->trans("AuvoZugferdDiagExecRequiredSub"), 'fix' => '/custom/auvozugferd/admin/setup.php'];
	} else {
		$diagOk[] = $langs->trans("AuvoZugferdDiagInjSubprocess");
	}
} else { // auto
	if ($execAvailable) {
		$diagOk[] = $langs->trans("AuvoZugferdDiagInjAuto");
	} elseif ($tcpdiActive) {
		// auto sans exec → in-process, mais tcpdi actif → échec garanti : on guide.
		$diagErrors[] = ['msg' => $langs->trans("AuvoZugferdDiagTcpdiConflict"), 'fix' => '/admin/const.php'];
	} else {
		$diagOk[] = $langs->trans("AuvoZugferdDiagInjAutoNoExec");
	}
}

// Binaire PHP CLI : pertinent uniquement quand le sous-process peut servir
// (mode subprocess, ou mode auto avec exec dispo). Sinon, sans objet.
if ($execAvailable && $diagInjMode !== 'inprocess') {
	$phpCliManual = trim(getDolGlobalString('AUVOZUGFERD_PHP_CLI_PATH', ''));
	// 'php' nu = pas une vraie surcharge (cohérent avec auvozugferd_resolve_php_cli) → auto.
	$phpCliIsAuto = ($phpCliManual === '' || $phpCliManual === 'php');
	$phpCliResolved = auvozugferd_resolve_php_cli($db);
	$phpCliLabel = $phpCliResolved.($phpCliIsAuto ? ' ('.$langs->trans("AuvoZugferdDiagPhpCliAuto").')' : '');
	if (auvozugferd_php_cli_is_valid($phpCliResolved)) {
		$diagOk[] = $langs->trans("AuvoZugferdDiagPhpCliOk").' : '.dol_escape_htmltag($phpCliLabel);
	} else {
		// Mode subprocess OU auto-avec-exec : le sous-process est le chemin
		// d'injection effectif → un CLI invalide est bloquant.
		$diagErrors[] = ['msg' => $langs->trans("AuvoZugferdDiagPhpCliNotFound", dol_escape_htmltag($phpCliResolved)), 'fix' => '/custom/auvozugferd/admin/setup.php'];
	}
}

// Multidevise : avertissement informatif (les factures en devise étrangère sont ignorées)
if (isModEnabled('multicurrency')) {
	$diagOk[] = $langs->trans("AuvoZugferdDiagMulticurrencyNote");
}

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("AuvoZugferdDiagResults").'</td></tr>';

foreach ($diagOk as $ok) {
	print '<tr class="oddeven"><td><span style="color: green;">&#10004;</span> '.$ok.'</td><td></td></tr>';
}
foreach ($diagErrors as $err) {
	$fixLabel = !empty($err['fixlabel']) ? $err['fixlabel'] : $langs->trans("AuvoZugferdDiagFixLink");
	print '<tr class="oddeven"><td><span style="color: red;">&#10008;</span> <strong>'.$err['msg'].'</strong></td>';
	print '<td><a href="'.DOL_URL_ROOT.$err['fix'].'">'.$fixLabel.'</a></td></tr>';
}
// Avertissements : recommandés, non bloquants → pastille orange, pas de croix
// rouge. Purement informatifs (constat), le réglage se fait dans les options ;
// un lien éventuel n'est affiché que s'il est explicitement fourni.
foreach ($diagWarnings as $warn) {
	print '<tr class="oddeven"><td><span style="color: #e67e22;">&#9888;</span> '.$warn['msg'].'</td><td>';
	if (!empty($warn['fix'])) {
		$fixLabel = !empty($warn['fixlabel']) ? $warn['fixlabel'] : $langs->trans("AuvoZugferdDiagFixLink");
		print '<a href="'.DOL_URL_ROOT.$warn['fix'].'">'.$fixLabel.'</a>';
	}
	print '</td></tr>';
}

if (empty($diagErrors)) {
	// Pas de blocage : message vert. S'il reste des avertissements (recommandés),
	// on le dit autrement pour ne pas laisser croire que tout est parfait.
	$allOkKey = empty($diagWarnings) ? "AuvoZugferdDiagAllOk" : "AuvoZugferdDiagNoBlocker";
	print '<tr class="oddeven"><td colspan="2"><span style="color: green;"><strong>'.$langs->trans($allOkKey).'</strong></span></td></tr>';
}

print '</table>';

// === Bloc "À propos" ===
print '<div style="margin:30px 0;padding:20px 25px;border:1px solid #e0e0e0;border-left:4px solid #FFD21F;border-radius:6px;background:linear-gradient(135deg,#fffef7 0%,#fafafa 100%);">';
print '<h3 style="margin:0 0 10px 0;color:#333;">'.$langs->trans("AuvoZugferdAboutTitle").'</h3>';
print '<p style="margin:0;color:#555;">'.$langs->trans("AuvoZugferdAboutIntro").' '.$langs->trans("AuvoZugferdAboutLocation").'</p>';
print '</div>';

llxFooter();
$db->close();
