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
 * Contrôle interne des règles métier EN16931 (sous-ensemble Schematron).
 *
 * Le XSD ne porte aucune règle BR-* : ce module vérifie en PHP les règles de
 * calcul et de cohérence les plus fréquemment contrôlées par les validateurs
 * (Mustang/FNFE, Chorus Pro, PDP) AVANT injection dans le PDF. Ce n'est PAS
 * un remplacement du Schematron officiel (200+ règles, XSLT 2.0 — non
 * exécutable avec l'extension XSL de PHP, limitée à XSLT 1.0) : c'est un
 * filet de sécurité couvrant les rejets les plus courants.
 *
 * Dépend de dolizugferd.lib.php (constante DOLIZUGFERD_FR_OVERSEAS_COUNTRIES),
 * toujours incluse avant ce fichier par le hook, l'API et les tests.
 */

/**
 * Message de règle traduit, préfixé par le code de la règle (« BR-XX : … »).
 * Le code reste en tête et non traduit : il sert de clé stable aux tests et
 * aux recherches dans le syslog.
 *
 * @param string $rule  Code de la règle (ex. 'BR-CO-10'), '' pour aucun préfixe
 * @param string $key   Clé de traduction (fichier dolizugferd.lang)
 * @param mixed  ...$args Paramètres %s de la traduction (4 au maximum)
 * @return string
 */
function dolizugferd_rule_msg($rule, $key, ...$args)
{
	$msg = dolizugferd_trans_raw($key, ...$args);
	return $rule !== '' ? $rule.' : '.$msg : $msg;
}

/**
 * Valide un XML contre le XSD Factur-X EN16931 embarqué.
 * Fonction partagée par le hook, les tests unitaires et les tests d'intégration
 * — une seule implémentation de la validation structurelle.
 *
 * @param string $xml         XML à valider
 * @param string $modulePath  Racine du module (pour localiser le XSD embarqué)
 * @return string|null        Message d'erreur si invalide, null si OK
 *                            (ou si le XSD est absent : well-formed seul)
 */
function dolizugferd_validate_xsd($xml, $modulePath)
{
	if (empty($xml)) {
		return dolizugferd_rule_msg('', 'DoliZugferdXsdEmpty');
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
		return dolizugferd_rule_msg('', 'DoliZugferdXsdMalformed', $firstError('-'));
	}

	$xsdPath = $modulePath.'/vendor/atgp/factur-x/xsd/factur-x/en16931/Factur-X_1.09.2_EN16931.xsd';
	if (!file_exists($xsdPath)) {
		// Absence du XSD embarqué : on ne bloque pas, le well-formed est vérifié
		libxml_clear_errors();
		libxml_use_internal_errors($prevUseErrors);
		return null;
	}

	if (!$dom->schemaValidate($xsdPath)) {
		return dolizugferd_rule_msg('', 'DoliZugferdXsdInvalid', $firstError('-'));
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
function dolizugferd_validate_business_rules($xml)
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
		$violations[] = dolizugferd_rule_msg('BR-16', 'DoliZugferdRuleBR16');
	}

	// === Lignes : BR-27 (prix net >= 0) + collecte pour BR-CO-10 et bases ===
	$sumLines = 0.0;
	$lineBasis = []; // "cat|rate" => somme des LineTotalAmount
	foreach ($lines as $i => $line) {
		$n = $i + 1;
		$price = $num('.//ram:NetPriceProductTradePrice/ram:ChargeAmount', $line);
		if ($price !== null && $price < 0) {
			$violations[] = dolizugferd_rule_msg('BR-27', 'DoliZugferdRuleBR27', $n, $price);
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
			$violations[] = dolizugferd_rule_msg('BR-S-05', 'DoliZugferdRuleS05', $n);
		}
		if (in_array($cat, ['E', 'K', 'G', 'AE', 'Z'], true) && $rate !== null && $rate != 0) {
			$violations[] = dolizugferd_rule_msg('BR-'.$cat.'-05', 'DoliZugferdRuleCat05', $n, $cat, $rate);
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
		$violations[] = dolizugferd_rule_msg('BR-CO-10', 'DoliZugferdRuleCO10', sprintf('%.2f', $btLineTotal), sprintf('%.2f', $sumLines));
	}
	if (!$eq($btAllowance, $sumAllowances)) {
		$violations[] = dolizugferd_rule_msg('BR-CO-11', 'DoliZugferdRuleCO11', sprintf('%.2f', $btAllowance), sprintf('%.2f', $sumAllowances));
	}
	if (!$eq($btCharge, $sumCharges)) {
		$violations[] = dolizugferd_rule_msg('BR-CO-12', 'DoliZugferdRuleCO12', sprintf('%.2f', $btCharge), sprintf('%.2f', $sumCharges));
	}
	if ($btTaxBasis !== null && $btLineTotal !== null && !$eq($btTaxBasis, $btLineTotal - $btAllowance + $btCharge)) {
		$violations[] = dolizugferd_rule_msg('BR-CO-13', 'DoliZugferdRuleCO13', sprintf('%.2f', $btTaxBasis), sprintf('%.2f', $btLineTotal - $btAllowance + $btCharge));
	}
	if ($btGrandTotal !== null && $btTaxBasis !== null && !$eq($btGrandTotal, $btTaxBasis + $btTaxTotal)) {
		$violations[] = dolizugferd_rule_msg('BR-CO-15', 'DoliZugferdRuleCO15', sprintf('%.2f', $btGrandTotal), sprintf('%.2f', $btTaxBasis), sprintf('%.2f', $btTaxTotal));
	}
	if ($btDuePayable !== null && $btGrandTotal !== null && !$eq($btDuePayable, $btGrandTotal - $btPrepaid)) {
		$violations[] = dolizugferd_rule_msg('BR-CO-16', 'DoliZugferdRuleCO16', sprintf('%.2f', $btDuePayable), sprintf('%.2f', $btGrandTotal), sprintf('%.2f', $btPrepaid));
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
			$violations[] = dolizugferd_rule_msg('BR-CO-17', 'DoliZugferdRuleCO17', sprintf('%.2f', $calc), sprintf('%.2f', $basis), sprintf('%.2f', $rate), sprintf('%.2f', round($basis * $rate / 100, 2)));
		}
		// Base par (catégorie, taux) = somme lignes - remises + frais de la catégorie
		$key = $cat.'|'.(float) $rate;
		if (isset($lineBasis[$key]) && !$eq($basis, $lineBasis[$key])) {
			$violations[] = dolizugferd_rule_msg('BR-'.$cat.'-08', 'DoliZugferdRuleCat08', $cat, (float) $rate, sprintf('%.2f', $basis), sprintf('%.2f', $lineBasis[$key]));
		}
		// Exonérations : motif obligatoire (BR-E-10, BR-K-10, BR-G-10, BR-AE-10)
		if (in_array($cat, ['E', 'K', 'G', 'AE'], true)) {
			$reason = $str('ram:ExemptionReason', $tax);
			$reasonCode = $str('ram:ExemptionReasonCode', $tax);
			if (empty($reason) && empty($reasonCode)) {
				$violations[] = dolizugferd_rule_msg('BR-'.$cat.'-10', 'DoliZugferdRuleCat10', $cat);
			}
		}
	}
	if (!$eq($btTaxTotal, $sumCalculated)) {
		$violations[] = dolizugferd_rule_msg('BR-CO-14', 'DoliZugferdRuleCO14', sprintf('%.2f', $btTaxTotal), sprintf('%.2f', $sumCalculated));
	}

	// === BR-61 : virement (30/58) => IBAN obligatoire ===
	foreach ($xp->query('//ram:SpecifiedTradeSettlementPaymentMeans') as $pm) {
		$typeCode = $str('ram:TypeCode', $pm);
		if (in_array($typeCode, ['30', '58'], true)) {
			$iban = $str('ram:PayeePartyCreditorFinancialAccount/ram:IBANID', $pm);
			if (empty($iban)) {
				$violations[] = dolizugferd_rule_msg('BR-61', 'DoliZugferdRuleBR61', $typeCode);
			}
		}
	}

	// === BR-IC-11/12 : livraison intracommunautaire (K) ===
	if ($hasK) {
		$deliveryDate = $str('//ram:ApplicableHeaderTradeDelivery/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString');
		$period = $xp->query('//ram:ApplicableHeaderTradeSettlement/ram:BillingSpecifiedPeriod');
		if (empty($deliveryDate) && $period->length === 0) {
			$violations[] = dolizugferd_rule_msg('BR-IC-11', 'DoliZugferdRuleIC11');
		}
		$shipToCountry = $str('//ram:ApplicableHeaderTradeDelivery/ram:ShipToTradeParty/ram:PostalTradeAddress/ram:CountryID');
		if (empty($shipToCountry)) {
			$violations[] = dolizugferd_rule_msg('BR-IC-12', 'DoliZugferdRuleIC12');
		}
		// BR-IC-02 : K => TVA intra vendeur obligatoire
		$sellerVat = $str('//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]');
		if (empty($sellerVat)) {
			$violations[] = dolizugferd_rule_msg('BR-IC-02', 'DoliZugferdRuleIC02');
		}
	}

	// === BR-AE-02 : autoliquidation => TVA vendeur ET acheteur ===
	$hasAE = $xp->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax[ram:CategoryCode="AE"]')->length > 0;
	if ($hasAE) {
		$sellerVat = $str('//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]');
		$buyerVat = $str('//ram:BuyerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]');
		if (empty($sellerVat) || empty($buyerVat)) {
			$violations[] = dolizugferd_rule_msg('BR-AE-02', 'DoliZugferdRuleAE02');
		}
	}

	// === BR-CO-26 : identifiant vendeur (légal ou fiscal) obligatoire ===
	$sellerLegal = $str('//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID');
	$sellerTax = $str('//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID');
	if (empty($sellerLegal) && empty($sellerTax)) {
		$violations[] = dolizugferd_rule_msg('BR-CO-26', 'DoliZugferdRuleCO26');
	}

	// === BR-CO-09 : n° de TVA (schemeID VA) préfixé par le code pays ISO 3166 ===
	foreach ($xp->query('//ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]') as $vaNode) {
		$vaVal = trim($vaNode->textContent);
		if (!preg_match('/^[A-Z]{2}/', $vaVal)) {
			$violations[] = dolizugferd_rule_msg('BR-CO-09', 'DoliZugferdRuleCO09', $vaVal);
		}
	}

	// === BR-09 / BR-11 : pays vendeur et acheteur obligatoires ===
	if (empty($str('//ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID'))) {
		$violations[] = dolizugferd_rule_msg('BR-09', 'DoliZugferdRuleBR09');
	}
	if (empty($str('//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CountryID'))) {
		$violations[] = dolizugferd_rule_msg('BR-11', 'DoliZugferdRuleBR11');
	}

	// === BR-CO-25 : montant dû > 0 => date d'échéance ou conditions de paiement ===
	if ($btDuePayable !== null && $btDuePayable > 0) {
		$dueDate = $str('//ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString');
		$terms = $str('//ram:SpecifiedTradePaymentTerms/ram:Description');
		if (empty($dueDate) && empty($terms)) {
			$violations[] = dolizugferd_rule_msg('BR-CO-25', 'DoliZugferdRuleCO25');
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
	if ($sellerCountry === 'FR' || in_array($sellerCountry, DOLIZUGFERD_FR_OVERSEAS_COUNTRIES, true)) {

		// === BR-FR-01 / BR-FR-02 : identifiant de facture (BT-1) ===
		$bt1 = $str('//rsm:ExchangedDocument/ram:ID');
		if ($bt1 !== null && $bt1 !== '') {
			$bt1Len = function_exists('mb_strlen') ? mb_strlen($bt1) : strlen($bt1);
			if ($bt1Len > 35) {
				$violations[] = dolizugferd_rule_msg('BR-FR-01', 'DoliZugferdRuleFR01', $bt1Len);
			}
			// Charset fermé : alphanumériques + tiret, signe plus, tiret bas, barre
			// oblique. L'espace n'est pas admis (retiré de la liste en V1.1).
			if (!preg_match('/^[A-Za-z0-9+_\/-]+$/', $bt1)) {
				$violations[] = dolizugferd_rule_msg('BR-FR-02', 'DoliZugferdRuleFR02', $bt1);
			}
		}

		// === BR-FR-04 : type de document (BT-3) — liste fermée admise en France ===
		// Simples 380/389/393/501, acomptes 386/500, rectificatives 384/471/472/473,
		// avoirs 261/262/381/396/502/503. Tout autre code UNTDID 1001 est proscrit.
		$bt3 = $str('//rsm:ExchangedDocument/ram:TypeCode');
		$frTypeCodes = ['380', '389', '393', '501', '386', '500', '384', '471', '472', '473', '261', '262', '381', '396', '502', '503'];
		if ($bt3 !== null && $bt3 !== '' && !in_array($bt3, $frTypeCodes, true)) {
			$violations[] = dolizugferd_rule_msg('BR-FR-04', 'DoliZugferdRuleFR04', $bt3);
		}

		// === BR-FR-09 : cohérence SIRET / SIREN par partie ===
		// Le SIRET (schemeID 0009) doit faire 14 chiffres et ses 9 premiers chiffres
		// doivent correspondre au SIREN (schemeID 0002) quand les deux sont présents.
		foreach (['Seller' => 'DoliZugferdRulePartySeller', 'Buyer' => 'DoliZugferdRulePartyBuyer'] as $partyTag => $partyKey) {
			$partyLabel = dolizugferd_rule_msg('', $partyKey);
			$base = '//ram:'.$partyTag.'TradeParty';
			$siret = $str($base.'/ram:GlobalID[@schemeID="0009"]');
			if ($siret === null || $siret === '') {
				// Profil Chorus Pro : le SIRET est porté par SpecifiedLegalOrganization
				$siret = $str($base.'/ram:SpecifiedLegalOrganization/ram:ID[@schemeID="0009"]');
			}
			$siren = $str($base.'/ram:SpecifiedLegalOrganization/ram:ID[@schemeID="0002"]');
			if ($siret !== null && $siret !== '') {
				if (!preg_match('/^\d{14}$/', $siret)) {
					$violations[] = dolizugferd_rule_msg('BR-FR-09', 'DoliZugferdRuleFR09Length', $partyLabel, $siret);
				} elseif ($siren !== null && $siren !== '' && strncmp($siret, $siren, 9) !== 0) {
					$violations[] = dolizugferd_rule_msg('BR-FR-09', 'DoliZugferdRuleFR09Mismatch', $partyLabel, $siret, $siren);
				}
			}
		}

		// === BR-FR-CO-04 / BR-FR-CO-05 : référence à la facture antérieure (BG-3) ===
		// Rectificatives : exactement UNE référence (BT-25) avec sa date (BT-26) —
		// le générateur ne référence QUE la facture remplacée dans ce cas (les
		// acomptes imputés restent en BT-113, cf. dolizugferd_get_preceding_invoices).
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
				$violations[] = dolizugferd_rule_msg('BR-FR-CO-04', 'DoliZugferdRuleFRCO04Count', $bt3, $precedingDocs->length);
			} elseif (!$hasDatedRef) {
				$violations[] = dolizugferd_rule_msg('BR-FR-CO-04', 'DoliZugferdRuleFRCO04Date', $bt3);
			}
		}
		if (in_array($bt3, ['261', '381', '396', '502', '503'], true)) {
			if ($precedingDocs->length === 0) {
				$violations[] = dolizugferd_rule_msg('BR-FR-CO-05', 'DoliZugferdRuleFRCO05Missing', $bt3);
			} elseif (!$hasDatedRef) {
				$violations[] = dolizugferd_rule_msg('BR-FR-CO-05', 'DoliZugferdRuleFRCO05Date', $bt3);
			}
		}

		// === BR-FR-23 : adresse électronique 0225 — charset fermé ===
		// Alphanumériques + tiret, tiret bas, point (liste PLUS restrictive que
		// celle du BT-1 : ni « + » ni « / »).
		foreach ($xp->query('//ram:URIUniversalCommunication/ram:URIID[@schemeID="0225"]') as $uriNode) {
			$uriVal = trim($uriNode->textContent);
			if ($uriVal !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $uriVal)) {
				$violations[] = dolizugferd_rule_msg('BR-FR-23', 'DoliZugferdRuleFR23', $uriVal);
			}
		}
	}

	return $violations;
}
