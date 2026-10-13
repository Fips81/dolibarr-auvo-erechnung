<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de> — fork, renamed and modified as DoliZUGFeRD
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Page de configuration du module DoliZUGFeRD
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
require_once dol_buildpath('/dolizugferd/core/lib/dolizugferd.lib.php');

// Sécurité
if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(["admin", "dolizugferd@dolizugferd"]);

// Auto-correctif : sous Dolibarr 23 les extrafields Chorus pouvaient être créés
// avec printable!=0 et polluaient la note du PDF (cf dolizugferd_fix_chorus_*).
// On corrige à l'ouverture de la config — pas besoin de réactiver le module.
dolizugferd_fix_chorus_extrafields_display($db);

$action = GETPOST('action', 'aZ09');

// Les valeurs par défaut des mentions légales BR-FR sont définies dans la lib
// (DOLIZUGFERD_DEFAULT_NOTE_*) pour rester synchronisées avec le builder XML.

// Sauvegarde des paramètres
if ($action == 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	// CSRF : vérifier le token courant (pas newToken() qui génère un futur token)
	if (GETPOST('token', 'alpha') !== currentToken()) {
		accessforbidden('Bad value for CSRF token');
	}
	$error = 0;

	// Mode d'injection : on n'accepte que les 3 valeurs connues (défaut auto).
	$injMode = strtolower(trim(GETPOST('DOLIZUGFERD_INJECTION_MODE', 'alpha')));
	if (!in_array($injMode, ['auto', 'inprocess', 'subprocess'], true)) {
		$injMode = 'auto';
	}

	$updates = [
		['DOLIZUGFERD_INJECTION_MODE', $injMode, 'chaine'],
		['DOLIZUGFERD_BANK_ACCOUNT', GETPOSTINT('DOLIZUGFERD_BANK_ACCOUNT'),    'int'],
		['DOLIZUGFERD_PAYMENT_MEANS',trim(GETPOST('DOLIZUGFERD_PAYMENT_MEANS', 'alpha')), 'chaine'],
		['DOLIZUGFERD_STRICT_MODE',  GETPOSTINT('DOLIZUGFERD_STRICT_MODE'),     'int'],
		['DOLIZUGFERD_BR_CHECK',     GETPOSTINT('DOLIZUGFERD_BR_CHECK'),        'int'],
		['DOLIZUGFERD_CHORUS_ENABLED', GETPOSTINT('DOLIZUGFERD_CHORUS_ENABLED'), 'int'],
		['DOLIZUGFERD_PHP_CLI_PATH', trim(GETPOST('DOLIZUGFERD_PHP_CLI_PATH', 'alphanohtml')), 'chaine'],
		['DOLIZUGFERD_VERAPDF_PATH', trim(GETPOST('DOLIZUGFERD_VERAPDF_PATH', 'alphanohtml')), 'chaine'],
		['DOLIZUGFERD_NOTE_PMD',     trim(GETPOST('DOLIZUGFERD_NOTE_PMD', 'restricthtml')),    'chaine'],
		['DOLIZUGFERD_NOTE_PMT',     trim(GETPOST('DOLIZUGFERD_NOTE_PMT', 'restricthtml')),    'chaine'],
		['DOLIZUGFERD_NOTE_AAB',     trim(GETPOST('DOLIZUGFERD_NOTE_AAB', 'restricthtml')),    'chaine'],
		['DOLIZUGFERD_NOTES_IN_FOOTER', GETPOSTINT('DOLIZUGFERD_NOTES_IN_FOOTER'), 'int'],
		['DOLIZUGFERD_NOTES_OVERWRITE', GETPOSTINT('DOLIZUGFERD_NOTES_OVERWRITE'), 'int'],
	];
	// Le bloc PA (suffixe d'endpoint BT-34) n'est affiché que pour un vendeur
	// FR : ne pas écraser la valeur enregistrée quand le champ est absent du POST.
	if (GETPOSTISSET('DOLIZUGFERD_ENDPOINT_SUFFIX_SELLER')) {
		$updates[] = ['DOLIZUGFERD_ENDPOINT_SUFFIX_SELLER', trim(GETPOST('DOLIZUGFERD_ENDPOINT_SUFFIX_SELLER', 'alphanohtml')), 'chaine'];
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
	if (!$error && GETPOSTINT('DOLIZUGFERD_NOTES_IN_FOOTER')) {
		$footerAdded = dolizugferd_append_notes_to_footer($db, GETPOSTINT('DOLIZUGFERD_NOTES_OVERWRITE') === 1);
	}

	// Police du PDF (constante globale Dolibarr MAIN_PDF_FORCE_FONT). On la pose
	// en visible=1 pour qu'elle reste gérable (Config > Divers ET ce menu). Vide
	// = on retire le forçage (police par défaut Dolibarr). On valide la valeur
	// contre la liste réelle des polices pour ne rien poser d'invalide.
	if (!$error) {
		$pdfFont = GETPOST('MAIN_PDF_FORCE_FONT', 'alpha');
		$availableFonts = dolizugferd_list_pdf_fonts();
		if ($pdfFont === '' || isset($availableFonts[$pdfFont])) {
			dolibarr_set_const($db, 'MAIN_PDF_FORCE_FONT', $pdfFont, 'chaine', 1, '', $conf->entity);
		}
	}

	if (!$error) {
		$msg = $langs->trans("SetupSaved");
		if ($footerAdded > 0) {
			$msg .= ' — '.$langs->trans('DoliZugferdCopyNotesDone', $footerAdded);
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
		setEventMessages($langs->trans("DoliZugferdForceFontSet"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}

// Affichage
llxHeader('', $langs->trans("DoliZugferdSetup"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("DoliZugferdSetup"), $linkback, 'title_setup');

// Bandeau "Nouvelle version disponible" si le check GitHub remonte une version > locale
require_once dirname(__DIR__).'/core/modules/modDoliZugferd.class.php';
$modDesc = new modDoliZugferd($db);
$updateInfo = dolizugferd_check_latest_release($db, $modDesc->version);
if ($updateInfo !== null) {
	print '<div class="warning" style="margin:8px 0;padding:10px;border-left:4px solid #e67e22;background:#fff3e0;">';
	print '<strong>'.$langs->trans("DoliZugferdUpdateAvailable").'</strong> : ';
	print $langs->trans("DoliZugferdUpdateAvailableMsg", dol_escape_htmltag($updateInfo['version']), dol_escape_htmltag($modDesc->version));
	print ' <a href="'.dol_escape_htmltag($updateInfo['url']).'" target="_blank" rel="noopener">'.$langs->trans("DoliZugferdUpdateSeeRelease").'</a>';
	print '</div>';
}

print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';

// ---- Bloc 1 : Facturation ----
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("DoliZugferdSecBilling").'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("DoliZugferdBankAccount").'</td><td>';
$currentBankAccount = getDolGlobalInt('DOLIZUGFERD_BANK_ACCOUNT');
$resql = $db->query("SELECT rowid, label, iban_prefix, bic FROM ".MAIN_DB_PREFIX."bank_account WHERE clos = 0 AND entity IN (".getEntity('bank_account').") ORDER BY label");
print '<select name="DOLIZUGFERD_BANK_ACCOUNT" class="flat minwidth300">';
print '<option value="0">-- '.$langs->trans("Select").' --</option>';
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$infoIban = !empty($obj->iban_prefix) ? ' ('.dolizugferd_iban_short($obj->iban_prefix).')' : ' (pas d\'IBAN)';
		print '<option value="'.$obj->rowid.'"'.($currentBankAccount == $obj->rowid ? ' selected' : '').'>'.dol_escape_htmltag($obj->label.$infoIban).'</option>';
	}
}
print '</select></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("DoliZugferdPaymentMeans").'<br><span class="opacitymedium small">'.$langs->trans("DoliZugferdPaymentMeansHint").'</span></td><td>';
$currentMeans = getDolGlobalString('DOLIZUGFERD_PAYMENT_MEANS', '30');
print '<select name="DOLIZUGFERD_PAYMENT_MEANS" class="flat">';
foreach (array('30'=>'PaymentMeans30','58'=>'PaymentMeans58','59'=>'PaymentMeans59','49'=>'PaymentMeans49') as $code=>$k) {
	print '<option value="'.$code.'"'.($currentMeans == $code ? ' selected' : '').'>'.$code.' - '.$langs->trans($k).'</option>';
}
print '</select></td></tr>';

// ---- Bloc 2 : Mentions légales ----
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("DoliZugferdLegalNotes").'</td></tr>';
// Les défauts PMD/PMT/AAB sont des mentions du droit français : pour un vendeur
// établi hors de France les champs restent vides tant qu'il n'a pas saisi les
// siennes (cf. dolizugferd_resolve_legal_notes).
$legalNotes = dolizugferd_resolve_legal_notes(dolizugferd_seller_is_fr($mysoc));
if (!dolizugferd_seller_is_fr($mysoc)) {
	print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium small">'.$langs->trans("DoliZugferdLegalNotesForeignHint").'</span></td></tr>';
}
foreach ([
	'PMD' => 'DoliZugferdNotePMD',
	'PMT' => 'DoliZugferdNotePMT',
	'AAB' => 'DoliZugferdNoteAAB',
] as $code => $transKey) {
	$val = $legalNotes[$code];
	print '<tr class="oddeven"><td>'.$langs->trans($transKey).'</td><td><textarea name="DOLIZUGFERD_NOTE_'.$code.'" class="flat minwidth500" rows="3">'.dol_escape_htmltag($val).'</textarea></td></tr>';
}
print '<tr class="oddeven"><td>'.$langs->trans("DoliZugferdNotesInFooter").'<br><span class="opacitymedium small">'.$langs->trans("DoliZugferdNotesInFooterHint").'</span></td><td>';
$notesFooter = getDolGlobalInt('DOLIZUGFERD_NOTES_IN_FOOTER', 0);
print '<select name="DOLIZUGFERD_NOTES_IN_FOOTER" class="flat">';
print '<option value="0"'.(!$notesFooter ? ' selected' : '').'>'.$langs->trans("No").'</option>';
print '<option value="1"'.($notesFooter ? ' selected' : '').'>'.$langs->trans("Yes").'</option>';
print '</select></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("DoliZugferdNotesOverwrite").'<br><span class="opacitymedium small">'.$langs->trans("DoliZugferdNotesOverwriteHint").'</span></td><td>';
$notesOverwrite = getDolGlobalInt('DOLIZUGFERD_NOTES_OVERWRITE', 0);
print '<select name="DOLIZUGFERD_NOTES_OVERWRITE" class="flat">';
print '<option value="0"'.(!$notesOverwrite ? ' selected' : '').'>'.$langs->trans("No").' ('.$langs->trans("DoliZugferdNotesAppend").')</option>';
print '<option value="1"'.($notesOverwrite ? ' selected' : '').'>'.$langs->trans("Yes").' ('.$langs->trans("DoliZugferdNotesReplace").')</option>';
print '</select></td></tr>';

// ---- Bloc 3 : Secteur public (Chorus Pro) ----
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("DoliZugferdSecPublic").'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("DoliZugferdChorusEnabled").'<br><span class="opacitymedium small">'.$langs->trans("DoliZugferdChorusEnabledHint").'</span></td><td>';
$chorusEnabled = getDolGlobalInt('DOLIZUGFERD_CHORUS_ENABLED', 0);
print '<select name="DOLIZUGFERD_CHORUS_ENABLED" class="flat">';
print '<option value="0"'.(!$chorusEnabled ? ' selected' : '').'>'.$langs->trans("No").'</option>';
print '<option value="1"'.($chorusEnabled ? ' selected' : '').'>'.$langs->trans("Yes").'</option>';
print '</select></td></tr>';

// ---- Bloc 4 : Validation & conformité ----
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("DoliZugferdSecValidation").'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("DoliZugferdBrCheck").'<br><span class="opacitymedium small">'.$langs->trans("DoliZugferdBrCheckHint").'</span></td><td>';
$brCheck = getDolGlobalInt('DOLIZUGFERD_BR_CHECK', 1);
print '<select name="DOLIZUGFERD_BR_CHECK" class="flat">';
print '<option value="1"'.($brCheck ? ' selected' : '').'>'.$langs->trans("Yes").'</option>';
print '<option value="0"'.(!$brCheck ? ' selected' : '').'>'.$langs->trans("No").'</option>';
print '</select></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("DoliZugferdStrictMode").'<br><span class="opacitymedium small">'.$langs->trans("DoliZugferdStrictModeHint").'</span></td><td>';
$strict = getDolGlobalInt('DOLIZUGFERD_STRICT_MODE', 0);
print '<select name="DOLIZUGFERD_STRICT_MODE" class="flat">';
print '<option value="0"'.($strict == 0 ? ' selected' : '').'>'.$langs->trans("DoliZugferdStrictModeBestEffort").'</option>';
print '<option value="1"'.($strict == 1 ? ' selected' : '').'>'.$langs->trans("DoliZugferdStrictModeStrict").'</option>';
print '</select></td></tr>';

// Police du PDF (MAIN_PDF_FORCE_FONT) : liste les polices du Dolibarr, marquées
// conformes Factur-X (embarquées ✓) ou non (base-14 ⚠). Conformes en premier.
print '<tr class="oddeven"><td>'.$langs->trans("DoliZugferdPdfFont").'<br><span class="opacitymedium small">'.$langs->trans("DoliZugferdPdfFontHint").'</span></td><td>';
$curFont = getDolGlobalString('MAIN_PDF_FORCE_FONT', '');
$pdfFonts = dolizugferd_list_pdf_fonts();
print '<select name="MAIN_PDF_FORCE_FONT" class="flat minwidth300">';
print '<option value="">'.$langs->trans("DoliZugferdPdfFontDefault").'</option>';
foreach (array(true, false) as $wantEmbedded) {
	foreach ($pdfFonts as $fname => $isEmbedded) {
		if ($isEmbedded !== $wantEmbedded) {
			continue;
		}
		$tag = $isEmbedded ? $langs->trans("DoliZugferdPdfFontOk") : $langs->trans("DoliZugferdPdfFontKo");
		print '<option value="'.dol_escape_htmltag($fname).'"'.($curFont === $fname ? ' selected' : '').'>'.dol_escape_htmltag($fname.' — '.$tag).'</option>';
	}
}
print '</select></td></tr>';

// ---- Bloc 5 : Technique (avancé) ----
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("DoliZugferdSecTech").'</td></tr>';

// Mode d'injection du XML dans le PDF. In-process (sans exec) par défaut :
// fonctionne sur les hébergements mutualisés où exec() est désactivé. Le
// sous-process (historique) reste disponible en repli/forçage.
$injModeCur = strtolower(trim(getDolGlobalString('DOLIZUGFERD_INJECTION_MODE', 'auto')));
if (!in_array($injModeCur, ['auto', 'inprocess', 'subprocess'], true)) {
	$injModeCur = 'auto';
}
print '<tr class="oddeven"><td>'.$langs->trans("DoliZugferdInjectionMode").'<br><span class="opacitymedium small">'.$langs->trans("DoliZugferdInjectionModeHint").'</span></td><td>';
print '<select name="DOLIZUGFERD_INJECTION_MODE" class="flat minwidth300">';
foreach (['auto' => 'DoliZugferdInjectionModeAuto', 'inprocess' => 'DoliZugferdInjectionModeInProcess', 'subprocess' => 'DoliZugferdInjectionModeSubprocess'] as $injVal => $injKey) {
	print '<option value="'.$injVal.'"'.($injModeCur === $injVal ? ' selected' : '').'>'.$langs->trans($injKey).'</option>';
}
print '</select></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("DoliZugferdPhpCliPath").'<br><span class="opacitymedium small">'.$langs->trans("DoliZugferdPhpCliPathHint").'</span></td><td>';
print '<input type="text" name="DOLIZUGFERD_PHP_CLI_PATH" class="flat minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('DOLIZUGFERD_PHP_CLI_PATH', '')).'" placeholder="'.$langs->trans("DoliZugferdPhpCliAutoPlaceholder").'"></td></tr>';
// veraPDF : outil EXTERNE optionnel, non embarqué et non installé par défaut.
// Vide = pas de post-validation (le contrôle XSD + règles BR internes reste actif).
print '<tr class="oddeven"><td>'.$langs->trans("DoliZugferdVeraPdfPath").'<br><span class="opacitymedium small">'.$langs->trans("DoliZugferdVeraPdfPathHint").'</span></td><td>';
print '<input type="text" name="DOLIZUGFERD_VERAPDF_PATH" class="flat minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('DOLIZUGFERD_VERAPDF_PATH', '')).'" placeholder="'.$langs->trans("DoliZugferdVeraPdfPlaceholder").'"></td></tr>';

// ---- Bloc 6 : Adressage Plateforme Agréée (PA) ----
// Suffixe d'endpoint VENDEUR (BT-34). Certaines PA exigent que l'adresse
// électronique du vendeur ne soit pas le SIREN nu mais un endpoint suffixé
// (ex Hubtimize : "<SIREN>_Status"). Vide = SIREN nu. L'adresse acheteur
// (BT-49) reste toujours le SIREN nu (la PA destinataire route).
// Propre à la réforme française : masqué pour un vendeur établi hors de France.
if (dolizugferd_seller_is_fr($mysoc)) {
	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("DoliZugferdSecPA").'</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans("DoliZugferdEndpointSuffixSeller").'<br><span class="opacitymedium small">'.$langs->trans("DoliZugferdEndpointSuffixSellerHint").'</span></td><td>';
	print '<input type="text" name="DOLIZUGFERD_ENDPOINT_SUFFIX_SELLER" class="flat minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('DOLIZUGFERD_ENDPOINT_SUFFIX_SELLER', '')).'" placeholder="'.$langs->trans("DoliZugferdEndpointSuffixSellerPlaceholder").'"></td></tr>';
}

print '</table>';
print '<br><div class="center"><input type="submit" class="button button-save" value="'.$langs->trans("Save").'"></div>';
print '</form>';

// Info
print '<br>';
print '<div class="info">';
print $langs->trans("DoliZugferdInfo");
print '</div>';

// === Diagnostic des infos obligatoires ===
print '<br>';
print load_fiche_titre($langs->trans("DoliZugferdDiagTitle"), '', '');

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
	$diagErrors[] = ['msg' => $langs->trans("DoliZugferdDiagModuleBankDisabled"), 'fix' => '/admin/modules.php'];
} else {
	$diagOk[] = $langs->trans("DoliZugferdDiagModuleBankEnabled");
}
if (!isModEnabled('facture')) {
	$diagErrors[] = ['msg' => $langs->trans("DoliZugferdDiagModuleInvoiceDisabled"), 'fix' => '/admin/modules.php'];
} else {
	$diagOk[] = $langs->trans("DoliZugferdDiagModuleInvoiceEnabled");
}

$diagCheck('DoliZugferdDiagSellerName', $mysoc->name);

$hasAddr = !empty($mysoc->address) && !empty($mysoc->zip) && !empty($mysoc->town);
$diagCheck('DoliZugferdDiagSellerAddress', $hasAddr ? '1' : '', dol_escape_htmltag($mysoc->zip).' '.dol_escape_htmltag($mysoc->town));

// TVA intra : en franchise en base (293 B CGI, auto-entrepreneurs), l'absence de
// numéro est normale — le SIREN est publié comme identifiant fiscal (schemeID="FC")
// par le générateur. On n'affiche donc pas d'erreur, cohérent avec check_mandatory().
$isFranchise = isset($mysoc->tva_assuj) && (int) $mysoc->tva_assuj === 0;
$vatRaw = trim((string) ($mysoc->tva_intra ?? ''));
if ($vatRaw !== '' && !dolizugferd_is_vat_id($vatRaw)) {
	// Saisie sans préfixe pays (ex. Steuernummer dans le champ TVA intra) :
	// non publiée en BT-31 (BR-CO-09).
	$diagErrors[] = ['msg' => $langs->trans("DoliZugferdWarnSellerVATInvalid", $vatRaw), 'fix' => '/admin/company.php'];
} elseif ($isFranchise && empty($mysoc->tva_intra)) {
	$diagOk[] = $langs->trans("DoliZugferdDiagSellerVAT").' : '.$langs->trans("DoliZugferdDiagSellerVATFranchise");
} else {
	$diagCheck('DoliZugferdDiagSellerVAT', $mysoc->tva_intra);
}

// Identifiants légaux : SIREN/SIRET pour un vendeur FR (réforme française),
// Steuernummer pour un vendeur DE. Ailleurs, la TVA intra contrôlée plus haut
// suffit (BR-CO-26) : rien de plus à afficher.
$diagSellerIsFR = dolizugferd_seller_is_fr($mysoc);
$diagSellerCountry = strtoupper((string) ($mysoc->country_code ?? ''));
if ($diagSellerIsFR) {
	if (empty($mysoc->idprof2)) {
		$diagErrors[] = ['msg' => $langs->trans("DoliZugferdDiagSellerSIRET").' (BR-FR-10)', 'fix' => '/admin/company.php'];
	} else {
		$siren = dolizugferd_extract_siren($mysoc->idprof2);
		$diagOk[] = $langs->trans("DoliZugferdDiagSellerSIRET").' : SIREN '.dol_escape_htmltag($siren).' (SIRET '.dol_escape_htmltag($mysoc->idprof2).')';
	}
} elseif ($diagSellerCountry === 'DE') {
	// § 14 Abs. 4 Nr. 2 UStG / BR-CO-26 : Steuernummer OU USt-IdNr. Sans
	// USt-IdNr. (typiquement Kleinunternehmer), la Steuernummer devient obligatoire.
	$steuernummer = dolizugferd_party_steuernummer($mysoc);
	if ($steuernummer !== '') {
		$diagOk[] = $langs->trans("DoliZugferdDiagSellerSteuernummer").' : '.dol_escape_htmltag($steuernummer);
	} elseif (dolizugferd_party_vat_id($mysoc) !== '') {
		$diagOk[] = $langs->trans("DoliZugferdDiagSellerSteuernummer").' : '.$langs->trans("DoliZugferdDiagSellerSteuernummerNotNeeded");
	} else {
		$diagErrors[] = ['msg' => $langs->trans("DoliZugferdDiagSellerTaxIdMissing"), 'fix' => '/admin/company.php'];
	}
}

$diagCheck('DoliZugferdDiagSellerEmail', $mysoc->email, null, $diagSellerIsFR ? '(BR-FR-13 / BT-34)' : '(BT-34)');

// Banque : lien "Corriger" vers la liste des comptes (compta/bank/list.php), pas la fiche société.
$bankFixUrl = '/compta/bank/list.php?mainmenu=bank';
$bankId = getDolGlobalInt('DOLIZUGFERD_BANK_ACCOUNT');
if ($bankId <= 0) {
	$diagErrors[] = ['msg' => $langs->trans("DoliZugferdDiagBankNotSet"), 'fix' => $bankFixUrl];
} else {
	$bankCheck = new Account($db);
	if ($bankCheck->fetch($bankId) <= 0) {
		$diagErrors[] = ['msg' => $langs->trans("DoliZugferdDiagBankNotFound"), 'fix' => $bankFixUrl];
	} else {
		$diagCheck('DoliZugferdDiagIBAN', $bankCheck->iban, dol_escape_htmltag(dolizugferd_iban_short($bankCheck->iban)), '', $bankFixUrl);
		$diagCheck('DoliZugferdDiagBIC', $bankCheck->bic, null, '', $bankFixUrl);
	}
}

// PDF/A-3 : police embarquée forcée (sinon veraPDF échoue sur les polices base-14)
$forceFont = getDolGlobalString('MAIN_PDF_FORCE_FONT', '');
if ($forceFont === '') {
	$diagErrors[] = [
		'msg' => $langs->trans("DoliZugferdDiagForceFontMissing"),
		'fix' => '/custom/dolizugferd/admin/setup.php?action=setforcefont&token='.newToken(),
		'fixlabel' => $langs->trans("DoliZugferdDiagFixForceFont"),
	];
} else {
	$diagOk[] = $langs->trans("DoliZugferdDiagForceFontOk").' : '.dol_escape_htmltag($forceFont);
}

// Pied de facture : mentions visibles sur le PDF. Recommandé mais NON
// bloquant — le XML embarqué reste conforme BR-FR-05 dans tous les cas. On
// l'affiche en avertissement (orange) et PUREMENT informatif : le diagnostic
// constate, c'est le toggle « recopier les mentions en pied » du bloc Mentions
// légales qui règle (comme les autres items, pas de contrôle dans le diag).
// On lit la BONNE constante (INVOICE_FREE_TEXT depuis Dolibarr 17, celle que
// le PDF imprime) — pas l'ancienne FACTURE_FREE_TEXT qui n'était plus lue.
$freeTextDiag = getDolGlobalString(dolizugferd_invoice_freetext_const(), '');
if (trim($freeTextDiag) === '') {
	$diagWarnings[] = ['msg' => $langs->trans("DoliZugferdDiagFreeTextMissing")];
} else {
	$diagOk[] = $langs->trans("DoliZugferdDiagFreeTextOk");
}

// Diagnostic d'injection, dépendant du mode choisi. L'injection in-process n'a
// lieu qu'en mode 'inprocess' (forcé) ou en mode 'auto' sur un serveur SANS
// exec() → là, exec()/PHP CLI ne sont pas requis (mutualisés durcis). Sinon
// (mode 'auto' avec exec, ou mode 'subprocess'), c'est le sous-process — process
// PHP isolé, le chemin le plus sûr — qui injecte : exec() + PHP CLI valide requis.
$diagInjMode = strtolower(trim(getDolGlobalString('DOLIZUGFERD_INJECTION_MODE', 'auto')));
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
		$diagErrors[] = ['msg' => $langs->trans("DoliZugferdDiagTcpdiConflict"), 'fix' => '/admin/const.php'];
	} else {
		$diagOk[] = $langs->trans("DoliZugferdDiagInjInProcess");
	}
} elseif ($diagInjMode === 'subprocess') {
	if (!$execAvailable) {
		$diagErrors[] = ['msg' => $langs->trans("DoliZugferdDiagExecRequiredSub"), 'fix' => '/custom/dolizugferd/admin/setup.php'];
	} else {
		$diagOk[] = $langs->trans("DoliZugferdDiagInjSubprocess");
	}
} else { // auto
	if ($execAvailable) {
		$diagOk[] = $langs->trans("DoliZugferdDiagInjAuto");
	} elseif ($tcpdiActive) {
		// auto sans exec → in-process, mais tcpdi actif → échec garanti : on guide.
		$diagErrors[] = ['msg' => $langs->trans("DoliZugferdDiagTcpdiConflict"), 'fix' => '/admin/const.php'];
	} else {
		$diagOk[] = $langs->trans("DoliZugferdDiagInjAutoNoExec");
	}
}

// Binaire PHP CLI : pertinent uniquement quand le sous-process peut servir
// (mode subprocess, ou mode auto avec exec dispo). Sinon, sans objet.
if ($execAvailable && $diagInjMode !== 'inprocess') {
	$phpCliManual = trim(getDolGlobalString('DOLIZUGFERD_PHP_CLI_PATH', ''));
	// 'php' nu = pas une vraie surcharge (cohérent avec dolizugferd_resolve_php_cli) → auto.
	$phpCliIsAuto = ($phpCliManual === '' || $phpCliManual === 'php');
	$phpCliResolved = dolizugferd_resolve_php_cli($db);
	$phpCliLabel = $phpCliResolved.($phpCliIsAuto ? ' ('.$langs->trans("DoliZugferdDiagPhpCliAuto").')' : '');
	if (dolizugferd_php_cli_is_valid($phpCliResolved)) {
		$diagOk[] = $langs->trans("DoliZugferdDiagPhpCliOk").' : '.dol_escape_htmltag($phpCliLabel);
	} else {
		// Mode subprocess OU auto-avec-exec : le sous-process est le chemin
		// d'injection effectif → un CLI invalide est bloquant.
		$diagErrors[] = ['msg' => $langs->trans("DoliZugferdDiagPhpCliNotFound", dol_escape_htmltag($phpCliResolved)), 'fix' => '/custom/dolizugferd/admin/setup.php'];
	}
}

// Multidevise : avertissement informatif (les factures en devise étrangère sont ignorées)
if (isModEnabled('multicurrency')) {
	$diagOk[] = $langs->trans("DoliZugferdDiagMulticurrencyNote");
}

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("DoliZugferdDiagResults").'</td></tr>';

foreach ($diagOk as $ok) {
	print '<tr class="oddeven"><td><span style="color: green;">&#10004;</span> '.$ok.'</td><td></td></tr>';
}
foreach ($diagErrors as $err) {
	$fixLabel = !empty($err['fixlabel']) ? $err['fixlabel'] : $langs->trans("DoliZugferdDiagFixLink");
	print '<tr class="oddeven"><td><span style="color: red;">&#10008;</span> <strong>'.$err['msg'].'</strong></td>';
	print '<td><a href="'.DOL_URL_ROOT.$err['fix'].'">'.$fixLabel.'</a></td></tr>';
}
// Avertissements : recommandés, non bloquants → pastille orange, pas de croix
// rouge. Purement informatifs (constat), le réglage se fait dans les options ;
// un lien éventuel n'est affiché que s'il est explicitement fourni.
foreach ($diagWarnings as $warn) {
	print '<tr class="oddeven"><td><span style="color: #e67e22;">&#9888;</span> '.$warn['msg'].'</td><td>';
	if (!empty($warn['fix'])) {
		$fixLabel = !empty($warn['fixlabel']) ? $warn['fixlabel'] : $langs->trans("DoliZugferdDiagFixLink");
		print '<a href="'.DOL_URL_ROOT.$warn['fix'].'">'.$fixLabel.'</a>';
	}
	print '</td></tr>';
}

if (empty($diagErrors)) {
	// Pas de blocage : message vert. S'il reste des avertissements (recommandés),
	// on le dit autrement pour ne pas laisser croire que tout est parfait.
	$allOkKey = empty($diagWarnings) ? "DoliZugferdDiagAllOk" : "DoliZugferdDiagNoBlocker";
	print '<tr class="oddeven"><td colspan="2"><span style="color: green;"><strong>'.$langs->trans($allOkKey).'</strong></span></td></tr>';
}

print '</table>';

// === Bloc "À propos" ===
print '<div style="margin:30px 0;padding:20px 25px;border:1px solid #e0e0e0;border-left:4px solid #FFD21F;border-radius:6px;background:linear-gradient(135deg,#fffef7 0%,#fafafa 100%);">';
print '<h3 style="margin:0 0 10px 0;color:#333;">'.$langs->trans("DoliZugferdAboutTitle").'</h3>';
print '<p style="margin:0;color:#555;">'.$langs->trans("DoliZugferdAboutIntro").' '.$langs->trans("DoliZugferdAboutLocation").'</p>';
print '</div>';

llxFooter();
$db->close();
