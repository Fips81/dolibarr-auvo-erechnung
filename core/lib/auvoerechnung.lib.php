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
 * Générateur XML CrossIndustryInvoice EN16931 pour Factur-X
 * Conforme aux règles BR-FR (XP Z12-012 V1.2.0)
 */

/**
 * Liste des codes ISO des pays de l'Union européenne (utilisée pour qualifier
 * la catégorie de TVA EN16931 sur les opérations B2B intracommunautaires).
 *
 * Mentions légales BR-FR par défaut (BG-3 IncludedNote) : surchargeable via
 * les constantes Dolibarr AUVOERECHNUNG_NOTE_*.
 *
 * define() (au lieu de const) pour rester tolérant si la lib est incluse depuis
 * deux chemins distincts (custom + dol_buildpath) sur certains setups.
 */
if (!defined('AUVOERECHNUNG_EU_COUNTRIES')) {
	define('AUVOERECHNUNG_EU_COUNTRIES', [
		'AT','BE','BG','CY','CZ','DE','DK','EE','ES','FI','FR','GR','HR','HU',
		'IE','IT','LT','LU','LV','MT','NL','PL','PT','RO','SE','SI','SK',
	]);
	// Codes pays des DOM/COM remplacés par FR dans les flux de la réforme
	// (BR-FR-MAP-14) : assimilés à la France pour la note BAR et pour
	// l'applicabilité du bloc de règles BR-FR du validateur interne.
	define('AUVOERECHNUNG_FR_OVERSEAS_COUNTRIES', ['GF', 'TF', 'GP', 'GY', 'MQ', 'YT', 'RE', 'BL', 'MF', 'PM']);
	define('AUVOERECHNUNG_DEFAULT_NOTE_PMD', 'En cas de retard de paiement, une pénalité égale à 3 fois le taux d\'intérêt légal sera exigible (article L.441-10 du Code de commerce).');
	define('AUVOERECHNUNG_DEFAULT_NOTE_PMT', 'Une indemnité forfaitaire de 40 euros sera exigible pour frais de recouvrement en cas de retard de paiement.');
	define('AUVOERECHNUNG_DEFAULT_NOTE_AAB', 'Pas d\'escompte pour paiement anticipé.');
	// XRechnung (CIUS allemande de l'EN16931, KoSIT) : BT-23 imposé par la règle
	// PEPPOL-EN16931-R001 du Schematron XRechnung, version par défaut de la
	// spécification (BT-24). La KoSIT publie deux fois par an (01.02 / 01.07) ;
	// la version reste réglable via AUVOERECHNUNG_XRECHNUNG_VERSION.
	define('AUVOERECHNUNG_XRECHNUNG_BUSINESS_PROCESS', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');
	define('AUVOERECHNUNG_XRECHNUNG_DEFAULT_VERSION', '3.0');
}

/**
 * Traduit une clé via $langs si l'environnement Dolibarr est chargé,
 * sinon renvoie la clé brute (contexte tests unitaires standalone).
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
 * Variante sans entités HTML de auvoerechnung_trans(), pour les messages qui
 * finissent aussi dans le syslog, l'API JSON ou sont tronqués (dol_trunc) :
 * « ä » reste « ä » au lieu de « &auml; ».
 *
 * @param string $key   Clé de traduction
 * @param mixed  ...$args Paramètres %s de la traduction
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
 * Le vendeur est-il établi en France au sens du socle réforme ?
 *
 * Sert de garde unique à tout ce qui relève du droit français : mentions
 * légales PMD/PMT/AAB (BR-FR-05), note BAR (BR-FR-07/20/31), cadre de
 * facturation BT-23 (BR-FR-08) et contrôle des identifiants SIREN/SIRET.
 * Ces éléments ne sont pas opposables à un vendeur étranger — Factur-X est
 * la même spécification que ZUGFeRD et sert aussi hors de France ; les
 * émettre pour un vendeur allemand y injecterait du droit français.
 * Les DOM sont assimilés à la France (BR-FR-MAP-14).
 *
 * @param Societe|object $mysoc  Société émettrice
 * @return bool
 */
function auvoerechnung_seller_is_fr($mysoc)
{
	// Pays inconnu (objet absent, fiche société incomplète) : on retombe sur FR,
	// comportement historique du module.
	$country = (is_object($mysoc) && !empty($mysoc->country_code)) ? strtoupper($mysoc->country_code) : 'FR';
	return ($country === 'FR' || in_array($country, AUVOERECHNUNG_FR_OVERSEAS_COUNTRIES, true));
}

/**
 * Vérifie si la facture est dans le périmètre supporté par le générateur.
 * Renvoie un message d'erreur bloquant, ou null si la facture est traitable.
 *
 * Multidevise : Dolibarr ne stocke la ventilation TVA qu'en devise société.
 * Émettre un XML en devise société alors que le PDF visible est en devise
 * étrangère violerait le principe hybride Factur-X (lisible = structuré).
 * Le support BT-5/BT-6 complet nécessiterait les montants multicurrency_total_*
 * par ligne + le double TaxTotalAmount — non implémenté à ce stade (documenté).
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

	// Taxes locales (localtax1/2, RE/IRPF...) : non représentables dans le XML
	// EN16931 (TVA uniquement). Le total XML divergerait du TTC visible sur le
	// PDF — même principe de refus que le multidevise.
	if (abs((float) ($invoice->total_localtax1 ?? 0)) > 0.005 || abs((float) ($invoice->total_localtax2 ?? 0)) > 0.005) {
		return auvoerechnung_trans('AuvoERechnungErrLocalTax');
	}

	if (empty($invoice->date)) {
		return auvoerechnung_trans('AuvoERechnungErrNoDate');
	}

	return null;
}

/**
 * Génère le XML Factur-X EN16931 à partir d'une facture Dolibarr.
 *
 * Convention avoirs (TypeCode 381) : Dolibarr stocke des totaux négatifs,
 * EN16931 exige des montants positifs (BR-27 : prix net ligne >= 0). Tous les
 * montants sont donc multipliés par -1 pour un avoir, et DuePayableAmount
 * respecte BR-CO-16 (BT-115 = BT-112 - BT-113, sans écrêtage à zéro).
 *
 * @param Facture $invoice        Objet facture Dolibarr (avec lines chargées)
 * @param Societe $mysoc          Société émettrice (vendeur)
 * @param array   $buildWarnings  (sortie) Avertissements non bloquants détectés pendant la génération
 * @return string                 XML CrossIndustryInvoice
 */
function auvoerechnung_build_xml($invoice, $mysoc, &$buildWarnings = [], $options = [])
{
	global $conf;

	// Profil de génération. 'pdp' (défaut) = norme EN16931/PDP (SIREN en BT-30).
	// 'choruspro' = profil B2G Chorus Pro (SIRET en BT-30 + champs BT-10/12/13).
	// 'xrechnung' = CIUS XRechnung (KoSIT) : BT-23/BT-24 propres, contact vendeur
	// (BG-6), adresses électroniques obligatoires, Leitweg-ID en BT-49 (0204).
	$profile = (isset($options['profile']) && in_array($options['profile'], ['choruspro', 'xrechnung'], true)) ? $options['profile'] : 'pdp';
	$isXRechnung = ($profile === 'xrechnung');
	$legalIdMode = ($profile === 'choruspro') ? 'siret' : 'siren';

	// Tout le bloc « socle réforme française » (BT-23, note BAR, mentions
	// légales) est conditionné à un vendeur établi en France : hors de France
	// le document reste un Factur-X/ZUGFeRD EN16931 pur.
	$sellerIsFR = auvoerechnung_seller_is_fr($mysoc);

	$typeCode = auvoerechnung_resolve_document_type($invoice, $buildWarnings);
	$isCreditNote = ($typeCode === '381');
	$sign = $isCreditNote ? -1.0 : 1.0;

	$issueDate = date('Ymd', $invoice->date);
	$dueDate = !empty($invoice->date_lim_reglement) ? date('Ymd', $invoice->date_lim_reglement) : $issueDate;
	$currency = !empty($conf->currency) ? $conf->currency : 'EUR';

	$buyer = $invoice->thirdparty;
	$bank = auvoerechnung_get_bank_account($invoice->db, $invoice);
	$paymentMeans = getDolGlobalString('AUVOERECHNUNG_PAYMENT_MEANS', '30');

	// Lignes utiles : on filtre une seule fois les lignes sans montant
	// (descriptions, titres, sous-totaux) pour les réutiliser ci-dessous.
	$billableLines = auvoerechnung_filter_billable_lines($invoice->lines);

	// Sépare lignes facturables et remises pied de facture (BG-21) : une ligne
	// à total négatif (remise fixe Dolibarr) devient une SpecifiedTradeAllowanceCharge
	// document pour respecter BR-27 (prix net de ligne jamais négatif).
	$prepared = auvoerechnung_prepare_lines($billableLines, $sign, $invoice, $buyer, $mysoc, $buildWarnings);

	// Ventilation TVA par (catégorie, taux), réconciliée avec les totaux facture
	$breakdown = auvoerechnung_get_tax_breakdown($prepared, $invoice, $sign, $buildWarnings);

	// BR-61 : un moyen de paiement 30/58 (virement) exige un IBAN. Sans compte
	// bancaire configuré, on omet le bloc PaymentMeans plutôt que d'émettre un
	// XML rejeté par les validateurs Schematron.
	$emitPaymentMeans = true;
	if (in_array($paymentMeans, ['30', '58'], true) && empty($bank['iban'])) {
		$emitPaymentMeans = false;
		$buildWarnings[] = auvoerechnung_trans('AuvoERechnungWarnPaymentMeansOmitted', $paymentMeans);
	}

	// Prélèvement SEPA (59) : ICS créancier (BT-90), RUM mandat (BT-89), IBAN débiteur (BT-91)
	$directDebit = ($paymentMeans === '59') ? auvoerechnung_get_direct_debit_info($invoice->db, $buyer, $buildWarnings) : null;

	$xml  = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
	$xml .= '<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"';
	$xml .= ' xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"';
	$xml .= ' xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100"';
	$xml .= ' xmlns:qdt="urn:un:unece:uncefact:data:standard:QualifiedDataType:100">'."\n";

	// === ExchangedDocumentContext ===
	$xml .= '<rsm:ExchangedDocumentContext>'."\n";
	// BT-23 : cadre de facturation, obligatoire pour la réforme FR (BR-FR-08).
	//  - profil Chorus B2G : cadre AIFE choisi PAR FACTURE dans l'onglet Chorus
	//    (24 valeurs A1..A25, A1 par défaut) — chemin historique inchangé ;
	//  - profil PDP/B2B : valeur du socle XP Z12-012 dérivée des lignes et des
	//    acomptes imputés (cas nominal : dépôt de la facture par le fournisseur).
	// Vendeur hors France : BT-23 est optionnel en EN16931 (0..1) et les codes
	// B1/S1/M1 sont propres à XP Z12-012 → on n'émet rien.
	if ($isXRechnung) {
		// XRechnung : BT-23 obligatoire (PEPPOL-EN16931-R001), valeur Peppol BIS Billing.
		$bt23 = AUVOERECHNUNG_XRECHNUNG_BUSINESS_PROCESS;
	} else {
		$bt23 = ($profile === 'choruspro')
			? (!empty($options['cadre']) ? $options['cadre'] : 'A1')
			: ($sellerIsFR
				? auvoerechnung_resolve_business_process($billableLines, $typeCode, auvoerechnung_get_prepaid_amount($invoice))
				: '');
	}
	if ($bt23 !== '') {
		$xml .= '  <ram:BusinessProcessSpecifiedDocumentContextParameter>'."\n";
		$xml .= '    <ram:ID>'.auvoerechnung_xml_encode($bt23).'</ram:ID>'."\n";
		$xml .= '  </ram:BusinessProcessSpecifiedDocumentContextParameter>'."\n";
	}
	$xml .= '  <ram:GuidelineSpecifiedDocumentContextParameter>'."\n";
	$xml .= '    <ram:ID>'.auvoerechnung_xml_encode($isXRechnung ? auvoerechnung_xrechnung_cius_id() : 'urn:cen.eu:en16931:2017').'</ram:ID>'."\n";
	$xml .= '  </ram:GuidelineSpecifiedDocumentContextParameter>'."\n";
	$xml .= '</rsm:ExchangedDocumentContext>'."\n";

	// === ExchangedDocument ===
	$xml .= '<rsm:ExchangedDocument>'."\n";
	$xml .= '  <ram:ID>'.auvoerechnung_xml_encode($invoice->ref).'</ram:ID>'."\n";
	// XRechnung (BR-DE-17) n'admet pas 386 : la facture d'acompte y est une
	// « Partial invoice » (326), comme le préconise la KoSIT pour les
	// Abschlagsrechnungen. ZUGFeRD/Factur-X garde 386.
	$emittedTypeCode = ($isXRechnung && $typeCode === '386') ? '326' : $typeCode;
	$xml .= '  <ram:TypeCode>'.auvoerechnung_xml_encode($emittedTypeCode).'</ram:TypeCode>'."\n";
	$xml .= '  <ram:IssueDateTime>'."\n";
	$xml .= '    <udt:DateTimeString format="102">'.auvoerechnung_xml_encode($issueDate).'</udt:DateTimeString>'."\n";
	$xml .= '  </ram:IssueDateTime>'."\n";
	// Note BAR (BR-FR-07/20) : qualification du traitement attendu, émise sur le
	// profil PDP uniquement (le B2G Chorus a son propre marquage, hors périmètre)
	// et pour un vendeur français uniquement — le code qualifie le flux de la
	// réforme FR (e-invoicing / e-reporting), sans objet pour un vendeur étranger.
	$barCode = ($profile === 'choruspro' || !$sellerIsFR) ? '' : auvoerechnung_resolve_bar_code($buyer);
	$xml .= auvoerechnung_build_legal_notes_xml($barCode, $sellerIsFR);
	$xml .= '</rsm:ExchangedDocument>'."\n";

	// === SupplyChainTradeTransaction ===
	$xml .= '<rsm:SupplyChainTradeTransaction>'."\n";

	$xmlLineNum = 0;
	foreach ($prepared['lines'] as $pl) {
		$xmlLineNum++;
		$xml .= auvoerechnung_build_line_xml($pl, $xmlLineNum);
	}

	$xml .= '  <ram:ApplicableHeaderTradeAgreement>'."\n";
	// BT-10 : référence acheteur. Pour un acheteur secteur public allemand, la
	// Leitweg-ID (routage ZRE/OZG-RE) doit être la SEULE valeur en BT-10 — elle
	// prime donc sur le code service Chorus et sur ref_client. Hors Allemagne,
	// comportement inchangé : code service Chorus (profil choruspro) sinon ref_client.
	$buyerCountry = strtoupper(!empty($buyer->country_code) ? $buyer->country_code : 'FR');
	$leitwegId = ($buyerCountry === 'DE') ? auvoerechnung_invoice_leitwegid($invoice) : '';
	if ($leitwegId !== '') {
		$buyerRef = $leitwegId;
	} elseif ($profile === 'choruspro' && !empty($options['service_code'])) {
		$buyerRef = $options['service_code'];
	} else {
		$buyerRef = !empty($invoice->ref_client) ? $invoice->ref_client : '';
	}
	if ($buyerRef !== '') {
		$xml .= '    <ram:BuyerReference>'.auvoerechnung_xml_encode($buyerRef).'</ram:BuyerReference>'."\n";
	}
	// XRechnung : contact vendeur (BG-6, BR-DE-2/5/6/7) et adresse électronique
	// de l'acheteur public = Leitweg-ID sous le schéma 0204 (identifiant Peppol
	// des destinataires de l'OZG-RE), à défaut son email.
	$sellerExtra = $isXRechnung ? ['contact' => auvoerechnung_get_seller_contact($invoice, $mysoc)] : [];
	// BT-29 : n° de fournisseur attribué par l'acheteur (« Lieferantennummer »,
	// exigée par § 5 E-RechV si l'administration l'a communiquée), saisi sur la
	// fiche du CLIENT. Émis pour tous les profils.
	$supplierNo = auvoerechnung_party_supplier_number($buyer);
	if ($supplierNo !== '') {
		$sellerExtra['seller_id'] = $supplierNo;
	}
	$buyerExtra = ($isXRechnung && $leitwegId !== '') ? ['endpoint' => ['0204', $leitwegId]] : [];
	$xml .= auvoerechnung_build_trade_party_xml('Seller', $mysoc, $mysoc->email ?? '', $legalIdMode, $sellerExtra);
	$xml .= auvoerechnung_build_trade_party_xml('Buyer', $buyer, auvoerechnung_get_buyer_email($buyer, $invoice->db), $legalIdMode, $buyerExtra);
	// BT-13 : n° d'engagement juridique (Chorus) sinon n° de commande de
	// l'acheteur porté par la commande liée (sa réf. client).
	$orderRef = ($profile === 'choruspro' && !empty($options['engagement']))
		? $options['engagement']
		: auvoerechnung_get_linked_order_ref($invoice);
	// Acheteur avec Leitweg-ID : BT-10 est occupé par la Leitweg-ID ; la réf.
	// client de la facture (« Ihr Zeichen », n° de commande de l'administration
	// exigé par § 5 E-RechV s'il a été communiqué) passe alors en BT-13.
	if ($orderRef === '' && $leitwegId !== '' && !empty($invoice->ref_client)) {
		$orderRef = $invoice->ref_client;
	}
	if ($orderRef !== '') {
		$xml .= '    <ram:BuyerOrderReferencedDocument>'."\n";
		$xml .= '      <ram:IssuerAssignedID>'.auvoerechnung_xml_encode($orderRef).'</ram:IssuerAssignedID>'."\n";
		$xml .= '    </ram:BuyerOrderReferencedDocument>'."\n";
	}
	// BT-12 : référence du marché (Chorus Pro). Séquence XSD : après BuyerOrder.
	if ($profile === 'choruspro' && !empty($options['marche'])) {
		$xml .= '    <ram:ContractReferencedDocument>'."\n";
		$xml .= '      <ram:IssuerAssignedID>'.auvoerechnung_xml_encode($options['marche']).'</ram:IssuerAssignedID>'."\n";
		$xml .= '    </ram:ContractReferencedDocument>'."\n";
	}
	// BG-24 : pièces jointes (PDF de la facture, justificatifs) encodées en
	// base64 dans le XML, préparées par auvoerechnung_collect_attachments().
	foreach ((array) ($options['attachments'] ?? []) as $att) {
		$xml .= '    <ram:AdditionalReferencedDocument>'."\n";
		$xml .= '      <ram:IssuerAssignedID>'.auvoerechnung_xml_encode($att['id']).'</ram:IssuerAssignedID>'."\n";
		$xml .= '      <ram:TypeCode>916</ram:TypeCode>'."\n";
		if (($att['description'] ?? '') !== '') {
			$xml .= '      <ram:Name>'.auvoerechnung_xml_encode($att['description']).'</ram:Name>'."\n";
		}
		$xml .= '      <ram:AttachmentBinaryObject mimeCode="'.auvoerechnung_xml_encode($att['mime']).'" filename="'.auvoerechnung_xml_encode($att['name']).'">'.base64_encode($att['content']).'</ram:AttachmentBinaryObject>'."\n";
		$xml .= '    </ram:AdditionalReferencedDocument>'."\n";
	}
	$xml .= '  </ram:ApplicableHeaderTradeAgreement>'."\n";

	// === Delivery (BT-70..BT-80) ===
	// BT-72 : date de livraison réelle si renseignée sur la facture. Pour les
	// livraisons intracommunautaires (catégorie K), BR-IC-11 exige une date de
	// livraison (repli : date d'émission) et BR-IC-12 un pays de livraison
	// (BT-80, repli : pays de l'acheteur via ShipToTradeParty).
	$hasK = false;
	$hasIntracom = false;
	foreach ($breakdown as $b) {
		if ($b['categoryCode'] === 'K') {
			$hasK = true;
		}
		if ($b['categoryCode'] === 'K' || $b['categoryCode'] === 'AE') {
			$hasIntracom = true;
		}
	}
	// Date de livraison (BT-72) : la vraie date si renseignée, sinon la date
	// d'émission. On la met TOUJOURS : ça satisfait BR-IC-11 pour l'intracom, et
	// surtout ça garantit un ApplicableHeaderTradeDelivery non vide (sinon warning
	// PEPPOL-EN16931-R008 « Document MUST not contain empty elements »).
	$deliveryDateTs = !empty($invoice->delivery_date) ? $invoice->delivery_date : ($invoice->date_livraison ?? null);
	$deliveryDate = !empty($deliveryDateTs) ? date('Ymd', $deliveryDateTs) : $issueDate;

	$xml .= '  <ram:ApplicableHeaderTradeDelivery>'."\n";
	if ($hasK) {
		$shipToCountry = !empty($buyer->country_code) ? $buyer->country_code : 'FR';
		$xml .= '    <ram:ShipToTradeParty>'."\n";
		$xml .= '      <ram:PostalTradeAddress>'."\n";
		$xml .= '        <ram:CountryID>'.auvoerechnung_xml_encode($shipToCountry).'</ram:CountryID>'."\n";
		$xml .= '      </ram:PostalTradeAddress>'."\n";
		$xml .= '    </ram:ShipToTradeParty>'."\n";
	}
	if ($deliveryDate !== null) {
		$xml .= '    <ram:ActualDeliverySupplyChainEvent>'."\n";
		$xml .= '      <ram:OccurrenceDateTime>'."\n";
		$xml .= '        <udt:DateTimeString format="102">'.auvoerechnung_xml_encode($deliveryDate).'</udt:DateTimeString>'."\n";
		$xml .= '      </ram:OccurrenceDateTime>'."\n";
		$xml .= '    </ram:ActualDeliverySupplyChainEvent>'."\n";
	}
	$xml .= '  </ram:ApplicableHeaderTradeDelivery>'."\n";

	// === Settlement ===
	$xml .= '  <ram:ApplicableHeaderTradeSettlement>'."\n";
	// BT-90 : identifiant créancier SEPA (ICS) pour le prélèvement
	if ($directDebit !== null && $directDebit['ics'] !== '') {
		$xml .= '    <ram:CreditorReferenceID>'.auvoerechnung_xml_encode($directDebit['ics']).'</ram:CreditorReferenceID>'."\n";
	}
	$xml .= '    <ram:InvoiceCurrencyCode>'.auvoerechnung_xml_encode($currency).'</ram:InvoiceCurrencyCode>'."\n";

	if ($emitPaymentMeans) {
		$xml .= '    <ram:SpecifiedTradeSettlementPaymentMeans>'."\n";
		$xml .= '      <ram:TypeCode>'.auvoerechnung_xml_encode($paymentMeans).'</ram:TypeCode>'."\n";
		// BT-91 : compte débité (prélèvement)
		if ($directDebit !== null && $directDebit['debtor_iban'] !== '') {
			$xml .= '      <ram:PayerPartyDebtorFinancialAccount>'."\n";
			$xml .= '        <ram:IBANID>'.auvoerechnung_xml_encode($directDebit['debtor_iban']).'</ram:IBANID>'."\n";
			$xml .= '      </ram:PayerPartyDebtorFinancialAccount>'."\n";
		}
		if (!empty($bank['iban'])) {
			$xml .= '      <ram:PayeePartyCreditorFinancialAccount>'."\n";
			$xml .= '        <ram:IBANID>'.auvoerechnung_xml_encode($bank['iban']).'</ram:IBANID>'."\n";
			$xml .= '      </ram:PayeePartyCreditorFinancialAccount>'."\n";
			if (!empty($bank['bic'])) {
				$xml .= '      <ram:PayeeSpecifiedCreditorFinancialInstitution>'."\n";
				$xml .= '        <ram:BICID>'.auvoerechnung_xml_encode($bank['bic']).'</ram:BICID>'."\n";
				$xml .= '      </ram:PayeeSpecifiedCreditorFinancialInstitution>'."\n";
			}
		}
		$xml .= '    </ram:SpecifiedTradeSettlementPaymentMeans>'."\n";
	}

	// BT-8 : exigibilité TVA (5 = débits, 72 = encaissements), émise sur les
	// catégories taxées uniquement. Dérivée automatiquement du régime TVA de
	// Dolibarr (constante TAX_MODE, Configuration > Taxes) — plus de réglage dédié.
	$dueDateTypeCode = auvoerechnung_resolve_vat_due_date_code();

	foreach ($breakdown as $amounts) {
		$xml .= '    <ram:ApplicableTradeTax>'."\n";
		$xml .= '      <ram:CalculatedAmount>'.auvoerechnung_format_amount($amounts['tax']).'</ram:CalculatedAmount>'."\n";
		$xml .= '      <ram:TypeCode>VAT</ram:TypeCode>'."\n";
		// ExemptionReason émis pour toute catégorie non-standard quand un motif est disponible
		if (!empty($amounts['exemption']) && in_array($amounts['categoryCode'], ['E', 'K', 'G', 'O', 'Z', 'AE'], true)) {
			$xml .= '      <ram:ExemptionReason>'.auvoerechnung_xml_encode($amounts['exemption']).'</ram:ExemptionReason>'."\n";
		}
		$xml .= '      <ram:BasisAmount>'.auvoerechnung_format_amount($amounts['base']).'</ram:BasisAmount>'."\n";
		$xml .= '      <ram:CategoryCode>'.auvoerechnung_xml_encode($amounts['categoryCode']).'</ram:CategoryCode>'."\n";
		// BT-121 : code d'exonération VATEX (attendu par la réforme FR)
		if (!empty($amounts['vatex'])) {
			$xml .= '      <ram:ExemptionReasonCode>'.auvoerechnung_xml_encode($amounts['vatex']).'</ram:ExemptionReasonCode>'."\n";
		}
		if ($dueDateTypeCode !== '' && $amounts['categoryCode'] === 'S') {
			$xml .= '      <ram:DueDateTypeCode>'.auvoerechnung_xml_encode($dueDateTypeCode).'</ram:DueDateTypeCode>'."\n";
		}
		// BR-O-05 : pas de RateApplicablePercent pour CategoryCode='O' (services hors champ)
		if ($amounts['categoryCode'] !== 'O') {
			$xml .= '      <ram:RateApplicablePercent>'.auvoerechnung_format_amount($amounts['rate']).'</ram:RateApplicablePercent>'."\n";
		}
		$xml .= '    </ram:ApplicableTradeTax>'."\n";
	}

	// BG-14 : période de facturation (dates de service des lignes Dolibarr)
	$xml .= auvoerechnung_build_billing_period_xml($billableLines);

	// BG-21 : remises pied de facture (issues des lignes à montant négatif)
	foreach ($prepared['allowances'] as $al) {
		$xml .= '    <ram:SpecifiedTradeAllowanceCharge>'."\n";
		$xml .= '      <ram:ChargeIndicator><udt:Indicator>false</udt:Indicator></ram:ChargeIndicator>'."\n";
		$xml .= '      <ram:ActualAmount>'.auvoerechnung_format_amount($al['amount']).'</ram:ActualAmount>'."\n";
		$xml .= '      <ram:Reason>'.auvoerechnung_xml_encode($al['reason']).'</ram:Reason>'."\n";
		$xml .= '      <ram:CategoryTradeTax>'."\n";
		$xml .= '        <ram:TypeCode>VAT</ram:TypeCode>'."\n";
		$xml .= '        <ram:CategoryCode>'.auvoerechnung_xml_encode($al['categoryCode']).'</ram:CategoryCode>'."\n";
		if ($al['categoryCode'] !== 'O') {
			$xml .= '        <ram:RateApplicablePercent>'.auvoerechnung_format_amount($al['rate']).'</ram:RateApplicablePercent>'."\n";
		}
		$xml .= '      </ram:CategoryTradeTax>'."\n";
		$xml .= '    </ram:SpecifiedTradeAllowanceCharge>'."\n";
	}

	$xml .= '    <ram:SpecifiedTradePaymentTerms>'."\n";
	// BT-20 : conditions de paiement avec les lignes Skonto (BR-DE-18)
	$paymentTermsText = auvoerechnung_build_payment_terms_description($invoice, $isCreditNote);
	if ($paymentTermsText !== '') {
		$xml .= '      <ram:Description>'.auvoerechnung_xml_encode($paymentTermsText).'</ram:Description>'."\n";
	}
	$xml .= '      <ram:DueDateDateTime>'."\n";
	$xml .= '        <udt:DateTimeString format="102">'.auvoerechnung_xml_encode($dueDate).'</udt:DateTimeString>'."\n";
	$xml .= '      </ram:DueDateDateTime>'."\n";
	// BT-89 : référence unique de mandat (RUM) pour le prélèvement SEPA
	if ($directDebit !== null && $directDebit['rum'] !== '') {
		$xml .= '      <ram:DirectDebitMandateID>'.auvoerechnung_xml_encode($directDebit['rum']).'</ram:DirectDebitMandateID>'."\n";
	}
	$xml .= '    </ram:SpecifiedTradePaymentTerms>'."\n";

	$xml .= auvoerechnung_build_monetary_summation_xml($invoice, $currency, $sign, $prepared, $breakdown, $buildWarnings);

	// BG-3 : références aux factures antérieures (facture d'origine d'un avoir /
	// d'une rectificative, factures d'acompte imputées sur une facture finale).
	// Rectificative (384) : seule la facture remplacée est référencée (BR-FR-CO-04).
	foreach (auvoerechnung_get_preceding_invoices($invoice, $typeCode) as $prev) {
		$xml .= '    <ram:InvoiceReferencedDocument>'."\n";
		$xml .= '      <ram:IssuerAssignedID>'.auvoerechnung_xml_encode($prev['ref']).'</ram:IssuerAssignedID>'."\n";
		if (!empty($prev['date'])) {
			$xml .= '      <ram:FormattedIssueDateTime>'."\n";
			$xml .= '        <qdt:DateTimeString format="102">'.auvoerechnung_xml_encode($prev['date']).'</qdt:DateTimeString>'."\n";
			$xml .= '      </ram:FormattedIssueDateTime>'."\n";
		}
		$xml .= '    </ram:InvoiceReferencedDocument>'."\n";
	}

	$xml .= '  </ram:ApplicableHeaderTradeSettlement>'."\n";

	$xml .= '</rsm:SupplyChainTradeTransaction>'."\n";
	$xml .= '</rsm:CrossIndustryInvoice>';

	// Avoir (381, BR-FR-CO-05) ou rectificative (384, BR-FR-CO-04) sans facture
	// d'origine : la référence BT-25 exigée ne peut pas être émise. Warning
	// actionnable ici ; le validateur interne porte la violation bloquante.
	if (empty($invoice->fk_facture_source)) {
		if ($isCreditNote) {
			$buildWarnings[] = auvoerechnung_trans('AuvoERechnungWarnCreditNoteNoSource');
		} elseif ($typeCode === '384') {
			$buildWarnings[] = auvoerechnung_trans('AuvoERechnungWarnCorrectiveNoSource');
		}
	}

	return $xml;
}

/**
 * Renvoie les seules lignes facturables (qty != 0 OU total_ht != 0).
 * Filtre les descriptions, titres et sous-totaux qui ne portent ni quantité
 * ni montant et ne doivent pas apparaître dans le XML EN16931.
 *
 * @param array $lines Lignes Dolibarr
 * @return array
 */
function auvoerechnung_filter_billable_lines($lines)
{
	$out = [];
	foreach ((array) $lines as $line) {
		// product_type=9 est la convention cœur Dolibarr pour les lignes de
		// présentation (titres, sous-totaux) ajoutées par le module natif
		// "Subtotals" (special_code 81) ou par des modules tiers comme
		// ATM Consulting "Sous-total" (special_code 104777). Ces lignes ne
		// représentent aucun bien/service facturable ; certaines portent un
		// montant non nul (sous-total affiché), ce qui les ferait compter en
		// double dans le XML EN16931 si elles n'étaient pas exclues ici.
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
 * Prépare les lignes pour le XML : normalise le signe (avoirs), arrondit les
 * totaux, et requalifie les lignes à montant négatif (remises fixes Dolibarr)
 * en remises document BG-21 pour respecter BR-27.
 *
 * Si AUCUNE ligne positive ne subsiste (facture 380 entièrement négative,
 * cas hors convention), les lignes sont conservées telles quelles : le XML
 * violera BR-27 mais le contrôle interne des règles métier le signalera.
 *
 * @return array ['lines' => array, 'allowances' => array]
 */
function auvoerechnung_prepare_lines($billableLines, $sign, $invoice, $thirdparty, $mysoc, &$buildWarnings)
{
	// Constructeur unique de ligne préparée : utilisé par le chemin nominal ET
	// le fallback "tout négatif" pour que les deux émettent le même mapping.
	$buildLine = function ($line) use ($sign, $invoice, $thirdparty, $mysoc) {
		$desc = trim(strip_tags($line->desc ?: ($line->description ?? $line->label ?? '')));
		if ($desc === '') {
			$desc = 'Article';
		}
		$lineTotal = round($sign * (float) $line->total_ht, 2);
		$qty = abs((float) $line->qty);
		return [
			'desc'      => $desc,
			'qty'       => ($qty != 0.0) ? $qty : 1.0,
			'unitPrice' => ($qty != 0.0) ? $lineTotal / $qty : $lineTotal,
			'lineTotal' => $lineTotal,
			'lineTax'   => $sign * (float) $line->total_tva,
			'vatRate'   => (float) $line->tva_tx,
			'taxCat'    => auvoerechnung_resolve_tax_category($line, $invoice, $thirdparty, $mysoc),
			'unitCode'  => auvoerechnung_map_unit_code($line),
		];
	};

	$lines = [];
	$allowances = [];
	foreach ($billableLines as $line) {
		$pl = $buildLine($line);
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

	if (empty($lines) && !empty($allowances)) {
		// Facture intégralement négative hors avoir : pas de conversion BG-21
		// possible (il faut au moins une ligne). On ré-émet les lignes brutes.
		$buildWarnings[] = auvoerechnung_trans('AuvoERechnungWarnAllNegativeLines');
		$lines = array_map($buildLine, $billableLines);
		$allowances = [];
	}

	return ['lines' => $lines, 'allowances' => $allowances];
}

/**
 * Résout le compte bancaire (IBAN/BIC) à publier dans le XML.
 *
 * Priorité — pour que le XML colle au PDF (issue #16) :
 *   1. le compte choisi SUR LA FACTURE (fk_account), c.-à-d. exactement celui
 *      que Dolibarr imprime dans le PDF quand un compte est sélectionné au
 *      niveau du tiers ou de la facture ;
 *   2. à défaut, le compte figé dans la config du module
 *      (AUVOERECHNUNG_BANK_ACCOUNT) — repli historique, préserve l'existant.
 *
 * @param object      $db      Handle DB Dolibarr
 * @param object|null $invoice Facture courante (pour lire fk_account) ; null = config seule
 * @return array ['iban' => string, 'bic' => string] (chaînes vides si aucun compte résolu)
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
 * Informations de prélèvement SEPA (moyen de paiement 59) :
 * - ICS créancier (BT-90) depuis la constante Dolibarr PRELEVEMENT_ICS
 * - RUM du mandat (BT-89) et IBAN débiteur (BT-91) depuis le RIB par défaut du tiers
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
		// type = 'ban' : llx_societe_rib stocke aussi les modes de paiement
		// carte (Stripe...) avec default_rib = 1 — il ne faut pas les lire.
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
 * Paliers d'escompte (Skonto) configurés pour une condition de règlement.
 *
 * Configuration : constante AUVOERECHNUNG_SKONTO, JSON indexé par le code de
 * la condition de règlement Dolibarr (dictionnaire c_payment_term), ex.
 * {"7D":[{"days":7,"percent":2}]}. Les entrées invalides sont ignorées.
 *
 * @param string $paymentTermCode  Code de la condition de règlement
 * @return array<int,array{days:int,percent:float}>  Paliers triés par délai
 */
function auvoerechnung_get_skonto_terms($paymentTermCode)
{
	$code = trim((string) $paymentTermCode);
	if ($code === '') {
		return [];
	}
	$all = json_decode(getDolGlobalString('AUVOERECHNUNG_SKONTO', ''), true);
	if (!is_array($all) || empty($all[$code]) || !is_array($all[$code])) {
		return [];
	}
	$terms = [];
	foreach ($all[$code] as $t) {
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
 * Lignes Skonto au format imposé par la KoSIT (BR-DE-18), aussi recommandé
 * par ZUGFeRD : « #SKONTO#TAGE=7#PROZENT=2.00# ». Chaque ligne se termine par
 * un saut de ligne, y compris la dernière (sinon BR-DE-18 rejette).
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
 * Libellé « sur facture » de la condition de règlement (traduction Dolibarr
 * PaymentCondition<code> si elle existe, sinon le texte du dictionnaire).
 * Les retours à la ligne sont aplatis et les « # » de tête retirés : en BT-20,
 * une ligne commençant par « # » serait lue comme une ligne Skonto.
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
 * Contenu de BT-20 (Payment terms) : texte de la condition de règlement suivi
 * des lignes Skonto. Vide (élément non émis) sans escompte configuré, et pour
 * un avoir, auquel un escompte ne s'applique pas.
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
	$terms = auvoerechnung_get_skonto_terms($invoice->cond_reglement_code ?? '');
	if (empty($terms)) {
		return '';
	}
	$text = auvoerechnung_payment_terms_text($invoice);
	return ($text !== '' ? $text."\n" : '').auvoerechnung_skonto_lines($terms);
}

/**
 * Propose un palier Skonto à partir du libellé d'une condition de règlement
 * (« 7 Tage 2% Skonto », « 2 % Skonto bei Zahlung innerhalb von 10 Tagen »…).
 * Simple suggestion affichée dans la configuration, jamais appliquée seule.
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
 * Retourne la valeur d'une constante Dolibarr, ou le défaut fourni si elle est
 * absente OU vide. getDolGlobalString() ne retombe sur le défaut que lorsque la
 * clé n'existe pas ; or nos constantes de configuration sont créées vides à
 * l'activation du module, donc le défaut ne s'appliquait jamais sans ce garde-fou.
 */
function auvoerechnung_conf_or_default($key, $default)
{
	$val = trim(getDolGlobalString($key, ''));
	return $val !== '' ? $val : $default;
}

/**
 * Mentions légales PMD/PMT/AAB effectivement applicables, dans l'ordre
 * d'émission, après surcharge par les constantes Dolibarr.
 *
 * Les trois textes par défaut sont des obligations du droit français
 * (art. L.441-9 et L.441-10 du Code de commerce, règle BR-FR-05). Pour un
 * vendeur établi hors de France, aucun défaut n'est appliqué : Factur-X est
 * la même spécification que ZUGFeRD, et imprimer d'office des mentions
 * françaises — ou leur transposition devinée — dans la facture d'un vendeur
 * étranger serait faux. Les constantes AUVOERECHNUNG_NOTE_* restent
 * pleinement utilisables : un vendeur allemand y saisit ses propres mentions
 * (§ 288 BGB, escompte…), et elles sont alors émises normalement.
 *
 * @param bool $sellerIsFR  cf. auvoerechnung_seller_is_fr()
 * @return array<string,string>  ['PMD' => texte, 'PMT' => texte, 'AAB' => texte]
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
 * Génère les 3 IncludedNote BR-FR-05 (PMD, PMT, AAB) avec les valeurs
 * surchargeables via constantes Dolibarr (défaut appliqué si la constante est vide),
 * plus la note BAR (qualification du traitement attendu, BR-FR-07/20) quand un
 * code est fourni. Chaque code sujet n'est émis qu'une fois (BR-FR-06, BR-FR-31).
 *
 * @param string $barCode     'B2B', 'B2BINT' ou 'B2C' (cf. auvoerechnung_resolve_bar_code),
 *                            ou '' pour ne pas émettre de note BAR
 * @param bool   $sellerIsFR  cf. auvoerechnung_seller_is_fr()
 */
function auvoerechnung_build_legal_notes_xml($barCode = '', $sellerIsFR = true)
{
	$notes = auvoerechnung_resolve_legal_notes($sellerIsFR);
	if ($barCode !== '') {
		// BR-FR-20 : le contenu (BT-22) est le code brut, sans texte libre autour
		$notes['BAR'] = $barCode;
	}
	$xml = '';
	foreach ($notes as $code => $content) {
		// Une note sans contenu violerait PEPPOL-EN16931-R008 (« Document MUST
		// not contain empty elements ») : cas d'un vendeur étranger sans
		// mention configurée.
		if (trim((string) $content) === '') {
			continue;
		}
		$xml .= '  <ram:IncludedNote>'."\n";
		$xml .= '    <ram:Content>'.auvoerechnung_xml_encode($content).'</ram:Content>'."\n";
		$xml .= '    <ram:SubjectCode>'.$code.'</ram:SubjectCode>'."\n";
		$xml .= '  </ram:IncludedNote>'."\n";
	}
	return $xml;
}

/**
 * Code de qualification du traitement attendu (note BAR — règles BR-FR-07,
 * BR-FR-20 et BR-FR-31 : une seule valeur parmi la liste fermée) :
 *  - 'B2C'    : acheteur non assujetti (particulier ou « Assujetti à la TVA » à
 *               Non) → relève du e-reporting des ventes B2C ;
 *  - 'B2BINT' : acheteur assujetti établi hors France → e-reporting des ventes
 *               B2B internationales ;
 *  - 'B2B'    : acheteur assujetti français → e-invoicing (cas nominal).
 * Les codes pays des DOM/COM (remplacés par FR dans les flux, cf. BR-FR-MAP-14)
 * sont assimilés à la France. Les valeurs OUTOFSCOPE et ARCHIVEONLY couvrent des
 * cas (hors réforme, avoir interne d'annulation) que le module ne produit pas.
 *
 * Choix assumé : un acheteur non assujetti établi HORS France est aussi émis en
 * 'B2C'. La liste fermée de BR-FR-20 (XP Z12-012, révision juillet 2025) ne
 * comporte aucune valeur « B2C international » — émettre un code hors liste
 * violerait BR-FR-20 ; la qualification fine domestique/international relève du
 * flux e-reporting (10.x), pas de la note BAR. À revoir si une révision
 * ultérieure de la norme ajoute cette valeur.
 *
 * @param Societe|object $buyer  Tiers acheteur
 * @return string 'B2B', 'B2BINT' ou 'B2C'
 */
function auvoerechnung_resolve_bar_code($buyer)
{
	if (auvoerechnung_is_non_assujetti($buyer)) {
		return 'B2C';
	}
	$country = strtoupper(!empty($buyer->country_code) ? $buyer->country_code : 'FR');
	// Codes remplacés par FR dans les flux (BR-FR-MAP-14) : traités comme FR
	if ($country !== 'FR' && !in_array($country, AUVOERECHNUNG_FR_OVERSEAS_COUNTRIES, true)) {
		return 'B2BINT';
	}
	return 'B2B';
}

/**
 * Cadre de facturation du socle réforme (BT-23, règle BR-FR-08) pour le profil
 * PDP/B2B. Liste fermée XP Z12-012 : la lettre qualifie la nature de la facture
 * (B = biens, S = services, M = double), déduite du product_type des lignes ;
 * le chiffre qualifie le processus de dépôt.
 *  - Cas nominal (dépôt de la facture par le fournisseur) : B1 / S1 / M1.
 *  - Facture définitive après acompte (acomptes imputés) : B4 / S4 / M4 —
 *    jamais sur une facture d'acompte elle-même (interdit par BR-FR-CO-08).
 * Les processus spécifiques (déjà payée x2, sous-traitance S3/S5/S6,
 * autofacturation, multi-vendeurs x8, bidirectionnel x9) ne correspondent à
 * aucun flux produit par ce module : non émis.
 *
 * @param array  $billableLines  Lignes facturables (cf. auvoerechnung_filter_billable_lines)
 * @param string $typeCode       TypeCode BT-3 émis ('380', '386', '381'...)
 * @param float  $prepaidAmount  Montant d'acomptes imputés (BT-113)
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
	// BR-FR-CO-08 : un cadre x4 (facture définitive après acompte) est
	// incompatible avec les types facture d'acompte (386/500/503)
	$isDepositInvoice = in_array($typeCode, ['386', '500', '503'], true);
	$digit = (!$isDepositInvoice && (float) $prepaidAmount > 0) ? '4' : '1';
	return $letter.$digit;
}

/**
 * BG-14 : période de facturation déduite des dates de service des lignes
 * (min des date_start / max des date_end). Bloc omis si aucune ligne datée.
 */
function auvoerechnung_build_billing_period_xml($billableLines)
{
	$start = null;
	$end = null;
	foreach ($billableLines as $line) {
		if (!empty($line->date_start) && ($start === null || $line->date_start < $start)) {
			$start = $line->date_start;
		}
		if (!empty($line->date_end) && ($end === null || $line->date_end > $end)) {
			$end = $line->date_end;
		}
	}
	if ($start === null && $end === null) {
		return '';
	}

	$xml = '    <ram:BillingSpecifiedPeriod>'."\n";
	if ($start !== null) {
		$xml .= '      <ram:StartDateTime>'."\n";
		$xml .= '        <udt:DateTimeString format="102">'.date('Ymd', $start).'</udt:DateTimeString>'."\n";
		$xml .= '      </ram:StartDateTime>'."\n";
	}
	if ($end !== null) {
		$xml .= '      <ram:EndDateTime>'."\n";
		$xml .= '        <udt:DateTimeString format="102">'.date('Ymd', $end).'</udt:DateTimeString>'."\n";
		$xml .= '      </ram:EndDateTime>'."\n";
	}
	$xml .= '    </ram:BillingSpecifiedPeriod>'."\n";

	return $xml;
}

/**
 * Génère le XML d'une ligne préparée (cf. auvoerechnung_prepare_lines).
 *
 * @param array  $pl       Ligne préparée (desc, qty, unitPrice, lineTotal, vatRate, taxCat, unitCode)
 * @param int    $lineNum  Numéro de ligne séquentiel
 * @return string XML
 */
function auvoerechnung_build_line_xml($pl, $lineNum)
{
	$xml  = '  <ram:IncludedSupplyChainTradeLineItem>'."\n";
	$xml .= '    <ram:AssociatedDocumentLineDocument>'."\n";
	$xml .= '      <ram:LineID>'.auvoerechnung_xml_encode((string) $lineNum).'</ram:LineID>'."\n";
	$xml .= '    </ram:AssociatedDocumentLineDocument>'."\n";
	$xml .= '    <ram:SpecifiedTradeProduct>'."\n";
	$xml .= '      <ram:Name>'.auvoerechnung_xml_encode($pl['desc']).'</ram:Name>'."\n";
	$xml .= '    </ram:SpecifiedTradeProduct>'."\n";
	$xml .= '    <ram:SpecifiedLineTradeAgreement>'."\n";
	$xml .= '      <ram:NetPriceProductTradePrice>'."\n";
	// BT-146 : 4 décimales pour limiter l'écart qty x prix vs total de ligne
	$xml .= '        <ram:ChargeAmount>'.auvoerechnung_format_unit_price($pl['unitPrice']).'</ram:ChargeAmount>'."\n";
	$xml .= '      </ram:NetPriceProductTradePrice>'."\n";
	$xml .= '    </ram:SpecifiedLineTradeAgreement>'."\n";
	$xml .= '    <ram:SpecifiedLineTradeDelivery>'."\n";
	$xml .= '      <ram:BilledQuantity unitCode="'.auvoerechnung_xml_encode($pl['unitCode']).'">'.auvoerechnung_format_qty($pl['qty']).'</ram:BilledQuantity>'."\n";
	$xml .= '    </ram:SpecifiedLineTradeDelivery>'."\n";
	$xml .= '    <ram:SpecifiedLineTradeSettlement>'."\n";
	$xml .= '      <ram:ApplicableTradeTax>'."\n";
	$xml .= '        <ram:TypeCode>VAT</ram:TypeCode>'."\n";
	$xml .= '        <ram:CategoryCode>'.auvoerechnung_xml_encode($pl['taxCat']['code']).'</ram:CategoryCode>'."\n";
	// BR-O-04 : pas de RateApplicablePercent pour CategoryCode='O' (services hors champ)
	if ($pl['taxCat']['code'] !== 'O') {
		$xml .= '        <ram:RateApplicablePercent>'.auvoerechnung_format_amount($pl['vatRate']).'</ram:RateApplicablePercent>'."\n";
	}
	$xml .= '      </ram:ApplicableTradeTax>'."\n";
	$xml .= '      <ram:SpecifiedTradeSettlementLineMonetarySummation>'."\n";
	$xml .= '        <ram:LineTotalAmount>'.auvoerechnung_format_amount($pl['lineTotal']).'</ram:LineTotalAmount>'."\n";
	$xml .= '      </ram:SpecifiedTradeSettlementLineMonetarySummation>'."\n";
	$xml .= '    </ram:SpecifiedLineTradeSettlement>'."\n";
	$xml .= '  </ram:IncludedSupplyChainTradeLineItem>'."\n";

	return $xml;
}

/**
 * Calcule la ventilation TVA par (catégorie, taux) — deux catégories distinctes
 * au même taux (ex. K et AE à 0 %) produisent deux blocs ApplicableTradeTax.
 *
 * Consomme les lignes/remises préparées par auvoerechnung_prepare_lines() (mêmes
 * catégories, mêmes arrondis) pour garantir la cohérence BR-S-08 : base par
 * (catégorie, taux) = somme des lignes - remises de la catégorie.
 *
 * Réconciliation des taxes, dans l'ordre de priorité :
 *  1. sum(tax) == total_tva facture (écart d'arrondi imputé au plus gros groupe)
 *  2. BR-CO-17 : chaque groupe doit rester à +/-0.01 de base x taux ; sinon la
 *     taxe du groupe est recalculée sur la base et un avertissement est émis
 *     (l'écart résiduel avec le TTC Dolibarr est signalé par build_xml).
 *
 * @param array  $prepared       ['lines' => [...], 'allowances' => [...]] (cf. prepare_lines)
 * @param object $invoice        Facture Dolibarr
 * @param float  $sign           1.0 ou -1.0 (avoir)
 * @param array  $buildWarnings  (sortie) avertissements
 * @return array<string,array>   Indexé par "categorie|taux", avec base/tax/rate/categoryCode/exemption/vatex
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

	// Arrondi des taxes par groupe puis réconciliation avec le total facture
	$sumTax = 0.0;
	foreach ($breakdown as $key => $b) {
		$breakdown[$key]['base'] = round($b['base'], 2);
		$breakdown[$key]['tax'] = round($b['tax'], 2);
		$sumTax += $breakdown[$key]['tax'];
	}
	$invoiceTax = round($sign * (float) $invoice->total_tva, 2);
	$delta = round($invoiceTax - $sumTax, 2);
	if (abs($delta) >= 0.01) {
		// Imputer l'écart d'arrondi sur le groupe taxé à la plus grande base
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

	// Garde-fou BR-CO-17 : la taxe d'un groupe ne doit pas s'écarter de plus
	// d'un centime de base x taux (rejet Schematron sinon). Si l'imputation
	// ci-dessus (ou des arrondis ligne à ligne cumulés) dépasse la tolérance,
	// on recale la taxe du groupe sur sa base — la priorité va à la validité
	// EN16931 du XML, l'écart avec les totaux Dolibarr est signalé en aval.
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
 * Vrai si le tiers est hors champ de l'e-invoicing B2B (réforme 2026-2027) :
 * particulier (typent TE_PRIVATE) OU non assujetti à la TVA (champ « Assujetti
 * à la TVA » de la fiche tiers). Ces tiers relèvent de l'e-reporting, pas de
 * la facturation électronique entre assujettis.
 *
 * Le typent seul ne suffit pas (une association non assujettie n'est pas un
 * « Particulier ») et le tva_assuj seul non plus (souvent laissé par défaut) :
 * on combine les deux.
 *
 * @param Societe|null $thirdparty  Tiers Dolibarr (fetch déjà fait)
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
 * Vérifie les infos obligatoires pour Factur-X EN16931 + BR-FR
 * Retourne un tableau de warnings (vide si tout est OK)
 *
 * @param Facture $invoice   Objet facture Dolibarr
 * @param Societe $mysoc     Société émettrice
 * @return array             Liste de messages d'avertissement
 */
function auvoerechnung_check_mandatory($invoice, $mysoc)
{
	$warnings = [];

	// SIREN/SIRET : identifiants français — mêmes règles de pays que le générateur
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
		// Franchise en base (293 B CGI) : pas de TVA intra → ne pas warn
		if ($field === 'tva_intra' && $isFranchise) {
			continue;
		}
		// SIRET/SIREN : identifiant français uniquement, non pertinent hors FR
		if ($field === 'idprof2' && !$sellerIsFR) {
			continue;
		}
		if (empty($mysoc->$field)) {
			$warnings[] = auvoerechnung_trans($transKey);
		}
	}

	// Champ TVA intra rempli mais sans préfixe pays (BR-CO-09) : il n'est pas
	// publié en BT-31. Cas typique : Steuernummer saisie au mauvais endroit.
	if (trim((string) ($mysoc->tva_intra ?? '')) !== '' && !auvoerechnung_is_vat_id($mysoc->tva_intra)) {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnSellerVATInvalid', $mysoc->tva_intra);
	}

	// BT-34 (adresse électronique vendeur) : satisfaite par le SIREN (endpoint 0225)
	// OU l'email. On n'avertit que si aucune des deux n'est disponible.
	// Hors vendeur FR, BT-34 est facultatif en EN16931 (BR-FR-13 ne s'applique
	// pas) : pas d'avertissement.
	$sellerSiren = $sellerIsFR ? auvoerechnung_party_siren($mysoc) : '';
	if ($sellerIsFR && $sellerSiren === '' && empty($mysoc->email)) {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnSellerEndpoint');
	}

	// BT-29 (ram:ID) : si un SIRET est renseigné (idprof2), il doit faire 14
	// chiffres. Il identifie l'établissement ; le SIREN (BT-30) vient d'idprof1.
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

	// BT-49 (adresse électronique acheteur) : satisfaite par le SIREN (endpoint 0225)
	// OU l'email. Pour un acheteur FR, l'absence de SIREN empêche le routage PA/PDP,
	// même si un email est présent (un email n'est pas routable sur le réseau).
	$buyerIsFR  = strtoupper(!empty($buyer->country_code) ? $buyer->country_code : 'FR') === 'FR';
	$buyerSiren = $buyerIsFR ? auvoerechnung_party_siren($buyer) : '';
	$buyerEmail = auvoerechnung_get_buyer_email($buyer, $invoice->db);
	// Hors vendeur FR, BT-49 est facultatif en EN16931 (BR-FR-12 ne s'applique
	// pas ; seule XRechnung l'exige, profil non émis ici) : pas d'avertissement.
	if ($sellerIsFR && $buyerSiren === '' && $buyerEmail === '') {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnBuyerEndpoint');
	} elseif ($sellerIsFR && $buyerSiren === '' && $buyerIsFR && !auvoerechnung_is_non_assujetti($buyer)) {
		// Non assujetti (particulier OU « Assujetti à la TVA » à Non, ex. asso) :
		// pas de SIREN, hors champ e-invoicing (relève du e-reporting)
		// → pas d'avertissement de routage
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnBuyerSIRENRouting');
	}

	// BT-46 acheteur (ram:ID) : si un SIRET est renseigné, il doit faire 14 chiffres.
	$buyerSiret = $buyerIsFR ? preg_replace('/[^0-9]/', '', $buyer->idprof2 ?? '') : '';
	if ($buyerSiret !== '' && strlen($buyerSiret) !== 14) {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnBuyerSIRETLen', strlen($buyerSiret));
	}

	// BR-FR-01/02 : identifiant de facture (BT-1) — 35 caractères maximum,
	// alphanumériques + « - + _ / » uniquement (l'espace n'est pas admis).
	// Contrôle amont ; la violation bloquante est portée par le validateur.
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

	// BR-FR-09 : quand un SIREN (idprof1) ET un SIRET (idprof2) sont saisis,
	// les 9 premiers chiffres du SIRET doivent correspondre au SIREN.
	$sellerSirenSaisi = $sellerIsFR ? preg_replace('/[^0-9]/', '', $mysoc->idprof1 ?? '') : '';
	if (strlen($sellerSirenSaisi) === 9 && strlen($sellerSiret) === 14 && strncmp($sellerSiret, $sellerSirenSaisi, 9) !== 0) {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnSellerSirenSiretMismatch', $sellerSirenSaisi, $sellerSiret);
	}
	$buyerSirenSaisi = $buyerIsFR ? preg_replace('/[^0-9]/', '', $buyer->idprof1 ?? '') : '';
	if (strlen($buyerSirenSaisi) === 9 && strlen($buyerSiret) === 14 && strncmp($buyerSiret, $buyerSirenSaisi, 9) !== 0) {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnBuyerSirenSiretMismatch', $buyerSirenSaisi, $buyerSiret);
	}

	// BR-FR-16 : taux de TVA hors liste française fermée (métropole + DOM).
	// AVERTISSEMENT uniquement — la liste de la norme peut évoluer et un taux
	// légitime ne doit jamais être bloqué.
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

	// BR-FR-23/25 : suffixe d'adresse électronique vendeur — charset fermé
	// (sanitisé à l'émission par auvoerechnung_build_endpoint_uri) et longueur
	// totale de l'adresse limitée à 125 caractères.
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

	// Compte bancaire : OK si la facture en porte un (fk_account) OU si le module
	// en a un configuré. On n'avertit que si les deux manquent (issue #16 : le
	// compte de la facture prime sur la config).
	if (empty($invoice->fk_account) && getDolGlobalInt('AUVOERECHNUNG_BANK_ACCOUNT') <= 0) {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnNoBank');
	}

	// PDF/A-3 : sans police embarquée forcée, le PDF TCPDF utilise les polices
	// base-14 non embarquées et échoue à la validation veraPDF.
	if (function_exists('getDolGlobalString') && getDolGlobalString('MAIN_PDF_FORCE_FONT', '') === '') {
		$warnings[] = auvoerechnung_trans('AuvoERechnungWarnNoForceFont');
	}

	return $warnings;
}

/**
 * Génère le bloc XML d'un TradeParty (vendeur ou acheteur).
 *
 * @param string $role   'Seller' ou 'Buyer'
 * @param object $party  Société émettrice (mysoc) ou Societe acheteur
 * @param string $email  Email à publier dans le bloc URI (BT-49 / BT-34)
 * @param string $legalIdMode  'siren' (défaut) ou 'siret' (Chorus Pro)
 * @param array  $extra  Options du profil XRechnung :
 *                       'contact'  => ['name' =>, 'phone' =>, 'email' =>] (BG-6)
 *                       'endpoint' => [schemeID, valeur] remplaçant l'adresse
 *                                     électronique calculée (ex. 0204 + Leitweg-ID)
 *                       'seller_id' => BT-29 (n° de fournisseur chez l'acheteur)
 */
function auvoerechnung_build_trade_party_xml($role, $party, $email, $legalIdMode = 'siren', $extra = [])
{
	$tag = ($role === 'Seller') ? 'SellerTradeParty' : 'BuyerTradeParty';
	$country = !empty($party->country_code) ? $party->country_code : 'FR';
	$vat     = auvoerechnung_party_vat_id($party);
	// SIREN/SIRET : identifiants français uniquement. Pour un tiers étranger,
	// idprof2 contient un identifiant local (HRB allemand, CRN...) qui ne doit
	// surtout pas être publié sous un scheme SIREN/SIRET (0002/0009/0225) —
	// l'endpoint retombe alors sur l'email (EM).
	// EN16931 CII : deux champs DISTINCTS, chacun son identifiant (ISO 6523).
	//  - BT-29/BT-46  ram:ID                        = SIRET (14 chiffres), schemeID 0009  → l'établissement
	//  - BT-30/BT-47  SpecifiedLegalOrganization/ID = SIREN (9 chiffres),  schemeID 0002  → l'entité légale
	// L'ordre suit la séquence XSD du TradePartyType : ID, Name, SpecifiedLegalOrganization.
	// Source SIREN = idprof1 (à défaut dérivé du SIRET) ; source SIRET = idprof2.
	$isFR = (strtoupper($country) === 'FR');
	$siren = $isFR ? auvoerechnung_party_siren($party) : '';
	$siretDigits = $isFR ? preg_replace('/[^0-9]/', '', $party->idprof2 ?? '') : '';
	$siret14 = (strlen($siretDigits) === 14) ? $siretDigits : '';

	// Deux profils selon la destination (cf. réforme FR : deux réseaux distincts).
	//  - 'siren' (défaut, PDP/B2B) : SIRET en BT-29 (ram:ID/0009) + SIREN en BT-30
	//    (SpecifiedLegalOrganization/0002). Conforme norme EN16931 / règle BR-FR-10.
	//  - 'siret' (Chorus Pro/B2G) : SIRET-14 en BT-30 (SpecifiedLegalOrganization/0009),
	//    qui est la clé de routage de l'annuaire Chorus Pro (il ne lit QUE ce champ).
	$chorus = ($legalIdMode === 'siret');

	// Vendeur allemand : BR-CO-26 exige BT-29, BT-30 ou BT-31 — la Steuernummer
	// (BT-32) n'y compte PAS. Registre du commerce (idprof3, ProfId3DE
	// « Handelsregister-Nummer ») → BT-30 ; sans USt-IdNr ni registre (cas typique
	// du Kleinunternehmer), la Steuernummer est aussi portée en BT-29 (Seller
	// identifier, sans schéma) pour que le vendeur reste identifiable.
	// Un n° de fournisseur communiqué par l'acheteur (extra 'seller_id') est le
	// vrai BT-29 et satisfait à lui seul BR-CO-26 : il remplace alors ce repli.
	$sellerId = ($role === 'Seller') ? trim((string) ($extra['seller_id'] ?? '')) : '';
	$deRegister = '';
	if ($role === 'Seller' && strtoupper($country) === 'DE') {
		$deRegister = trim((string) ($party->idprof3 ?? ''));
		if ($sellerId === '' && $vat === '' && $deRegister === '') {
			$sellerId = auvoerechnung_party_steuernummer($party);
		}
	}

	$xml  = '    <ram:'.$tag.'>'."\n";
	if ($sellerId !== '') {
		$xml .= '      <ram:ID>'.auvoerechnung_xml_encode($sellerId).'</ram:ID>'."\n";
	}
	if (!$chorus && !empty($siret14)) {
		// BT-29 (vendeur) / BT-46 (acheteur) avec scheme ISO 6523 (0009 = SIRET) :
		// l'identifiant qualifié va dans ram:GlobalID, PAS dans ram:ID. EN16931 ne
		// lit le schemeID que sur GlobalID — sur ram:ID il est « not used in the
		// given context » et fait échouer la validation Schematron (FNFE-MPE).
		$xml .= '      <ram:GlobalID schemeID="0009">'.auvoerechnung_xml_encode($siret14).'</ram:GlobalID>'."\n";
	}
	$xml .= '      <ram:Name>'.auvoerechnung_xml_encode($party->name ?? '').'</ram:Name>'."\n";
	if ($chorus && !empty($siret14)) {
		$xml .= '      <ram:SpecifiedLegalOrganization>'."\n";
		$xml .= '        <ram:ID schemeID="0009">'.auvoerechnung_xml_encode($siret14).'</ram:ID>'."\n";
		$xml .= '      </ram:SpecifiedLegalOrganization>'."\n";
	} elseif (!empty($siren)) {
		$xml .= '      <ram:SpecifiedLegalOrganization>'."\n";
		$xml .= '        <ram:ID schemeID="0002">'.auvoerechnung_xml_encode($siren).'</ram:ID>'."\n";
		$xml .= '      </ram:SpecifiedLegalOrganization>'."\n";
	} elseif ($deRegister !== '') {
		// BT-30 sans schemeID : aucun code ISO 6523 pour le registre allemand.
		$xml .= '      <ram:SpecifiedLegalOrganization>'."\n";
		$xml .= '        <ram:ID>'.auvoerechnung_xml_encode($deRegister).'</ram:ID>'."\n";
		$xml .= '      </ram:SpecifiedLegalOrganization>'."\n";
	}
	// BG-6 / BG-9 : contact. Séquence XSD du TradePartyType : après
	// SpecifiedLegalOrganization, avant PostalTradeAddress. Éléments vides omis
	// (PEPPOL-EN16931-R008).
	if (!empty($extra['contact'])) {
		$c = $extra['contact'];
		if (($c['name'] ?? '') !== '' || ($c['phone'] ?? '') !== '' || ($c['email'] ?? '') !== '') {
			$xml .= '      <ram:DefinedTradeContact>'."\n";
			if (($c['name'] ?? '') !== '') {
				$xml .= '        <ram:PersonName>'.auvoerechnung_xml_encode($c['name']).'</ram:PersonName>'."\n";
			}
			if (($c['phone'] ?? '') !== '') {
				$xml .= '        <ram:TelephoneUniversalCommunication>'."\n";
				$xml .= '          <ram:CompleteNumber>'.auvoerechnung_xml_encode($c['phone']).'</ram:CompleteNumber>'."\n";
				$xml .= '        </ram:TelephoneUniversalCommunication>'."\n";
			}
			if (($c['email'] ?? '') !== '') {
				$xml .= '        <ram:EmailURIUniversalCommunication>'."\n";
				$xml .= '          <ram:URIID>'.auvoerechnung_xml_encode($c['email']).'</ram:URIID>'."\n";
				$xml .= '        </ram:EmailURIUniversalCommunication>'."\n";
			}
			$xml .= '      </ram:DefinedTradeContact>'."\n";
		}
	}
	$xml .= '      <ram:PostalTradeAddress>'."\n";
	$xml .= '        <ram:PostcodeCode>'.auvoerechnung_xml_encode($party->zip ?? '').'</ram:PostcodeCode>'."\n";
	$xml .= '        <ram:LineOne>'.auvoerechnung_xml_encode($party->address ?? '').'</ram:LineOne>'."\n";
	$xml .= '        <ram:CityName>'.auvoerechnung_xml_encode($party->town ?? '').'</ram:CityName>'."\n";
	$xml .= '        <ram:CountryID>'.auvoerechnung_xml_encode($country).'</ram:CountryID>'."\n";
	$xml .= '      </ram:PostalTradeAddress>'."\n";
	// BT-34 (vendeur) / BT-49 (acheteur) : adresse électronique de routage.
	// Le réseau des Plateformes Agréées (réforme FR) route par SIREN ; l'endpoint
	// porte donc le SIREN avec schemeID="0225" (annuaire PPF), pas l'email. Le rôle
	// est transmis : certaines PA exigent un suffixe sur l'adresse VENDEUR.
	if (!empty($extra['endpoint'][0]) && ($extra['endpoint'][1] ?? '') !== '') {
		$xml .= '      <ram:URIUniversalCommunication>'."\n";
		$xml .= '        <ram:URIID schemeID="'.auvoerechnung_xml_encode($extra['endpoint'][0]).'">'.auvoerechnung_xml_encode($extra['endpoint'][1]).'</ram:URIID>'."\n";
		$xml .= '      </ram:URIUniversalCommunication>'."\n";
	} else {
		$xml .= auvoerechnung_build_endpoint_uri($siren, $email, $role);
	}
	if (!empty($vat)) {
		$xml .= '      <ram:SpecifiedTaxRegistration>'."\n";
		$xml .= '        <ram:ID schemeID="VA">'.auvoerechnung_xml_encode($vat).'</ram:ID>'."\n";
		$xml .= '      </ram:SpecifiedTaxRegistration>'."\n";
	} elseif ($role === 'Seller' && !empty($siren)) {
		// BR-CO-26 / BR-E-09 : le Seller doit publier un identifiant fiscal
		// (BT-31 TVA intra OU BT-32 identifiant fiscal). En l'absence de TVA
		// intra (franchise en base 293 B CGI typiquement), on émet le SIREN
		// comme tax registration schemeID="FC" (Tax registration identifier
		// France) pour satisfaire la règle.
		$xml .= '      <ram:SpecifiedTaxRegistration>'."\n";
		$xml .= '        <ram:ID schemeID="FC">'.auvoerechnung_xml_encode($siren).'</ram:ID>'."\n";
		$xml .= '      </ram:SpecifiedTaxRegistration>'."\n";
	} elseif ($role === 'Seller' && strtoupper($country) === 'DE' && auvoerechnung_party_steuernummer($party) !== '') {
		// BR-CO-26 / BR-E-09, vendeur allemand : un Kleinunternehmer (§ 19 UStG)
		// n'a pas d'USt-IdNr, donc pas de BT-31. On publie sa Steuernummer en
		// BT-32 (schemeID="FC", Tax registration identifier) — pendant exact du
		// SIREN côté français, que ZUGFeRD/XRechnung attendent dans ce cas.
		$xml .= '      <ram:SpecifiedTaxRegistration>'."\n";
		$xml .= '        <ram:ID schemeID="FC">'.auvoerechnung_xml_encode(auvoerechnung_party_steuernummer($party)).'</ram:ID>'."\n";
		$xml .= '      </ram:SpecifiedTaxRegistration>'."\n";
	}
	$xml .= '    </ram:'.$tag.'>'."\n";

	return $xml;
}

/**
 * Construit l'endpoint d'adressage électronique (BT-34 vendeur / BT-49 acheteur).
 *
 * Le réseau des Plateformes Agréées (réforme française) route les factures par
 * SIREN : l'adresse doit porter le SIREN avec schemeID="0225" (annuaire PPF,
 * XP Z12-012), schéma figé en dur (les codes ISO 6523 alternatifs 0002 SIREN /
 * 0009 SIRET ne sont plus exposés en réglage). Sans SIREN (tiers étranger hors
 * périmètre), repli sur l'email (schemeID="EM"). Renvoie une chaîne
 * vide si aucune adresse n'est disponible, pour ne pas émettre de bloc vide.
 *
 * Suffixe VENDEUR : certaines PA exigent que l'adresse électronique du vendeur
 * (BT-34) ne soit PAS le SIREN nu mais un endpoint suffixé — p.ex. Hubtimize/
 * Esalink attend "<SIREN>_Status" (adresse de retour des statuts de cycle de vie),
 * tel que paramétré à l'enregistrement de l'entité sur la PA. Configurable via
 * AUVOERECHNUNG_ENDPOINT_SUFFIX_SELLER (vide par défaut). Appliqué AU vendeur
 * uniquement : l'adresse acheteur (BT-49) reste le SIREN nu, la PA destinataire
 * se chargeant du routage — on ne préjuge pas de son endpoint.
 *
 * @param string $siren SIREN 9 chiffres (vide si absent)
 * @param string $email Email de repli
 * @param string $role  'Seller' (BT-34, suffixe appliqué) ou 'Buyer' (BT-49, nu)
 * @return string Bloc <ram:URIUniversalCommunication> ou chaîne vide
 */
function auvoerechnung_build_endpoint_uri($siren, $email, $role = 'Seller')
{
	if (!empty($siren)) {
		$scheme = '0225';
		$suffix = ($role === 'Seller') ? trim(getDolGlobalString('AUVOERECHNUNG_ENDPOINT_SUFFIX_SELLER', '')) : '';
		// BR-FR-23 : une adresse électronique en 0225 n'admet que les
		// alphanumériques, le tiret, le tiret bas et le point — tout autre
		// caractère du suffixe configuré est retiré (sanitisation défensive ;
		// un avertissement est émis par auvoerechnung_check_mandatory).
		if ($suffix !== '') {
			$suffix = preg_replace('/[^A-Za-z0-9._-]/', '', $suffix);
		}
		$value  = $siren.$suffix;
	} elseif (!empty($email)) {
		$scheme = 'EM';
		$value  = $email;
	} else {
		return '';
	}

	$xml  = '      <ram:URIUniversalCommunication>'."\n";
	$xml .= '        <ram:URIID schemeID="'.auvoerechnung_xml_encode($scheme).'">'.auvoerechnung_xml_encode($value).'</ram:URIID>'."\n";
	$xml .= '      </ram:URIUniversalCommunication>'."\n";

	return $xml;
}

/**
 * Mappe l'unité Dolibarr d'une ligne vers le code UN/ECE Rec 20 attendu par Factur-X.
 * Cherche via $line->fk_unit dans llx_c_units, fallback C62 (pièce / one) si absent.
 *
 * @param object $line Ligne de facture Dolibarr
 * @return string Code UN/ECE (ex: HUR, DAY, MTR, KGM, C62...)
 */
function auvoerechnung_map_unit_code($line)
{
	static $unitCache = [];
	static $shortLabelMap = [
		'h'      => 'HUR', // heure
		'min'    => 'MIN', // minute
		'd'      => 'DAY', // jour
		'week'   => 'WEE', // semaine
		'wk'     => 'WEE',
		'month'  => 'MON', // mois
		'm'      => 'MTR', // mètre (conflit avec min/month résolu par unit_type)
		'cm'     => 'CMT',
		'mm'     => 'MMT',
		'km'     => 'KMT',
		'm2'     => 'MTK', // mètre carré
		'm3'     => 'MTQ', // mètre cube
		'kg'     => 'KGM',
		'g'      => 'GRM',
		't'      => 'TNE', // tonne métrique
		'l'      => 'LTR',
		'cl'     => 'CLT',
		'ml'     => 'MLT',
		'p'      => 'C62', // pièce
		'pc'     => 'C62',
		'pcs'    => 'C62',
		'piece'  => 'C62',
		'u'      => 'C62', // unité
	];

	$fkUnit = !empty($line->fk_unit) ? (int) $line->fk_unit : 0;
	if ($fkUnit <= 0) {
		return 'C62';
	}
	if (isset($unitCache[$fkUnit])) {
		return $unitCache[$fkUnit];
	}

	global $db;
	if (!is_object($db)) {
		return 'C62';
	}
	$sql = "SELECT short_label, unit_type FROM ".MAIN_DB_PREFIX."c_units WHERE rowid = ".$fkUnit;
	$res = $db->query($sql);
	if (!$res) {
		return $unitCache[$fkUnit] = 'C62';
	}
	$obj = $db->fetch_object($res);
	if (!$obj || empty($obj->short_label)) {
		return $unitCache[$fkUnit] = 'C62';
	}

	$code = strtolower(trim($obj->short_label));
	// Désambiguïser 'm' : time=minute (MIN), size=mètre (MTR)
	if ($code === 'm') {
		return $unitCache[$fkUnit] = ($obj->unit_type === 'time') ? 'MIN' : 'MTR';
	}
	return $unitCache[$fkUnit] = ($shortLabelMap[$code] ?? 'C62');
}

/**
 * Résout la catégorie TVA EN16931 (CategoryCode + code VATEX) selon le contexte métier.
 *
 * Intracommunautaire B2B (acheteur UE hors FR avec TVA intra, taux 0) :
 *  - biens (product_type 0)    → K  (livraison intracommunautaire, art. 138 dir. 2006/112/CE)
 *  - services (product_type 1) → AE (autoliquidation, art. 196 dir. 2006/112/CE)
 *
 * @param object $line        Ligne de facture
 * @param object $invoice     Facture Dolibarr
 * @param object $thirdparty  Tiers acheteur
 * @param object $mysoc       Société émettrice
 * @return array ['code' => 'S|K|AE|G|O|E|Z', 'exemption' => string|null, 'vatex' => string|null]
 */
function auvoerechnung_resolve_tax_category($line, $invoice, $thirdparty, $mysoc)
{
	// Société émettrice non assujettie (franchise en base 293 B CGI, micro-entreprise) :
	// catégorie E (Exempt from tax). Le code 'O' (Services hors champ) déclencherait
	// BR-O-04/05 sur le taux 0 et n'est sémantiquement pas le bon (293 B = exonération
	// française, pas une opération hors champ EU). BR-E-09 demande un identifiant
	// fiscal vendeur : assuré par SpecifiedTaxRegistration schemeID="FC" (SIREN) dans
	// auvoerechnung_build_trade_party_xml() quand tva_intra est vide.
	// TVA > 0 : standard — prioritaire sur le statut franchise, car une ligne
	// qui porte réellement de la TVA (ex. ancienne facture régénérée après un
	// passage en franchise) en catégorie E violerait BR-E-05 (taux non nul).
	if ((float) $line->tva_tx > 0) {
		return ['code' => 'S', 'exemption' => null, 'vatex' => null];
	}

	// Pays « domestique » du vendeur : sert à distinguer les opérations
	// intracommunautaires (acheteur UE d'un autre pays) et à choisir la langue
	// du motif d'exonération (droit français ou allemand). Les DOM restent
	// rattachés à FR, comportement historique du module.
	$sellerCountry = auvoerechnung_seller_is_fr($mysoc) ? 'FR' : strtoupper($mysoc->country_code);
	$sellerIsDE    = ($sellerCountry === 'DE');
	$isService     = ((int) ($line->product_type ?? 0) === 1);

	if (isset($mysoc->tva_assuj) && (int) $mysoc->tva_assuj === 0) {
		// Régime de franchise : le motif d'exonération dépend du pays du vendeur.
		// Le code VATEX-FR-FRANCHISE est propre au droit français ; côté allemand
		// (§ 19 UStG) aucun code de la liste CEF ne correspond, le texte libre
		// suffit à satisfaire BR-E-10.
		if ($sellerIsDE) {
			return ['code' => 'E', 'exemption' => 'Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG', 'vatex' => null];
		}
		return ['code' => 'E', 'exemption' => 'TVA non applicable, art. 293 B du CGI', 'vatex' => 'VATEX-FR-FRANCHISE'];
	}

	// TVA = 0 : qualifier selon le contexte
	$buyerCountry = strtoupper(!empty($thirdparty->country_code) ? $thirdparty->country_code : $sellerCountry);
	$buyerVat     = auvoerechnung_party_vat_id($thirdparty);

	// Export hors UE : G
	if (!in_array($buyerCountry, AUVOERECHNUNG_EU_COUNTRIES, true)) {
		if ($sellerIsDE) {
			$reason = $isService
				? 'Nicht im Inland steuerbare Leistung (§ 3a Abs. 2 UStG)'
				: 'Steuerfreie Ausfuhrlieferung (§ 4 Nr. 1a i. V. m. § 6 UStG)';
			return ['code' => 'G', 'exemption' => $reason, 'vatex' => 'VATEX-EU-G'];
		}
		return ['code' => 'G', 'exemption' => 'Export hors Union européenne (art. 262 I du CGI)', 'vatex' => 'VATEX-EU-G'];
	}

	// UE hors pays du vendeur avec TVA intra : K (biens) ou AE (services, art. 196)
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

	// Pays du vendeur ou UE sans TVA intra et TVA=0 : exonération par défaut.
	// Pas de code VATEX émis : impossible de deviner la base légale (296ter,
	// 261-4 formation, § 4 UStG…) — renseigner un motif explicite via la description.
	return ['code' => 'E', 'exemption' => $sellerIsDE ? 'Umsatzsteuerfrei' : 'Exonéré de TVA', 'vatex' => null];
}

/**
 * Résout le TypeCode documentaire EN16931 selon le type de facture Dolibarr.
 *
 * Types Dolibarr non couverts par un TypeCode dédié :
 *  - TYPE_SITUATION (5) : émis en 380 avec un avertissement — le mapping des
 *    lignes de situation (cumuls, retenues de garantie) n'est pas garanti.
 *  - TYPE_PROFORMA (4) : une proforma n'est pas une facture au sens EN16931.
 *
 * @param object $invoice        Facture Dolibarr
 * @param array  $buildWarnings  (sortie) avertissements
 * @return string '380' (standard), '381' (avoir), '384' (rectificative), '386' (acompte)
 */
function auvoerechnung_resolve_document_type($invoice, &$buildWarnings = [])
{
	$type = (int) $invoice->type;

	switch ($type) {
		case 1: // Facture::TYPE_REPLACEMENT
			return '384'; // Corrected invoice + BG-3 vers la facture remplacée
		case 2: // Facture::TYPE_CREDIT_NOTE
			return '381';
		case 3: // Facture::TYPE_DEPOSIT
			return '386'; // EN16931 : prepayment / advance invoice
		case 5: // Facture::TYPE_SITUATION
			$buildWarnings[] = auvoerechnung_trans('AuvoERechnungWarnSituationInvoice');
			return '380';
		default:
			return '380';
	}
}

/**
 * Retourne le montant total déjà prépayé via acomptes imputés sur la facture finale.
 *
 * @param object $invoice Facture Dolibarr
 * @return float Montant prépayé ≥ 0
 */
function auvoerechnung_get_prepaid_amount($invoice)
{
	if (!method_exists($invoice, 'getSumDepositsUsed')) {
		return 0.0;
	}
	return max(0.0, (float) $invoice->getSumDepositsUsed());
}

/**
 * Liste les factures antérieures à référencer en BG-3 :
 *  - facture d'origine d'un avoir ou d'une rectificative (fk_facture_source)
 *  - factures d'acompte imputées sur la facture (llx_societe_remise_except)
 *
 * Rectificatives (BT-3 384/471/472/473) : BR-FR-CO-04 exige UNE ET UNE SEULE
 * référence BT-25 — celle de la facture remplacée. Les factures d'acompte
 * imputées ne sont donc PAS référencées en BG-3 dans ce cas (le montant
 * prépayé reste porté par BT-113) ; sans cette restriction, une rectificative
 * avec acompte imputé produirait N>1 références et le validateur interne
 * bloquerait à tort la génération en mode strict.
 *
 * @param object $invoice  Facture Dolibarr
 * @param string $typeCode TypeCode BT-3 émis ('380', '384', '381'...) ; '' = pas de restriction
 * @return array Liste de ['ref' => string, 'date' => 'YYYYMMDD'|'']
 */
function auvoerechnung_get_preceding_invoices($invoice, $typeCode = '')
{
	$out = [];
	$seen = [];
	$db = $invoice->db ?? null;
	if (!is_object($db)) {
		return $out;
	}

	// BR-FR-CO-04 : une rectificative ne référence QUE la facture remplacée
	$isCorrective = in_array($typeCode, ['384', '471', '472', '473'], true);

	$sourceIds = [];
	if (!empty($invoice->fk_facture_source)) {
		$sourceIds[] = (int) $invoice->fk_facture_source;
	}

	if (!$isCorrective && !empty($invoice->id)) {
		// Acomptes/avoirs consommés sur cette facture via remises exceptionnelles
		$sql = "SELECT DISTINCT fk_facture_source FROM ".MAIN_DB_PREFIX."societe_remise_except"
			." WHERE fk_facture = ".((int) $invoice->id)." AND fk_facture_source IS NOT NULL";
		$res = $db->query($sql);
		if ($res) {
			while ($obj = $db->fetch_object($res)) {
				$sourceIds[] = (int) $obj->fk_facture_source;
			}
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
 * N° de commande DE L'ACHETEUR (BT-13 « Purchase order reference ») de la
 * première commande client liée à la facture, via llx_element_element.
 *
 * C'est le champ « Réf. client » de la commande (ref_client, libellé allemand
 * « Ihr Zeichen » / « Kunden-BestellNr. »), PAS la référence interne Dolibarr
 * de la commande (c.ref, ex. CO2606-0042) : celle-ci est un numéro du vendeur,
 * inexploitable par l'acheteur. Vide si la commande n'a pas de réf. client.
 *
 * @param object $invoice Facture Dolibarr
 * @return string N° de commande de l'acheteur ou chaîne vide
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
 * Génère le bloc SpecifiedTradeSettlementHeaderMonetarySummation.
 *
 * Tous les montants sont calculés de bas en haut à partir des valeurs émises
 * (lignes arrondies, remises, ventilation TVA réconciliée) pour garantir les
 * règles de calcul BR-CO-10/11/13/14/15/16. Un écart avec le total_ttc Dolibarr
 * (taxes locales par ex.) est signalé en avertissement.
 */
function auvoerechnung_build_monetary_summation_xml($invoice, $currency, $sign, $prepared, $breakdown, &$buildWarnings)
{
	$lineTotal = 0.0;
	foreach ($prepared['lines'] as $pl) {
		$lineTotal += $pl['lineTotal'];
	}
	$lineTotal = round($lineTotal, 2);

	$allowanceTotal = 0.0;
	foreach ($prepared['allowances'] as $al) {
		$allowanceTotal += $al['amount'];
	}
	$allowanceTotal = round($allowanceTotal, 2);

	$taxBasisTotal = round($lineTotal - $allowanceTotal, 2); // BR-CO-13

	$taxTotal = 0.0;
	foreach ($breakdown as $b) {
		$taxTotal += $b['tax'];
	}
	$taxTotal = round($taxTotal, 2); // BR-CO-14

	$grandTotal   = round($taxBasisTotal + $taxTotal, 2); // BR-CO-15
	$totalPrepaid = auvoerechnung_get_prepaid_amount($invoice);
	$duePayable   = round($grandTotal - $totalPrepaid, 2); // BR-CO-16, sans écrêtage

	// Cohérence avec les totaux Dolibarr (PDF visible) : un écart signale des
	// données hors périmètre (taxes locales, incohérence de lignes).
	$invoiceTtc = round($sign * (float) $invoice->total_ttc, 2);
	if (abs($grandTotal - $invoiceTtc) > 0.005) {
		$buildWarnings[] = auvoerechnung_trans('AuvoERechnungWarnTotalsMismatch', auvoerechnung_format_amount($grandTotal), auvoerechnung_format_amount($invoiceTtc));
	}

	$xml  = '    <ram:SpecifiedTradeSettlementHeaderMonetarySummation>'."\n";
	$xml .= '      <ram:LineTotalAmount>'.auvoerechnung_format_amount($lineTotal).'</ram:LineTotalAmount>'."\n";
	if ($allowanceTotal > 0) {
		$xml .= '      <ram:AllowanceTotalAmount>'.auvoerechnung_format_amount($allowanceTotal).'</ram:AllowanceTotalAmount>'."\n";
	}
	$xml .= '      <ram:TaxBasisTotalAmount>'.auvoerechnung_format_amount($taxBasisTotal).'</ram:TaxBasisTotalAmount>'."\n";
	$xml .= '      <ram:TaxTotalAmount currencyID="'.auvoerechnung_xml_encode($currency).'">'.auvoerechnung_format_amount($taxTotal).'</ram:TaxTotalAmount>'."\n";
	$xml .= '      <ram:GrandTotalAmount>'.auvoerechnung_format_amount($grandTotal).'</ram:GrandTotalAmount>'."\n";
	if ($totalPrepaid > 0) {
		$xml .= '      <ram:TotalPrepaidAmount>'.auvoerechnung_format_amount($totalPrepaid).'</ram:TotalPrepaidAmount>'."\n";
	}
	$xml .= '      <ram:DuePayableAmount>'.auvoerechnung_format_amount($duePayable).'</ram:DuePayableAmount>'."\n";
	$xml .= '    </ram:SpecifiedTradeSettlementHeaderMonetarySummation>'."\n";

	return $xml;
}

/**
 * Extrait le SIREN (9 premiers chiffres) d'un SIRET
 */
function auvoerechnung_extract_siren($siret)
{
	if (empty($siret)) {
		return '';
	}
	return substr(preg_replace('/[^0-9]/', '', $siret), 0, 9);
}

/**
 * Détermine si une facture relève du circuit Chorus Pro (B2G, secteur public),
 * auquel cas un PDF Factur-X au profil Chorus doit être généré EN PLUS du PDF
 * EN16931 standard. Trois signaux (du plus explicite au filet de sécurité) :
 *   1. case à cocher « Facture Chorus Pro » sur la facture (extrafield lfxchorus) ;
 *   2. présence d'un champ Chorus rempli (code service / engagement / marché) ;
 *   3. acheteur = État central (SIRET commençant par 110002011).
 *
 * @param Facture $invoice  Facture Dolibarr (thirdparty et array_options chargés)
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
	// Filet auto : acheteur = secteur public détecté par le SIRET.
	return auvoerechnung_is_public_sector_siret($invoice);
}

/**
 * Détecte si l'acheteur est une entité publique d'après son SIRET (État central :
 * SIRET commençant par 110002011). Sert au filet de détection automatique ET au
 * message d'information affiché quand les fonctionnalités Chorus sont désactivées.
 * NB : les collectivités/hôpitaux ont leur propre SIREN — non détectables ainsi,
 * d'où la case à cocher manuelle dans l'onglet Chorus.
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
 * Construit le tableau d'options pour générer le XML au profil Chorus Pro à
 * partir des extrafields de la facture (cadre de facturation BT-23, code service
 * BT-10, n° engagement BT-13, n° marché BT-12).
 *
 * @param Facture $invoice
 * @return array  options pour auvoerechnung_build_xml()
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
 * Liste officielle des cadres de facturation Chorus Pro (BT-23), source AIFE
 * « Annexe Processus de facturation » V4.00 §2.1. A1 = dépôt fournisseur (défaut
 * B2G). Pas de A11 dans la nomenclature AIFE.
 *
 * @return array  code => libellé
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
 * Recopie les 3 mentions légales BR-FR-05 (PMD/PMT/AAB) dans le pied de facture
 * Dolibarr (FACTURE_FREE_TEXT). N'écrase jamais l'existant : seules les mentions
 * absentes sont ajoutées. Idempotent.
 *
 * @param DoliDB $db
 * @return int  Nombre de mentions ajoutées
 */
/**
 * Force printable=0 et list=0 sur les extrafields Chorus (lfx*).
 *
 * Ces champs sont internes (édités dans l'onglet « Chorus Pro » dédié) et ne
 * doivent jamais s'imprimer sur le PDF. Dolibarr 23 les crée avec printable!=0,
 * et son modèle PDF (pdf_sponge → getExtrafieldsInHtml) imprime dans la NOTE du
 * PDF tout extrafield dont printable vaut 1 ou 2 → un bloc de libellés Chorus
 * vides apparaissait dans la description de la facture (Dolibarr 23.0.1).
 * Idempotent, inoffensif sur Dolibarr ≤ 22 (déjà à 0). Renvoie le nb de lignes
 * corrigées.
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
	// Depuis Dolibarr 17, la « Mention complémentaire sur les factures » est
	// stockée dans INVOICE_FREE_TEXT (ex-FACTURE_FREE_TEXT) — c'est CETTE
	// constante que le PDF imprime. On vise donc INVOICE_FREE_TEXT.
	$constName = auvoerechnung_invoice_freetext_const();
	if ($overwrite === null) {
		$overwrite = (getDolGlobalInt('AUVOERECHNUNG_NOTES_OVERWRITE', 0) === 1);
	}
	$freeText = getDolGlobalString($constName, '');
	// Mêmes mentions que dans le XML : pas de défaut français imposé au PDF
	// d'un vendeur étranger (seules ses constantes AUVOERECHNUNG_NOTE_* comptent).
	$mentions = array();
	foreach (auvoerechnung_resolve_legal_notes(auvoerechnung_seller_is_fr($mysoc ?? null)) as $m) {
		$m = trim($m);
		if ($m !== '') {
			$mentions[] = $m;
		}
	}
	// On centre les mentions sur le PDF (HTML), cohérent avec la « Mention
	// complémentaire » qui est rendue en HTML et souvent déjà centrée.
	$wrap = static function ($m) {
		return '<p style="text-align:center;margin:0;">'.$m.'</p>';
	};
	$added = 0;
	if ($overwrite || trim($freeText) === '') {
		// Champ vide OU option « écraser » : on (re)met nos mentions.
		$parts = array();
		foreach ($mentions as $m) {
			$parts[] = $wrap($m);
		}
		$newText = implode("\n", $parts);
		$added = ($newText !== $freeText) ? count($mentions) : 0;
	} else {
		// On AJOUTE nos mentions après le texte existant (seulement celles
		// qui manquent — on teste le texte brut), sans toucher au reste.
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
 * Nom de la constante de « mention complémentaire sur les factures » : Dolibarr
 * l'a renommée FACTURE_FREE_TEXT → INVOICE_FREE_TEXT en v17, et c'est CELLE-CI
 * que le modèle PDF lit. Le module cible Dolibarr 19+ → toujours INVOICE_FREE_TEXT.
 *
 * NB : surtout PAS de détection « celle qui a du contenu » — si l'ancienne
 * FACTURE_FREE_TEXT traîne un résidu, on écrirait dans une constante que le PDF
 * n'imprime plus (et le champ INVOICE_FREE_TEXT vide ne serait jamais rempli).
 *
 * @return string
 */
function auvoerechnung_invoice_freetext_const()
{
	return 'INVOICE_FREE_TEXT';
}

/**
 * Liste les polices PDF disponibles dans le TCPDF de Dolibarr, avec pour chacune
 * si elle est conforme Factur-X (PDF/A-3 = police EMBARQUÉE).
 *
 * Détection (calibrée sur les définitions TCPDF) : une police est embarquée si
 * son $type n'est PAS 'core' (base-14 non embarquée) ET qu'elle a un $file
 * (programme de police embarqué, ex 'dejavusans.z', 'pdfahelvetica.z').
 *
 * On ne renvoie que les polices « régulières » : les variantes gras/italique
 * (suffixe b / i / bi) sont écartées car TCPDF les sélectionne automatiquement
 * à partir de la police de base posée dans MAIN_PDF_FORCE_FONT.
 *
 * Chemin TCPDF stable de Dolibarr 18 à 23. Renvoie array(nomPolice => bool
 * embarquée), trié, ou array() si le dossier est introuvable.
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
		// Écarter les variantes gras/italique (b / i / bi) d'une police de base.
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
 * Code d'exigibilité TVA (BT-8) dérivé du régime TVA de Dolibarr (constante
 * TAX_MODE, Configuration > Taxes) — plus de réglage dédié dans le module.
 *   TAX_MODE 1 = sur les débits      → '5'
 *   TAX_MODE 2 = sur les encaissements → '72'
 *   TAX_MODE 0 = standard (mixte) : on suit TAX_MODE_SELL_SERVICE si explicite,
 *                sinon on omet (BT-8 optionnel, ne pas deviner).
 *
 * Conformité XP Z12-012 vérifiée (2026-07) : la codelist UNTDID 2475 admise par
 * EN16931 en CII est {5, 29, 72}. Pour l'option « débits », la règle
 * BR-FR-MAP-03 impose la valeur 5 en CII, et BR-FR-MAP-29 précise que le PPF
 * n'attend QUE 5 (le 29 « date de livraison », pourtant admis par EN16931, doit
 * être retranscrit en 5 dans les flux). Le 72 (date de paiement) code la TVA
 * sur les encaissements. Le mapping actuel est donc exact — ne pas y toucher.
 * Périmètre d'émission : uniquement sur les catégories 'S' (cf. build_xml), ce
 * qui est correct — toute ligne à taux > 0 est classée S par ce module, et
 * l'exigibilité n'a pas de sens sur les catégories sans TVA due (E/K/G/AE/O/Z).
 *
 * @return string  '5', '72' ou '' (omis)
 */
function auvoerechnung_resolve_vat_due_date_code()
{
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
 * Résout le binaire PHP CLI pour le subprocess d'injection.
 *  1. surcharge manuelle (AUVOERECHNUNG_PHP_CLI_PATH non vide) → utilisée telle quelle ;
 *  2. cache (AUVOERECHNUNG_PHP_CLI_CACHE) encore valide → réutilisé ;
 *  3. sinon auto-détection puis mise en cache.
 *
 * @param DoliDB $db
 * @return string  Chemin/commande du binaire PHP CLI ('php' en dernier recours)
 */
function auvoerechnung_resolve_php_cli($db)
{
	// 'php' nu (ancien défaut) = pas une vraie surcharge → on auto-détecte (qui
	// fera mieux, et retombe sur 'php' au pire). Une surcharge = un chemin précis.
	$manual = trim(getDolGlobalString('AUVOERECHNUNG_PHP_CLI_PATH', ''));
	if ($manual !== '' && $manual !== 'php') {
		return $manual;
	}
	$cache = trim(getDolGlobalString('AUVOERECHNUNG_PHP_CLI_CACHE', ''));
	if ($cache !== '' && auvoerechnung_php_cli_is_valid($cache)) {
		return $cache;
	}
	$detected = auvoerechnung_detect_php_cli();
	// Mise en cache best-effort : dolibarr_set_const() vit dans admin.lib.php,
	// chargée par main.inc.php en contexte web/hook mais pas dans un bootstrap CLI
	// minimal — on ne casse pas la résolution si elle est absente.
	if ($detected !== '' && is_object($db) && function_exists('dolibarr_set_const')) {
		dolibarr_set_const($db, 'AUVOERECHNUNG_PHP_CLI_CACHE', $detected, 'chaine', 0, '', 0);
	}
	return $detected !== '' ? $detected : 'php';
}

/**
 * Sonde une liste de candidats et renvoie le premier binaire PHP CLI dont le
 * SAPI est « cli » ET dont la version major.minor correspond à celle du web
 * (pour ne pas prendre un PHP d'une autre version). Renvoie '' si rien.
 *
 * NB : en contexte FPM, PHP_BINARY pointe sur php-fpm (inutilisable en CLI) —
 * on en déduit un candidat CLI en remplaçant php-fpm→php et sbin→bin.
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
		// Windows : dériver php.exe du dossier du binaire web
		// (ex C:\php\php-cgi.exe → C:\php\php.exe).
		if (DIRECTORY_SEPARATOR === '\\') {
			$candidates[] = dirname(PHP_BINARY).'\\php.exe';
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
 * Vérifie qu'un candidat est un binaire PHP CLI exécutable (SAPI cli), et
 * éventuellement de la version attendue.
 *
 * @param string $cand
 * @param string $wantVersion  major.minor attendu, ou '' pour ne pas vérifier
 * @return bool
 */
function auvoerechnung_php_cli_is_valid($cand, $wantVersion = '')
{
	if (!function_exists('exec')) {
		return false;
	}
	// Sonde cross-plateforme via `php -v` (et NON `php -r "..."`) : un snippet -r
	// contient des guillemets, or escapeshellarg() sous Windows remplace les "
	// par des espaces — la commande serait cassée. `php -v` n'a aucun guillemet.
	// La redirection d'erreurs diffère aussi : NUL sous Windows, /dev/null sinon.
	// 1re ligne de `php -v` : « PHP 8.2.30 (cli) (built: ...) ... »
	$devnull = (DIRECTORY_SEPARATOR === '\\') ? 'NUL' : '/dev/null';
	$out = array();
	$rc = 1;
	@exec(escapeshellarg($cand).' -v 2>'.$devnull, $out, $rc);
	if ($rc !== 0) {
		return false;
	}
	$version = '';
	foreach ($out as $line) {
		// SAPI « (cli) » sur la ligne de version = c'est bien un binaire CLI.
		if (preg_match('/^PHP\s+(\d+\.\d+)\.[^\s]*\s+\(cli\)/i', $line, $m)) {
			$version = $m[1];
			break;
		}
	}
	if ($version === '') {
		return false; // pas exécutable, ou SAPI != cli
	}
	if ($wantVersion !== '') {
		return $version === $wantVersion;
	}
	return true;
}

/**
 * SIREN (9 chiffres) d'un tiers, pour BT-30/BT-47 (SpecifiedLegalOrganization).
 * Source canonique : champ idprof1 (SIREN) de Dolibarr ; à défaut, dérivé des
 * 9 premiers chiffres du SIRET (idprof2). Renvoie '' si rien d'exploitable ou
 * si la valeur ne se normalise pas à exactement 9 chiffres (BR-FR-10).
 *
 * @param object $party  mysoc ou Societe (champs idprof1 / idprof2)
 * @return string        SIREN à 9 chiffres, ou ''
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
 * La valeur est-elle un n° de TVA intracommunautaire exploitable en BT-31/BT-48 ?
 *
 * BR-CO-09 : l'identifiant TVA doit commencer par le code pays ISO 3166-1
 * alpha-2 (EL pour la Grèce). Une saisie sans préfixe — typiquement une
 * Steuernummer allemande « 22/180/80394 » tapée dans le champ TVA intra par un
 * Kleinunternehmer — ne doit PAS être publiée sous schemeID="VA".
 *
 * @param string|null $vat  Valeur du champ tva_intra
 * @return bool
 */
function auvoerechnung_is_vat_id($vat)
{
	$vat = preg_replace('/[\s.\-]/', '', (string) $vat);
	return (bool) preg_match('/^[A-Za-z]{2}[0-9A-Za-z+*]{2,13}$/', $vat);
}

/**
 * N° de TVA intra d'un tiers, seulement s'il a la forme d'un n° de TVA
 * (cf. auvoerechnung_is_vat_id), sinon ''.
 *
 * @param object $party  mysoc ou Societe
 * @return string
 */
function auvoerechnung_party_vat_id($party)
{
	$vat = trim((string) ($party->tva_intra ?? ''));
	return auvoerechnung_is_vat_id($vat) ? $vat : '';
}

/**
 * Steuernummer (numéro fiscal allemand) d'un tiers, pour BT-32.
 *
 * Conventions Dolibarr pour l'Allemagne (langs/de_DE/companies.lang, depuis
 * 2016) :
 *   idprof1 = Steuernummer   → c'est CE champ
 *   idprof2 = Amtsgericht    (texte, ex. « Amtsgericht Koblenz »)
 *   idprof3 = Handelsregister-Nummer
 * Ordre de lecture : idprof1, puis idprof2 (anciennes fiches de ce module, qui
 * y lisaient la Steuernummer), puis le champ TVA intra quand il contient une
 * Steuernummer au lieu d'un n° de TVA. Seule une valeur ayant la forme d'une
 * Steuernummer (chiffres, « / » et espaces, au moins 10 chiffres) est
 * retenue : jamais un nom de tribunal, jamais une USt-IdNr (préfixe pays).
 *
 * @param object $party  mysoc ou Societe
 * @return string        Steuernummer telle que saisie, ou ''
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
 * Leitweg-ID (identifiant de routage administration publique allemande),
 * pour BT-10, lue sur l'extrafield tiers dédié — pas de champ Dolibarr natif
 * équivalent (contrairement à la Steuernummer qui réutilise idprof2, les
 * idprof* sont déjà occupés par les conventions allemandes existantes).
 */
function auvoerechnung_party_leitwegid($party)
{
	$ao = (is_object($party) && !empty($party->array_options)) ? $party->array_options : [];
	return trim((string) ($ao['options_lfxleitwegid'] ?? ''));
}

/**
 * Leitweg-ID applicable à une facture : celle saisie sur la facture
 * (extrafield facture lfxleitwegid, ex. autre service d'une même
 * administration), sinon celle du tiers.
 *
 * @param object $invoice  Facture (array_options et thirdparty chargés)
 * @return string
 */
function auvoerechnung_invoice_leitwegid($invoice)
{
	$own = auvoerechnung_party_leitwegid($invoice);
	return $own !== '' ? $own : auvoerechnung_party_leitwegid($invoice->thirdparty ?? null);
}

/**
 * Identifiant de spécification XRechnung (BT-24), ex.
 * urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0
 * (BR-DE-21). Version « majeure.mineure » réglable (AUVOERECHNUNG_XRECHNUNG_VERSION)
 * pour suivre les publications semestrielles de la KoSIT sans nouvelle version
 * du module ; toute valeur mal formée retombe sur la version par défaut.
 *
 * @return string
 */
function auvoerechnung_xrechnung_cius_id()
{
	$version = trim(getDolGlobalString('AUVOERECHNUNG_XRECHNUNG_VERSION', AUVOERECHNUNG_XRECHNUNG_DEFAULT_VERSION));
	if (!preg_match('/^\d+\.\d+$/', $version)) {
		$version = AUVOERECHNUNG_XRECHNUNG_DEFAULT_VERSION;
	}
	return 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_'.$version;
}

/**
 * N° de fournisseur du vendeur chez ce client (BT-29), extrafield tiers
 * « Unsere Lieferantennummer beim Kunden » (lfxsupplierno).
 *
 * @param object $party  Tiers acheteur
 * @return string
 */
function auvoerechnung_party_supplier_number($party)
{
	$ao = (is_object($party) && !empty($party->array_options)) ? $party->array_options : [];
	return trim((string) ($ao['options_lfxsupplierno'] ?? ''));
}

/**
 * Chemin du fichier XRechnung d'une facture : {dossier facture}/{ref}-XRECHNUNG.xml.
 * Nom fixe, indépendant du nom du PDF (les modèles ODT produisent {ref}_{modèle}.pdf).
 *
 * @param string $ref     Référence de la facture
 * @param int    $entity  Entité de la facture
 * @return string  Chemin, ou '' si le répertoire documents des factures est inconnu
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
 * Types MIME admis pour les pièces jointes BG-24 (BR-CL-24), par extension.
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
 * Pièces jointes à intégrer dans la XRechnung (BG-24), selon la configuration :
 *  - AUVOERECHNUNG_XR_EMBED_PDF : le PDF de la facture ;
 *  - AUVOERECHNUNG_XR_EMBED_FILES : les autres fichiers déposés dans les
 *    documents de la facture (justificatifs, relevés d'heures…), dans les
 *    formats admis par BR-CL-24. Les documents générés par Dolibarr ou le
 *    module (nom commençant par la référence de la facture : PDF/ODT du
 *    modèle, XRechnung, PDF Chorus) sont exclus, sauf s'ils sont indexés
 *    comme « déposés » dans l'ECM.
 * Noms de fichiers uniques (BR-DE-22). Au-delà de 7 Mo cumulés (≈ 9,3 Mo une
 * fois en base64), avertissement : l'OZG-RE refuse les e-mails de plus de 10 Mo.
 *
 * @param object   $invoice   Facture (ref, id, entity, db, last_main_doc)
 * @param string[] $warnings  (sortie) avertissements
 * @param string   $pdfPath   PDF de la facture (vide = recherche automatique)
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
 * Prépare une facture d'exemple pour le test de la page de configuration :
 * vendeur = la société réelle ($mysoc), acheteur fictif d'une administration
 * allemande avec la Leitweg-ID de test de la KoSIT (991-33333TEST-33), deux
 * lignes (prestation et livraison) à 19 % — 0 % pour un vendeur non
 * assujetti (Kleinunternehmer). Rien n'est enregistré.
 *
 * @param object $invoice  Facture vide (Facture ou objet compatible)
 * @param object $buyer    Tiers vide (Societe ou objet compatible)
 * @param object $mysoc    Société émettrice
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
	$invoice->cond_reglement_code = '';
	$invoice->array_options = [];
}

/**
 * Faut-il produire une XRechnung pour ce tiers ?
 *
 * Extrafield tiers « E-Rechnungsformat » (lfxeformat) :
 *   'xrechnung' → oui ; 'zugferd' → non ;
 *   vide / 'auto' → oui dès qu'une Leitweg-ID est renseignée (acheteur public
 *   allemand, qui attend XRechnung ou ZUGFeRD au profil XRECHNUNG).
 *
 * Une Leitweg-ID saisie sur la facture ($invoice) vaut comme celle du tiers.
 *
 * @param object      $buyer    Tiers acheteur (array_options chargés)
 * @param object|null $invoice  Facture, pour sa Leitweg-ID propre
 * @return bool
 */
function auvoerechnung_wants_xrechnung($buyer, $invoice = null)
{
	$ao = (is_object($buyer) && !empty($buyer->array_options)) ? $buyer->array_options : [];
	$format = strtolower(trim((string) ($ao['options_lfxeformat'] ?? '')));
	if ($format === 'xrechnung') {
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
 * Contact du vendeur pour XRechnung (BG-6 : BT-41 nom, BT-42 téléphone,
 * BT-43 email ; BR-DE-2/5/6/7). Chaque champ est pris, dans l'ordre :
 *   1. réglage du module (AUVOERECHNUNG_CONTACT_NAME / _PHONE / _EMAIL) ;
 *   2. auteur de la facture (nom complet, téléphone pro/mobile, email) ;
 *   3. société émettrice (téléphone, email ; pas de nom de personne).
 * Le téléphone ne garde que les caractères admis par l'OZG-RE (0-9 ( ) - + et
 * espace).
 *
 * @param object $invoice  Facture
 * @param object $mysoc    Société émettrice
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
 * Contrôle amont des données requises par XRechnung (règles BR-DE fatales
 * et PEPPOL-EN16931-R010/R020). Renvoie des messages traduits ; une liste vide
 * signifie que la XRechnung peut être produite.
 *
 * @param object $invoice  Facture (thirdparty chargé)
 * @param object $mysoc    Société émettrice
 * @return string[]
 */
function auvoerechnung_check_xrechnung($invoice, $mysoc)
{
	$problems = [];
	$buyer = $invoice->thirdparty ?? null;

	// BT-10 (BR-DE-15) : Leitweg-ID, à défaut référence client.
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
	// BT-34 (PEPPOL-EN16931-R020) : email de la société.
	if (empty($mysoc->email)) {
		$problems[] = auvoerechnung_trans('AuvoERechnungXrMissingSellerEmail');
	}
	// BT-49 (PEPPOL-EN16931-R010) : Leitweg-ID (0204) ou email de l'acheteur.
	if (auvoerechnung_invoice_leitwegid($invoice) === '' && auvoerechnung_get_buyer_email($buyer, $invoice->db ?? null) === '') {
		$problems[] = auvoerechnung_trans('AuvoERechnungXrMissingBuyerAddress');
	}
	// BT-37/38, BT-52/53 (BR-DE-3/4/8/9)
	if (empty($mysoc->zip) || empty($mysoc->town)) {
		$problems[] = auvoerechnung_trans('AuvoERechnungXrMissingSellerZipTown');
	}
	if (empty($buyer->zip) || empty($buyer->town)) {
		$problems[] = auvoerechnung_trans('AuvoERechnungXrMissingBuyerZipTown');
	}
	// BG-16 (BR-DE-1, BR-DE-23-a) : virement → IBAN obligatoire.
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
 * Cherche l'email d'un tiers : d'abord sur la fiche, sinon sur le 1er contact
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
 * Résout le chemin du PDF principal d'une facture, ou null s'il n'existe pas.
 * Convention Dolibarr : <dir_entité>/<ref>/<ref>.pdf ; repli sur last_main_doc
 * (chemin relatif à DOL_DATA_ROOT) si le fichier conventionnel est absent.
 *
 * @param string $ref          Référence de la facture
 * @param int    $entity       Entité de la facture (multicompany)
 * @param string $lastMainDoc  Valeur de llx_facture.last_main_doc (optionnel)
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
	// Repli modèle ODT : le PDF y est nommé {ref}_{template}.pdf (pas {ref}.pdf) et
	// last_main_doc peut pointer le .odt. On prend alors le PDF le plus récent du
	// dossier de la facture, hors PDF Chorus ({ref}-CHORUS.pdf).
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
 * Extrait le XML Factur-X embarqué dans un PDF (lecture seule, in-process :
 * le Reader atgp repose sur smalot/pdfparser, sans conflit FPDF/TCPDF).
 *
 * @param string $pdfPath Chemin du PDF
 * @return string|null    XML, ou null si absent/illisible
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
 * Tronque un IBAN pour affichage : "FR76...0185". Vide si IBAN absent.
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
	 * Échappe une valeur pour insertion dans le XML (ENT_XML1).
	 */
	function auvoerechnung_xml_encode($str)
	{
		return htmlspecialchars((string) $str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('auvoerechnung_format_amount')) {
	/**
	 * Formate un montant à 2 décimales (AmountType EN16931).
	 */
	function auvoerechnung_format_amount($amount)
	{
		return number_format((float) $amount, 2, '.', '');
	}
}

/**
 * Formate une quantité (BT-129) : jusqu'à 4 décimales, zéros traînants retirés.
 */
function auvoerechnung_format_qty($qty)
{
	$s = number_format((float) $qty, 4, '.', '');
	$s = rtrim($s, '0');
	return rtrim($s, '.');
}

/**
 * Formate un prix unitaire net (BT-146) : 2 décimales, étendu à 4 quand la
 * précision le justifie (ex. 100/3 = 33.3333) pour limiter l'écart qty x prix.
 */
function auvoerechnung_format_unit_price($price)
{
	$price = (float) $price;
	if (abs(round($price, 2) - round($price, 4)) < 0.0000001) {
		return number_format($price, 2, '.', '');
	}
	return number_format($price, 4, '.', '');
}

/**
 * Interroge l'API GitHub pour la dernière release publiée du module.
 * Résultat (succès OU échec) mis en cache 24h dans une constante Dolibarr pour
 * ne pas retenter un appel réseau lent à chaque ouverture de la page admin.
 *
 * @param object $db               Handle DB Dolibarr
 * @param string $currentVersion   Version actuelle du module (ex: "1.1.0")
 * @return array|null              ['version' => 'x.y.z', 'url' => 'https://...']
 *                                 si une version plus récente existe, null sinon
 */
function auvoerechnung_check_latest_release($db, $currentVersion)
{
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
					// Validation défensive : on n'accepte qu'une URL github.com officielle du repo
					if (!preg_match('#^https://github\.com/Fips81/dolibarr-auvo-erechnung/#', $htmlUrl)) {
						$htmlUrl = 'https://github.com/Fips81/dolibarr-auvo-erechnung/releases';
					}
				}
			}
		}

		// Cacher aussi l'échec (version null) : GitHub injoignable ne doit pas
		// ralentir la page admin de 5s à chaque ouverture pendant 24h.
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
