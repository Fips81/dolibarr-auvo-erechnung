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
 * Contrôle interne des règles métier EN16931 (sous-ensemble Schematron).
 *
 * Le XSD ne porte aucune règle BR-* : ce module vérifie en PHP les règles de
 * calcul et de cohérence les plus fréquemment contrôlées par les validateurs
 * (Mustang/FNFE, Chorus Pro, PDP) AVANT injection dans le PDF. Ce n'est PAS
 * un remplacement du Schematron officiel (200+ règles, XSLT 2.0 — non
 * exécutable avec l'extension XSL de PHP, limitée à XSLT 1.0) : c'est un
 * filet de sécurité couvrant les rejets les plus courants.
 *
 * Dépend de auvoerechnung.lib.php (constante AUVOERECHNUNG_FR_OVERSEAS_COUNTRIES),
 * toujours incluse avant ce fichier par le hook, l'API et les tests.
 */

/**
 * Message de règle traduit, préfixé par le code de la règle (« BR-XX : … »).
 * Le code reste en tête et non traduit : il sert de clé stable aux tests et
 * aux recherches dans le syslog.
 *
 * @param string $rule  Code de la règle (ex. 'BR-CO-10'), '' pour aucun préfixe
 * @param string $key   Clé de traduction (fichier auvoerechnung.lang)
 * @param mixed  ...$args Paramètres %s de la traduction (4 au maximum)
 * @return string
 */
function auvoerechnung_rule_msg($rule, $key, ...$args)
{
	$msg = auvoerechnung_trans_raw($key, ...$args);
	return $rule !== '' ? $rule.' : '.$msg : $msg;
}

/**
 * Valide un XML contre le XSD embarqué de sa syntaxe : Factur-X EN16931 (CII)
 * ou UBL 2.1 Invoice / CreditNote (OASIS).
 * Fonction partagée par le hook, les tests unitaires et les tests d'intégration
 * — une seule implémentation de la validation structurelle.
 *
 * @param string $xml         XML à valider
 * @param string $modulePath  Racine du module (pour localiser le XSD embarqué)
 * @return string|null        Message d'erreur si invalide, null si OK
 *                            (ou si le XSD est absent : well-formed seul)
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

	// Syntaxe d'après l'élément racine : UBL 2.1 (Invoice / CreditNote, XSD
	// OASIS embarqués) ou CII (XSD Factur-X EN16931).
	$rootNs = $dom->documentElement ? (string) $dom->documentElement->namespaceURI : '';
	if (preg_match('/^urn:oasis:names:specification:ubl:schema:xsd:(Invoice|CreditNote)-2$/', $rootNs, $mUbl)) {
		$xsdPath = $modulePath.'/vendor/oasis/ubl-2.1/xsd/maindoc/UBL-'.$mUbl[1].'-2.1.xsd';
	} else {
		$xsdPath = $modulePath.'/vendor/atgp/factur-x/xsd/factur-x/en16931/Factur-X_1.09.2_EN16931.xsd';
	}
	if (!file_exists($xsdPath)) {
		// Absence du XSD embarqué : on ne bloque pas, le well-formed est vérifié
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
 * Valide un XML CrossIndustryInvoice contre un sous-ensemble des règles
 * métier EN16931 (BR-*, BR-CO-*, BR-S/E/K/G/AE-*, BR-IC-*).
 *
 * @param string $xml XML CrossIndustryInvoice généré
 * @return array      Liste de violations ("BR-XX : message"), vide si conforme
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

	// === BR-16 : au moins une ligne ===
	$lines = $xp->query('//ram:IncludedSupplyChainTradeLineItem');
	if ($lines->length === 0) {
		$violations[] = auvoerechnung_rule_msg('BR-16', 'AuvoERechnungRuleBR16');
	}

	// === Lignes : BR-27 (prix net >= 0) + collecte pour BR-CO-10 et bases ===
	$sumLines = 0.0;
	$lineBasis = []; // "cat|rate" => somme des LineTotalAmount
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

		// Cohérence catégorie/taux au niveau ligne
		if ($cat === 'S' && $rate !== null && $rate <= 0) {
			$violations[] = auvoerechnung_rule_msg('BR-S-05', 'AuvoERechnungRuleS05', $n);
		}
		if (in_array($cat, ['E', 'K', 'G', 'AE', 'Z'], true) && $rate !== null && $rate != 0) {
			$violations[] = auvoerechnung_rule_msg('BR-'.$cat.'-05', 'AuvoERechnungRuleCat05', $n, $cat, $rate);
		}
	}

	// === Remises/frais document (BG-20/BG-21) ===
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

	// === Totaux (BG-22) ===
	$ms = '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/';
	$btLineTotal   = $num($ms.'ram:LineTotalAmount');
	$btAllowance   = $num($ms.'ram:AllowanceTotalAmount') ?? 0.0;
	$btCharge      = $num($ms.'ram:ChargeTotalAmount') ?? 0.0;
	$btTaxBasis    = $num($ms.'ram:TaxBasisTotalAmount');
	// BT-110 : EN16931 autorise deux TaxTotalAmount (devise facture BT-110 +
	// devise de TVA BT-111) — prendre celui dont currencyID = devise facture.
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

	// === Ventilation TVA (BG-23) : BR-CO-14, BR-CO-17, bases par catégorie ===
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

		// BR-CO-17 : montant TVA = base x taux, arrondi à 2 décimales (+/- 0.01)
		if ($rate !== null && abs($calc - round($basis * $rate / 100, 2)) > 0.0105) {
			$violations[] = auvoerechnung_rule_msg('BR-CO-17', 'AuvoERechnungRuleCO17', sprintf('%.2f', $calc), sprintf('%.2f', $basis), sprintf('%.2f', $rate), sprintf('%.2f', round($basis * $rate / 100, 2)));
		}
		// Base par (catégorie, taux) = somme lignes - remises + frais de la catégorie
		$key = $cat.'|'.(float) $rate;
		if (isset($lineBasis[$key]) && !$eq($basis, $lineBasis[$key])) {
			$violations[] = auvoerechnung_rule_msg('BR-'.$cat.'-08', 'AuvoERechnungRuleCat08', $cat, (float) $rate, sprintf('%.2f', $basis), sprintf('%.2f', $lineBasis[$key]));
		}
		// Exonérations : motif obligatoire (BR-E-10, BR-K-10, BR-G-10, BR-AE-10)
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

	// === BR-61 : virement (30/58) => IBAN obligatoire ===
	foreach ($xp->query('//ram:SpecifiedTradeSettlementPaymentMeans') as $pm) {
		$typeCode = $str('ram:TypeCode', $pm);
		if (in_array($typeCode, ['30', '58'], true)) {
			$iban = $str('ram:PayeePartyCreditorFinancialAccount/ram:IBANID', $pm);
			if (empty($iban)) {
				$violations[] = auvoerechnung_rule_msg('BR-61', 'AuvoERechnungRuleBR61', $typeCode);
			}
		}
	}

	// === BR-IC-11/12 : livraison intracommunautaire (K) ===
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
		// BR-IC-02 : K => TVA intra vendeur obligatoire
		$sellerVat = $str('//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]');
		if (empty($sellerVat)) {
			$violations[] = auvoerechnung_rule_msg('BR-IC-02', 'AuvoERechnungRuleIC02');
		}
	}

	// === BR-AE-02 : autoliquidation => TVA vendeur ET acheteur ===
	$hasAE = $xp->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax[ram:CategoryCode="AE"]')->length > 0;
	if ($hasAE) {
		$sellerVat = $str('//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]');
		$buyerVat = $str('//ram:BuyerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]');
		if (empty($sellerVat) || empty($buyerVat)) {
			$violations[] = auvoerechnung_rule_msg('BR-AE-02', 'AuvoERechnungRuleAE02');
		}
	}

	// === BR-CO-26 : BT-29 (ID / GlobalID), BT-30 (registre) ou BT-31 (TVA, schéma VA).
	// Le n° fiscal BT-32 (schéma FC) ne suffit PAS (Schematron EN16931 officiel).
	$sellerId = $str('//ram:SellerTradeParty/ram:ID') ?: $str('//ram:SellerTradeParty/ram:GlobalID');
	$sellerLegal = $str('//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID');
	$sellerVat = $str('//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]');
	if (empty($sellerId) && empty($sellerLegal) && empty($sellerVat)) {
		$violations[] = auvoerechnung_rule_msg('BR-CO-26', 'AuvoERechnungRuleCO26');
	}

	// === BR-CO-09 : n° de TVA (schemeID VA) préfixé par le code pays ISO 3166 ===
	foreach ($xp->query('//ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]') as $vaNode) {
		$vaVal = trim($vaNode->textContent);
		if (!preg_match('/^[A-Z]{2}/', $vaVal)) {
			$violations[] = auvoerechnung_rule_msg('BR-CO-09', 'AuvoERechnungRuleCO09', $vaVal);
		}
	}

	// === BR-09 / BR-11 : pays vendeur et acheteur obligatoires ===
	if (empty($str('//ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID'))) {
		$violations[] = auvoerechnung_rule_msg('BR-09', 'AuvoERechnungRuleBR09');
	}
	if (empty($str('//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CountryID'))) {
		$violations[] = auvoerechnung_rule_msg('BR-11', 'AuvoERechnungRuleBR11');
	}

	// === BR-CO-25 : montant dû > 0 => date d'échéance ou conditions de paiement ===
	if ($btDuePayable !== null && $btDuePayable > 0) {
		$dueDate = $str('//ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString');
		$terms = $str('//ram:SpecifiedTradePaymentTerms/ram:Description');
		if (empty($dueDate) && empty($terms)) {
			$violations[] = auvoerechnung_rule_msg('BR-CO-25', 'AuvoERechnungRuleCO25');
		}
	}

	// =========================================================================
	// Règles France — XP Z12-012 §4.5.1 (socle réforme facture électronique).
	// Sous-ensemble contrôlable sur le XML émis ; les règles nécessitant
	// l'annuaire PPF (BR-FR-10/11) ou le contexte applicatif restent hors champ.
	// Opposables aux seuls vendeurs établis en France (métropole + DOM assimilés,
	// même liste que BR-FR-MAP-14) : Factur-X est aussi utilisé hors de France
	// (ZUGFeRD) et le socle réforme ne s'applique pas à un vendeur étranger —
	// les règles EN16931 ci-dessus restent, elles, universelles.
	// =========================================================================
	$sellerCountry = $str('//ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID');
	if ($sellerCountry === 'FR' || in_array($sellerCountry, AUVOERECHNUNG_FR_OVERSEAS_COUNTRIES, true)) {

		// === BR-FR-01 / BR-FR-02 : identifiant de facture (BT-1) ===
		$bt1 = $str('//rsm:ExchangedDocument/ram:ID');
		if ($bt1 !== null && $bt1 !== '') {
			$bt1Len = function_exists('mb_strlen') ? mb_strlen($bt1) : strlen($bt1);
			if ($bt1Len > 35) {
				$violations[] = auvoerechnung_rule_msg('BR-FR-01', 'AuvoERechnungRuleFR01', $bt1Len);
			}
			// Charset fermé : alphanumériques + tiret, signe plus, tiret bas, barre
			// oblique. L'espace n'est pas admis (retiré de la liste en V1.1).
			if (!preg_match('/^[A-Za-z0-9+_\/-]+$/', $bt1)) {
				$violations[] = auvoerechnung_rule_msg('BR-FR-02', 'AuvoERechnungRuleFR02', $bt1);
			}
		}

		// === BR-FR-04 : type de document (BT-3) — liste fermée admise en France ===
		// Simples 380/389/393/501, acomptes 386/500, rectificatives 384/471/472/473,
		// avoirs 261/262/381/396/502/503. Tout autre code UNTDID 1001 est proscrit.
		$bt3 = $str('//rsm:ExchangedDocument/ram:TypeCode');
		$frTypeCodes = ['380', '389', '393', '501', '386', '500', '384', '471', '472', '473', '261', '262', '381', '396', '502', '503'];
		if ($bt3 !== null && $bt3 !== '' && !in_array($bt3, $frTypeCodes, true)) {
			$violations[] = auvoerechnung_rule_msg('BR-FR-04', 'AuvoERechnungRuleFR04', $bt3);
		}

		// === BR-FR-09 : cohérence SIRET / SIREN par partie ===
		// Le SIRET (schemeID 0009) doit faire 14 chiffres et ses 9 premiers chiffres
		// doivent correspondre au SIREN (schemeID 0002) quand les deux sont présents.
		foreach (['Seller' => 'AuvoERechnungRulePartySeller', 'Buyer' => 'AuvoERechnungRulePartyBuyer'] as $partyTag => $partyKey) {
			$partyLabel = auvoerechnung_rule_msg('', $partyKey);
			$base = '//ram:'.$partyTag.'TradeParty';
			$siret = $str($base.'/ram:GlobalID[@schemeID="0009"]');
			if ($siret === null || $siret === '') {
				// Profil Chorus Pro : le SIRET est porté par SpecifiedLegalOrganization
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

		// === BR-FR-CO-04 / BR-FR-CO-05 : référence à la facture antérieure (BG-3) ===
		// Rectificatives : exactement UNE référence (BT-25) avec sa date (BT-26) —
		// le générateur ne référence QUE la facture remplacée dans ce cas (les
		// acomptes imputés restent en BT-113, cf. auvoerechnung_get_preceding_invoices).
		// Avoirs : au moins une référence avec sa date. (La variante « référence en
		// ligne » EXT-FR-FE-136 relève du profil EXTENDED-CTC-FR, non émis ici.)
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

		// === BR-FR-23 : adresse électronique 0225 — charset fermé ===
		// Alphanumériques + tiret, tiret bas, point (liste PLUS restrictive que
		// celle du BT-1 : ni « + » ni « / »).
		foreach ($xp->query('//ram:URIUniversalCommunication/ram:URIID[@schemeID="0225"]') as $uriNode) {
			$uriVal = trim($uriNode->textContent);
			if ($uriVal !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $uriVal)) {
				$violations[] = auvoerechnung_rule_msg('BR-FR-23', 'AuvoERechnungRuleFR23', $uriVal);
			}
		}
	}

	// =========================================================================
	// Règles XRechnung (CIUS allemande, KoSIT) — sous-ensemble des règles
	// « fatal » du Schematron XRechnung 3.0 contrôlable sur le XML émis.
	// Appliquées seulement quand BT-24 annonce XRechnung : un ZUGFeRD EN16931
	// n'y est pas soumis. Le validateur officiel KoSIT reste la référence.
	// =========================================================================
	$bt24 = (string) $str('//rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID');
	if (strpos($bt24, 'urn:xeinkauf.de:kosit:xrechnung_') !== false) {
		$seller = '//ram:SellerTradeParty';
		$buyerP = '//ram:BuyerTradeParty';
		// PEPPOL-EN16931-R001 : processus métier (BT-23)
		if (empty($str('//rsm:ExchangedDocumentContext/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID'))) {
			$violations[] = auvoerechnung_rule_msg('PEPPOL-EN16931-R001', 'AuvoERechnungRuleXrR001');
		}
		// BR-DE-15 : référence acheteur (BT-10)
		if (empty($str('//ram:ApplicableHeaderTradeAgreement/ram:BuyerReference'))) {
			$violations[] = auvoerechnung_rule_msg('BR-DE-15', 'AuvoERechnungRuleDE15');
		}
		// PEPPOL-EN16931-R020 / R010 : adresses électroniques vendeur / acheteur
		if (empty($str($seller.'/ram:URIUniversalCommunication/ram:URIID'))) {
			$violations[] = auvoerechnung_rule_msg('PEPPOL-EN16931-R020', 'AuvoERechnungRuleXrR020');
		}
		if (empty($str($buyerP.'/ram:URIUniversalCommunication/ram:URIID'))) {
			$violations[] = auvoerechnung_rule_msg('PEPPOL-EN16931-R010', 'AuvoERechnungRuleXrR010');
		}
		// BR-DE-2/5/6/7 : contact vendeur (BG-6)
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
		// BR-DE-3/4/8/9 : ville et code postal vendeur / acheteur
		foreach ([['BR-DE-3', $seller, 'CityName'], ['BR-DE-4', $seller, 'PostcodeCode'], ['BR-DE-8', $buyerP, 'CityName'], ['BR-DE-9', $buyerP, 'PostcodeCode']] as $r) {
			if (empty($str($r[1].'/ram:PostalTradeAddress/ram:'.$r[2]))) {
				$violations[] = auvoerechnung_rule_msg($r[0], 'AuvoERechnungRule'.str_replace('-', '', substr($r[0], 3)));
			}
		}
		// BR-DE-1 : instructions de paiement (BG-16)
		$pmNodes = $xp->query('//ram:SpecifiedTradeSettlementPaymentMeans');
		if ($pmNodes->length === 0) {
			$violations[] = auvoerechnung_rule_msg('BR-DE-1', 'AuvoERechnungRuleDE1');
		}
		// BR-DE-23-a / BR-DE-25-a : virement → BG-17 (IBAN) ; prélèvement → BT-89/90/91
		foreach ($pmNodes as $pm) {
			$code = $str('ram:TypeCode', $pm);
			if (in_array($code, ['30', '58'], true) && empty($str('ram:PayeePartyCreditorFinancialAccount/ram:IBANID', $pm)) && empty($str('ram:PayeePartyCreditorFinancialAccount/ram:ProprietaryID', $pm))) {
				$violations[] = auvoerechnung_rule_msg('BR-DE-23-a', 'AuvoERechnungRuleDE23a');
			}
			if ($code === '59' && empty($str('ram:PayerPartyDebtorFinancialAccount/ram:IBANID', $pm))) {
				$violations[] = auvoerechnung_rule_msg('BR-DE-31', 'AuvoERechnungRuleDE31');
			}
		}
		// BR-DE-16 : identifiant fiscal du vendeur (BT-31 ou BT-32)
		if ($xp->query($seller.'/ram:SpecifiedTaxRegistration/ram:ID')->length === 0) {
			$violations[] = auvoerechnung_rule_msg('BR-DE-16', 'AuvoERechnungRuleDE16');
		}
		// BR-DE-14 : taux de TVA (BT-119) dans chaque ventilation
		foreach ($xp->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax') as $tax) {
			if ($str('ram:RateApplicablePercent', $tax) === null || $str('ram:RateApplicablePercent', $tax) === '') {
				$violations[] = auvoerechnung_rule_msg('BR-DE-14', 'AuvoERechnungRuleDE14', $str('ram:CategoryCode', $tax));
			}
		}
		// BR-DE-18 : lignes Skonto de BT-20 conformes à la syntaxe KoSIT, et
		// saut de ligne obligatoire après la dernière ligne Skonto
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
