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
 * Internal checks for EN16931 business rules (a Schematron subset).
 *
 * The XSD does not contain BR-* rules. Before embedding XML in the PDF, this
 * module checks in PHP the calculation and consistency rules most commonly
 * checked by validators (Mustang/FNFE, Chorus Pro, PDP). It is NOT a replacement
 * for the official Schematron (200+ rules, XSLT 2.0, which cannot run with PHP's
 * XSL extension, limited to XSLT 1.0); it is a safety net for common rejections.
 *
 * Depends on auvoerechnung.lib.php (constant AUVOERECHNUNG_FR_OVERSEAS_COUNTRIES),
 * which the hook, API, and tests always include before this file.
 */

/**
 * Returns a translated rule message prefixed by its rule code ("BR-XX: …").
 * The code remains untranslated as a stable key for tests and syslog searches.
 *
 * @param string $rule  Rule code (e.g. 'BR-CO-10'); '' for no prefix
 * @param string $key   Translation key (auvoerechnung.lang file)
 * @param mixed  ...$args Translation %s parameters (up to 4)
 * @return string
 */
function auvoerechnung_rule_msg($rule, $key, ...$args)
{
	$msg = auvoerechnung_trans_raw($key, ...$args);
	return $rule !== '' ? $rule.' : '.$msg : $msg;
}

/**
 * Validates XML against the bundled schema for its syntax: Factur-X EN16931
 * (CII) or UBL 2.1 Invoice/CreditNote (OASIS). Shared by the hook, unit tests,
 * and integration tests so structural validation has a single implementation.
 *
 * @param string $xml         XML to validate
 * @param string $modulePath  Module root (used to locate the bundled XSD)
 * @return string|null        Error message if invalid, null if valid
 *                            (or if the XSD is missing; well-formedness only)
 */
function auvoerechnung_validate_xsd($xml, $modulePath)
{
	if (empty($xml)) {
		return auvoerechnung_rule_msg('', 'AuvoERechnungXsdEmpty');
	}

	$prevUseErrors = libxml_use_internal_errors(true);
	libxml_clear_errors();

	$firstError = function ($fallback) use ($prevUseErrors) {
		$errs = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($prevUseErrors);
		return !empty($errs) ? trim($errs[0]->message) : $fallback;
	};

	$dom = new DOMDocument();
	if (!$dom->loadXML($xml)) {
		return auvoerechnung_rule_msg('', 'AuvoERechnungXsdMalformed', $firstError('-'));
	}

	// Select syntax from the root element: UBL 2.1 (Invoice/CreditNote,
	// bundled OASIS XSD) or CII (Factur-X EN16931 XSD).
	$rootNs = $dom->documentElement ? (string) $dom->documentElement->namespaceURI : '';
	if (preg_match('/^urn:oasis:names:specification:ubl:schema:xsd:(Invoice|CreditNote)-2$/', $rootNs, $mUbl)) {
		$xsdPath = $modulePath.'/vendor/oasis/ubl-2.1/xsd/maindoc/UBL-'.$mUbl[1].'-2.1.xsd';
	} else {
		$xsdPath = $modulePath.'/vendor/atgp/factur-x/xsd/factur-x/en16931/Factur-X_1.09.2_EN16931.xsd';
	}
	if (!file_exists($xsdPath)) {
		// Do not block when the bundled XSD is missing; well-formedness was checked.
		libxml_clear_errors();
		libxml_use_internal_errors($prevUseErrors);
		return null;
	}

	if (!$dom->schemaValidate($xsdPath)) {
		return auvoerechnung_rule_msg('', 'AuvoERechnungXsdInvalid', $firstError('-'));
	}

	libxml_clear_errors();
	libxml_use_internal_errors($prevUseErrors);
	return null;
}

