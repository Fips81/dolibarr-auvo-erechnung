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
 * EN16931 CrossIndustryInvoice XML generator for Factur-X.
 * Compliant with BR-FR rules (XP Z12-012 V1.2.0).
 */

/**
 * ISO country codes for the European Union (used to classify the EN16931 VAT
 * category for intra-community B2B transactions).
 *
 * Default BR-FR legal notices (BG-3 IncludedNote), overridable through
 * Dolibarr constants AUVOERECHNUNG_NOTE_*.
 *
 * Use define() rather than const so this remains safe if the library is included
 * through two different paths (custom + dol_buildpath) in some setups.
 */
if (!defined('AUVOERECHNUNG_EU_COUNTRIES')) {
	define('AUVOERECHNUNG_EU_COUNTRIES', [
		'AT','BE','BG','CY','CZ','DE','DK','EE','ES','FI','FR','GR','HR','HU',
		'IE','IT','LT','LU','LV','MT','NL','PL','PT','RO','SE','SI','SK',
	]);
	// Overseas department/territory country codes mapped to FR in reform flows
	// (BR-FR-MAP-14): treat them as France for the BAR note and applicability of
	// the internal validator's BR-FR rules.
	define('AUVOERECHNUNG_FR_OVERSEAS_COUNTRIES', ['GF', 'TF', 'GP', 'GY', 'MQ', 'YT', 'RE', 'BL', 'MF', 'PM']);
	define('AUVOERECHNUNG_DEFAULT_NOTE_PMD', 'En cas de retard de paiement, une pénalité égale à 3 fois le taux d\'intérêt légal sera exigible (article L.441-10 du Code de commerce).');
	define('AUVOERECHNUNG_DEFAULT_NOTE_PMT', 'Une indemnité forfaitaire de 40 euros sera exigible pour frais de recouvrement en cas de retard de paiement.');
	define('AUVOERECHNUNG_DEFAULT_NOTE_AAB', 'Pas d\'escompte pour paiement anticipé.');
	// XRechnung (German EN16931 CIUS from KoSIT): BT-23 is required by
	// PEPPOL-EN16931-R001 in the XRechnung Schematron. The specification
	// version (BT-24) is fixed: KoSIT bugfix bundles keep the identifier
	// xrechnung_3.0, and a new major version (4.0) changes the content too, so
	// it needs a module update rather than a setting.
	define('AUVOERECHNUNG_XRECHNUNG_BUSINESS_PROCESS', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');
	define('AUVOERECHNUNG_XRECHNUNG_CIUS_VERSION', '3.0');
}

require_once __DIR__.'/auvoerechnung_model.php';

/**
 * Translates a key through $langs when the Dolibarr environment is loaded;
 * otherwise returns the raw key (standalone unit-test context).
 */
function auvoerechnung_trans($key, ...$args)
{
	global $langs;
	if (is_object($langs) && method_exists($langs, 'trans')) {
		$langs->load('auvoerechnung@auvoerechnung');
		return $langs->trans($key, ...$args);
	}
	return $args ? $key.' ('.implode(', ', array_map('strval', $args)).')' : $key;
}

/**
 * Variant of auvoerechnung_trans() without HTML entities, for messages also
 * written to syslog, returned as API JSON, or truncated (dol_trunc): "ä" stays
 * "ä" instead of becoming "&auml;".
 *
 * @param string $key   Translation key
 * @param mixed  ...$args Translation %s parameters
 * @return string
 */
function auvoerechnung_trans_raw($key, ...$args)
{
	global $langs;
	if (is_object($langs) && method_exists($langs, 'transnoentitiesnoconv')) {
		$langs->load('auvoerechnung@auvoerechnung');
		return $langs->transnoentitiesnoconv($key, ...array_map('strval', $args));
	}
	return $args ? $key.' ('.implode(', ', array_map('strval', $args)).')' : $key;
}

/**
 * Translates text without HTML entities when it has MORE than four parameters.
 *
 * Translate::trans() accepts only four values (the fifth is treated as $maxsize)
 * and transnoentitiesnoconv() accepts five. More values make sprintf() throw an
 * ArgumentCountError (PHP 8), which Dolibarr does not catch and which would stop
 * generation (issue #1). Read the raw template and fill it here instead. If the
 * template is missing or the number of %s placeholders is wrong, fall back to
 * the key and values.
 *
 * @param string $key  Translation key
 * @param array  $args Values for %s placeholders, in order
 * @return string
 */
function auvoerechnung_trans_format($key, array $args)
{
	global $langs;
	$args = array_map('strval', array_values($args));
	$fallback = $args ? $key.' ('.implode(', ', $args).')' : $key;
	if (!is_object($langs) || !method_exists($langs, 'load')) {
		return $fallback;
	}
	$langs->load('auvoerechnung@auvoerechnung');
	$tpl = $langs->tab_translate[$key] ?? '';
	if (!is_string($tpl) || $tpl === '') {
		return $fallback;
	}
	try {
		return vsprintf($tpl, $args);
	} catch (\ValueError $e) {
		return $fallback;
	} catch (\ArgumentCountError $e) {
		return $fallback;
	}
}

/**
 * Is the seller established in France for purposes of the reform baseline?
 *
 * This is the single guard for all French-law features: PMD/PMT/AAB legal
 * notices (BR-FR-05), BAR note (BR-FR-07/20/31), invoice process BT-23
 * (BR-FR-08), and SIREN/SIRET validation. These do not apply to foreign sellers:
 * Factur-X uses the same specification as ZUGFeRD and is also used outside
 * France, so emitting these for a German seller would incorrectly apply French
 * law. Overseas departments are treated as France (BR-FR-MAP-14).
 *
 * @param Societe|object $mysoc  Issuing company
 * @return bool
 */
function auvoerechnung_seller_is_fr($mysoc)
{
	// Unknown country (missing object or incomplete company card): fall back to
	// FR, matching the module's historical behavior.
	$country = (is_object($mysoc) && !empty($mysoc->country_code)) ? strtoupper($mysoc->country_code) : 'FR';
	return ($country === 'FR' || in_array($country, AUVOERECHNUNG_FR_OVERSEAS_COUNTRIES, true));
}

/**
 * Rights of the module (since 5.2.0), always together with the invoice
 * rights of Dolibarr, which stay required:
 *  - read:  check the embedded XML, download the XRechnung (invoices: read)
 *  - write: regenerate ZUGFeRD PDF, XRechnung, Chorus Pro (invoices: create)
 *  - send:  send the XRechnung to the OZG-RE (invoices: create, plus "send
 *           by email" with advanced permissions, like the core mail button)
 * The automatic ZUGFeRD XML when Dolibarr builds the PDF needs no right.
 *
 * @param User   $user
 * @param string $what  read|write|send
 * @return bool
 */
function auvoerechnung_user_can($user, $what)
{
	if (!is_object($user) || !method_exists($user, 'hasRight')) {
		return false;
	}
	if (!$user->hasRight('auvoerechnung', 'einvoice', $what)) {
		return false;
	}
	if ($what === 'read') {
		return (bool) $user->hasRight('facture', 'lire');
	}
	if (!$user->hasRight('facture', 'creer')) {
		return false;
	}
	if ($what === 'send' && getDolGlobalString('MAIN_USE_ADVANCED_PERMS')) {
		return (bool) $user->hasRight('facture', 'invoice_advance', 'send');
	}
	return true;
}

/**
 * Country assumed for a party without country: the seller's own country
 * (a customer without country is most likely a domestic one), FR only when
 * the company itself has no country. check_mandatory() warns in both cases.
 *
 * @return string ISO 3166-1 alpha-2
 */
function auvoerechnung_default_country()
{
	global $mysoc;
	return (is_object($mysoc) && !empty($mysoc->country_code)) ? strtoupper($mysoc->country_code) : 'FR';
}

/**
 * Checks whether the invoice is within the generator's supported scope.
 * Returns a blocking error message, or null if the invoice can be processed.
 *
 * Multi-currency: Dolibarr stores the VAT breakdown only in the company currency.
 * Emitting XML in the company currency while the visible PDF uses a foreign
 * currency would violate the Factur-X hybrid principle (readable = structured).
 * Full BT-5/BT-6 support requires multicurrency_total_* amounts per line and a
 * second TaxTotalAmount; this is not implemented yet (see documentation).
 *
 * @param Facture $invoice
 * @return string|null
 */
function auvoerechnung_check_supported($invoice)
{
	global $conf;

	$companyCurrency = !empty($conf->currency) ? $conf->currency : 'EUR';
	if (!empty($invoice->multicurrency_code) && $invoice->multicurrency_code !== $companyCurrency) {
		return auvoerechnung_trans('AuvoERechnungErrMulticurrency', $invoice->multicurrency_code, $companyCurrency);
	}

	// Local taxes (localtax1/2, RE/IRPF, etc.) cannot be represented in standard
	// EN16931 XML (VAT only). XML totals would differ from the PDF total, so refuse
	// the invoice as with multi-currency.
	if (abs((float) ($invoice->total_localtax1 ?? 0)) > 0.005 || abs((float) ($invoice->total_localtax2 ?? 0)) > 0.005) {
		return auvoerechnung_trans('AuvoERechnungErrLocalTax');
	}

	if (empty($invoice->date)) {
		return auvoerechnung_trans('AuvoERechnungErrNoDate');
	}

	return null;
}

/**
 * Generates EN16931 Factur-X XML from a Dolibarr invoice.
 *
 * Credit note convention (TypeCode 381): Dolibarr stores negative totals, but
 * EN16931 requires positive amounts (BR-27: line net price >= 0). Multiply all
 * amounts by -1 for credit notes. DuePayableAmount follows BR-CO-16
 * (BT-115 = BT-112 - BT-113, without clamping to zero).
 *
 * @param Facture $invoice        Dolibarr invoice (with lines loaded)
 * @param Societe $mysoc          Issuing company (seller)
 * Business decisions are made by auvoerechnung_build_model() (syntax-neutral
 * EN16931 semantic model), which is serialized here as CII.
 *
 * @param array   $buildWarnings  (output) Non-blocking warnings detected during generation
 * @param array   $options        See auvoerechnung_build_model()
 * @return string                 CrossIndustryInvoice XML
 */
function auvoerechnung_build_xml($invoice, $mysoc, &$buildWarnings = [], $options = [])
{
	return auvoerechnung_model_to_cii(auvoerechnung_build_model($invoice, $mysoc, $buildWarnings, $options));
}

/**
 * Returns only billable lines (qty != 0 OR total_ht != 0).
 * Filters descriptions, headings, and subtotals with no quantity or amount,
 * which must not appear in EN16931 XML.
 *
 * @param array $lines Dolibarr invoice lines
 * @return array
 */
function auvoerechnung_filter_billable_lines($lines)
{
	$out = [];
	foreach ((array) $lines as $line) {
		// product_type=9 is Dolibarr's convention for presentation lines
		// (headings, subtotals) added by the native "Subtotals" module
		// (special_code 81) or third-party modules such as ATM Consulting's
		// "Subtotal" (special_code 104777). These do not represent billable goods
		// or services. Some have non-zero amounts (displayed subtotal), which
		// would be counted twice in EN16931 XML if not excluded here.
		$productType = isset($line->product_type) ? (int) $line->product_type : null;
		if ($productType === 9) {
			continue;
		}

		if ((float) $line->qty == 0 && (float) $line->total_ht == 0) {
			continue;
		}
		$out[] = $line;
	}
	return $out;
}

/**
 * Prepares invoice lines for XML: normalizes signs for credit notes, rounds
 * totals, and converts negative-amount lines (Dolibarr fixed discounts) into
 * BG-21 document allowances to satisfy BR-27.
 *
 * If NO positive lines remain (an entirely negative type 380 invoice, outside
 * the normal convention), retain the lines as-is. The XML will violate BR-27,
 * which the internal business-rule check will report.
 *
 * Deposits applied as lines (see auvoerechnung_get_deposit_lines) are collected
 * separately in 'deposits': they go in BT-113, not in BG-20 allowances.
 *
 * @return array ['lines' => array, 'allowances' => array, 'deposits' => array]
 */
function auvoerechnung_prepare_lines($billableLines, $sign, $invoice, $thirdparty, $mysoc, &$buildWarnings)
{
	// Single constructor for a prepared line, used by both the normal path and
	// the "all negative" fallback so they emit the same mapping.
	$warnedUnits = [];
	$buildLine = function ($line) use ($sign, $invoice, $thirdparty, $mysoc, &$buildWarnings, &$warnedUnits) {
		$desc = auvoerechnung_html_to_text($line->desc ?: ($line->description ?? $line->label ?? ''));
		$item = auvoerechnung_line_item($line, $desc);
		if ($desc === '') {
			// BT-153 is mandatory: free-text line without a description.
			$desc = auvoerechnung_trans_raw('AuvoERechnungLineNoName');
			if ($desc === 'AuvoERechnungLineNoName') {
				$desc = 'Position';
			}
			if ($item['name'] === '') {
				$item['name'] = $desc;
			}
		}
		$lineTotal = round($sign * (float) $line->total_ht, 2);
		$qty = abs((float) $line->qty);
		$unknownUnit = null;
		$unitCode = auvoerechnung_map_unit_code($line, $unknownUnit);
		if ($unknownUnit !== null && !isset($warnedUnits[$unknownUnit])) {
			$warnedUnits[$unknownUnit] = true;
			$buildWarnings[] = auvoerechnung_trans('AuvoERechnungWarnUnitUnmapped', $unknownUnit);
		}
		return [
			'desc'      => $desc,
			'name'      => $item['name'],
			'itemDesc'  => $item['description'],
			'sellerId'  => $item['seller_id'],
			'gtin'      => $item['gtin'],
			'qty'       => ($qty != 0.0) ? $qty : 1.0,
			'unitPrice' => auvoerechnung_line_unit_price($line, $sign, $qty, $lineTotal),
			'lineTotal' => $lineTotal,
			'lineTax'   => $sign * (float) $line->total_tva,
			'vatRate'   => (float) $line->tva_tx,
			'taxCat'    => auvoerechnung_resolve_tax_category($line, $invoice, $thirdparty, $mysoc),
			'unitCode'  => $unitCode,
			// BG-26: line period (Dolibarr services: date_start / date_end)
			'dateStart' => !empty($line->date_start) ? (int) $line->date_start : null,
			'dateEnd'   => !empty($line->date_end) ? (int) $line->date_end : null,
		];
	};

	$lines = [];
	$allowances = [];
	$deposits = [];
	$depositLines = auvoerechnung_get_deposit_lines($invoice);
	foreach ($billableLines as $line) {
		$pl = $buildLine($line);
		if ($depositLines && auvoerechnung_is_deposit_line($line, $depositLines)) {
			// Applied deposit: neither a line nor an allowance; reported in BT-113.
			$deposits[] = [
				'amount' => abs($pl['lineTotal']),
				'tax'    => abs($pl['lineTax']),
			];
			continue;
		}
		if ($pl['lineTotal'] < 0) {
			$allowances[] = [
				'amount'       => abs($pl['lineTotal']),
				'tax'          => $pl['lineTax'],
				'reason'       => $pl['desc'],
				'rate'         => $pl['vatRate'],
				'categoryCode' => $pl['taxCat']['code'],
				'taxCat'       => $pl['taxCat'],
			];
			continue;
		}
		$lines[] = $pl;
	}

	if (empty($lines) && (!empty($allowances) || !empty($deposits))) {
		// Entirely negative invoice other than a credit note: cannot convert to
		// BG-21 because at least one line is required. Emit the original lines.
		$buildWarnings[] = auvoerechnung_trans('AuvoERechnungWarnAllNegativeLines');
		$lines = array_map($buildLine, $billableLines);
		$allowances = [];
		$deposits = [];
	}

	return ['lines' => $lines, 'allowances' => $allowances, 'deposits' => $deposits];
}

/**
 * Resolves the bank account (IBAN/BIC) to include in the XML.
 *
 * Priority, so the XML matches the PDF (issue #16):
 *   1. the account selected ON THE INVOICE (fk_account), exactly the one
 *      Dolibarr prints when an account is selected for the third party or invoice;
 *   2. otherwise, the account configured in the module
 *      (AUVOERECHNUNG_BANK_ACCOUNT), the historical fallback.
 *
 * @param object      $db      Handle DB Dolibarr
 * @param object|null $invoice Current invoice (to read fk_account); null = use module setting only
 * @return array ['iban' => string, 'bic' => string] (empty strings if no account is resolved)
 */
function auvoerechnung_get_bank_account($db, $invoice = null)
{
	$bankAccountId = ($invoice !== null && !empty($invoice->fk_account))
		? (int) $invoice->fk_account
		: getDolGlobalInt('AUVOERECHNUNG_BANK_ACCOUNT');
	if ($bankAccountId <= 0) {
		return ['iban' => '', 'bic' => ''];
	}
	require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
	$bankAccount = new Account($db);
	if ($bankAccount->fetch($bankAccountId) <= 0) {
		return ['iban' => '', 'bic' => ''];
	}
	return [
		'iban' => str_replace(' ', '', (string) $bankAccount->iban),
		'bic'  => str_replace(' ', '', (string) $bankAccount->bic),
	];
}

/**
 * SEPA direct debit information (payment means 59):
 * - creditor ICS (BT-90) from the Dolibarr PRELEVEMENT_ICS constant;
 * - mandate RUM (BT-89) and debtor IBAN (BT-91) from the third party's default bank account.
 *
 * @return array ['ics' => string, 'rum' => string, 'debtor_iban' => string]
 */
function auvoerechnung_get_direct_debit_info($db, $buyer, &$buildWarnings)
{
	$info = [
		'ics'         => trim(getDolGlobalString('PRELEVEMENT_ICS', '')),
		'rum'         => '',
		'debtor_iban' => '',
	];

	if (!empty($buyer->id)) {
		// type = 'ban': llx_societe_rib also stores card payment methods (Stripe,
		// etc.) with default_rib = 1; do not use those entries.
		$sql = "SELECT rum, iban_prefix FROM ".MAIN_DB_PREFIX."societe_rib"
			." WHERE fk_soc = ".((int) $buyer->id)." AND default_rib = 1"
			." AND (type = 'ban' OR type IS NULL OR type = '')"
			." ORDER BY rowid ASC LIMIT 1";
		$res = $db->query($sql);
		if ($res) {
			$obj = $db->fetch_object($res);
			if ($obj) {
				$info['rum'] = trim((string) $obj->rum);
				$info['debtor_iban'] = str_replace(' ', '', (string) $obj->iban_prefix);
			}
		}
	}

	if ($info['ics'] === '') {
		$buildWarnings[] = auvoerechnung_trans('AuvoERechnungWarnDirectDebitNoICS');
	}
	if ($info['rum'] === '') {
		$buildWarnings[] = auvoerechnung_trans('AuvoERechnungWarnDirectDebitNoRUM');
	}

	return $info;
}

/**
 * Configured cash-discount (Skonto) tiers for a payment term.
 *
 * Configuration: AUVOERECHNUNG_SKONTO_TERMS constant, JSON indexed by the
 * Dolibarr payment-term ID (rowid) in dictionary c_payment_term, e.g.
 * {"3":[{"days":7,"percent":2}]}. The ID remains stable if the dictionary
 * code or label changes. Invalid entries are ignored.
 *
 * Through 5.2.x, AUVOERECHNUNG_SKONTO was indexed by code. Until
 * auvoerechnung_migrate_skonto_keys() has run, read the old format through
 * $paymentTermCode.
 *
 * Public function, available to other modules (dol_include_once for
 * /auvoerechnung/core/lib/auvoerechnung.lib.php).
 *
 * @param int    $paymentTermId    Payment-term ID (Facture::cond_reglement_id)
 * @param string $paymentTermCode  Payment-term code (legacy format only)
 * @return array<int,array{days:int,percent:float}>  Discount tiers sorted by due day
 */
function auvoerechnung_get_skonto_terms($paymentTermId, $paymentTermCode = '')
{
	if (getDolGlobalString('AUVOERECHNUNG_SKONTO_TERMS') === '' && getDolGlobalString('AUVOERECHNUNG_SKONTO') !== '') {
		$all = json_decode(getDolGlobalString('AUVOERECHNUNG_SKONTO'), true);
		$key = trim((string) $paymentTermCode);
	} else {
		$all = json_decode(getDolGlobalString('AUVOERECHNUNG_SKONTO_TERMS', ''), true);
		$key = (int) $paymentTermId > 0 ? (string) (int) $paymentTermId : '';
	}
	if ($key === '' || !is_array($all) || empty($all[$key]) || !is_array($all[$key])) {
		return [];
	}
	$terms = [];
	foreach ($all[$key] as $t) {
		$days = isset($t['days']) ? (int) $t['days'] : 0;
		$percent = isset($t['percent']) ? round((float) $t['percent'], 2) : 0.0;
		if ($days > 0 && $days <= 365 && $percent > 0 && $percent < 100) {
			$terms[$days] = ['days' => $days, 'percent' => $percent];
		}
	}
	ksort($terms);
	return array_values($terms);
}

/**
 * Migration 5.2.x → 5.3.0: AUVOERECHNUNG_SKONTO (indexed by code) becomes
 * AUVOERECHNUNG_SKONTO_TERMS (indexed by ID). Codes not found in the dictionary
 * are discarded (and logged). The old constant is then deleted. Idempotent:
 * does nothing when the old constant is absent.
 *
 * @param DoliDB $db
 * @return int  Number of payment terms migrated, or -1 on error
 */
function auvoerechnung_migrate_skonto_keys($db)
{
	global $conf;
	$old = getDolGlobalString('AUVOERECHNUNG_SKONTO');
	if ($old === '') {
		return 0;
	}
	if (!function_exists('dolibarr_del_const')) {
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
	}
	$byCode = json_decode($old, true);
	$byId = json_decode(getDolGlobalString('AUVOERECHNUNG_SKONTO_TERMS', ''), true);
	if (!is_array($byId)) {
		$byId = [];
	}
	$n = 0;
	foreach ((is_array($byCode) ? $byCode : []) as $code => $terms) {
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_payment_term WHERE code = '".$db->escape((string) $code)."' AND entity IN (".getEntity('c_payment_term').")";
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		if (!$obj) {
			dol_syslog('auvoerechnung: Skonto für unbekannte Zahlungsbedingung '.$code.' verworfen', LOG_WARNING);
			continue;
		}
		if (!isset($byId[(string) (int) $obj->rowid])) {
			$byId[(string) (int) $obj->rowid] = $terms;
			$n++;
		}
	}
	$entity = (int) ($conf->entity ?? 1);
	if (dolibarr_set_const($db, 'AUVOERECHNUNG_SKONTO_TERMS', empty($byId) ? '' : json_encode($byId), 'chaine', 0, '', $entity) < 0) {
		return -1;
	}
	dolibarr_del_const($db, 'AUVOERECHNUNG_SKONTO', $entity);
	return $n;
}

/**
 * Remove payment terms that no longer exist in the dictionary from the Skonto
 * configuration. Dolibarr does not define a foreign key on c_payment_term, so
 * a term can be deleted and its freed ID reassigned (the auto-increment value
 * may be recalculated at restart on older MySQL/MariaDB versions). Without this
 * cleanup, the new term would inherit the old tiers.
 *
 * @param array<string,mixed> $all          Configuration indexed by ID
 * @param int[]               $existingIds  IDs present in the dictionary
 * @return array<string,mixed>
 */
function auvoerechnung_skonto_drop_orphans($all, $existingIds)
{
	$keep = array_flip(array_map('intval', $existingIds));
	$out = [];
	foreach ($all as $id => $terms) {
		if (isset($keep[(int) $id])) {
			$out[(string) $id] = $terms;
		}
	}
	return $out;
}

/**
 * Notify other modules when Skonto tiers change through the
 * "auvoerechnungSkontoChanged" hook ("auvoerechnungsetup" context).
 * Hook parameters: 'terms' (complete configuration, indexed by payment term
 * ID) and 'previous' (configuration before the change).
 *
 * @param array<string,array<int,array{days:int,percent:float}>> $terms
 * @param array<string,array<int,array{days:int,percent:float}>> $previous
 * @return void
 */
function auvoerechnung_notify_skonto_changed($terms, $previous)
{
	global $hookmanager, $db;
	if (!is_object($hookmanager)) {
		include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
		$hookmanager = new HookManager($db);
	}
	$hookmanager->initHooks(['auvoerechnungsetup']);
	$parameters = ['terms' => $terms, 'previous' => $previous];
	$object = null;
	$tmpaction = '';
	$hookmanager->executeHooks('auvoerechnungSkontoChanged', $parameters, $object, $tmpaction);
}

/**
 * Skonto lines in the format required by KoSIT (BR-DE-18) and also recommended
 * by ZUGFeRD: "#SKONTO#TAGE=7#PROZENT=2.00#". Every line, including the last,
 * must end with a newline (otherwise BR-DE-18 rejects it).
 *
 * @param array<int,array{days:int,percent:float}> $terms
 * @return string
 */
function auvoerechnung_skonto_lines($terms)
{
	$out = '';
	foreach ($terms as $t) {
		$out .= '#SKONTO#TAGE='.((int) $t['days']).'#PROZENT='.number_format((float) $t['percent'], 2, '.', '').'#'."\n";
	}
	return $out;
}

/**
 * Invoice-facing label of the payment term (Dolibarr translation
 * PaymentCondition<code> if available, otherwise the dictionary text).
 * Flatten line breaks and remove leading "#" characters: in BT-20, a line
 * starting with "#" would be read as a Skonto line.
 *
 * @param object $invoice
 * @return string
 */
function auvoerechnung_payment_terms_text($invoice)
{
	global $langs;
	$code = (string) ($invoice->cond_reglement_code ?? '');
	$text = '';
	if ($code !== '' && is_object($langs) && method_exists($langs, 'transnoentitiesnoconv')) {
		$tr = $langs->transnoentitiesnoconv('PaymentCondition'.$code);
		if ($tr !== '' && $tr !== 'PaymentCondition'.$code) {
			$text = $tr;
		}
	}
	if ($text === '') {
		$text = (string) ($invoice->cond_reglement_doc ?? '');
		if (preg_match('/^PaymentCondition\w*$/', $text)) {
			$text = '';
		}
	}
	return ltrim(trim(preg_replace('/\s+/u', ' ', $text)), '#');
}

/**
 * BT-20 (Payment terms) content: payment term text followed by Skonto lines.
 * Empty (element omitted) when no discount is configured, and for a credit
 * note, to which a discount does not apply.
 *
 * @param object $invoice
 * @param bool   $isCreditNote
 * @return string
 */
function auvoerechnung_build_payment_terms_description($invoice, $isCreditNote = false)
{
	if ($isCreditNote) {
		return '';
	}
	$terms = auvoerechnung_get_skonto_terms($invoice->cond_reglement_id ?? 0, $invoice->cond_reglement_code ?? '');
	if (empty($terms)) {
		return '';
	}
	$text = auvoerechnung_payment_terms_text($invoice);
	return ($text !== '' ? $text."\n" : '').auvoerechnung_skonto_lines($terms);
}

/**
 * Suggest a Skonto tier from a payment term label ("7 Tage 2% Skonto",
 * "2 % Skonto bei Zahlung innerhalb von 10 Tagen", etc.). This is only a
 * suggestion shown in settings and is never applied automatically.
 *
 * @param string $label
 * @return array{days:int,percent:float}|null
 */
function auvoerechnung_guess_skonto($label)
{
	$label = (string) $label;
	if (stripos($label, 'skonto') === false) {
		return null;
	}
	$num = '(\d{1,2}(?:[.,]\d{1,2})?)';
	if (preg_match('/(\d{1,3})\s*(?:Tage|Tagen|Tg\.?|T\.)\D{0,40}?'.$num.'\s*%/iu', $label, $m)) {
		return ['days' => (int) $m[1], 'percent' => (float) str_replace(',', '.', $m[2])];
	}
	if (preg_match('/'.$num.'\s*%\D{0,40}?(\d{1,3})\s*(?:Tage|Tagen|Tg\.?|T\.)/iu', $label, $m)) {
		return ['days' => (int) $m[2], 'percent' => (float) str_replace(',', '.', $m[1])];
	}
	return null;
}

/**
 * Return a Dolibarr constant's value, or the supplied default if the constant
 * is missing OR empty. getDolGlobalString() uses the default only when the key
 * does not exist. Since module configuration constants are created empty on
 * activation, this guard is needed for the default to take effect.
 */
function auvoerechnung_conf_or_default($key, $default)
{
	$val = trim(getDolGlobalString($key, ''));
	return $val !== '' ? $val : $default;
}

/**
 * Applicable PMD/PMT/AAB legal notices in emission order, after Dolibarr
 * constant overrides.
 *
 * The three default texts are required by French law (Arts. L.441-9 and
 * L.441-10 of the Commercial Code, rule BR-FR-05). No defaults are applied for
 * a seller established outside France: Factur-X is the same specification as
 * ZUGFeRD, and automatically printing French notices—or guessed equivalents—
 * on a foreign seller's invoice would be wrong. AUVOERECHNUNG_NOTE_* constants
 * remain available, so a German seller can enter its own notices (§ 288 BGB,
 * cash discount, etc.) and have them emitted normally.
 *
 * @param bool $sellerIsFR  See auvoerechnung_seller_is_fr()
 * @return array<string,string>  ['PMD' => text, 'PMT' => text, 'AAB' => text]
 */
function auvoerechnung_resolve_legal_notes($sellerIsFR = true)
{
	$defaults = $sellerIsFR
		? [
			'PMD' => AUVOERECHNUNG_DEFAULT_NOTE_PMD,
			'PMT' => AUVOERECHNUNG_DEFAULT_NOTE_PMT,
			'AAB' => AUVOERECHNUNG_DEFAULT_NOTE_AAB,
		]
		: ['PMD' => '', 'PMT' => '', 'AAB' => ''];

	$notes = [];
	foreach ($defaults as $code => $default) {
		$notes[$code] = auvoerechnung_conf_or_default('AUVOERECHNUNG_NOTE_'.$code, $default);
	}
	return $notes;
}


/**
 * Expected processing qualification code (BAR note; rules BR-FR-07, BR-FR-20,
 * and BR-FR-31 allow only one value from this closed list):
 *  - 'B2C': buyer is not taxable (private individual or "Subject to VAT" is No),
 *           so B2C sales are handled through e-reporting;
 *  - 'B2BINT': taxable buyer established outside France; international B2B sales
 *              are handled through e-reporting;
 *  - 'B2B': taxable buyer established in France; e-invoicing (standard case).
 * Overseas department/territory country codes (mapped to FR in submissions,
 * see BR-FR-MAP-14) are treated as France. OUTOFSCOPE and ARCHIVEONLY cover
 * cases this module does not produce (outside the reform, internal cancellation
 * credit note).
 *
 * Intentional choice: a non-taxable buyer established OUTSIDE France is also
 * emitted as 'B2C'. The closed list in BR-FR-20 (XP Z12-012, July 2025 revision)
 * has no "international B2C" value; emitting an unlisted code would violate
 * BR-FR-20. Detailed domestic/international classification belongs in the
 * e-reporting flow (10.x), not the BAR note. Revisit if a future standard
 * revision adds such a value.
 *
 * @param Societe|object $buyer  Buyer
 * @return string 'B2B', 'B2BINT', or 'B2C'
 */
function auvoerechnung_resolve_bar_code($buyer)
{
	if (auvoerechnung_is_non_assujetti($buyer)) {
		return 'B2C';
	}
	$country = strtoupper(!empty($buyer->country_code) ? $buyer->country_code : auvoerechnung_default_country());
	// Country codes mapped to FR in submissions (BR-FR-MAP-14) are treated as FR.
	if ($country !== 'FR' && !in_array($country, AUVOERECHNUNG_FR_OVERSEAS_COUNTRIES, true)) {
		return 'B2BINT';
	}
	return 'B2B';
}

/**
 * Billing framework for the reform baseline (BT-23, rule BR-FR-08) in the
 * PDP/B2B profile. The closed XP Z12-012 list uses the letter for invoice type
 * (B = goods, S = services, M = both), derived from line product_type; the
 * digit identifies the submission process.
 *  - Standard case (invoice submitted by the supplier): B1 / S1 / M1.
 *  - Final invoice after a deposit (deposits applied): B4 / S4 / M4; never on
 *    the deposit invoice itself (prohibited by BR-FR-CO-08).
 * Specific processes (already paid x2, subcontracting S3/S5/S6, self-billing,
 * multiple sellers x8, bidirectional x9) do not match any flow produced by
 * this module and are not emitted.
 *
 * @param array  $billableLines  Billable lines (see auvoerechnung_filter_billable_lines)
 * @param string $typeCode       Emitted BT-3 TypeCode ('380', '386', '381',...)
 * @param float  $prepaidAmount  Amount of applied deposits (BT-113)
 * @return string 'B1'|'S1'|'M1'|'B4'|'S4'|'M4'
 */
function auvoerechnung_resolve_business_process($billableLines, $typeCode, $prepaidAmount = 0.0)
{
	$hasGoods = false;
	$hasServices = false;
	foreach ((array) $billableLines as $line) {
		if ((int) ($line->product_type ?? 0) === 1) {
			$hasServices = true;
		} else {
			$hasGoods = true;
		}
	}
	$letter = ($hasGoods && $hasServices) ? 'M' : ($hasServices ? 'S' : 'B');
	// BR-FR-CO-08: an x4 framework (final invoice after deposit) is incompatible
	// with deposit invoice types (386/500/503).
	$isDepositInvoice = in_array($typeCode, ['386', '500', '503'], true);
	$digit = (!$isDepositInvoice && (float) $prepaidAmount > 0) ? '4' : '1';
	return $letter.$digit;
}



/**
 * Calculate the VAT breakdown by (category, rate). Two distinct categories at
 * the same rate (e.g. K and AE at 0%) produce two ApplicableTradeTax blocks.
 *
 * Uses the lines/discounts prepared by auvoerechnung_prepare_lines() (same
 * categories and rounding) to ensure BR-S-08 consistency: base per
 * (category, rate) = sum of lines minus discounts in that category.
 *
 * Reconcile taxes in priority order:
 *  1. sum(tax) == invoice total_tva (apply rounding difference to the largest group)
 *  2. BR-CO-17: each group must remain within +/-0.01 of base × rate; otherwise
 *     recalculate the group's tax from its base and issue a warning (build_xml
 *     reports any remaining difference from Dolibarr's gross total).
 *
 * @param array  $prepared       ['lines' => [...], 'allowances' => [...]] (cf. prepare_lines)
 * @param object $invoice        Dolibarr invoice
 * @param float  $sign           1.0 or -1.0 (credit note)
 * @param array  $buildWarnings  (output) warnings
 * @return array<string,array>   Indexed by "category|rate", with base/tax/rate/categoryCode/exemption/vatex
 */
function auvoerechnung_get_tax_breakdown($prepared, $invoice, $sign = 1.0, &$buildWarnings = [])
{
	$breakdown = [];
	$accumulate = function ($taxCat, $rate, $base, $tax) use (&$breakdown) {
		$key = $taxCat['code'].'|'.(float) $rate;
		if (!isset($breakdown[$key])) {
			$breakdown[$key] = [
				'base'         => 0.0,
				'tax'          => 0.0,
				'rate'         => (float) $rate,
				'categoryCode' => $taxCat['code'],
				'exemption'    => $taxCat['exemption'],
				'vatex'        => $taxCat['vatex'],
			];
		} elseif (!empty($taxCat['exemption']) && strpos((string) $breakdown[$key]['exemption'], $taxCat['exemption']) === false) {
			// One breakdown per category and rate (BR-G-08 & co. sum all lines
			// of a category): lines with different legal grounds, e.g. export
			// goods and a § 3a service to a third country, name both grounds.
			$breakdown[$key]['exemption'] = trim($breakdown[$key]['exemption'].'; '.$taxCat['exemption'], '; ');
		}
		$breakdown[$key]['base'] += $base;
		$breakdown[$key]['tax'] += $tax;
	};

	foreach ($prepared['lines'] as $pl) {
		$accumulate($pl['taxCat'], $pl['vatRate'], $pl['lineTotal'], $pl['lineTax']);
	}
	foreach ($prepared['allowances'] as $al) {
		$accumulate($al['taxCat'], $al['rate'], -$al['amount'], $al['tax']);
	}

	// Round tax per group, then reconcile with the invoice total.
	$sumTax = 0.0;
	foreach ($breakdown as $key => $b) {
		$breakdown[$key]['base'] = round($b['base'], 2);
		$breakdown[$key]['tax'] = round($b['tax'], 2);
		$sumTax += $breakdown[$key]['tax'];
	}
	$invoiceTax = round($sign * (float) $invoice->total_tva, 2);
	// Deposits applied as lines: Dolibarr deducted their VAT from the invoice
	// total, so the breakdown (without these lines) carries VAT on the full amount.
	foreach ($prepared['deposits'] ?? [] as $dep) {
		$invoiceTax = round($invoiceTax + $dep['tax'], 2);
	}
	$delta = round($invoiceTax - $sumTax, 2);
	if (abs($delta) >= 0.01) {
		// Apply the rounding difference to the taxed group with the largest base.
		$targetKey = null;
		$targetBase = -1.0;
		foreach ($breakdown as $key => $b) {
			if ($b['rate'] > 0 && abs($b['base']) > $targetBase) {
				$targetBase = abs($b['base']);
				$targetKey = $key;
			}
		}
		if ($targetKey !== null) {
			$breakdown[$targetKey]['tax'] = round($breakdown[$targetKey]['tax'] + $delta, 2);
			if (abs($delta) > 0.01) {
				$buildWarnings[] = auvoerechnung_trans('AuvoERechnungWarnRoundingAdjusted', auvoerechnung_format_amount($delta));
			}
		} else {
			$buildWarnings[] = auvoerechnung_trans('AuvoERechnungWarnTaxMismatch', auvoerechnung_format_amount($delta));
		}
	}

	// BR-CO-17 safeguard: a group's tax must not differ from base × rate by more
	// than one cent (otherwise Schematron rejects it). If the adjustment above
	// (or accumulated line-level rounding) exceeds the tolerance, recalculate
	// the group's tax from its base. XML EN16931 validity takes priority; any
	// difference from Dolibarr totals is reported later.
	foreach ($breakdown as $key => $b) {
		if ($b['rate'] <= 0) {
			continue;
		}
		$expected = round($b['base'] * $b['rate'] / 100, 2);
		if (abs($b['tax'] - $expected) > 0.01) {
			$buildWarnings[] = auvoerechnung_trans('AuvoERechnungWarnTaxRecomputed', $b['categoryCode'].' '.$b['rate'].'%', auvoerechnung_format_amount($expected), auvoerechnung_format_amount($b['tax']));
			$breakdown[$key]['tax'] = $expected;
		}
	}

	return $breakdown;
}

/**
 * Whether the customer is outside the scope of B2B e-invoicing (2026-2027 reform):
 * a private individual (typent TE_PRIVATE) OR not subject to VAT (the
 * "Subject to VAT" field on the customer record). These customers fall under
 * e-reporting, not electronic invoicing between taxable persons.
 *
 * typent alone is insufficient (a non-taxable association is not a "Private
 * individual"), and tva_assuj alone is insufficient (often left at its default),
 * so both are checked.
 *
 * @param Societe|null $thirdparty  Dolibarr customer (already fetched)
 * @return bool
 */
function auvoerechnung_is_non_assujetti($thirdparty)
{
	if (empty($thirdparty)) {
		return false;
	}
	if (($thirdparty->typent_code ?? '') === 'TE_PRIVATE' || (int) ($thirdparty->typent_id ?? 0) === 8) {
		return true;
	}
	return isset($thirdparty->tva_assuj) && (int) $thirdparty->tva_assuj === 0;
}

/**
 * Check the required information for Factur-X EN16931 + BR-FR.
 * Returns an array of warnings (empty when everything is valid).
 *
 * @param Facture $invoice   Dolibarr invoice object
 * @param Societe $mysoc     Seller company
 * @return array             List of warning messages
 */
function auvoerechnung_check_mandatory($invoice, $mysoc)
{
	$warnings = [];

	// SIREN/SIRET are French identifiers; use the same country rules as the generator.
	$sellerIsFR = auvoerechnung_seller_is_fr($mysoc);

	$sellerChecks = [
		'name'      => 'AuvoERechnungWarnSellerName',
		'address'   => 'AuvoERechnungWarnSellerAddress',
		'zip'       => 'AuvoERechnungWarnSellerZip',
		'town'      => 'AuvoERechnungWarnSellerTown',
		'tva_intra' => 'AuvoERechnungWarnSellerVAT',
		'idprof2'   => 'AuvoERechnungWarnSellerSIRET',
	];
	$isFranchise = isset($mysoc->tva_assuj) && (int) $mysoc->tva_assuj === 0;
	foreach ($sellerChecks as $field => $transKey) {
		// Small-business VAT exemption (Art. 293 B CGI): no intra-community VAT ID; no warning.
		if ($field === 'tva_intra' && $isFranchise) {
			continue;
		}
		// SIRET/SIREN are French identifiers and are not relevant outside France.
		if ($field === 'idprof2' && !$sellerIsFR) {
			continue;
		}
		if (empty($mysoc->$field)) {
			$warnings[] = auvoerechnung_trans($transKey);
		}
	}

	// A VAT ID field populated without a country prefix (BR-CO-09) is not
	// published in BT-31. Typical case: a Steuernummer entered in the wrong field.
	if (trim((string) ($mysoc->tva_intra ?? '')) !== '' && !auvoerechnung_is_vat_id($mysoc->tva_intra)) {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnSellerVATInvalid', $mysoc->tva_intra);
	}

	// BT-34 (seller electronic address) is satisfied by the SIREN (endpoint 0225)
	// OR an email address. Warn only when neither is available.
	// For non-French sellers, BT-34 is optional under EN16931 (BR-FR-13 does not
	// apply), so no warning is needed.
	$sellerSiren = $sellerIsFR ? auvoerechnung_party_siren($mysoc) : '';
	if ($sellerIsFR && $sellerSiren === '' && empty($mysoc->email)) {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnSellerEndpoint');
	}

	// BT-29 (ram:ID): if a SIRET is provided (idprof2), it must contain 14 digits.
	// It identifies the establishment; the SIREN (BT-30) comes from idprof1.
	$sellerSiret = $sellerIsFR ? preg_replace('/[^0-9]/', '', $mysoc->idprof2 ?? '') : '';
	if ($sellerSiret !== '' && strlen($sellerSiret) !== 14) {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnSellerSIRETLen', strlen($sellerSiret));
	}

	$buyer = $invoice->thirdparty;
	$buyerChecks = [
		'name'    => 'AuvoERechnungWarnBuyerName',
		'address' => 'AuvoERechnungWarnBuyerAddress',
		'zip'     => 'AuvoERechnungWarnBuyerZip',
		'town'    => 'AuvoERechnungWarnBuyerTown',
	];
	foreach ($buyerChecks as $field => $transKey) {
		if (empty($buyer->$field)) {
			$warnings[] = auvoerechnung_trans($transKey);
		}
	}

	// BT-49 (buyer electronic address) is satisfied by the SIREN (endpoint 0225)
	// OR an email address. For a French buyer, a missing SIREN prevents PA/PDP
	// routing even when an email is present (email cannot be routed on the network).
	$buyerIsFR  = strtoupper(!empty($buyer->country_code) ? $buyer->country_code : auvoerechnung_default_country()) === 'FR';
	// Missing country: the address (BT-40/BT-55) is mandatory and the country
	// determines the VAT categories; assume the seller company's country.
	if (!is_object($mysoc) || empty($mysoc->country_code)) {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnSellerNoCountry');
	}
	if (is_object($buyer) && empty($buyer->country_code)) {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnBuyerNoCountry', auvoerechnung_default_country());
	}
	$buyerSiren = $buyerIsFR ? auvoerechnung_party_siren($buyer) : '';
	$buyerEmail = auvoerechnung_get_buyer_email($buyer, $invoice->db);
	// For non-French sellers, BT-49 is optional under EN16931 (BR-FR-12 does not
	// apply; only XRechnung requires it, and that profile is not emitted here).
	if ($sellerIsFR && $buyerSiren === '' && $buyerEmail === '') {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnBuyerEndpoint');
	} elseif ($sellerIsFR && $buyerSiren === '' && $buyerIsFR && !auvoerechnung_is_non_assujetti($buyer)) {
		// Not taxable (private individual OR "Subject to VAT" is No, e.g. an
		// association): no SIREN, outside e-invoicing scope (handled by e-reporting),
		// so no routing warning.
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnBuyerSIRENRouting');
	}

	// BT-46 buyer (ram:ID): if a SIRET is provided, it must contain 14 digits.
	$buyerSiret = $buyerIsFR ? preg_replace('/[^0-9]/', '', $buyer->idprof2 ?? '') : '';
	if ($buyerSiret !== '' && strlen($buyerSiret) !== 14) {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnBuyerSIRETLen', strlen($buyerSiret));
	}

	// BR-FR-01/02: invoice identifier (BT-1), maximum 35 characters; only
	// alphanumeric characters and "- + _ /" are allowed (no spaces).
	// This is a pre-check; the blocking violation is handled by the validator.
	$ref = (string) ($invoice->ref ?? '');
	if ($ref !== '') {
		$refLen = function_exists('mb_strlen') ? mb_strlen($ref) : strlen($ref);
		if ($refLen > 35) {
			$warnings[] = auvoerechnung_trans('AuvoERechnungWarnRefTooLong', $refLen);
		}
		if (!preg_match('/^[A-Za-z0-9+_\/-]+$/', $ref)) {
			$warnings[] = auvoerechnung_trans('AuvoERechnungWarnRefCharset', $ref);
		}
	}

	// BR-FR-09: when both a SIREN (idprof1) and SIRET (idprof2) are entered,
	// the first nine digits of the SIRET must match the SIREN.
	$sellerSirenSaisi = $sellerIsFR ? preg_replace('/[^0-9]/', '', $mysoc->idprof1 ?? '') : '';
	if (strlen($sellerSirenSaisi) === 9 && strlen($sellerSiret) === 14 && strncmp($sellerSiret, $sellerSirenSaisi, 9) !== 0) {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnSellerSirenSiretMismatch', $sellerSirenSaisi, $sellerSiret);
	}
	$buyerSirenSaisi = $buyerIsFR ? preg_replace('/[^0-9]/', '', $buyer->idprof1 ?? '') : '';
	if (strlen($buyerSirenSaisi) === 9 && strlen($buyerSiret) === 14 && strncmp($buyerSiret, $buyerSirenSaisi, 9) !== 0) {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnBuyerSirenSiretMismatch', $buyerSirenSaisi, $buyerSiret);
	}

	// BR-FR-16: VAT rate is outside the closed French list (mainland + overseas).
	// Warning only: the standards list may change, and a legitimate rate must
	// never be blocked. Apply only to French sellers; 19% or 7% are normal German rates.
	if ($sellerIsFR) {
		$frVatRates = [0.0, 0.9, 1.05, 1.75, 2.1, 5.5, 7.0, 8.5, 9.2, 9.6, 10.0, 13.0, 19.6, 20.0, 20.6];
		$flaggedRates = [];
		foreach ((array) ($invoice->lines ?? []) as $line) {
			$rate = round((float) ($line->tva_tx ?? 0), 2);
			$known = false;
			foreach ($frVatRates as $fr) {
				if (abs($rate - $fr) < 0.001) {
					$known = true;
					break;
				}
			}
			if (!$known) {
				$flaggedRates[(string) $rate] = true;
			}
		}
		foreach (array_keys($flaggedRates) as $rate) {
			$warnings[] = auvoerechnung_trans('AuvoERechnungWarnVatRateNotFR', $rate);
		}
	}

	// BR-FR-23/25: seller electronic-address suffix uses a closed character set
	// (sanitized by auvoerechnung_resolve_endpoint), and the full address is
	// limited to 125 characters.
	$endpointSuffix = trim(getDolGlobalString('AUVOERECHNUNG_ENDPOINT_SUFFIX_SELLER', ''));
	if ($endpointSuffix !== '') {
		$suffixClean = preg_replace('/[^A-Za-z0-9._-]/', '', $endpointSuffix);
		if ($suffixClean !== $endpointSuffix) {
			$warnings[] = auvoerechnung_trans('AuvoERechnungWarnEndpointSuffixCharset');
		}
		$endpointLen = strlen($sellerSiren.$suffixClean);
		if ($sellerSiren !== '' && $endpointLen > 125) {
			$warnings[] = auvoerechnung_trans('AuvoERechnungWarnEndpointTooLong', $endpointLen);
		}
	}

	// Bank account: valid if the invoice has one (fk_account) OR one is configured
	// in the module. Warn only if both are missing (issue #16: the invoice account
	// takes precedence over the configuration).
	if (empty($invoice->fk_account) && getDolGlobalInt('AUVOERECHNUNG_BANK_ACCOUNT') <= 0) {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnNoBank');
	}

	// PDF/A-3: without a forced embedded font, TCPDF uses non-embedded Base 14
	// fonts and fails veraPDF validation.
	$forceFont = function_exists('getDolGlobalString') ? getDolGlobalString('MAIN_PDF_FORCE_FONT', '') : '';
	if ($forceFont === '') {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnNoForceFont');
	} elseif (auvoerechnung_pdf_font_embedded($forceFont) === false) {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnForceFontNotEmbedded', $forceFont);
	}

	return $warnings;
}



/**
 * Map a Dolibarr line unit to the UN/ECE Rec 20 code expected by Factur-X.
 *
 * Resolve by the Dolibarr unit CODE (llx_c_units.code: H, D, W, MO...,
 * which is unique), then by the short label for user-added units. If there is
 * no match, use C62 (piece / one) and set $unknown to the label so the caller
 * can issue a warning instead of silently emitting "piece".
 *
 * @param object      $line    Dolibarr invoice line
 * @param string|null $unknown Receives the label of an unmatched unit
 * @return string UN/ECE code (e.g. HUR, DAY, MTR, KGM, C62...)
 */
function auvoerechnung_map_unit_code($line, &$unknown = null)
{
	static $unitCache = [];
	static $codeMap = [
		'T' => 'TNE', 'KG' => 'KGM', 'G' => 'GRM', 'MG' => 'MGM', 'OZ' => 'ONZ', 'LB' => 'LBR',
		'M' => 'MTR', 'DM' => 'DMT', 'CM' => 'CMT', 'MM' => 'MMT', 'FT' => 'FOT', 'IN' => 'INH',
		'M2' => 'MTK', 'DM2' => 'DMK', 'CM2' => 'CMK', 'MM2' => 'MMK', 'FT2' => 'FTK', 'IN2' => 'INK',
		'M3' => 'MTQ', 'DM3' => 'DMQ', 'CM3' => 'CMQ', 'MM3' => 'MMQ', 'FT3' => 'FTQ', 'IN3' => 'INQ',
		'OZ3' => 'OZA', 'L' => 'LTR', 'GAL' => 'GLL',
		'P' => 'C62', 'SET' => 'SET',
		'S' => 'SEC', 'MI' => 'MIN', 'H' => 'HUR', 'D' => 'DAY', 'W' => 'WEE', 'MO' => 'MON', 'Y' => 'ANN',
	];
	// User-added units: match by short label only when it is unambiguous.
	static $shortLabelMap = [
		'h' => 'HUR', 'mn' => 'MIN', 'min' => 'MIN', 'd' => 'DAY', 'w' => 'WEE', 'wk' => 'WEE',
		'week' => 'WEE', 'month' => 'MON', 'y' => 'ANN', 'cl' => 'CLT', 'ml' => 'MLT', 'km' => 'KMT',
		'kg' => 'KGM', 'g' => 'GRM', 't' => 'TNE', 'l' => 'LTR', 'm2' => 'MTK', 'm3' => 'MTQ',
		'p' => 'C62', 'pc' => 'C62', 'pcs' => 'C62', 'piece' => 'C62', 'u' => 'C62',
	];

	$fkUnit = !empty($line->fk_unit) ? (int) $line->fk_unit : 0;
	if ($fkUnit <= 0) {
		return 'C62';
	}
	if (isset($unitCache[$fkUnit])) {
		$unknown = $unitCache[$fkUnit][1];
		return $unitCache[$fkUnit][0];
	}

	global $db;
	if (!is_object($db)) {
		return 'C62';
	}
	$res = $db->query("SELECT code, short_label FROM ".MAIN_DB_PREFIX."c_units WHERE rowid = ".$fkUnit);
	$obj = $res ? $db->fetch_object($res) : false;
	if (!$obj) {
		return 'C62';
	}

	$code = strtoupper(trim((string) $obj->code));
	$label = trim((string) $obj->short_label);
	$unmapped = null;
	if (isset($codeMap[$code])) {
		$unece = $codeMap[$code];
	} elseif (isset($shortLabelMap[strtolower($label)])) {
		$unece = $shortLabelMap[strtolower($label)];
	} else {
		$unece = 'C62';
		$unmapped = ($label !== '') ? $label : $code;
	}
	$unitCache[$fkUnit] = [$unece, $unmapped];
	$unknown = $unmapped;
	return $unece;
}

/**
 * Resolve the EN16931 VAT category (CategoryCode + VATEX code) for the business context.
 *
 * Intra-community B2B (EU buyer outside France with a VAT ID, 0% rate):
 *  - goods (product_type 0)    → K  (intra-community supply, Art. 138 Directive 2006/112/EC)
 *  - services (product_type 1) → AE (reverse charge, Art. 196 Directive 2006/112/EC)
 *
 * @param object $line        Invoice line
 * @param object $invoice     Dolibarr invoice
 * @param object $thirdparty  Buyer
 * @param object $mysoc       Seller company
 * @return array ['code' => 'S|K|AE|G|O|E|Z', 'exemption' => string|null, 'vatex' => string|null]
 */
function auvoerechnung_resolve_tax_category($line, $invoice, $thirdparty, $mysoc)
{
	// Non-taxable seller (small-business VAT exemption under Art. 293 B CGI): use
	// category E (Exempt from tax). Code 'O' (services outside scope) would trigger
	// BR-O-04/05 at a 0% rate and is semantically incorrect (Art. 293 B is a French
	// exemption, not an EU out-of-scope transaction). BR-E-09 requires a seller tax
	// identifier, provided as SpecifiedTaxRegistration schemeID="FC" (SIREN) in
	// auvoerechnung_build_party_model() when tva_intra is empty.
	// A VAT rate above 0 takes precedence over the exemption status: a line that
	// actually carries VAT (e.g. an old invoice regenerated after switching to the
	// exemption regime) would violate BR-E-05 if categorized as E.
	if ((float) $line->tva_tx > 0) {
		return ['code' => 'S', 'exemption' => null, 'vatex' => null];
	}

	// Seller's "domestic" country: used to distinguish intra-community operations
	// (an EU buyer in another country) and choose the exemption-reason language
	// (French or German law). Overseas territories remain mapped to FR, matching
	// the module's historical behavior.
	$sellerCountry = auvoerechnung_seller_is_fr($mysoc) ? 'FR' : strtoupper($mysoc->country_code);
	$sellerIsDE    = ($sellerCountry === 'DE');
	$isService     = ((int) ($line->product_type ?? 0) === 1);

	if (isset($mysoc->tva_assuj) && (int) $mysoc->tva_assuj === 0) {
		// Exemption regime: the reason depends on the seller's country.
		// VATEX-FR-FRANCHISE is specific to French law. For Germany (§ 19 UStG), no
		// CEF list code applies, so free text is sufficient for BR-E-10.
		if ($sellerIsDE) {
			return ['code' => 'E', 'exemption' => 'Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG', 'vatex' => null];
		}
		return ['code' => 'E', 'exemption' => 'TVA non applicable, art. 293 B du CGI', 'vatex' => 'VATEX-FR-FRANCHISE'];
	}

	// For 0% VAT, determine the category from the context.
	$buyerCountry = strtoupper(!empty($thirdparty->country_code) ? $thirdparty->country_code : $sellerCountry);
	$buyerVat     = auvoerechnung_party_vat_id($thirdparty);

	// Export outside the EU: G.
	if (!in_array($buyerCountry, AUVOERECHNUNG_EU_COUNTRIES, true)) {
		if ($sellerIsDE) {
			$reason = $isService
				? 'Nicht im Inland steuerbare Leistung (§ 3a Abs. 2 UStG)'
				: 'Steuerfreie Ausfuhrlieferung (§ 4 Nr. 1a i. V. m. § 6 UStG)';
			return ['code' => 'G', 'exemption' => $reason, 'vatex' => 'VATEX-EU-G'];
		}
		return ['code' => 'G', 'exemption' => 'Export hors Union européenne (art. 262 I du CGI)', 'vatex' => 'VATEX-EU-G'];
	}

	// EU buyer outside the seller's country with a VAT ID: K (goods) or AE (services, Art. 196).
	if ($buyerCountry !== $sellerCountry && !empty($buyerVat)) {
		if ($isService) {
			$reason = $sellerIsDE
				? 'Steuerschuldnerschaft des Leistungsempfängers (Reverse Charge, Art. 196 MwStSystRL)'
				: 'Autoliquidation — TVA due par le preneur (art. 196, directive 2006/112/CE)';
			return ['code' => 'AE', 'exemption' => $reason, 'vatex' => 'VATEX-EU-AE'];
		}
		$reason = $sellerIsDE
			? 'Steuerfreie innergemeinschaftliche Lieferung (§ 4 Nr. 1b i. V. m. § 6a UStG)'
			: 'Livraison intracommunautaire exonérée — TVA due par le preneur (art. 138, directive 2006/112/CE)';
		return ['code' => 'K', 'exemption' => $reason, 'vatex' => 'VATEX-EU-IC'];
	}

	// Seller's country or EU buyer without a VAT ID at 0%: default to exempt.
	// Do not emit a VATEX code because the legal basis cannot be inferred (Art. 296ter,
	// Art. 261-4 training, § 4 UStG, etc.); provide an explicit reason in the description.
	return ['code' => 'E', 'exemption' => $sellerIsDE ? 'Umsatzsteuerfrei' : 'Exonéré de TVA', 'vatex' => null];
}

/**
 * Resolve the EN16931 document TypeCode from the Dolibarr invoice type.
 *
 * Dolibarr types without a dedicated TypeCode:
 *  - TYPE_SITUATION (5): emitted as 380 with a warning; mapping of progress
 *    billing lines (cumulative amounts, retention) is not guaranteed.
 *  - TYPE_PROFORMA (4): a pro forma is not an invoice under EN16931.
 *
 * @param object $invoice        Dolibarr invoice
 * @param array  $buildWarnings  (output) warnings
 * @return string '380' (standard), '381' (credit note), '384' (corrective), '386' (prepayment)
 */
function auvoerechnung_resolve_document_type($invoice, &$buildWarnings = [])
{
	$type = (int) $invoice->type;

	switch ($type) {
		case 1: // Facture::TYPE_REPLACEMENT
			return '384'; // Corrected invoice + BG-3 referencing the replaced invoice
		case 2: // Facture::TYPE_CREDIT_NOTE
			return '381';
		case 3: // Facture::TYPE_DEPOSIT
			return '386'; // EN16931: prepayment / advance invoice
		case 5: // Facture::TYPE_SITUATION
			$buildWarnings[] = auvoerechnung_trans('AuvoERechnungWarnSituationInvoice');
			return '380';
		default:
			return '380';
	}
}

/**
 * Return the total amount already prepaid through deposits applied to the final invoice.
 *
 * @param object $invoice Dolibarr invoice
 * @return float Prepaid amount ≥ 0
 */
function auvoerechnung_get_prepaid_amount($invoice)
{
	$prepaid = method_exists($invoice, 'getSumDepositsUsed') ? max(0.0, (float) $invoice->getSumDepositsUsed()) : 0.0;
	return round($prepaid + auvoerechnung_get_deposit_lines_gross($invoice), 2);
}

/**
 * Deposits applied as a LINE on the final invoice (Dolibarr's standard path:
 * "Insert discount", a negative line with fk_remise_except).
 *
 * By default, Dolibarr treats a deposit invoice as a negative line rather than
 * a payment (FACTURE_DEPOSITS_ARE_JUST_PAYMENTS is discouraged); in that case
 * getSumDepositsUsed() returns 0. These lines are not discounts (BG-20): remove
 * them from lines and discounts, and report them gross in BT-113, with the
 * deposit invoice in BG-3 and the partial-payment details in BT-22 (BMF
 * 15 Oct 2026, para. 3), as in the payment path.
 *
 * This applies only to standard invoices and discounts sourced from a deposit
 * invoice (type 3). A credit note or overpayment applied as a line remains a discount.
 *
 * @param object $invoice Dolibarr invoice
 * @return array<int,object> Indexed by discount rowid: fid, ref, datef,
 *                           amount_ht, amount_tva, amount_ttc, tva_tx
 */
function auvoerechnung_get_deposit_lines($invoice)
{
	$db = $invoice->db ?? null;
	if (!is_object($db) || (int) ($invoice->type ?? 0) !== 0 || empty($invoice->lines)) {
		return [];
	}
	$ids = [];
	foreach ($invoice->lines as $line) {
		if (!empty($line->fk_remise_except) && (float) $line->total_ht < 0) {
			$ids[(int) $line->fk_remise_except] = true;
		}
	}
	if (!$ids) {
		return [];
	}
	$sql = "SELECT rx.rowid, f.rowid AS fid, f.ref, f.datef, rx.amount_ht, rx.amount_tva, rx.amount_ttc, rx.tva_tx"
		." FROM ".MAIN_DB_PREFIX."societe_remise_except rx"
		." INNER JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = rx.fk_facture_source"
		." WHERE rx.rowid IN (".implode(',', array_keys($ids)).") AND f.type = 3"
		." ORDER BY f.datef, f.rowid";
	$res = $db->query($sql);
	if (!$res) {
		return [];
	}
	$out = [];
	while ($obj = $db->fetch_object($res)) {
		$out[(int) $obj->rowid] = $obj;
	}
	return $out;
}

/**
 * Is this a deposit line applied to the invoice (see auvoerechnung_get_deposit_lines)?
 *
 * @param object $line         Invoice line
 * @param array  $depositLines Result of auvoerechnung_get_deposit_lines()
 * @return bool
 */
function auvoerechnung_is_deposit_line($line, array $depositLines)
{
	return !empty($line->fk_remise_except) && (float) $line->total_ht < 0
		&& isset($depositLines[(int) $line->fk_remise_except]);
}

/**
 * Gross amount of deposits applied as lines, as deducted by Dolibarr from the
 * invoice totals (sum of lines, as an absolute value).
 *
 * @param object $invoice Dolibarr invoice
 * @return float
 */
function auvoerechnung_get_deposit_lines_gross($invoice)
{
	$depositLines = auvoerechnung_get_deposit_lines($invoice);
	if (!$depositLines) {
		return 0.0;
	}
	$gross = 0.0;
	foreach ($invoice->lines as $line) {
		if (auvoerechnung_is_deposit_line($line, $depositLines)) {
			$gross += abs((float) $line->total_ht + (float) $line->total_tva);
		}
	}
	return round($gross, 2);
}

/**
 * List the preceding invoices to reference in BG-3:
 *  - source invoice for a credit note or corrective invoice (fk_facture_source)
 *  - deposit invoices applied to this invoice (llx_societe_remise_except)
 *
 * Corrective invoices (BT-3 384/471/472/473): BR-FR-CO-04 requires exactly
 * ONE BT-25 reference, to the invoice being replaced. Applied deposit invoices
 * are therefore NOT referenced in BG-3 in this case (the prepaid amount stays
 * in BT-113). Without this restriction, a corrective invoice with an applied
 * deposit would produce multiple references and the internal validator would
 * incorrectly block generation in strict mode.
 *
 * @param object $invoice  Dolibarr invoice
 * @param string $typeCode Emitted BT-3 TypeCode ('380', '384', '381',...); '' = no restriction
 * @return array List of ['ref' => string, 'date' => 'YYYYMMDD'|'']
 */
function auvoerechnung_get_preceding_invoices($invoice, $typeCode = '')
{
	$out = [];
	$seen = [];
	$db = $invoice->db ?? null;
	if (!is_object($db)) {
		return $out;
	}

	// BR-FR-CO-04: a corrective invoice references ONLY the invoice it replaces.
	$isCorrective = in_array($typeCode, ['384', '471', '472', '473'], true);

	$sourceIds = [];
	if (!empty($invoice->fk_facture_source)) {
		$sourceIds[] = (int) $invoice->fk_facture_source;
	}

	if (!$isCorrective && !empty($invoice->id)) {
		// Deposits/credit notes consumed on this invoice through exceptional discounts.
		$sql = "SELECT DISTINCT fk_facture_source FROM ".MAIN_DB_PREFIX."societe_remise_except"
			." WHERE fk_facture = ".((int) $invoice->id)." AND fk_facture_source IS NOT NULL";
		$res = $db->query($sql);
		if ($res) {
			while ($obj = $db->fetch_object($res)) {
				$sourceIds[] = (int) $obj->fk_facture_source;
			}
		}
	}

	if (!$isCorrective) {
		// Deposits applied as lines (Dolibarr's standard path).
		foreach (auvoerechnung_get_deposit_lines($invoice) as $dep) {
			$sourceIds[] = (int) $dep->fid;
		}
	}

	foreach (array_unique(array_filter($sourceIds)) as $fkSource) {
		if (isset($seen[$fkSource])) {
			continue;
		}
		$seen[$fkSource] = true;
		$sql = "SELECT ref, datef FROM ".MAIN_DB_PREFIX."facture WHERE rowid = ".((int) $fkSource);
		$res = $db->query($sql);
		if (!$res) {
			continue;
		}
		$obj = $db->fetch_object($res);
		if ($obj && !empty($obj->ref)) {
			$out[] = [
				'ref'  => $obj->ref,
				'date' => !empty($obj->datef) ? str_replace('-', '', substr($obj->datef, 0, 10)) : '',
			];
		}
	}

	return $out;
}

/**
 * BT-22 text for a final invoice with applied deposits (§ 14(5) UStG).
 *
 * BMF letter of 15.10.2026 (para. 3): until 30.06.2030 it is accepted if the
 * partial payments are deducted as a total in BT-113 and the details required
 * by UStAE 14.8 (7)/(8) (net amount, tax rate, tax per advance payment) are
 * stated as free text in BT-22. Empty when no deposit (including a deposit
 * invoice) was applied or when the source data is unavailable.
 *
 * @param object $invoice Dolibarr invoice
 * @return string Text (one line per deposit) or an empty string
 */
function auvoerechnung_build_deposit_note($invoice)
{
	$db = $invoice->db ?? null;
	if (!is_object($db) || empty($invoice->id) || auvoerechnung_get_prepaid_amount($invoice) <= 0) {
		return '';
	}
	// "Payment" path (FACTURE_DEPOSITS_ARE_JUST_PAYMENTS): fk_facture.
	$deposits = [];
	$sql = "SELECT f.rowid AS fid, f.ref, f.datef, re.amount_ht, re.amount_tva, re.amount_ttc, re.tva_tx"
		." FROM ".MAIN_DB_PREFIX."societe_remise_except re"
		." INNER JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = re.fk_facture_source"
		." WHERE re.fk_facture = ".((int) $invoice->id)." AND f.type = 3"
		." ORDER BY f.datef, f.rowid";
	$res = $db->query($sql);
	if ($res) {
		while ($obj = $db->fetch_object($res)) {
			$deposits[] = $obj;
		}
	}
	// Standard path: negative line (fk_facture_line).
	foreach (auvoerechnung_get_deposit_lines($invoice) as $obj) {
		$deposits[] = $obj;
	}
	usort($deposits, function ($a, $b) {
		return [(string) ($a->datef ?? ''), (int) ($a->fid ?? 0)] <=> [(string) ($b->datef ?? ''), (int) ($b->fid ?? 0)];
	});
	$lines = [];
	foreach ($deposits as $obj) {
		$date = !empty($obj->datef) ? substr($obj->datef, 8, 2).'.'.substr($obj->datef, 5, 2).'.'.substr($obj->datef, 0, 4) : '';
		// Six values: $langs->trans() fails with more than four (issue #1).
		$lines[] = auvoerechnung_trans_format('AuvoERechnungDepositNoteLine', [
			$obj->ref,
			$date,
			number_format((float) $obj->amount_ht, 2, ',', '.'),
			rtrim(rtrim(number_format((float) $obj->tva_tx, 2, ',', '.'), '0'), ','),
			number_format((float) $obj->amount_tva, 2, ',', '.'),
			number_format((float) $obj->amount_ttc, 2, ',', '.'),
		]);
	}
	if (!$lines) {
		return '';
	}
	return auvoerechnung_trans_raw('AuvoERechnungDepositNoteHead')."\n".implode("\n", $lines);
}

/**
 * BUYER'S purchase order number (BT-13 "Purchase order reference") from the
 * first customer order linked to the invoice through llx_element_element.
 *
 * This is the order's "Customer reference" field (ref_client, German labels
 * "Ihr Zeichen" / "Kunden-BestellNr."), NOT its internal Dolibarr reference
 * (c.ref, e.g. CO2606-0042), which is the seller's number and unusable by the
 * buyer. Empty if the order has no customer reference.
 *
 * @param object $invoice Dolibarr invoice
 * @return string Buyer's purchase order number or an empty string
 */
function auvoerechnung_get_linked_order_ref($invoice)
{
	$db = $invoice->db ?? null;
	if (!is_object($db) || empty($invoice->id)) {
		return '';
	}
	$sql = "SELECT c.ref_client FROM ".MAIN_DB_PREFIX."element_element ee"
		." INNER JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = ee.fk_source"
		." WHERE ee.sourcetype = 'commande' AND ee.targettype = 'facture'"
		." AND ee.fk_target = ".((int) $invoice->id)
		." ORDER BY ee.rowid ASC LIMIT 1";
	$res = $db->query($sql);
	if (!$res) {
		return '';
	}
	$obj = $db->fetch_object($res);
	return ($obj && !empty($obj->ref_client)) ? trim((string) $obj->ref_client) : '';
}


/**
 * Extract the SIREN (first nine digits) from a SIRET.
 */
function auvoerechnung_extract_siren($siret)
{
	if (empty($siret)) {
		return '';
	}
	return substr(preg_replace('/[^0-9]/', '', $siret), 0, 9);
}

/**
 * Determine whether an invoice uses the Chorus Pro workflow (B2G, public sector).
 * If so, generate a Chorus-profile Factur-X PDF in addition to the standard
 * EN16931 PDF. Three signals, from most explicit to fallback:
 *   1. "Chorus Pro invoice" checkbox on the invoice (extrafield lfxchorus);
 *   2. a populated Chorus field (service code / commitment / contract);
 *   3. buyer is the central government (SIRET starts with 110002011).
 *
 * @param Facture $invoice  Dolibarr invoice (thirdparty and array_options loaded)
 * @return bool
 */
function auvoerechnung_is_chorus_invoice($invoice)
{
	$ao = (is_object($invoice) && !empty($invoice->array_options)) ? $invoice->array_options : [];

	if (!empty($ao['options_lfxchorus'])) {
		return true;
	}
	foreach (['options_lfxservicecode', 'options_lfxengagement', 'options_lfxmarche'] as $k) {
		if (!empty($ao[$k])) {
			return true;
		}
	}
	// Automatic fallback: buyer is public sector based on the SIRET.
	return auvoerechnung_is_public_sector_siret($invoice);
}

/**
 * Detect whether the buyer is a public entity from its SIRET (central government:
 * SIRET starts with 110002011). Used as an automatic fallback and in the
 * information message shown when Chorus features are disabled.
 * Note: local authorities and hospitals have their own SIREN and cannot be
 * detected this way, which is why the Chorus tab has a manual checkbox.
 *
 * @param Facture $invoice
 * @return bool
 */
function auvoerechnung_is_public_sector_siret($invoice)
{
	$buyer = (is_object($invoice) && !empty($invoice->thirdparty)) ? $invoice->thirdparty : null;
	if (is_object($buyer) && !empty($buyer->idprof2)) {
		$siret = preg_replace('/[^0-9]/', '', $buyer->idprof2);
		if (strpos($siret, '110002011') === 0) {
			return true;
		}
	}
	return false;
}

/**
 * Build the options array for generating XML with the Chorus Pro profile from
 * invoice extrafields (billing framework BT-23, service code BT-10, commitment
 * number BT-13, contract number BT-12).
 *
 * @param Facture $invoice
 * @return array  Options for auvoerechnung_build_xml()
 */
function auvoerechnung_chorus_options($invoice)
{
	$ao = (is_object($invoice) && !empty($invoice->array_options)) ? $invoice->array_options : [];
	return [
		'profile'      => 'choruspro',
		'cadre'        => trim((string) ($ao['options_lfxcadre'] ?? '')),
		'service_code' => trim((string) ($ao['options_lfxservicecode'] ?? '')),
		'engagement'   => trim((string) ($ao['options_lfxengagement'] ?? '')),
		'marche'       => trim((string) ($ao['options_lfxmarche'] ?? '')),
	];
}

/**
 * Official list of Chorus Pro billing frameworks (BT-23), from AIFE's
 * "Billing Process Annex" V4.00 §2.1. A1 = supplier submission (B2G default).
 * A11 is not present in the AIFE nomenclature.
 *
 * @return array  code => label
 */
function auvoerechnung_chorus_frameworks()
{
	return [
		'A1'  => 'A1 — Dépôt d\'une facture par un fournisseur',
		'A2'  => 'A2 — Facture déjà payée (carte d\'achat)',
		'A3'  => 'A3 — Mémoire de frais de justice',
		'A4'  => 'A4 — Projet de décompte mensuel (travaux)',
		'A5'  => 'A5 — État d\'acompte (travaux)',
		'A6'  => 'A6 — Pièce de facturation travaux au service financier',
		'A7'  => 'A7 — Projet de décompte final (travaux)',
		'A8'  => 'A8 — Décompte général signé (travaux)',
		'A9'  => 'A9 — Sous-traitant : demande de paiement',
		'A10' => 'A10 — Sous-traitant : demande de paiement (marché de travaux)',
		'A12' => 'A12 — Cotraitant : facture',
		'A13' => 'A13 — Cotraitant : projet de décompte mensuel',
		'A14' => 'A14 — Cotraitant : projet de décompte final',
		'A15' => 'A15 — MOE : état d\'acompte',
		'A16' => 'A16 — MOE : état d\'acompte validé',
		'A17' => 'A17 — MOE : projet de décompte général',
		'A18' => 'A18 — MOE : décompte général',
		'A19' => 'A19 — MOA : état d\'acompte validé',
		'A20' => 'A20 — MOA : décompte général',
		'A21' => 'A21 — Demande de remboursement TIC',
		'A22' => 'A22 — Fournisseur : projet de décompte général tacite',
		'A23' => 'A23 — Fournisseur : décompte général définitif tacite',
		'A24' => 'A24 — MOE : décompte général définitif tacite',
		'A25' => 'A25 — MOA : décompte général définitif tacite',
	];
}

/**
 * Copy the three BR-FR-05 legal notices (PMD/PMT/AAB) to the Dolibarr invoice
 * footer (FACTURE_FREE_TEXT). Never overwrite existing text; add only missing
 * notices. Idempotent.
 *
 * @param DoliDB $db
 * @return int  Number of notices added
 */
/**
 * Set printable=0 and list=0 for Chorus extrafields (lfx*).
 *
 * These fields are internal (edited in the dedicated "Chorus Pro" tab) and
 * must never print on the PDF. Dolibarr 23 creates them with printable!=0, and
 * its PDF model (pdf_sponge → getExtrafieldsInHtml) prints every extrafield with
 * printable set to 1 or 2 in the PDF NOTE. This caused an empty block of Chorus
 * labels to appear in invoice descriptions (Dolibarr 23.0.1). Idempotent and
 * harmless on Dolibarr <= 22 (already set to 0). Returns the number of rows fixed.
 *
 * @param DoliDB $db
 * @return int
 */
function auvoerechnung_fix_chorus_extrafields_display($db)
{
	$names = "'lfxchorus','lfxcadre','lfxservicecode','lfxengagement','lfxmarche'";
	$sql = "UPDATE ".MAIN_DB_PREFIX."extrafields SET printable = 0, list = '0'";
	$sql .= " WHERE elementtype = 'facture' AND name IN (".$names.")";
	$sql .= " AND (printable <> 0 OR list <> '0')";
	$res = $db->query($sql);
	return $res ? $db->affected_rows($res) : 0;
}

function auvoerechnung_append_notes_to_footer($db, $overwrite = null)
{
	global $conf, $mysoc;
	// Since Dolibarr 17, "Additional invoice text" is stored in INVOICE_FREE_TEXT
	// (formerly FACTURE_FREE_TEXT); this is the constant printed by the PDF.
	// Therefore, target INVOICE_FREE_TEXT.
	$constName = auvoerechnung_invoice_freetext_const();
	if ($overwrite === null) {
		$overwrite = (getDolGlobalInt('AUVOERECHNUNG_NOTES_OVERWRITE', 0) === 1);
	}
	$freeText = getDolGlobalString($constName, '');
	// Use the same notices as in the XML: do not impose French defaults on a
	// foreign seller's PDF (only its AUVOERECHNUNG_NOTE_* constants apply).
	$mentions = array();
	foreach (auvoerechnung_resolve_legal_notes(auvoerechnung_seller_is_fr($mysoc ?? null)) as $m) {
		$m = trim($m);
		if ($m !== '') {
			$mentions[] = $m;
		}
	}
	// Center the notices in the PDF (HTML), matching the "Additional text" field,
	// which is rendered as HTML and is often already centered.
	$wrap = static function ($m) {
		return '<p style="text-align:center;margin:0;">'.$m.'</p>';
	};
	$added = 0;
	if ($overwrite || trim($freeText) === '') {
		// Empty field OR "overwrite" option: add or replace our notices.
		$parts = array();
		foreach ($mentions as $m) {
			$parts[] = $wrap($m);
		}
		$newText = implode("\n", $parts);
		$added = ($newText !== $freeText) ? count($mentions) : 0;
	} else {
		// Append our notices after existing text (only missing ones, checked
		// against the plain text) without changing anything else.
		$newText = $freeText;
		foreach ($mentions as $m) {
			if (strpos($newText, $m) !== false) {
				continue;
			}
			$newText = rtrim($newText)."\n".$wrap($m);
			$added++;
		}
	}
	if ($newText !== $freeText) {
		dolibarr_set_const($db, $constName, $newText, 'chaine', 0, '', $conf->entity);
	}
	return $added;
}

/**
 * Name of the "additional invoice text" constant: Dolibarr renamed
 * FACTURE_FREE_TEXT to INVOICE_FREE_TEXT in v17, and the PDF model reads the
 * latter. This module targets Dolibarr 19+, so always use INVOICE_FREE_TEXT.
 *
 * Note: do NOT detect "whichever one has content". If the old FACTURE_FREE_TEXT
 * contains stale text, we would write to a constant the PDF no longer reads,
 * leaving INVOICE_FREE_TEXT empty and nothing printed.
 *
 * @return string
 */
function auvoerechnung_invoice_freetext_const()
{
	return 'INVOICE_FREE_TEXT';
}

/**
 * List the PDF fonts available in Dolibarr's TCPDF, and whether each one
 * complies with Factur-X (PDF/A-3 requires an EMBEDDED font).
 *
 * Detection (based on TCPDF definitions): a font is embedded when its $type is
 * not 'core' (Base 14 fonts are not embedded) AND it has a $file (embedded font
 * program, e.g. 'dejavusans.z' or 'pdfahelvetica.z').
 *
 * Return only regular fonts: bold/italic variants (suffix b / i / bi) are
 * excluded because TCPDF selects them automatically from the base font set in
 * MAIN_PDF_FORCE_FONT.
 *
 * Stable TCPDF path in Dolibarr 18 through 23. Returns a sorted array of
 * (fontName => embedded) or an empty array if the directory cannot be found.
 *
 * @return array
 */
function auvoerechnung_list_pdf_fonts()
{
	$dir = DOL_DOCUMENT_ROOT.'/includes/tecnickcom/tcpdf/fonts/';
	if (!is_dir($dir)) {
		return array();
	}
	$fonts = array();
	$files = glob($dir.'*.php');
	if (!is_array($files)) {
		return array();
	}
	foreach ($files as $path) {
		$name = basename($path, '.php');
		// Skip bold/italic variants (b / i / bi) of a base font.
		$base = preg_replace('/(bi|b|i)$/', '', $name);
		if ($base !== $name && file_exists($dir.$base.'.php')) {
			continue;
		}
		$content = @file_get_contents($path);
		if ($content === false) {
			continue;
		}
		$type = '';
		$file = '';
		if (preg_match('/\$type\s*=\s*\'([^\']*)\'/', $content, $m)) {
			$type = $m[1];
		}
		if (preg_match('/\$file\s*=\s*\'([^\']*)\'/', $content, $m)) {
			$file = $m[1];
		}
		$fonts[$name] = ($type !== '' && $type !== 'core' && $file !== '');
	}
	ksort($fonts);
	return $fonts;
}

/**
 * VAT due-date code (BT-8) derived from Dolibarr's VAT regime (TAX_MODE
 * constant, Configuration > Taxes); there is no separate module setting.
 *   TAX_MODE 1 = on debits      → '5'
 *   TAX_MODE 2 = on payments    → '72'
 *   TAX_MODE 0 = standard (mixte) : on suit TAX_MODE_SELL_SERVICE.
 *
 * Only for French sellers (setting AUVOERECHNUNG_VAT_DUE_DATE = auto); other
 * sellers get BT-8 only when set explicitly (5 = Soll, 72 = Ist).
 *
 * XP Z12-012 compliance checked (2026-07): the UNTDID 2475 code list allowed
 * by EN16931 in CII is {5, 29, 72}. For "on debits", BR-FR-MAP-03 requires
 * value 5 in CII, and BR-FR-MAP-29 specifies that PPF expects only 5 (29,
 * "delivery date", although allowed by EN16931, must be mapped to 5 in
 * submissions). Value 72 (payment date) codes VAT on payments. The current
 * mapping is correct and should not be changed. Emit only for category 'S'
 * (see build_xml), which is correct: this module classifies every line with a
 * rate above 0 as S, and the due date is not relevant to categories without
 * VAT due (E/K/G/AE/O/Z).
 *
 * @param object|null $mysoc  Seller; null keeps the Dolibarr mapping
 * @return string  '5', '72' or '' (omitted)
 */
function auvoerechnung_resolve_vat_due_date_code($mysoc = null)
{
	// Own setting first: Dolibarr fills TAX_MODE_SELL_SERVICE with 'payment'
	// in memory when it was never saved (conf.class.php), so a German system
	// that never touched the French tax options would claim "Ist-Versteuerung"
	// (72) on every invoice. Germany does not need BT-8 at all.
	$setting = getDolGlobalString('AUVOERECHNUNG_VAT_DUE_DATE', 'auto');
	if ($setting === '5' || $setting === '72') {
		return $setting;
	}
	if ($setting === 'none' || ($mysoc !== null && !auvoerechnung_seller_is_fr($mysoc))) {
		return '';
	}
	$mode = getDolGlobalInt('TAX_MODE', 0);
	if ($mode === 1) {
		return '5';
	}
	if ($mode === 2) {
		return '72';
	}
	$sellService = getDolGlobalString('TAX_MODE_SELL_SERVICE', '');
	if ($sellService === 'invoice') {
		return '5';
	}
	if ($sellService === 'payment') {
		return '72';
	}
	return '';
}

/**
 * Resolve the PHP CLI binary for the injection subprocess.
 *  1. Use a non-empty manual override (AUVOERECHNUNG_PHP_CLI_PATH) as provided;
 *     append php.exe (Windows) or php when the value is a directory.
 *  2. Reuse a cached value (AUVOERECHNUNG_PHP_CLI_CACHE) if it is still a PHP
 *     CLI with the same major.minor version as the running PHP. Without the
 *     version check, a binary detected before a PHP upgrade (e.g. php8.2 after
 *     switching the web server to 8.4) stayed in use as long as it existed.
 *  3. Otherwise, auto-detect and cache the result.
 *
 * @param DoliDB $db
 * @return string  PHP CLI binary path/command ('php' as a last resort)
 */
function auvoerechnung_resolve_php_cli($db)
{
	// Bare 'php' (the old default) is not a real override: auto-detect a better
	// candidate, falling back to 'php' if needed. An override is a specific path.
	$manual = trim(getDolGlobalString('AUVOERECHNUNG_PHP_CLI_PATH', ''));
	if ($manual !== '' && $manual !== 'php') {
		return auvoerechnung_php_cli_from_dir($manual);
	}
	$cache = trim(getDolGlobalString('AUVOERECHNUNG_PHP_CLI_CACHE', ''));
	if ($cache !== '' && auvoerechnung_php_cli_is_valid($cache, PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION)) {
		return $cache;
	}
	$detected = auvoerechnung_detect_php_cli();
	// Best-effort caching: dolibarr_set_const() is in admin.lib.php, loaded by
	// main.inc.php in web/hook contexts but not in a minimal CLI bootstrap. Do not
	// fail binary resolution if the function is unavailable.
	if ($detected !== '' && is_object($db) && function_exists('dolibarr_set_const')) {
		dolibarr_set_const($db, 'AUVOERECHNUNG_PHP_CLI_CACHE', $detected, 'chaine', 0, '', 0);
	}
	return $detected !== '' ? $detected : 'php';
}

/**
 * Manual override for the PHP CLI binary: if the user entered a directory
 * (e.g. C:\laragon\bin\php\php-8.3.16), append php.exe on Windows or php
 * elsewhere. A file path is returned unchanged.
 *
 * @param string $path
 * @return string
 */
function auvoerechnung_php_cli_from_dir($path)
{
	if (!@is_dir($path)) {
		return $path;
	}
	return rtrim($path, '/\\').DIRECTORY_SEPARATOR.(DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php');
}

/**
 * Probe a list of candidates and return the first PHP CLI binary whose SAPI is
 * "cli" AND whose major.minor version matches the web version, avoiding a
 * different PHP installation. Return '' if no candidate matches.
 *
 * Note: in an FPM context, PHP_BINARY points to php-fpm (not usable from CLI).
 * Derive a CLI candidate by replacing php-fpm with php and sbin with bin.
 *
 * @return string
 */
function auvoerechnung_detect_php_cli()
{
	if (!function_exists('exec')) {
		return '';
	}
	$want = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
	$candidates = array();
	if (defined('PHP_BINARY') && PHP_BINARY !== '') {
		$candidates[] = str_replace(array('php-fpm', '/sbin/'), array('php', '/bin/'), PHP_BINARY);
		// Windows: derive php.exe from the web binary's directory
		// (e.g. C:\php\php-cgi.exe → C:\php\php.exe).
		if (DIRECTORY_SEPARATOR === '\\') {
			$candidates[] = dirname(PHP_BINARY).'\\php.exe';
		}
	}
	// Windows with mod_php (Laragon, XAMPP, WAMP): PHP_BINARY points to httpd.exe.
	// Find the PHP directory through the loaded php.ini or the extension
	// directory (...\php-8.x\ext).
	if (DIRECTORY_SEPARATOR === '\\') {
		$ini = php_ini_loaded_file();
		if ($ini) {
			$candidates[] = dirname($ini).'\\php.exe';
		}
		if (defined('PHP_EXTENSION_DIR') && PHP_EXTENSION_DIR !== '') {
			$candidates[] = dirname(PHP_EXTENSION_DIR).'\\php.exe';
		}
	}
	foreach (array('/usr/bin/', '/usr/local/bin/', '') as $dir) {
		$candidates[] = $dir.'php'.$want;
		$candidates[] = $dir.'php'.PHP_MAJOR_VERSION;
		$candidates[] = $dir.'php';
	}
	$seen = array();
	foreach ($candidates as $cand) {
		if ($cand === '' || isset($seen[$cand])) {
			continue;
		}
		$seen[$cand] = true;
		if (auvoerechnung_php_cli_is_valid($cand, $want)) {
			return $cand;
		}
	}
	return '';
}

/**
 * Check that a candidate is an executable PHP CLI binary (SAPI cli), and
 * optionally that it has the expected version.
 *
 * @param string $cand
 * @param string $wantVersion  Expected major.minor, or '' to skip the check
 * @return bool
 */
function auvoerechnung_php_cli_is_valid($cand, $wantVersion = '')
{
	if (!function_exists('exec')) {
		return false;
	}
	// Probe cross-platform with `php -v` (not `php -r "..."`): an -r snippet
	// contains quotes, and escapeshellarg() replaces " with spaces on Windows,
	// breaking the command. `php -v` contains no quotes. Error redirection also
	// differs: NUL on Windows, /dev/null elsewhere.
	// First line of `php -v`: "PHP 8.2.30 (cli) (built: ...) ..."
	$devnull = (DIRECTORY_SEPARATOR === '\\') ? 'NUL' : '/dev/null';
	$out = array();
	$rc = 1;
	@exec(escapeshellarg($cand).' -v 2>'.$devnull, $out, $rc);
	if ($rc !== 0) {
		return false;
	}
	$version = '';
	foreach ($out as $line) {
		// SAPI "(cli)" on the version line confirms this is a CLI binary.
		if (preg_match('/^PHP\s+(\d+\.\d+)\.[^\s]*\s+\(cli\)/i', $line, $m)) {
			$version = $m[1];
			break;
		}
	}
	if ($version === '') {
		return false; // not executable, or SAPI != cli
	}
	if ($wantVersion !== '') {
		return $version === $wantVersion;
	}
	return true;
}

/**
 * Customer's 9-digit SIREN for BT-30/BT-47 (SpecifiedLegalOrganization).
 * Canonical source: Dolibarr's idprof1 (SIREN) field; if empty, derive it from
 * the first nine digits of SIRET (idprof2). Return '' if there is no usable
 * value or it cannot be normalized to exactly nine digits (BR-FR-10).
 *
 * @param object $party  mysoc or Societe (idprof1 / idprof2 fields)
 * @return string        9-digit SIREN, or ''
 */
function auvoerechnung_party_siren($party)
{
	$siren = auvoerechnung_extract_siren($party->idprof1 ?? '');
	if ($siren === '') {
		$siren = auvoerechnung_extract_siren($party->idprof2 ?? '');
	}
	return (strlen($siren) === 9) ? $siren : '';
}

/**
 * Is this a VAT ID that can be used in BT-31/BT-48?
 *
 * BR-CO-09: a VAT identifier must start with the ISO 3166-1 alpha-2 country
 * code (EL for Greece). A value without a prefix—typically a German
 * Kleinunternehmer's Steuernummer "22/180/80394" entered in the VAT ID field—
 * must NOT be published with schemeID="VA".
 *
 * @param string|null $vat  Value of the tva_intra field
 * @return bool
 */
function auvoerechnung_is_vat_id($vat)
{
	$vat = preg_replace('/[\s.\-]/', '', (string) $vat);
	return (bool) preg_match('/^[A-Za-z]{2}[0-9A-Za-z+*]{2,13}$/', $vat);
}

/**
 * Customer VAT ID, only when it has the format of a VAT ID
 * (see auvoerechnung_is_vat_id); otherwise, ''.
 *
 * @param object $party  mysoc or Societe
 * @return string
 */
function auvoerechnung_party_vat_id($party)
{
	$vat = trim((string) ($party->tva_intra ?? ''));
	return auvoerechnung_is_vat_id($vat) ? $vat : '';
}

/**
 * Customer's German tax number (Steuernummer), for BT-32.
 *
 * Dolibarr conventions for Germany (langs/de_DE/companies.lang, since 2016):
 *   idprof1 = Steuernummer   → this is THE field
 *   idprof2 = Amtsgericht    (text, e.g. "Amtsgericht Koblenz")
 *   idprof3 = Handelsregister-Nummer
 * Read order: idprof1, then idprof2 (older records in this module stored the
 * Steuernummer there), then the VAT ID field if it contains a Steuernummer
 * instead of a VAT ID. Accept only values matching the Steuernummer format
 * (digits, "/" and spaces, at least 10 digits): never a court name and never a
 * USt-IdNr (country prefix).
 *
 * @param object $party  mysoc or Societe
 * @return string        Steuernummer as entered, or ''
 */
function auvoerechnung_party_steuernummer($party)
{
	$candidates = [$party->idprof1 ?? '', $party->idprof2 ?? ''];
	if (!auvoerechnung_is_vat_id($party->tva_intra ?? '')) {
		$candidates[] = $party->tva_intra ?? '';
	}
	foreach ($candidates as $candidate) {
		$candidate = trim((string) $candidate);
		if (preg_match('#^[0-9][0-9/ ]*$#', $candidate) && strlen(preg_replace('/[^0-9]/', '', $candidate)) >= 10) {
			return $candidate;
		}
	}
	return '';
}

/**
 * Leitweg ID (German public administration routing identifier) for BT-10,
 * read from the dedicated customer extrafield. Dolibarr has no native
 * equivalent field; unlike the Steuernummer, it cannot reuse idprof2 because
 * the idprof* fields already follow existing German conventions.
 */
function auvoerechnung_party_leitwegid($party)
{
	$ao = (is_object($party) && !empty($party->array_options)) ? $party->array_options : [];
	return trim((string) ($ao['options_lfxleitwegid'] ?? ''));
}

/**
 * Leitweg ID applicable to an invoice: use the value entered on the invoice
 * (invoice extrafield lfxleitwegid, e.g. for another department of the same
 * administration), otherwise use the customer's value.
 *
 * @param object $invoice  Invoice (array_options and thirdparty loaded)
 * @return string
 */
function auvoerechnung_invoice_leitwegid($invoice)
{
	// The Leitweg ID applies only to buyers in Germany (as defined for BT-10/BT-49).
	// Ignore it for foreign buyers so the pre-check and XML remain consistent.
	$buyer = $invoice->thirdparty ?? null;
	$country = strtoupper((is_object($buyer) && !empty($buyer->country_code)) ? $buyer->country_code : auvoerechnung_default_country());
	if ($country !== 'DE') {
		return '';
	}
	$own = auvoerechnung_party_leitwegid($invoice);
	return $own !== '' ? $own : auvoerechnung_party_leitwegid($buyer);
}

/**
 * XRechnung specification identifier (BT-24), e.g.
 * urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0
 * (BR-DE-21). The version is fixed by the module and must match the
 * content it generates; the former setting AUVOERECHNUNG_XRECHNUNG_VERSION
 * is ignored (a value such as 4.0 led to rejected invoices).
 *
 * @return string
 */
function auvoerechnung_xrechnung_cius_id()
{
	return 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_'.AUVOERECHNUNG_XRECHNUNG_CIUS_VERSION;
}

/**
 * Seller's supplier number at this customer (BT-29), customer extrafield
 * "Unsere Lieferantennummer beim Kunden" (lfxsupplierno).
 *
 * @param object $party  Buyer
 * @return string
 */
function auvoerechnung_party_supplier_number($party)
{
	$ao = (is_object($party) && !empty($party->array_options)) ? $party->array_options : [];
	return trim((string) ($ao['options_lfxsupplierno'] ?? ''));
}

/**
 * Path to an invoice's XRechnung file: {invoice folder}/{ref}-XRECHNUNG.xml.
 * The name is fixed and independent of the PDF name (ODT templates produce
 * {ref}_{template}.pdf).
 *
 * @param string $ref     Invoice reference
 * @param int    $entity  Invoice entity
 * @return string  Path, or '' if the invoice documents directory is unknown
 */
function auvoerechnung_xrechnung_path($ref, $entity)
{
	global $conf;

	$dir = !empty($conf->facture->multidir_output[$entity])
		? $conf->facture->multidir_output[$entity]
		: ($conf->facture->dir_output ?? '');
	if (empty($dir)) {
		return '';
	}
	$safeRef = dol_sanitizeFileName($ref);
	return $dir.'/'.$safeRef.'/'.$safeRef.'-XRECHNUNG.xml';
}

/**
 * MIME types allowed for BG-24 attachments (BR-CL-24), by file extension.
 */
const AUVOERECHNUNG_ATTACHMENT_MIMES = [
	'pdf'  => 'application/pdf',
	'png'  => 'image/png',
	'jpg'  => 'image/jpeg',
	'jpeg' => 'image/jpeg',
	'csv'  => 'text/csv',
	'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
];

/**
 * Attachments to include in the XRechnung (BG-24), according to configuration:
 *  - AUVOERECHNUNG_XR_EMBED_PDF: the invoice PDF;
 *  - AUVOERECHNUNG_XR_EMBED_FILES: other files in the invoice documents
 *    directory (supporting documents, timesheets, etc.) in formats allowed by
 *    BR-CL-24. Files generated by Dolibarr or this module (names starting with
 *    the invoice reference: template PDF/ODT, XRechnung, Chorus PDF) are
 *    excluded unless indexed as uploaded in ECM.
 * File names must be unique (BR-DE-22). Above 7 MB total (about 9.3 MB after
 * base64 encoding), issue a warning: OZG-RE rejects emails larger than 10 MB.
 *
 * @param object   $invoice   Invoice (ref, id, entity, db, last_main_doc)
 * @param string[] $warnings  (sortie) avertissements
 * @param string   $pdfPath   Invoice PDF (empty = search automatically)
 * @return array<int,array{id:string,name:string,mime:string,content:string,description:string}>
 */
function auvoerechnung_collect_attachments($invoice, &$warnings = [], $pdfPath = '')
{
	global $conf;

	$embedPdf = getDolGlobalInt('AUVOERECHNUNG_XR_EMBED_PDF');
	$embedFiles = getDolGlobalInt('AUVOERECHNUNG_XR_EMBED_FILES');
	if (!$embedPdf && !$embedFiles) {
		return [];
	}
	$entity = (int) ($invoice->entity ?? $conf->entity ?? 1);
	$safeRef = dol_sanitizeFileName($invoice->ref);
	$files = [];
	if ($embedPdf) {
		$pdf = $pdfPath !== '' ? $pdfPath : auvoerechnung_invoice_pdf_path($invoice->ref, $entity, (string) ($invoice->last_main_doc ?? ''));
		if (!empty($pdf) && is_file($pdf)) {
			$files[] = [$pdf, auvoerechnung_trans_raw('AuvoERechnungAttachInvoicePdf')];
		}
	}
	if ($embedFiles) {
		$xrPath = auvoerechnung_xrechnung_path($invoice->ref, $entity);
		$dir = $xrPath !== '' ? dirname($xrPath) : '';
		$uploaded = [];
		if (!empty($invoice->db) && !empty($invoice->id) && method_exists($invoice->db, 'query')) {
			$sql = "SELECT filename FROM ".MAIN_DB_PREFIX."ecm_files WHERE src_object_type = 'facture'";
			$sql .= " AND src_object_id = ".((int) $invoice->id)." AND gen_or_uploaded = 'uploaded'";
			$resql = $invoice->db->query($sql);
			while ($resql && ($obj = $invoice->db->fetch_object($resql))) {
				$uploaded[$obj->filename] = true;
			}
		}
		$cands = ($dir !== '' && is_dir($dir)) ? (glob($dir.'/*') ?: []) : [];
		sort($cands);
		foreach ($cands as $f) {
			$base = basename($f);
			if (!is_file($f) || strpos($base, $safeRef) === 0 && empty($uploaded[$base])) {
				continue;
			}
			$ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
			if (!isset(AUVOERECHNUNG_ATTACHMENT_MIMES[$ext])) {
				$warnings[] = auvoerechnung_trans('AuvoERechnungWarnAttachType', $base);
				continue;
			}
			$files[] = [$f, ''];
		}
	}

	$out = [];
	$names = [];
	$total = 0;
	foreach ($files as $i => [$path, $description]) {
		$name = basename($path);
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$mime = AUVOERECHNUNG_ATTACHMENT_MIMES[$ext] ?? '';
		if ($mime === '' || isset($names[$name])) {
			continue;
		}
		$content = @file_get_contents($path);
		if ($content === false || $content === '') {
			continue;
		}
		$names[$name] = true;
		$total += strlen($content);
		$out[] = ['id' => $invoice->ref.'-A'.($i + 1), 'name' => $name, 'mime' => $mime, 'content' => $content, 'description' => $description];
	}
	if ($total > 7 * 1024 * 1024) {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnAttachSize', round($total / 1048576, 1));
	}
	return $out;
}

/**
 * Prepare a sample invoice for the configuration page test: the seller is the
 * real company ($mysoc); the fictional buyer is a German public administration
 * with KoSIT's test Leitweg ID (991-33333TEST-33); two lines (service and
 * delivery) at 19%, or 0% for a non-taxable seller (Kleinunternehmer). Nothing
 * is saved.
 *
 * @param object $invoice  Empty invoice (Facture or compatible object)
 * @param object $buyer    Empty customer (Societe or compatible object)
 * @param object $mysoc    Seller company
 * @return void
 */
function auvoerechnung_sample_invoice($invoice, $buyer, $mysoc)
{
	$buyer->id = 0;
	$buyer->name = 'Musterbehörde (Test)';
	$buyer->address = 'Amtsweg 1';
	$buyer->zip = '53113';
	$buyer->town = 'Bonn';
	$buyer->country_code = 'DE';
	$buyer->email = 'rechnungseingang@example.invalid';
	$buyer->tva_intra = '';
	$buyer->tva_assuj = 1;
	$buyer->array_options = ['options_lfxleitwegid' => '991-33333TEST-33'];

	$vat = !empty($mysoc->tva_assuj) ? 19.0 : 0.0;
	$lines = [];
	foreach ([['Beratung (Test)', 8.0, 95.0, 1], ['Druckerzeugnis (Test)', 1.0, 120.0, 0]] as [$desc, $qty, $price, $type]) {
		$line = new stdClass();
		$line->desc = $desc;
		$line->qty = $qty;
		$line->subprice = $price;
		$line->total_ht = round($qty * $price, 2);
		$line->total_tva = round($line->total_ht * $vat / 100, 2);
		$line->total_ttc = $line->total_ht + $line->total_tva;
		$line->tva_tx = $vat;
		$line->product_type = $type;
		$line->fk_unit = 0;
		$line->date_start = null;
		$line->date_end = null;
		$line->special_code = 0;
		$line->info_bits = 0;
		$lines[] = $line;
	}
	$invoice->id = 0;
	$invoice->ref = 'TEST-0001';
	$invoice->ref_client = 'TEST-BESTELLUNG-4711';
	$invoice->type = 0;
	$invoice->date = dol_now();
	$invoice->date_lim_reglement = dol_now() + 14 * 86400;
	$invoice->lines = $lines;
	$invoice->total_ht = array_sum(array_column($lines, 'total_ht'));
	$invoice->total_tva = array_sum(array_column($lines, 'total_tva'));
	$invoice->total_ttc = $invoice->total_ht + $invoice->total_tva;
	$invoice->thirdparty = $buyer;
	$invoice->socid = 0;
	$invoice->cond_reglement_id = 0;
	$invoice->cond_reglement_code = '';
	$invoice->array_options = [];
}

/**
 * Should an XRechnung be produced for this customer?
 *
 * Customer extrafield "E-invoice format" (lfxeformat):
 *   'xrechnung', 'xrechnung_cii', 'xrechnung_ubl' → oui ; 'zugferd' → non ;
 *   empty / 'auto' → yes whenever a Leitweg ID is set (German public buyer,
 *   which expects XRechnung or ZUGFeRD with the XRECHNUNG profile).
 *
 * A Leitweg ID entered on the invoice ($invoice) counts the same as one on the customer record.
 *
 * @param object      $buyer    Buyer (array_options loaded)
 * @param object|null $invoice  Invoice, for its own Leitweg ID
 * @return bool
 */
function auvoerechnung_wants_xrechnung($buyer, $invoice = null)
{
	$ao = (is_object($buyer) && !empty($buyer->array_options)) ? $buyer->array_options : [];
	$format = strtolower(trim((string) ($ao['options_lfxeformat'] ?? '')));
	if (in_array($format, ['xrechnung', 'xrechnung_cii', 'xrechnung_ubl'], true)) {
		return true;
	}
	if ($format === 'zugferd') {
		return false;
	}
	if (is_object($invoice) && auvoerechnung_party_leitwegid($invoice) !== '') {
		return true;
	}
	return auvoerechnung_party_leitwegid($buyer) !== '';
}

/**
 * XRechnung syntax for this customer: 'ubl' or 'cii'. Use the explicit choice
 * on the customer record ("XRechnung (UBL)" / "XRechnung (CII)"), otherwise use
 * the AUVOERECHNUNG_XRECHNUNG_SYNTAX module setting (CII by default).
 *
 * @param object $buyer  Buyer (array_options loaded)
 * @return string 'cii'|'ubl'
 */
function auvoerechnung_xrechnung_syntax($buyer)
{
	$ao = (is_object($buyer) && !empty($buyer->array_options)) ? $buyer->array_options : [];
	$format = strtolower(trim((string) ($ao['options_lfxeformat'] ?? '')));
	if ($format === 'xrechnung_ubl') {
		return 'ubl';
	}
	if ($format === 'xrechnung_cii') {
		return 'cii';
	}
	return strtolower(getDolGlobalString('AUVOERECHNUNG_XRECHNUNG_SYNTAX', 'cii')) === 'ubl' ? 'ubl' : 'cii';
}

/**
 * Build an invoice's XRechnung in the requested syntax from a single model.
 * The CII version is always produced too, so the internal business rules
 * (auvoerechnung_validate_business_rules) apply to it. Both use the same model
 * and semantic content; the UBL syntax itself is validated by its XSD.
 *
 * @param object      $invoice
 * @param object      $mysoc
 * @param array       $buildWarnings  (sortie)
 * @param array       $options        See auvoerechnung_build_model() ('profile' forced to xrechnung)
 * @param string|null $syntax         'cii'|'ubl', null = based on the customer (auvoerechnung_xrechnung_syntax)
 * @return array{syntax:string,xml:string,cii:string}
 */
function auvoerechnung_build_xrechnung($invoice, $mysoc, &$buildWarnings = [], $options = [], $syntax = null)
{
	$syntax = $syntax ?? auvoerechnung_xrechnung_syntax($invoice->thirdparty ?? null);
	$model = auvoerechnung_build_model($invoice, $mysoc, $buildWarnings, array_merge($options, ['profile' => 'xrechnung']));
	$cii = auvoerechnung_model_to_cii($model);
	return [
		'syntax' => $syntax,
		'xml'    => ($syntax === 'ubl') ? auvoerechnung_model_to_ubl($model) : $cii,
		'cii'    => $cii,
	];
}

/**
 * Date entered in an invoice date-type extrafield (timestamp), or null if empty.
 *
 * @param object $invoice
 * @param string $name     Extrafield name without the options_ prefix
 * @return int|null
 */
function auvoerechnung_invoice_date_option($invoice, $name)
{
	$v = $invoice->array_options['options_'.$name] ?? null;
	if ($v === null || $v === '') {
		return null;
	}
	if (!is_numeric($v)) {
		$v = strtotime((string) $v);
	}
	return $v ? (int) $v : null;
}

/**
 * Delivery location (BG-13): first external "delivery" (SHIPPING) contact
 * linked to the invoice. Name (BT-70): contact's full name, or their company;
 * address (BG-15): the contact's address, defaulting the country to the buyer's.
 * Null when there is no delivery contact.
 *
 * @param object $invoice
 * @return array{name:string,address:array}|null
 */
function auvoerechnung_get_ship_to($invoice)
{
	if (!is_object($invoice) || !method_exists($invoice, 'liste_contact') || empty($invoice->id)) {
		return null;
	}
	$contacts = $invoice->liste_contact(-1, 'external', 0, 'SHIPPING');
	if (!is_array($contacts) || empty($contacts[0]['id'])) {
		return null;
	}
	require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
	$c = new Contact($invoice->db);
	if ($c->fetch((int) $contacts[0]['id']) <= 0) {
		return null;
	}
	$name = trim((string) $c->getFullName(null));
	if ($name === '') {
		$name = trim((string) ($c->socname ?? ''));
	}
	$buyerCountry = !empty($invoice->thirdparty->country_code) ? $invoice->thirdparty->country_code : auvoerechnung_default_country();
	return [
		'name'    => $name,
		'address' => [
			'postcode' => trim((string) ($c->zip ?? '')),
			'line1'    => trim((string) ($c->address ?? '')),
			'city'     => trim((string) ($c->town ?? '')),
			'country'  => !empty($c->country_code) ? $c->country_code : $buyerCountry,
		],
	];
}

/**
 * Is the invoice excluded from e-invoicing ("No e-invoice" checkbox,
 * extrafield lfxnoeinvoice)? The PDF remains a regular PDF and no XRechnung is
 * generated automatically.
 *
 * @param object $invoice
 * @return bool
 */
function auvoerechnung_einvoice_disabled($invoice)
{
	if (is_object($invoice) && empty($invoice->array_options) && method_exists($invoice, 'fetch_optionals') && !empty($invoice->id)) {
		$invoice->fetch_optionals();
	}
	return !empty($invoice->array_options['options_lfxnoeinvoice']);
}

/**
 * Seller contact for XRechnung (BG-6: BT-41 name, BT-42 phone, BT-43 email;
 * BR-DE-2/5/6/7). Each field is selected in this order:
 *   1. module setting (AUVOERECHNUNG_CONTACT_NAME / _PHONE / _EMAIL);
 *   2. invoice author (full name, work/mobile phone, email);
 *   3. seller company (phone, email; no individual name).
 * The phone number keeps only characters allowed by OZG-RE (0-9 ( ) - + and
 * spaces).
 *
 * @param object $invoice  Invoice
 * @param object $mysoc    Seller company
 * @return array{name:string,phone:string,email:string}
 */
function auvoerechnung_get_seller_contact($invoice, $mysoc)
{
	$author = null;
	$authorId = (int) ($invoice->fk_user_author ?? ($invoice->user_author ?? 0));
	if ($authorId > 0 && is_object($invoice->db ?? null) && class_exists('User')) {
		$u = new User($invoice->db);
		if ($u->fetch($authorId) > 0) {
			$author = $u;
		}
	}

	$name = trim(getDolGlobalString('AUVOERECHNUNG_CONTACT_NAME', ''));
	if ($name === '' && $author !== null) {
		$name = trim(method_exists($author, 'getFullName') ? $author->getFullName(null) : trim(($author->firstname ?? '').' '.($author->lastname ?? '')));
	}

	$phone = trim(getDolGlobalString('AUVOERECHNUNG_CONTACT_PHONE', ''));
	if ($phone === '' && $author !== null) {
		$phone = trim((string) (!empty($author->office_phone) ? $author->office_phone : ($author->user_mobile ?? '')));
	}
	if ($phone === '') {
		$phone = trim((string) ($mysoc->phone ?? ''));
	}
	$phone = trim(preg_replace('/[^0-9()+\- ]/', '', $phone));

	$email = trim(getDolGlobalString('AUVOERECHNUNG_CONTACT_EMAIL', ''));
	if ($email === '' && $author !== null) {
		$email = trim((string) ($author->email ?? ''));
	}
	if ($email === '') {
		$email = trim((string) ($mysoc->email ?? ''));
	}

	return ['name' => $name, 'phone' => $phone, 'email' => $email];
}

/**
 * Pre-check the data required by XRechnung (fatal BR-DE and PEPPOL-EN16931-
 * R010/R020 rules). Returns translated messages; an empty list means the
 * XRechnung can be generated.
 *
 * @param object $invoice  Invoice (thirdparty loaded)
 * @param object $mysoc    Seller company
 * @return string[]
 */
function auvoerechnung_check_xrechnung($invoice, $mysoc)
{
	$problems = [];
	$buyer = $invoice->thirdparty ?? null;

	// BT-10 (BR-DE-15): Leitweg ID, falling back to the customer reference.
	if (auvoerechnung_invoice_leitwegid($invoice) === '' && empty($invoice->ref_client)) {
		$problems[] = auvoerechnung_trans('AuvoERechnungXrMissingBuyerRef');
	}
	// BG-6 (BR-DE-2/5/6/7)
	$contact = auvoerechnung_get_seller_contact($invoice, $mysoc);
	if ($contact['name'] === '') {
		$problems[] = auvoerechnung_trans('AuvoERechnungXrMissingContactName');
	}
	if (!preg_match('/([0-9].*){3,}/', $contact['phone'])) {
		$problems[] = auvoerechnung_trans('AuvoERechnungXrMissingContactPhone');
	}
	if (!preg_match('/^[^@\s]+@([^@.\s]+\.)+[^@.\s]+$/', $contact['email'])) {
		$problems[] = auvoerechnung_trans('AuvoERechnungXrMissingContactEmail');
	}
	// BT-34 (PEPPOL-EN16931-R020): company email address.
	if (empty($mysoc->email)) {
		$problems[] = auvoerechnung_trans('AuvoERechnungXrMissingSellerEmail');
	}
	// BT-49 (PEPPOL-EN16931-R010): buyer's Leitweg ID (0204) or email address.
	if (auvoerechnung_invoice_leitwegid($invoice) === '' && auvoerechnung_get_buyer_email($buyer, $invoice->db ?? null) === '') {
		$problems[] = auvoerechnung_trans('AuvoERechnungXrMissingBuyerAddress');
	}
	// BT-37/38, BT-52/53 (BR-DE-3/4/8/9).
	if (empty($mysoc->zip) || empty($mysoc->town)) {
		$problems[] = auvoerechnung_trans('AuvoERechnungXrMissingSellerZipTown');
	}
	if (empty($buyer->zip) || empty($buyer->town)) {
		$problems[] = auvoerechnung_trans('AuvoERechnungXrMissingBuyerZipTown');
	}
	// BG-16 (BR-DE-1, BR-DE-23-a): bank transfer requires an IBAN.
	$paymentMeans = getDolGlobalString('AUVOERECHNUNG_PAYMENT_MEANS', '30');
	if (in_array($paymentMeans, ['30', '58'], true)) {
		$bank = auvoerechnung_get_bank_account($invoice->db ?? null, $invoice);
		if (empty($bank['iban'])) {
			$problems[] = auvoerechnung_trans('AuvoERechnungXrMissingIban');
		}
	}
	return $problems;
}

/**
 * Find a customer's email: first on the customer record, otherwise on the first contact.
 */
function auvoerechnung_get_buyer_email($buyer, $db)
{
	if (!empty($buyer->email)) {
		return $buyer->email;
	}
	if (empty($buyer->id) || !is_object($db)) {
		return '';
	}

	$sql = "SELECT email FROM ".MAIN_DB_PREFIX."socpeople"
		." WHERE fk_soc = ".((int) $buyer->id)
		." AND email IS NOT NULL AND email != ''";
	if (function_exists('getEntity')) {
		$sql .= " AND entity IN (".getEntity('socpeople').")";
	}
	$sql .= " ORDER BY rowid ASC LIMIT 1";
	$res = $db->query($sql);
	if (!$res) {
		return '';
	}
	$obj = $db->fetch_object($res);
	return ($obj && !empty($obj->email)) ? $obj->email : '';
}

/**
 * Resolve the path to an invoice's main PDF, or null if it does not exist.
 * Dolibarr convention: <entity_dir>/<ref>/<ref>.pdf; fall back to last_main_doc
 * (relative to DOL_DATA_ROOT) if the conventional file is missing.
 *
 * @param string $ref          Invoice reference
 * @param int    $entity       Invoice entity (multicompany)
 * @param string $lastMainDoc  Value of llx_facture.last_main_doc (optional)
 * @return string|null
 */
function auvoerechnung_invoice_pdf_path($ref, $entity, $lastMainDoc = '')
{
	global $conf;

	$dir = !empty($conf->facture->multidir_output[$entity])
		? $conf->facture->multidir_output[$entity]
		: ($conf->facture->dir_output ?? '');
	$safeRef = dol_sanitizeFileName($ref);
	if (!empty($dir)) {
		$path = $dir.'/'.$safeRef.'/'.$safeRef.'.pdf';
		if (file_exists($path)) {
			return $path;
		}
	}
	if (!empty($lastMainDoc) && defined('DOL_DATA_ROOT')) {
		$candidate = DOL_DATA_ROOT.'/'.ltrim($lastMainDoc, '/');
		if (file_exists($candidate)) {
			return $candidate;
		}
	}
	// ODT template fallback: its PDF is named {ref}_{template}.pdf (not {ref}.pdf),
	// and last_main_doc may point to the .odt. In that case, use the newest PDF
	// in the invoice folder, excluding the Chorus PDF ({ref}-CHORUS.pdf).
	if (!empty($dir)) {
		$cands = glob($dir.'/'.$safeRef.'/*.pdf');
		if (!empty($cands)) {
			$cands = array_values(array_filter($cands, function ($f) {
				return !preg_match('/-CHORUS\.pdf$/i', $f);
			}));
			if (!empty($cands)) {
				usort($cands, function ($a, $b) {
					return (int) filemtime($b) - (int) filemtime($a);
				});
				return $cands[0];
			}
		}
	}
	return null;
}

/**
 * Extract the Factur-X XML embedded in a PDF (read-only, in-process: the atgp
 * Reader uses smalot/pdfparser, avoiding conflicts with FPDF/TCPDF).
 *
 * @param string $pdfPath PDF path
 * @return string|null    XML, or null if missing/unreadable
 */
function auvoerechnung_extract_xml_from_pdf($pdfPath)
{
	require_once dirname(__DIR__, 2).'/vendor/autoload.php';
	try {
		$reader = new \Atgp\FacturX\Reader();
		return $reader->extractXML((string) file_get_contents($pdfPath), false);
	} catch (\Throwable $e) {
		return null;
	}
}

/**
 * Truncate an IBAN for display: "FR76...0185". Empty if no IBAN is available.
 */
function auvoerechnung_iban_short($iban)
{
	if (empty($iban)) {
		return '';
	}
	return substr($iban, 0, 4).'...'.substr($iban, -4);
}

if (!function_exists('auvoerechnung_xml_encode')) {
	/**
	 * Escape a value for insertion into XML (ENT_XML1).
	 */
	function auvoerechnung_xml_encode($str)
	{
		return htmlspecialchars((string) $str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('auvoerechnung_format_amount')) {
	/**
	 * Format an amount to two decimal places (EN16931 AmountType).
	 */
	function auvoerechnung_format_amount($amount)
	{
		return number_format((float) $amount, 2, '.', '');
	}
}

/**
 * Format a quantity (BT-129) to up to four decimal places, removing trailing zeros.
 */
function auvoerechnung_format_qty($qty)
{
	$s = number_format((float) $qty, 4, '.', '');
	$s = rtrim($s, '0');
	return rtrim($s, '.');
}

/**
 * Converts a Dolibarr line description (WYSIWYG HTML) to plain text: line
 * breaks from <br> and block ends are kept, tags removed, entities decoded
 * (&nbsp; becomes a space), so the XML carries the text and not "&amp;nbsp;".
 *
 * @param string $html
 * @return string
 */
function auvoerechnung_html_to_text($html)
{
	$s = (string) $html;
	$s = preg_replace('~<br\s*/?>|</(?:p|div|li|h[1-6]|tr)>~i', "\n", $s);
	$s = strip_tags($s);
	$s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$s = str_replace(["\xC2\xA0", "\r\n", "\r", "\t"], [' ', "\n", "\n", ' '], $s);
	$out = [];
	foreach (explode("\n", $s) as $l) {
		$l = trim(preg_replace('/ {2,}/', ' ', $l));
		if ($l !== '' || (end($out) !== '' && !empty($out))) {
			$out[] = $l;
		}
	}
	return trim(implode("\n", $out));
}

/**
 * Item data of a line (BG-31): with a product, its label is the name
 * (BT-153), the line text the description (BT-154), its ref the seller's
 * item ID (BT-155) and a valid EAN/GTIN barcode the standard ID (BT-157).
 * Free-text lines keep the line text as the name.
 *
 * @param object $line  Dolibarr invoice line (fetch_lines() loads the product fields)
 * @param string $desc  Line text, already converted to plain text
 * @return array{name:string, description:string, seller_id:string, gtin:string}
 */
function auvoerechnung_line_item($line, $desc)
{
	$label = (!empty($line->fk_product) && isset($line->product_label)) ? auvoerechnung_html_to_text((string) $line->product_label) : '';
	$item = [
		'name'        => $label !== '' ? $label : $desc,
		'description' => ($label !== '' && $desc !== '' && $desc !== $label) ? $desc : '',
		'seller_id'   => !empty($line->fk_product) ? trim((string) ($line->product_ref ?? '')) : '',
		'gtin'        => '',
	];
	$barcode = preg_replace('/\s+/', '', (string) ($line->product_barcode ?? ''));
	if (!empty($line->fk_product) && auvoerechnung_is_gtin($barcode)) {
		$item['gtin'] = $barcode;
	}
	return $item;
}

/**
 * Whether a barcode is a GTIN (EAN-8, UPC-A, EAN-13, GTIN-14) with a valid
 * check digit, as required for scheme 0160 (GS1).
 *
 * @param string $code
 * @return bool
 */
function auvoerechnung_is_gtin($code)
{
	if (!preg_match('/^(\d{8}|\d{12}|\d{13}|\d{14})$/', $code)) {
		return false;
	}
	$digits = array_reverse(str_split($code));
	$sum = 0;
	foreach (array_slice($digits, 1) as $i => $d) {
		$sum += (int) $d * ($i % 2 === 0 ? 3 : 1);
	}
	return (10 - $sum % 10) % 10 === (int) $digits[0];
}

/**
 * Net unit price of a line (BT-146). The price after the line discount when
 * quantity x price gives the line total to the cent (as on the PDF), else the
 * line total divided by the quantity (prices with more than 4 decimals, lines
 * changed by hand).
 *
 * @param object $line       Dolibarr invoice line
 * @param float  $sign       -1 for a credit note
 * @param float  $qty        absolute quantity
 * @param float  $lineTotal  signed, rounded line total
 * @return float
 */
function auvoerechnung_line_unit_price($line, $sign, $qty, $lineTotal)
{
	if ($qty == 0.0) {
		return $lineTotal;
	}
	if (isset($line->subprice) && is_numeric($line->subprice)) {
		$net = round($sign * (float) $line->subprice * (1 - (float) ($line->remise_percent ?? 0) / 100), 4);
		if (abs(round($net * $qty, 2) - $lineTotal) < 0.005) {
			return $net;
		}
	}
	return $lineTotal / $qty;
}

/**
 * Whether a TCPDF font embeds its glyphs in the PDF (PDF/A-3 needs this).
 * The base-14 fonts (helvetica, times, courier...) are never embedded.
 *
 * @param string $font  Value of MAIN_PDF_FORCE_FONT
 * @return bool|null    null when the font is not in the TCPDF font folder
 */
function auvoerechnung_pdf_font_embedded($font)
{
	$fonts = auvoerechnung_list_pdf_fonts();
	return array_key_exists($font, $fonts) ? $fonts[$font] : null;
}

/**
 * Format a net unit price (BT-146) to two decimal places, extending to four
 * when precision requires it (e.g. 100/3 = 33.3333) to limit quantity × price drift.
 */
function auvoerechnung_format_unit_price($price)
{
	$price = (float) $price;
	if (abs(round($price, 2) - round($price, 4)) < 0.0000001) {
		return number_format($price, 2, '.', '');
	}
	// 14.3920 -> 14.392 (price after a 20 % discount on 17.99)
	return preg_replace('/(\.\d\d\d*?)0+$/', '$1', number_format($price, 4, '.', ''));
}

/**
 * Query the GitHub API for the module's latest published release.
 * Cache the result (success OR failure) in a Dolibarr constant for 24 hours to
 * avoid retrying a slow network request every time the admin page is opened.
 *
 * @param object $db               Handle DB Dolibarr
 * @param string $currentVersion   Current module version (e.g. "1.1.0")
 * @return array|null              ['version' => 'x.y.z', 'url' => 'https://...']
 *                                 if a newer version exists, otherwise null
 */
function auvoerechnung_check_latest_release($db, $currentVersion)
{
	// Can be switched off (firewalls without internet access, privacy): the
	// only outgoing request of the module, to api.github.com, admin page only.
	if (!getDolGlobalInt('AUVOERECHNUNG_UPDATE_CHECK', 1)) {
		return null;
	}
	$now = time();
	$cacheRaw = getDolGlobalString('AUVOERECHNUNG_UPDATE_CHECK_CACHE', '');
	$cache = !empty($cacheRaw) ? json_decode($cacheRaw, true) : null;

	$latest = null;
	$htmlUrl = '';
	if (is_array($cache) && isset($cache['ts']) && ($now - (int) $cache['ts']) < 86400) {
		$latest  = $cache['version'] ?? null;
		$htmlUrl = $cache['url']     ?? '';
	} else {
		$latest = null;
		$htmlUrl = '';
		if (function_exists('curl_init')) {
			$url = 'https://api.github.com/repos/Fips81/dolibarr-auvo-erechnung/releases/latest';
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_USERAGENT, 'AuvoERechnung-UpdateCheck');
			curl_setopt($ch, CURLOPT_TIMEOUT, 5);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
			$json = @curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			if ($httpCode === 200 && !empty($json)) {
				$data = json_decode($json, true);
				if (is_array($data) && !empty($data['tag_name'])) {
					$latest  = ltrim($data['tag_name'], 'v');
					$htmlUrl = $data['html_url'] ?? '';
					// Defensive validation: accept only an official github.com URL for this repository.
					if (!preg_match('#^https://github\.com/Fips81/dolibarr-auvo-erechnung/#', $htmlUrl)) {
						$htmlUrl = 'https://github.com/Fips81/dolibarr-auvo-erechnung/releases';
					}
				}
			}
		}

		// Cache failures too (version null): an unreachable GitHub must not slow
		// down the admin page by five seconds on every visit for 24 hours.
		dolibarr_set_const($db, 'AUVOERECHNUNG_UPDATE_CHECK_CACHE', json_encode([
			'ts'      => $now,
			'version' => $latest,
			'url'     => $htmlUrl,
		]), 'chaine', 0, '', 0);
	}

	if (!empty($latest) && version_compare($latest, $currentVersion, '>')) {
		return ['version' => $latest, 'url' => $htmlUrl];
	}
	return null;
}