/**
 * Validates a CrossIndustryInvoice XML against a subset of EN16931 business
 * rules (BR-*, BR-CO-*, BR-S/E/K/G/AE-*, BR-IC-*).
 *
 * @param string $xml Generated CrossIndustryInvoice XML
 * @return array      List of violations ("BR-XX: message"), empty if compliant
 */
function auvoerechnung_validate_business_rules($xml)
{
	$violations = [];

	$prevUseErrors = libxml_use_internal_errors(true);
	$dom = new DOMDocument();
	if (!$dom->loadXML($xml)) {
		libxml_clear_errors();
		libxml_use_internal_errors($prevUseErrors);
		return ['XML : document non parsable'];
	}
	libxml_clear_errors();
	libxml_use_internal_errors($prevUseErrors);

	$xp = new DOMXPath($dom);
	$xp->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
	$xp->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
	$xp->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');
	$xp->registerNamespace('qdt', 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100');

	$num = function ($query, $context = null) use ($xp) {
		$nodes = $context ? $xp->query($query, $context) : $xp->query($query);
		return ($nodes && $nodes->length > 0) ? (float) $nodes->item(0)->textContent : null;
	};
	$str = function ($query, $context = null) use ($xp) {
		$nodes = $context ? $xp->query($query, $context) : $xp->query($query);
		return ($nodes && $nodes->length > 0) ? trim($nodes->item(0)->textContent) : null;
	};
	$eq = function ($a, $b) {
		return abs($a - $b) < 0.005;
	};

	// === BR-16: at least one line ===
	$lines = $xp->query('//ram:IncludedSupplyChainTradeLineItem');
	if ($lines->length === 0) {
		$violations[] = auvoerechnung_rule_msg('BR-16', 'AuvoERechnungRuleBR16');
	}

	// === Lines: BR-27 (net price >= 0) + collection for BR-CO-10 and tax bases ===
	$sumLines = 0.0;
	$lineBasis = []; // "cat|rate" => sum of LineTotalAmount values
	foreach ($lines as $i => $line) {
		$n = $i + 1;
		$price = $num('.//ram:NetPriceProductTradePrice/ram:ChargeAmount', $line);
		if ($price !== null && $price < 0) {
			$violations[] = auvoerechnung_rule_msg('BR-27', 'AuvoERechnungRuleBR27', $n, $price);
		}
		$lineTotal = $num('.//ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', $line);
		if ($lineTotal !== null) {
			$sumLines += $lineTotal;
		}
		$cat = $str('.//ram:ApplicableTradeTax/ram:CategoryCode', $line);
		$rate = $num('.//ram:ApplicableTradeTax/ram:RateApplicablePercent', $line);
		$key = $cat.'|'.(float) $rate;
		$lineBasis[$key] = ($lineBasis[$key] ?? 0.0) + (float) $lineTotal;

		// Category/rate consistency at line level.
		if ($cat === 'S' && $rate !== null && $rate <= 0) {
			$violations[] = auvoerechnung_rule_msg('BR-S-05', 'AuvoERechnungRuleS05', $n);
		}
		if (in_array($cat, ['E', 'K', 'G', 'AE', 'Z'], true) && $rate !== null && $rate != 0) {
			$violations[] = auvoerechnung_rule_msg('BR-'.$cat.'-05', 'AuvoERechnungRuleCat05', $n, $cat, $rate);
		}
	}

	// === Document-level allowances/charges (BG-20/BG-21) ===
	$sumAllowances = 0.0;
	$sumCharges = 0.0;
	foreach ($xp->query('//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeAllowanceCharge') as $ac) {
		$indicator = $str('.//ram:ChargeIndicator/udt:Indicator', $ac);
		$amount = (float) $num('.//ram:ActualAmount', $ac);
		$cat = $str('.//ram:CategoryTradeTax/ram:CategoryCode', $ac);
		$rate = $num('.//ram:CategoryTradeTax/ram:RateApplicablePercent', $ac);
		$key = $cat.'|'.(float) $rate;
		if ($indicator === 'true') {
			$sumCharges += $amount;
			$lineBasis[$key] = ($lineBasis[$key] ?? 0.0) + $amount;
		} else {
			$sumAllowances += $amount;
			$lineBasis[$key] = ($lineBasis[$key] ?? 0.0) - $amount;
		}
	}

	// === Totals (BG-22) ===
	$ms = '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/';
	$btLineTotal   = $num($ms.'ram:LineTotalAmount');
	$btAllowance   = $num($ms.'ram:AllowanceTotalAmount') ?? 0.0;
	$btCharge      = $num($ms.'ram:ChargeTotalAmount') ?? 0.0;
	$btTaxBasis    = $num($ms.'ram:TaxBasisTotalAmount');
	// BT-110: EN16931 permits two TaxTotalAmount values (invoice currency BT-110
	// and VAT currency BT-111); use the one whose currencyID matches the invoice.
	$invoiceCurrency = $str('//ram:ApplicableHeaderTradeSettlement/ram:InvoiceCurrencyCode');
	$btTaxTotal = null;
	foreach ($xp->query($ms.'ram:TaxTotalAmount') as $taxTotalNode) {
		$nodeCurrency = $taxTotalNode->getAttribute('currencyID');
		if ($btTaxTotal === null || $nodeCurrency === $invoiceCurrency || $nodeCurrency === '') {
			$btTaxTotal = (float) $taxTotalNode->textContent;
			if ($nodeCurrency === $invoiceCurrency) {
				break;
			}
		}
	}
	$btTaxTotal    = $btTaxTotal ?? 0.0;
	$btGrandTotal  = $num($ms.'ram:GrandTotalAmount');
	$btPrepaid     = $num($ms.'ram:TotalPrepaidAmount') ?? 0.0;
	$btDuePayable  = $num($ms.'ram:DuePayableAmount');

	if ($btLineTotal !== null && !$eq($btLineTotal, $sumLines)) {
		$violations[] = auvoerechnung_rule_msg('BR-CO-10', 'AuvoERechnungRuleCO10', sprintf('%.2f', $btLineTotal), sprintf('%.2f', $sumLines));
	}
	if (!$eq($btAllowance, $sumAllowances)) {
		$violations[] = auvoerechnung_rule_msg('BR-CO-11', 'AuvoERechnungRuleCO11', sprintf('%.2f', $btAllowance), sprintf('%.2f', $sumAllowances));
	}
	if (!$eq($btCharge, $sumCharges)) {
		$violations[] = auvoerechnung_rule_msg('BR-CO-12', 'AuvoERechnungRuleCO12', sprintf('%.2f', $btCharge), sprintf('%.2f', $sumCharges));
	}
	if ($btTaxBasis !== null && $btLineTotal !== null && !$eq($btTaxBasis, $btLineTotal - $btAllowance + $btCharge)) {
		$violations[] = auvoerechnung_rule_msg('BR-CO-13', 'AuvoERechnungRuleCO13', sprintf('%.2f', $btTaxBasis), sprintf('%.2f', $btLineTotal - $btAllowance + $btCharge));
	}
	if ($btGrandTotal !== null && $btTaxBasis !== null && !$eq($btGrandTotal, $btTaxBasis + $btTaxTotal)) {
		$violations[] = auvoerechnung_rule_msg('BR-CO-15', 'AuvoERechnungRuleCO15', sprintf('%.2f', $btGrandTotal), sprintf('%.2f', $btTaxBasis), sprintf('%.2f', $btTaxTotal));
	}
	if ($btDuePayable !== null && $btGrandTotal !== null && !$eq($btDuePayable, $btGrandTotal - $btPrepaid)) {
		$violations[] = auvoerechnung_rule_msg('BR-CO-16', 'AuvoERechnungRuleCO16', sprintf('%.2f', $btDuePayable), sprintf('%.2f', $btGrandTotal), sprintf('%.2f', $btPrepaid));
	}

	// === VAT breakdown (BG-23): BR-CO-14, BR-CO-17, bases by category ===
	$sumCalculated = 0.0;
	$hasK = false;
	foreach ($xp->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax') as $tax) {
		$cat = $str('ram:CategoryCode', $tax);
		$rate = $num('ram:RateApplicablePercent', $tax);
		$basis = (float) $num('ram:BasisAmount', $tax);
		$calc = (float) $num('ram:CalculatedAmount', $tax);
		$sumCalculated += $calc;
		if ($cat === 'K') {
			$hasK = true;
		}

		// BR-CO-17: tax amount = base x rate, rounded to 2 decimals (+/- 0.01).
		if ($rate !== null && abs($calc - round($basis * $rate / 100, 2)) > 0.0105) {
			$violations[] = auvoerechnung_rule_msg('BR-CO-17', 'AuvoERechnungRuleCO17', sprintf('%.2f', $calc), sprintf('%.2f', $basis), sprintf('%.2f', $rate), sprintf('%.2f', round($basis * $rate / 100, 2)));
		}
		// Base per (category, rate) = line sum - allowances + charges in that category.
		$key = $cat.'|'.(float) $rate;
		if (isset($lineBasis[$key]) && !$eq($basis, $lineBasis[$key])) {
			$violations[] = auvoerechnung_rule_msg('BR-'.$cat.'-08', 'AuvoERechnungRuleCat08', $cat, (float) $rate, sprintf('%.2f', $basis), sprintf('%.2f', $lineBasis[$key]));
		}
		// Exemptions require a reason (BR-E-10, BR-K-10, BR-G-10, BR-AE-10).
		if (in_array($cat, ['E', 'K', 'G', 'AE'], true)) {
			$reason = $str('ram:ExemptionReason', $tax);
			$reasonCode = $str('ram:ExemptionReasonCode', $tax);
			if (empty($reason) && empty($reasonCode)) {
				$violations[] = auvoerechnung_rule_msg('BR-'.$cat.'-10', 'AuvoERechnungRuleCat10', $cat);
			}
		}
	}
	if (!$eq($btTaxTotal, $sumCalculated)) {
		$violations[] = auvoerechnung_rule_msg('BR-CO-14', 'AuvoERechnungRuleCO14', sprintf('%.2f', $btTaxTotal), sprintf('%.2f', $sumCalculated));
	}

	// === BR-61: bank transfer (30/58) requires an IBAN ===
	foreach ($xp->query('//ram:SpecifiedTradeSettlementPaymentMeans') as $pm) {
		$typeCode = $str('ram:TypeCode', $pm);
		if (in_array($typeCode, ['30', '58'], true)) {
			$iban = $str('ram:PayeePartyCreditorFinancialAccount/ram:IBANID', $pm);
			if (empty($iban)) {
				$violations[] = auvoerechnung_rule_msg('BR-61', 'AuvoERechnungRuleBR61', $typeCode);
			}
		}
	}

	// === BR-IC-11/12: intra-community supply (K) ===
	if ($hasK) {
		$deliveryDate = $str('//ram:ApplicableHeaderTradeDelivery/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString');
		$period = $xp->query('//ram:ApplicableHeaderTradeSettlement/ram:BillingSpecifiedPeriod');
		if (empty($deliveryDate) && $period->length === 0) {
			$violations[] = auvoerechnung_rule_msg('BR-IC-11', 'AuvoERechnungRuleIC11');
		}
		$shipToCountry = $str('//ram:ApplicableHeaderTradeDelivery/ram:ShipToTradeParty/ram:PostalTradeAddress/ram:CountryID');
		if (empty($shipToCountry)) {
			$violations[] = auvoerechnung_rule_msg('BR-IC-12', 'AuvoERechnungRuleIC12');
		}
		// BR-IC-02: K => seller intra-community VAT ID is required.
		$sellerVat = $str('//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]');
		if (empty($sellerVat)) {
			$violations[] = auvoerechnung_rule_msg('BR-IC-02', 'AuvoERechnungRuleIC02');
		}
	}

	// === BR-AE-02: reverse charge => seller AND buyer VAT IDs are required ===
	$hasAE = $xp->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax[ram:CategoryCode="AE"]')->length > 0;
	if ($hasAE) {
		$sellerVat = $str('//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]');
		$buyerVat = $str('//ram:BuyerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]');
		if (empty($sellerVat) || empty($buyerVat)) {
			$violations[] = auvoerechnung_rule_msg('BR-AE-02', 'AuvoERechnungRuleAE02');
		}
	}

	// === BR-CO-26: BT-29 (ID/GlobalID), BT-30 (registry) or BT-31 (VAT, scheme VA).
	// Tax ID BT-32 (scheme FC) is NOT sufficient (official EN16931 Schematron).
	$sellerId = $str('//ram:SellerTradeParty/ram:ID') ?: $str('//ram:SellerTradeParty/ram:GlobalID');
	$sellerLegal = $str('//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID');
	$sellerVat = $str('//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]');
	if (empty($sellerId) && empty($sellerLegal) && empty($sellerVat)) {
		$violations[] = auvoerechnung_rule_msg('BR-CO-26', 'AuvoERechnungRuleCO26');
	}

	// === BR-CO-09: VAT number (schemeID VA) prefixed with an ISO 3166 country code ===
	foreach ($xp->query('//ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]') as $vaNode) {
		$vaVal = trim($vaNode->textContent);
		if (!preg_match('/^[A-Z]{2}/', $vaVal)) {
			$violations[] = auvoerechnung_rule_msg('BR-CO-09', 'AuvoERechnungRuleCO09', $vaVal);
		}
	}

	// === BR-09 / BR-11: seller and buyer countries are required ===
	if (empty($str('//ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID'))) {
		$violations[] = auvoerechnung_rule_msg('BR-09', 'AuvoERechnungRuleBR09');
	}
	if (empty($str('//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CountryID'))) {
		$violations[] = auvoerechnung_rule_msg('BR-11', 'AuvoERechnungRuleBR11');
	}

	// === BR-CO-25: amount due > 0 requires a due date or payment terms ===
	if ($btDuePayable !== null && $btDuePayable > 0) {
		$dueDate = $str('//ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString');
		$terms = $str('//ram:SpecifiedTradePaymentTerms/ram:Description');
		if (empty($dueDate) && empty($terms)) {
			$violations[] = auvoerechnung_rule_msg('BR-CO-25', 'AuvoERechnungRuleCO25');
		}
	}

	// =========================================================================
	// French rules from XP Z12-012 §4.5.1 (electronic invoicing reform baseline).
	// This subset can be checked from the generated XML; rules requiring the PPF
	// directory (BR-FR-10/11) or application context are outside this check.
	// These rules apply only to sellers established in France (mainland and
	// equivalent overseas departments, same list as BR-FR-MAP-14). Factur-X is
	// also used outside France (ZUGFeRD), where the French reform baseline does
	// not apply. The EN16931 rules above remain universal.
	// =========================================================================
	$sellerCountry = $str('//ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID');
	if ($sellerCountry === 'FR' || in_array($sellerCountry, AUVOERECHNUNG_FR_OVERSEAS_COUNTRIES, true)) {

		// === BR-FR-01 / BR-FR-02: invoice identifier (BT-1) ===
		$bt1 = $str('//rsm:ExchangedDocument/ram:ID');
		if ($bt1 !== null && $bt1 !== '') {
			$bt1Len = function_exists('mb_strlen') ? mb_strlen($bt1) : strlen($bt1);
			if ($bt1Len > 35) {
				$violations[] = auvoerechnung_rule_msg('BR-FR-01', 'AuvoERechnungRuleFR01', $bt1Len);
			}
			// Allowed characters: alphanumerics, hyphen, plus, underscore, and
			// slash. Spaces are not allowed (removed from the list in v1.1).
			if (!preg_match('/^[A-Za-z0-9+_\/-]+$/', $bt1)) {
				$violations[] = auvoerechnung_rule_msg('BR-FR-02', 'AuvoERechnungRuleFR02', $bt1);
			}
		}

		// === BR-FR-04: document type (BT-3), closed list accepted in France ===
		// Simples 380/389/393/501, acomptes 386/500, rectificatives 384/471/472/473,
		// credit notes 261/262/381/396/502/503. Any other UNTDID 1001 code is prohibited.
		$bt3 = $str('//rsm:ExchangedDocument/ram:TypeCode');
		$frTypeCodes = ['380', '389', '393', '501', '386', '500', '384', '471', '472', '473', '261', '262', '381', '396', '502', '503'];
		if ($bt3 !== null && $bt3 !== '' && !in_array($bt3, $frTypeCodes, true)) {
			$violations[] = auvoerechnung_rule_msg('BR-FR-04', 'AuvoERechnungRuleFR04', $bt3);
		}

		// === BR-FR-09: SIRET/SIREN consistency for each party ===
		// The SIRET (schemeID 0009) must contain 14 digits, and its first 9 digits
		// must match the SIREN (schemeID 0002) when both are present.
		foreach (['Seller' => 'AuvoERechnungRulePartySeller', 'Buyer' => 'AuvoERechnungRulePartyBuyer'] as $partyTag => $partyKey) {
			$partyLabel = auvoerechnung_rule_msg('', $partyKey);
			$base = '//ram:'.$partyTag.'TradeParty';
			$siret = $str($base.'/ram:GlobalID[@schemeID="0009"]');
			if ($siret === null || $siret === '') {
				// Chorus Pro profile: the SIRET is in SpecifiedLegalOrganization.
				$siret = $str($base.'/ram:SpecifiedLegalOrganization/ram:ID[@schemeID="0009"]');
			}
			$siren = $str($base.'/ram:SpecifiedLegalOrganization/ram:ID[@schemeID="0002"]');
			if ($siret !== null && $siret !== '') {
				if (!preg_match('/^\d{14}$/', $siret)) {
					$violations[] = auvoerechnung_rule_msg('BR-FR-09', 'AuvoERechnungRuleFR09Length', $partyLabel, $siret);
				} elseif ($siren !== null && $siren !== '' && strncmp($siret, $siren, 9) !== 0) {
					$violations[] = auvoerechnung_rule_msg('BR-FR-09', 'AuvoERechnungRuleFR09Mismatch', $partyLabel, $siret, $siren);
				}
			}
		}

		// === BR-FR-CO-04/BR-FR-CO-05: reference to a preceding invoice (BG-3) ===
		// Corrective invoices: exactly ONE reference (BT-25) with its date (BT-26).
		// In this case, the generator references ONLY the replaced invoice; applied
		// deposits remain in BT-113 (see auvoerechnung_get_preceding_invoices).
		// Credit notes: at least one reference with its date. The "line reference"
		// variant EXT-FR-FE-136 belongs to the EXTENDED-CTC-FR profile, not emitted here.
		$precedingDocs = $xp->query('//ram:ApplicableHeaderTradeSettlement/ram:InvoiceReferencedDocument');
		$hasDatedRef = false;
		foreach ($precedingDocs as $prd) {
			if (!empty($str('ram:FormattedIssueDateTime/qdt:DateTimeString', $prd))) {
				$hasDatedRef = true;
				break;
			}
		}
		if (in_array($bt3, ['384', '471', '472', '473'], true)) {
			if ($precedingDocs->length !== 1) {
				$violations[] = auvoerechnung_rule_msg('BR-FR-CO-04', 'AuvoERechnungRuleFRCO04Count', $bt3, $precedingDocs->length);
			} elseif (!$hasDatedRef) {
				$violations[] = auvoerechnung_rule_msg('BR-FR-CO-04', 'AuvoERechnungRuleFRCO04Date', $bt3);
			}
		}
		if (in_array($bt3, ['261', '381', '396', '502', '503'], true)) {
			if ($precedingDocs->length === 0) {
				$violations[] = auvoerechnung_rule_msg('BR-FR-CO-05', 'AuvoERechnungRuleFRCO05Missing', $bt3);
			} elseif (!$hasDatedRef) {
				$violations[] = auvoerechnung_rule_msg('BR-FR-CO-05', 'AuvoERechnungRuleFRCO05Date', $bt3);
			}
		}

		// === BR-FR-23: electronic address 0225, closed character set ===
		// Alphanumerics, hyphen, underscore, and period (more restrictive than
		// BT-1: neither '+' nor '/' is allowed).
		foreach ($xp->query('//ram:URIUniversalCommunication/ram:URIID[@schemeID="0225"]') as $uriNode) {
			$uriVal = trim($uriNode->textContent);
			if ($uriVal !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $uriVal)) {
				$violations[] = auvoerechnung_rule_msg('BR-FR-23', 'AuvoERechnungRuleFR23', $uriVal);
			}
		}
	}

	// =========================================================================
	// XRechnung rules (German KoSIT CIUS): subset of fatal XRechnung 3.0
	// Schematron rules that can be checked from the generated XML.
	// Applied only when BT-24 identifies XRechnung; ZUGFeRD EN16931 is not
	// subject to them. The official KoSIT validator remains authoritative.
	// =========================================================================
	$bt24 = (string) $str('//rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID');
	if (strpos($bt24, 'urn:xeinkauf.de:kosit:xrechnung_') !== false) {
		$seller = '//ram:SellerTradeParty';
		$buyerP = '//ram:BuyerTradeParty';
		// PEPPOL-EN16931-R001: business process (BT-23).
		if (empty($str('//rsm:ExchangedDocumentContext/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID'))) {
			$violations[] = auvoerechnung_rule_msg('PEPPOL-EN16931-R001', 'AuvoERechnungRuleXrR001');
		}
		// BR-DE-15: buyer reference (BT-10).
		if (empty($str('//ram:ApplicableHeaderTradeAgreement/ram:BuyerReference'))) {
			$violations[] = auvoerechnung_rule_msg('BR-DE-15', 'AuvoERechnungRuleDE15');
		}
		// PEPPOL-EN16931-R020/R010: seller/buyer electronic addresses.
		if (empty($str($seller.'/ram:URIUniversalCommunication/ram:URIID'))) {
			$violations[] = auvoerechnung_rule_msg('PEPPOL-EN16931-R020', 'AuvoERechnungRuleXrR020');
		}
		if (empty($str($buyerP.'/ram:URIUniversalCommunication/ram:URIID'))) {
			$violations[] = auvoerechnung_rule_msg('PEPPOL-EN16931-R010', 'AuvoERechnungRuleXrR010');
		}
		// BR-DE-2/5/6/7: seller contact (BG-6).
		if ($xp->query($seller.'/ram:DefinedTradeContact')->length === 0) {
			$violations[] = auvoerechnung_rule_msg('BR-DE-2', 'AuvoERechnungRuleDE2');
		} else {
			$contact = $seller.'/ram:DefinedTradeContact';
			if (empty($str($contact.'/ram:PersonName')) && empty($str($contact.'/ram:DepartmentName'))) {
				$violations[] = auvoerechnung_rule_msg('BR-DE-5', 'AuvoERechnungRuleDE5');
			}
			if (empty($str($contact.'/ram:TelephoneUniversalCommunication/ram:CompleteNumber'))) {
				$violations[] = auvoerechnung_rule_msg('BR-DE-6', 'AuvoERechnungRuleDE6');
			}
			if (empty($str($contact.'/ram:EmailURIUniversalCommunication/ram:URIID'))) {
				$violations[] = auvoerechnung_rule_msg('BR-DE-7', 'AuvoERechnungRuleDE7');
			}
		}
		// BR-DE-3/4/8/9: seller/buyer city and postal code.
		foreach ([['BR-DE-3', $seller, 'CityName'], ['BR-DE-4', $seller, 'PostcodeCode'], ['BR-DE-8', $buyerP, 'CityName'], ['BR-DE-9', $buyerP, 'PostcodeCode']] as $r) {
			if (empty($str($r[1].'/ram:PostalTradeAddress/ram:'.$r[2]))) {
				$violations[] = auvoerechnung_rule_msg($r[0], 'AuvoERechnungRule'.str_replace('-', '', substr($r[0], 3)));
			}
		}
		// BR-DE-1: payment instructions (BG-16).
		$pmNodes = $xp->query('//ram:SpecifiedTradeSettlementPaymentMeans');
		if ($pmNodes->length === 0) {
			$violations[] = auvoerechnung_rule_msg('BR-DE-1', 'AuvoERechnungRuleDE1');
		}
		// BR-DE-23-a/BR-DE-25-a: bank transfer requires BG-17 (IBAN); direct debit requires BT-89/90/91.
		foreach ($pmNodes as $pm) {
			$code = $str('ram:TypeCode', $pm);
			if (in_array($code, ['30', '58'], true) && empty($str('ram:PayeePartyCreditorFinancialAccount/ram:IBANID', $pm)) && empty($str('ram:PayeePartyCreditorFinancialAccount/ram:ProprietaryID', $pm))) {
				$violations[] = auvoerechnung_rule_msg('BR-DE-23-a', 'AuvoERechnungRuleDE23a');
			}
			if ($code === '59' && empty($str('ram:PayerPartyDebtorFinancialAccount/ram:IBANID', $pm))) {
				$violations[] = auvoerechnung_rule_msg('BR-DE-31', 'AuvoERechnungRuleDE31');
			}
		}
		// BR-DE-16: seller tax identifier (BT-31 or BT-32).
		if ($xp->query($seller.'/ram:SpecifiedTaxRegistration/ram:ID')->length === 0) {
			$violations[] = auvoerechnung_rule_msg('BR-DE-16', 'AuvoERechnungRuleDE16');
		}
		// BR-DE-14: VAT rate (BT-119) in each breakdown.
		foreach ($xp->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax') as $tax) {
			if ($str('ram:RateApplicablePercent', $tax) === null || $str('ram:RateApplicablePercent', $tax) === '') {
				$violations[] = auvoerechnung_rule_msg('BR-DE-14', 'AuvoERechnungRuleDE14', $str('ram:CategoryCode', $tax));
			}
		}
		// BR-DE-18: BT-20 discount lines follow KoSIT syntax and require a line
		// break after the final discount line.
		foreach ($xp->query('//ram:SpecifiedTradePaymentTerms/ram:Description') as $desc) {
			$hasSkonto = false;
			foreach (preg_split('/\r?\n/', $desc->textContent) as $l) {
				if (strpos(trim($l), '#') === 0) {
					$hasSkonto = true;
					if (!preg_match('/^#SKONTO#TAGE=[0-9]+#PROZENT=[0-9]+\.[0-9]{2}(#BASISBETRAG=-?[0-9]+\.[0-9]{2})?#$/', trim($l))) {
						$violations[] = auvoerechnung_rule_msg('BR-DE-18', 'AuvoERechnungRuleDE18', trim($l));
					}
				}
			}
			$parts = preg_split('/#.+#/', $desc->textContent);
			if ($hasSkonto && !preg_match('/^\s*\n/', (string) end($parts))) {
				$violations[] = auvoerechnung_rule_msg('BR-DE-18', 'AuvoERechnungRuleDE18Newline');
			}
		}
	}

	return $violations;
}
