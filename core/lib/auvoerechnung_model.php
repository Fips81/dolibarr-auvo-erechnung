<?php
/*
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Modèle de données sémantique d'une facture EN16931 (BT-/BG-) et sa
 * sérialisation CII (ZUGFeRD/Factur-X, XRechnung CII).
 *
 * auvoerechnung_build_model() rassemble TOUTES les décisions métier (profil,
 * catégories TVA, identifiants, arrondis, réconciliations) dans un tableau
 * indépendant de la syntaxe ; les sérialiseurs (CII ici, UBL ensuite) ne font
 * qu'écrire ce modèle. Montants en float non formatés, dates au format
 * AAAAMMJJ, chaînes vides ou null pour les éléments à omettre.
 */

/**
 * Construit le modèle sémantique EN16931 d'une facture Dolibarr.
 *
 * @param object $invoice        Facture Dolibarr (lignes et tiers chargés)
 * @param object $mysoc          Société émettrice
 * @param array  $buildWarnings  (sortie) avertissements non bloquants
 * @param array  $options        'profile' => 'pdp'|'choruspro'|'xrechnung',
 *                               Chorus : 'cadre', 'service_code', 'engagement', 'marche' ;
 *                               'attachments' => pièces BG-24 (cf. auvoerechnung_collect_attachments)
 * @return array Modèle (clés document, seller, buyer, delivery, payment, lines,
 *               allowances, vat_breakdown, totals, preceding_invoices, attachments)
 */
function auvoerechnung_build_model($invoice, $mysoc, &$buildWarnings = [], $options = [])
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
	// à total négatif (remise fixe Dolibarr) devient une remise document pour
	// respecter BR-27 (prix net de ligne jamais négatif).
	$prepared = auvoerechnung_prepare_lines($billableLines, $sign, $invoice, $buyer, $mysoc, $buildWarnings);

	// Ventilation TVA par (catégorie, taux), réconciliée avec les totaux facture
	$breakdown = auvoerechnung_get_tax_breakdown($prepared, $invoice, $sign, $buildWarnings);

	// BR-61 : un moyen de paiement 30/58 (virement) exige un IBAN. Sans compte
	// bancaire configuré, on omet le moyen de paiement plutôt que d'émettre un
	// XML rejeté par les validateurs Schematron.
	$emitPaymentMeans = true;
	if (in_array($paymentMeans, ['30', '58'], true) && empty($bank['iban'])) {
		$emitPaymentMeans = false;
		$buildWarnings[] = auvoerechnung_trans('AuvoERechnungWarnPaymentMeansOmitted', $paymentMeans);
	}

	// Prélèvement SEPA (59) : ICS créancier (BT-90), RUM mandat (BT-89), IBAN débiteur (BT-91)
	$directDebit = ($paymentMeans === '59') ? auvoerechnung_get_direct_debit_info($invoice->db, $buyer, $buildWarnings) : null;

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

	// BG-1 : mentions légales BR-FR-05 (PMD, PMT, AAB) surchargeables par
	// constantes, plus la note BAR (qualification du traitement attendu,
	// BR-FR-07/20) sur le profil PDP d'un vendeur français uniquement — le
	// B2G Chorus a son propre marquage, et le code qualifie le flux de la
	// réforme FR, sans objet pour un vendeur étranger. Chaque code sujet n'est
	// émis qu'une fois (BR-FR-06, BR-FR-31). Une note vide violerait
	// PEPPOL-EN16931-R008 (vendeur étranger sans mention configurée).
	$noteTexts = auvoerechnung_resolve_legal_notes($sellerIsFR);
	$barCode = ($profile === 'choruspro' || !$sellerIsFR) ? '' : auvoerechnung_resolve_bar_code($buyer);
	if ($barCode !== '') {
		// BR-FR-20 : le contenu (BT-22) est le code brut, sans texte libre autour
		$noteTexts['BAR'] = $barCode;
	}
	$notes = [];
	foreach ($noteTexts as $code => $content) {
		if (trim((string) $content) !== '') {
			$notes[] = ['subject' => $code, 'content' => $content];
		}
	}

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
	$seller = auvoerechnung_build_party_model('Seller', $mysoc, $mysoc->email ?? '', $legalIdMode, $sellerExtra);
	$buyerModel = auvoerechnung_build_party_model('Buyer', $buyer, auvoerechnung_get_buyer_email($buyer, $invoice->db), $legalIdMode, $buyerExtra);

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

	// BT-80 / BT-72. Pour les livraisons intracommunautaires (catégorie K),
	// BR-IC-11 exige une date de livraison et BR-IC-12 un pays de livraison
	// (BT-80, repli : pays de l'acheteur). La date de livraison est TOUJOURS
	// émise (vraie date si renseignée, sinon date d'émission) : ça satisfait
	// BR-IC-11 et garantit un bloc livraison non vide (PEPPOL-EN16931-R008).
	$hasK = false;
	foreach ($breakdown as $b) {
		if ($b['categoryCode'] === 'K') {
			$hasK = true;
		}
	}
	$deliveryDateTs = !empty($invoice->delivery_date) ? $invoice->delivery_date : ($invoice->date_livraison ?? null);

	// BG-14 : période de facturation (min des date_start / max des date_end des
	// lignes), remplacée par la période saisie sur la facture (extrafields
	// lfxperiodstart / lfxperiodend) quand elle est renseignée.
	$periodStart = null;
	$periodEnd = null;
	foreach ($billableLines as $line) {
		if (!empty($line->date_start) && ($periodStart === null || $line->date_start < $periodStart)) {
			$periodStart = $line->date_start;
		}
		if (!empty($line->date_end) && ($periodEnd === null || $line->date_end > $periodEnd)) {
			$periodEnd = $line->date_end;
		}
	}
	$ownStart = auvoerechnung_invoice_date_option($invoice, 'lfxperiodstart');
	$ownEnd = auvoerechnung_invoice_date_option($invoice, 'lfxperiodend');
	if ($ownStart !== null || $ownEnd !== null) {
		$periodStart = $ownStart;
		$periodEnd = $ownEnd;
		// BR-29 : la fin ne peut précéder le début
		if ($ownStart !== null && $ownEnd !== null && $ownEnd < $ownStart) {
			$buildWarnings[] = auvoerechnung_trans('AuvoERechnungWarnPeriodOrder');
		}
	}

	// BG-25 : lignes
	$lines = [];
	$lineNum = 0;
	foreach ($prepared['lines'] as $pl) {
		$lineNum++;
		$lines[] = [
			'id'           => (string) $lineNum,
			'name'         => $pl['desc'],
			'net_price'    => $pl['unitPrice'],
			'quantity'     => $pl['qty'],
			'unit_code'    => $pl['unitCode'],
			'vat_category' => $pl['taxCat']['code'],
			// BR-O-04 : pas de taux pour la catégorie O (hors champ)
			'vat_rate'     => ($pl['taxCat']['code'] !== 'O') ? $pl['vatRate'] : null,
			'net_amount'   => $pl['lineTotal'],
		];
	}

	// BG-20 : remises document (issues des lignes à montant négatif)
	$allowances = [];
	foreach ($prepared['allowances'] as $al) {
		$allowances[] = [
			'amount'       => $al['amount'],
			'reason'       => $al['reason'],
			'vat_category' => $al['categoryCode'],
			'vat_rate'     => ($al['categoryCode'] !== 'O') ? $al['rate'] : null,
		];
	}

	// BG-23 : ventilation TVA. Motif d'exonération (BT-120) pour toute
	// catégorie non standard quand un motif est disponible ; code VATEX
	// (BT-121) attendu par la réforme FR ; pas de taux pour O (BR-O-05).
	$vatBreakdown = [];
	foreach ($breakdown as $b) {
		$vatBreakdown[] = [
			'category'         => $b['categoryCode'],
			'rate'             => ($b['categoryCode'] !== 'O') ? $b['rate'] : null,
			'basis'            => $b['base'],
			'tax'              => $b['tax'],
			'exemption_reason' => (!empty($b['exemption']) && in_array($b['categoryCode'], ['E', 'K', 'G', 'O', 'Z', 'AE'], true)) ? $b['exemption'] : '',
			'exemption_code'   => !empty($b['vatex']) ? $b['vatex'] : '',
		];
	}

	$model = [
		'profile'  => $profile,
		'document' => [
			'id'                 => (string) $invoice->ref,                     // BT-1
			'issue_date'         => $issueDate,                                // BT-2
			// XRechnung (BR-DE-17) n'admet pas 386 : la facture d'acompte y est
			// une « Partial invoice » (326), comme le préconise la KoSIT pour les
			// Abschlagsrechnungen. ZUGFeRD/Factur-X garde 386.
			'type_code'          => ($isXRechnung && $typeCode === '386') ? '326' : $typeCode, // BT-3
			'is_credit_note'     => $isCreditNote,
			'currency'           => $currency,                                 // BT-5
			// BT-8 : exigibilité TVA (5 = débits, 72 = encaissements), dérivée du
			// régime TVA de Dolibarr (TAX_MODE) ; émise sur la catégorie S.
			'vat_due_date_code'  => auvoerechnung_resolve_vat_due_date_code(),
			'buyer_reference'    => (string) $buyerRef,                        // BT-10
			'contract_reference' => ($profile === 'choruspro' && !empty($options['marche'])) ? (string) $options['marche'] : '', // BT-12
			'order_reference'    => (string) $orderRef,                        // BT-13
			'business_process'   => (string) $bt23,                            // BT-23
			'specification'      => $isXRechnung ? auvoerechnung_xrechnung_cius_id() : 'urn:cen.eu:en16931:2017', // BT-24
			'notes'              => $notes,                                    // BG-1
			'period_start'       => $periodStart !== null ? date('Ymd', $periodStart) : '', // BT-73
			'period_end'         => $periodEnd !== null ? date('Ymd', $periodEnd) : '',     // BT-74
		],
		'seller'   => $seller,
		'buyer'    => $buyerModel,
		'delivery' => [
			// BG-13 : lieu de livraison (contact « livraison » de la facture)
			'ship_to'         => auvoerechnung_get_ship_to($invoice),
			'ship_to_country' => $hasK ? (!empty($buyer->country_code) ? $buyer->country_code : 'FR') : '', // BT-80
			'date'            => !empty($deliveryDateTs) ? date('Ymd', $deliveryDateTs) : $issueDate,      // BT-72
		],
		'payment'  => [
			'means_code'  => $emitPaymentMeans ? $paymentMeans : '',                                  // BT-81
			'payee_iban'  => $emitPaymentMeans ? (string) ($bank['iban'] ?? '') : '',                 // BT-84
			'payee_bic'   => $emitPaymentMeans ? (string) ($bank['bic'] ?? '') : '',                  // BT-86
			'debtor_iban' => ($emitPaymentMeans && $directDebit !== null) ? $directDebit['debtor_iban'] : '', // BT-91
			'creditor_id' => ($directDebit !== null) ? $directDebit['ics'] : '',                      // BT-90
			'mandate_id'  => ($directDebit !== null) ? $directDebit['rum'] : '',                      // BT-89
			// BT-20 : conditions de paiement avec les lignes Skonto (BR-DE-18)
			'terms'       => auvoerechnung_build_payment_terms_description($invoice, $isCreditNote),
			'due_date'    => $dueDate,                                                                // BT-9
		],
		'lines'              => $lines,
		'allowances'         => $allowances,
		'vat_breakdown'      => $vatBreakdown,
		'totals'             => auvoerechnung_compute_totals($invoice, $sign, $prepared, $breakdown, $buildWarnings),
		// BG-3 : facture d'origine d'un avoir / d'une rectificative, factures
		// d'acompte imputées sur une facture finale. Rectificative (384) : seule
		// la facture remplacée est référencée (BR-FR-CO-04).
		'preceding_invoices' => auvoerechnung_get_preceding_invoices($invoice, $typeCode),
		// BG-24 : pièces jointes (PDF de la facture, justificatifs)
		'attachments'        => array_values((array) ($options['attachments'] ?? [])),
	];

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

	return $model;
}

/**
 * Totaux du document (BG-22), calculés de bas en haut à partir des valeurs
 * émises (lignes arrondies, remises, ventilation TVA réconciliée) pour
 * garantir les règles de calcul BR-CO-10/11/13/14/15/16. Un écart avec le
 * total_ttc Dolibarr (taxes locales par ex.) est signalé en avertissement.
 *
 * @return array{line_total:float,allowance_total:float,tax_basis:float,tax_total:float,grand_total:float,prepaid:float,due_payable:float}
 */
function auvoerechnung_compute_totals($invoice, $sign, $prepared, $breakdown, &$buildWarnings)
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

	return [
		'line_total'      => $lineTotal,      // BT-106
		'allowance_total' => $allowanceTotal, // BT-107
		'tax_basis'       => $taxBasisTotal,  // BT-109
		'tax_total'       => $taxTotal,       // BT-110
		'grand_total'     => $grandTotal,     // BT-112
		'prepaid'         => $totalPrepaid,   // BT-113
		'due_payable'     => $duePayable,     // BT-115
	];
}

/**
 * Modèle d'une partie (vendeur BG-4 ou acheteur BG-7).
 *
 * SIREN/SIRET : identifiants français uniquement. Pour un tiers étranger,
 * idprof2 contient un identifiant local (HRB allemand, CRN...) qui ne doit
 * surtout pas être publié sous un scheme SIREN/SIRET (0002/0009/0225) —
 * l'adresse électronique retombe alors sur l'email (EM).
 * EN16931 : deux champs DISTINCTS, chacun son identifiant (ISO 6523) :
 *  - BT-29/BT-46 = SIRET (14 chiffres), schemeID 0009 → l'établissement ;
 *  - BT-30/BT-47 = SIREN (9 chiffres), schemeID 0002 → l'entité légale.
 * Source SIREN = idprof1 (à défaut dérivé du SIRET) ; source SIRET = idprof2.
 *
 * @param string $role         'Seller' ou 'Buyer'
 * @param object $party        Société émettrice (mysoc) ou Societe acheteur
 * @param string $email        Email de repli pour l'adresse électronique (BT-34 / BT-49)
 * @param string $legalIdMode  'siren' (défaut) ou 'siret' (Chorus Pro)
 * @param array  $extra        'contact'  => ['name' =>, 'phone' =>, 'email' =>] (BG-6) ;
 *                             'endpoint' => [schemeID, valeur] remplaçant l'adresse
 *                                           électronique calculée (ex. 0204 + Leitweg-ID) ;
 *                             'seller_id' => BT-29 (n° de fournisseur chez l'acheteur)
 * @return array{role:string,name:string,id:string,global_id:?array,legal_id:?array,contact:?array,address:array,endpoint:?array,vat_id:string,tax_id:string}
 */
function auvoerechnung_build_party_model($role, $party, $email, $legalIdMode = 'siren', $extra = [])
{
	$country = !empty($party->country_code) ? $party->country_code : 'FR';
	$vat     = auvoerechnung_party_vat_id($party);
	$isFR = (strtoupper($country) === 'FR');
	$siren = $isFR ? auvoerechnung_party_siren($party) : '';
	$siretDigits = $isFR ? preg_replace('/[^0-9]/', '', $party->idprof2 ?? '') : '';
	$siret14 = (strlen($siretDigits) === 14) ? $siretDigits : '';

	// Deux profils selon la destination (cf. réforme FR : deux réseaux distincts).
	//  - 'siren' (défaut, PDP/B2B) : SIRET en BT-29 (GlobalID/0009) + SIREN en BT-30
	//    (0002). Conforme norme EN16931 / règle BR-FR-10.
	//  - 'siret' (Chorus Pro/B2G) : SIRET-14 en BT-30 (0009), qui est la clé de
	//    routage de l'annuaire Chorus Pro (il ne lit QUE ce champ).
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

	// BT-29/BT-46 avec schéma ISO 6523 (0009 = SIRET), hors profil Chorus
	$globalId = (!$chorus && !empty($siret14)) ? ['scheme' => '0009', 'id' => $siret14] : null;

	// BT-30/BT-47 : SIRET (Chorus), sinon SIREN, sinon registre du commerce
	// allemand (sans schemeID : aucun code ISO 6523 pour ce registre).
	$legalId = null;
	if ($chorus && !empty($siret14)) {
		$legalId = ['scheme' => '0009', 'id' => $siret14];
	} elseif (!empty($siren)) {
		$legalId = ['scheme' => '0002', 'id' => $siren];
	} elseif ($deRegister !== '') {
		$legalId = ['scheme' => '', 'id' => $deRegister];
	}

	// BG-6 / BG-9 : contact, éléments vides omis (PEPPOL-EN16931-R008)
	$contact = null;
	if (!empty($extra['contact'])) {
		$c = $extra['contact'];
		if (($c['name'] ?? '') !== '' || ($c['phone'] ?? '') !== '' || ($c['email'] ?? '') !== '') {
			$contact = [
				'name'  => (string) ($c['name'] ?? ''),
				'phone' => (string) ($c['phone'] ?? ''),
				'email' => (string) ($c['email'] ?? ''),
			];
		}
	}

	// BT-34 (vendeur) / BT-49 (acheteur) : adresse électronique de routage.
	if (!empty($extra['endpoint'][0]) && ($extra['endpoint'][1] ?? '') !== '') {
		$endpoint = ['scheme' => (string) $extra['endpoint'][0], 'id' => (string) $extra['endpoint'][1]];
	} else {
		$endpoint = auvoerechnung_resolve_endpoint($siren, $email, $role);
	}

	// BT-32 : identifiant fiscal (schemeID FC) quand il n'y a pas de n° de TVA.
	// BR-CO-26 / BR-E-09 : le vendeur doit publier BT-31 OU BT-32. Sans TVA intra
	// (franchise en base 293 B CGI typiquement), le SIREN ; pour un vendeur
	// allemand sans USt-IdNr (Kleinunternehmer, § 19 UStG), sa Steuernummer —
	// pendant exact du SIREN, que ZUGFeRD/XRechnung attendent dans ce cas.
	$taxId = '';
	if (empty($vat) && $role === 'Seller') {
		if (!empty($siren)) {
			$taxId = $siren;
		} elseif (strtoupper($country) === 'DE') {
			$taxId = auvoerechnung_party_steuernummer($party);
		}
	}

	return [
		'role'      => $role,
		'name'      => (string) ($party->name ?? ''),           // BT-27 / BT-44
		'id'        => $sellerId,                               // BT-29 sans schéma
		'global_id' => $globalId,                               // BT-29 / BT-46 avec schéma
		'legal_id'  => $legalId,                                // BT-30 / BT-47
		'contact'   => $contact,                                // BG-6 / BG-9
		'address'   => [                                        // BG-5 / BG-8
			'postcode' => (string) ($party->zip ?? ''),
			'line1'    => (string) ($party->address ?? ''),
			'city'     => (string) ($party->town ?? ''),
			'country'  => $country,
		],
		'endpoint'  => $endpoint,                               // BT-34 / BT-49
		'vat_id'    => (string) $vat,                           // BT-31 / BT-48
		'tax_id'    => (string) $taxId,                         // BT-32
	];
}

/**
 * Adresse électronique de routage calculée (BT-34 vendeur / BT-49 acheteur).
 *
 * Le réseau des Plateformes Agréées (réforme française) route les factures par
 * SIREN : l'adresse porte le SIREN avec schemeID="0225" (annuaire PPF,
 * XP Z12-012). Sans SIREN (tiers étranger hors périmètre), repli sur l'email
 * (schemeID="EM"). Null si aucune adresse n'est disponible, pour ne pas
 * émettre de bloc vide.
 *
 * Suffixe VENDEUR : certaines PA exigent que l'adresse électronique du vendeur
 * (BT-34) ne soit PAS le SIREN nu mais un endpoint suffixé — p.ex. Hubtimize/
 * Esalink attend "<SIREN>_Status" (adresse de retour des statuts de cycle de vie),
 * tel que paramétré à l'enregistrement de l'entité sur la PA. Configurable via
 * AUVOERECHNUNG_ENDPOINT_SUFFIX_SELLER (vide par défaut). Appliqué AU vendeur
 * uniquement : l'adresse acheteur (BT-49) reste le SIREN nu, la PA destinataire
 * se chargeant du routage.
 *
 * @param string $siren SIREN 9 chiffres (vide si absent)
 * @param string $email Email de repli
 * @param string $role  'Seller' (BT-34, suffixe appliqué) ou 'Buyer' (BT-49, nu)
 * @return array{scheme:string,id:string}|null
 */
function auvoerechnung_resolve_endpoint($siren, $email, $role = 'Seller')
{
	if (!empty($siren)) {
		$suffix = ($role === 'Seller') ? trim(getDolGlobalString('AUVOERECHNUNG_ENDPOINT_SUFFIX_SELLER', '')) : '';
		// BR-FR-23 : une adresse électronique en 0225 n'admet que les
		// alphanumériques, le tiret, le tiret bas et le point — tout autre
		// caractère du suffixe configuré est retiré (sanitisation défensive ;
		// un avertissement est émis par auvoerechnung_check_mandatory).
		if ($suffix !== '') {
			$suffix = preg_replace('/[^A-Za-z0-9._-]/', '', $suffix);
		}
		return ['scheme' => '0225', 'id' => $siren.$suffix];
	}
	if (!empty($email)) {
		return ['scheme' => 'EM', 'id' => (string) $email];
	}
	return null;
}

/**
 * Sérialise le modèle en CrossIndustryInvoice (CII D16B, profil EN16931),
 * dans l'ordre imposé par le XSD Factur-X / ZUGFeRD.
 *
 * @param array $m Modèle (cf. auvoerechnung_build_model)
 * @return string XML
 */
function auvoerechnung_model_to_cii($m)
{
	$e = 'auvoerechnung_xml_encode';
	$amt = 'auvoerechnung_format_amount';
	$doc = $m['document'];

	$xml  = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
	$xml .= '<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"';
	$xml .= ' xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"';
	$xml .= ' xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100"';
	$xml .= ' xmlns:qdt="urn:un:unece:uncefact:data:standard:QualifiedDataType:100">'."\n";

	// === ExchangedDocumentContext (BT-23, BT-24) ===
	$xml .= '<rsm:ExchangedDocumentContext>'."\n";
	if ($doc['business_process'] !== '') {
		$xml .= '  <ram:BusinessProcessSpecifiedDocumentContextParameter>'."\n";
		$xml .= '    <ram:ID>'.$e($doc['business_process']).'</ram:ID>'."\n";
		$xml .= '  </ram:BusinessProcessSpecifiedDocumentContextParameter>'."\n";
	}
	$xml .= '  <ram:GuidelineSpecifiedDocumentContextParameter>'."\n";
	$xml .= '    <ram:ID>'.$e($doc['specification']).'</ram:ID>'."\n";
	$xml .= '  </ram:GuidelineSpecifiedDocumentContextParameter>'."\n";
	$xml .= '</rsm:ExchangedDocumentContext>'."\n";

	// === ExchangedDocument (BT-1, BT-3, BT-2, BG-1) ===
	$xml .= '<rsm:ExchangedDocument>'."\n";
	$xml .= '  <ram:ID>'.$e($doc['id']).'</ram:ID>'."\n";
	$xml .= '  <ram:TypeCode>'.$e($doc['type_code']).'</ram:TypeCode>'."\n";
	$xml .= '  <ram:IssueDateTime>'."\n";
	$xml .= '    <udt:DateTimeString format="102">'.$e($doc['issue_date']).'</udt:DateTimeString>'."\n";
	$xml .= '  </ram:IssueDateTime>'."\n";
	foreach ($doc['notes'] as $note) {
		$xml .= '  <ram:IncludedNote>'."\n";
		$xml .= '    <ram:Content>'.$e($note['content']).'</ram:Content>'."\n";
		$xml .= '    <ram:SubjectCode>'.$note['subject'].'</ram:SubjectCode>'."\n";
		$xml .= '  </ram:IncludedNote>'."\n";
	}
	$xml .= '</rsm:ExchangedDocument>'."\n";

	// === SupplyChainTradeTransaction ===
	$xml .= '<rsm:SupplyChainTradeTransaction>'."\n";

	// BG-25 : lignes
	foreach ($m['lines'] as $l) {
		$xml .= '  <ram:IncludedSupplyChainTradeLineItem>'."\n";
		$xml .= '    <ram:AssociatedDocumentLineDocument>'."\n";
		$xml .= '      <ram:LineID>'.$e($l['id']).'</ram:LineID>'."\n";
		$xml .= '    </ram:AssociatedDocumentLineDocument>'."\n";
		$xml .= '    <ram:SpecifiedTradeProduct>'."\n";
		$xml .= '      <ram:Name>'.$e($l['name']).'</ram:Name>'."\n";
		$xml .= '    </ram:SpecifiedTradeProduct>'."\n";
		$xml .= '    <ram:SpecifiedLineTradeAgreement>'."\n";
		$xml .= '      <ram:NetPriceProductTradePrice>'."\n";
		// BT-146 : 4 décimales pour limiter l'écart qty x prix vs total de ligne
		$xml .= '        <ram:ChargeAmount>'.auvoerechnung_format_unit_price($l['net_price']).'</ram:ChargeAmount>'."\n";
		$xml .= '      </ram:NetPriceProductTradePrice>'."\n";
		$xml .= '    </ram:SpecifiedLineTradeAgreement>'."\n";
		$xml .= '    <ram:SpecifiedLineTradeDelivery>'."\n";
		$xml .= '      <ram:BilledQuantity unitCode="'.$e($l['unit_code']).'">'.auvoerechnung_format_qty($l['quantity']).'</ram:BilledQuantity>'."\n";
		$xml .= '    </ram:SpecifiedLineTradeDelivery>'."\n";
		$xml .= '    <ram:SpecifiedLineTradeSettlement>'."\n";
		$xml .= '      <ram:ApplicableTradeTax>'."\n";
		$xml .= '        <ram:TypeCode>VAT</ram:TypeCode>'."\n";
		$xml .= '        <ram:CategoryCode>'.$e($l['vat_category']).'</ram:CategoryCode>'."\n";
		if ($l['vat_rate'] !== null) {
			$xml .= '        <ram:RateApplicablePercent>'.$amt($l['vat_rate']).'</ram:RateApplicablePercent>'."\n";
		}
		$xml .= '      </ram:ApplicableTradeTax>'."\n";
		$xml .= '      <ram:SpecifiedTradeSettlementLineMonetarySummation>'."\n";
		$xml .= '        <ram:LineTotalAmount>'.$amt($l['net_amount']).'</ram:LineTotalAmount>'."\n";
		$xml .= '      </ram:SpecifiedTradeSettlementLineMonetarySummation>'."\n";
		$xml .= '    </ram:SpecifiedLineTradeSettlement>'."\n";
		$xml .= '  </ram:IncludedSupplyChainTradeLineItem>'."\n";
	}

	// === ApplicableHeaderTradeAgreement ===
	$xml .= '  <ram:ApplicableHeaderTradeAgreement>'."\n";
	if ($doc['buyer_reference'] !== '') {
		$xml .= '    <ram:BuyerReference>'.$e($doc['buyer_reference']).'</ram:BuyerReference>'."\n";
	}
	$xml .= auvoerechnung_cii_party($m['seller']);
	$xml .= auvoerechnung_cii_party($m['buyer']);
	if ($doc['order_reference'] !== '') {
		$xml .= '    <ram:BuyerOrderReferencedDocument>'."\n";
		$xml .= '      <ram:IssuerAssignedID>'.$e($doc['order_reference']).'</ram:IssuerAssignedID>'."\n";
		$xml .= '    </ram:BuyerOrderReferencedDocument>'."\n";
	}
	if ($doc['contract_reference'] !== '') {
		$xml .= '    <ram:ContractReferencedDocument>'."\n";
		$xml .= '      <ram:IssuerAssignedID>'.$e($doc['contract_reference']).'</ram:IssuerAssignedID>'."\n";
		$xml .= '    </ram:ContractReferencedDocument>'."\n";
	}
	// BG-24 : pièces jointes encodées en base64 (TypeCode 916)
	foreach ($m['attachments'] as $att) {
		$xml .= '    <ram:AdditionalReferencedDocument>'."\n";
		$xml .= '      <ram:IssuerAssignedID>'.$e($att['id']).'</ram:IssuerAssignedID>'."\n";
		$xml .= '      <ram:TypeCode>916</ram:TypeCode>'."\n";
		if (($att['description'] ?? '') !== '') {
			$xml .= '      <ram:Name>'.$e($att['description']).'</ram:Name>'."\n";
		}
		$xml .= '      <ram:AttachmentBinaryObject mimeCode="'.$e($att['mime']).'" filename="'.$e($att['name']).'">'.base64_encode($att['content']).'</ram:AttachmentBinaryObject>'."\n";
		$xml .= '    </ram:AdditionalReferencedDocument>'."\n";
	}
	$xml .= '  </ram:ApplicableHeaderTradeAgreement>'."\n";

	// === ApplicableHeaderTradeDelivery (BT-80, BT-72) ===
	$xml .= '  <ram:ApplicableHeaderTradeDelivery>'."\n";
	if (!empty($m['delivery']['ship_to'])) {
		// BG-13 : BT-70 nom, BG-15 adresse (BT-80 : pays du lieu de livraison)
		$st = $m['delivery']['ship_to'];
		$xml .= '    <ram:ShipToTradeParty>'."\n";
		if ($st['name'] !== '') {
			$xml .= '      <ram:Name>'.$e($st['name']).'</ram:Name>'."\n";
		}
		$xml .= '      <ram:PostalTradeAddress>'."\n";
		if ($st['address']['postcode'] !== '') {
			$xml .= '        <ram:PostcodeCode>'.$e($st['address']['postcode']).'</ram:PostcodeCode>'."\n";
		}
		if ($st['address']['line1'] !== '') {
			$xml .= '        <ram:LineOne>'.$e($st['address']['line1']).'</ram:LineOne>'."\n";
		}
		if ($st['address']['city'] !== '') {
			$xml .= '        <ram:CityName>'.$e($st['address']['city']).'</ram:CityName>'."\n";
		}
		$xml .= '        <ram:CountryID>'.$e($st['address']['country']).'</ram:CountryID>'."\n";
		$xml .= '      </ram:PostalTradeAddress>'."\n";
		$xml .= '    </ram:ShipToTradeParty>'."\n";
	} elseif ($m['delivery']['ship_to_country'] !== '') {
		$xml .= '    <ram:ShipToTradeParty>'."\n";
		$xml .= '      <ram:PostalTradeAddress>'."\n";
		$xml .= '        <ram:CountryID>'.$e($m['delivery']['ship_to_country']).'</ram:CountryID>'."\n";
		$xml .= '      </ram:PostalTradeAddress>'."\n";
		$xml .= '    </ram:ShipToTradeParty>'."\n";
	}
	$xml .= '    <ram:ActualDeliverySupplyChainEvent>'."\n";
	$xml .= '      <ram:OccurrenceDateTime>'."\n";
	$xml .= '        <udt:DateTimeString format="102">'.$e($m['delivery']['date']).'</udt:DateTimeString>'."\n";
	$xml .= '      </ram:OccurrenceDateTime>'."\n";
	$xml .= '    </ram:ActualDeliverySupplyChainEvent>'."\n";
	$xml .= '  </ram:ApplicableHeaderTradeDelivery>'."\n";

	// === ApplicableHeaderTradeSettlement ===
	$pay = $m['payment'];
	$xml .= '  <ram:ApplicableHeaderTradeSettlement>'."\n";
	if ($pay['creditor_id'] !== '') {
		$xml .= '    <ram:CreditorReferenceID>'.$e($pay['creditor_id']).'</ram:CreditorReferenceID>'."\n";
	}
	$xml .= '    <ram:InvoiceCurrencyCode>'.$e($doc['currency']).'</ram:InvoiceCurrencyCode>'."\n";
	if ($pay['means_code'] !== '') {
		$xml .= '    <ram:SpecifiedTradeSettlementPaymentMeans>'."\n";
		$xml .= '      <ram:TypeCode>'.$e($pay['means_code']).'</ram:TypeCode>'."\n";
		if ($pay['debtor_iban'] !== '') {
			$xml .= '      <ram:PayerPartyDebtorFinancialAccount>'."\n";
			$xml .= '        <ram:IBANID>'.$e($pay['debtor_iban']).'</ram:IBANID>'."\n";
			$xml .= '      </ram:PayerPartyDebtorFinancialAccount>'."\n";
		}
		if ($pay['payee_iban'] !== '') {
			$xml .= '      <ram:PayeePartyCreditorFinancialAccount>'."\n";
			$xml .= '        <ram:IBANID>'.$e($pay['payee_iban']).'</ram:IBANID>'."\n";
			$xml .= '      </ram:PayeePartyCreditorFinancialAccount>'."\n";
			if ($pay['payee_bic'] !== '') {
				$xml .= '      <ram:PayeeSpecifiedCreditorFinancialInstitution>'."\n";
				$xml .= '        <ram:BICID>'.$e($pay['payee_bic']).'</ram:BICID>'."\n";
				$xml .= '      </ram:PayeeSpecifiedCreditorFinancialInstitution>'."\n";
			}
		}
		$xml .= '    </ram:SpecifiedTradeSettlementPaymentMeans>'."\n";
	}

	foreach ($m['vat_breakdown'] as $vb) {
		$xml .= '    <ram:ApplicableTradeTax>'."\n";
		$xml .= '      <ram:CalculatedAmount>'.$amt($vb['tax']).'</ram:CalculatedAmount>'."\n";
		$xml .= '      <ram:TypeCode>VAT</ram:TypeCode>'."\n";
		if ($vb['exemption_reason'] !== '') {
			$xml .= '      <ram:ExemptionReason>'.$e($vb['exemption_reason']).'</ram:ExemptionReason>'."\n";
		}
		$xml .= '      <ram:BasisAmount>'.$amt($vb['basis']).'</ram:BasisAmount>'."\n";
		$xml .= '      <ram:CategoryCode>'.$e($vb['category']).'</ram:CategoryCode>'."\n";
		if ($vb['exemption_code'] !== '') {
			$xml .= '      <ram:ExemptionReasonCode>'.$e($vb['exemption_code']).'</ram:ExemptionReasonCode>'."\n";
		}
		// BT-8 : porté par la catégorie taxée (S) en CII
		if ($doc['vat_due_date_code'] !== '' && $vb['category'] === 'S') {
			$xml .= '      <ram:DueDateTypeCode>'.$e($doc['vat_due_date_code']).'</ram:DueDateTypeCode>'."\n";
		}
		if ($vb['rate'] !== null) {
			$xml .= '      <ram:RateApplicablePercent>'.$amt($vb['rate']).'</ram:RateApplicablePercent>'."\n";
		}
		$xml .= '    </ram:ApplicableTradeTax>'."\n";
	}

	// BG-14 : période de facturation
	if ($doc['period_start'] !== '' || $doc['period_end'] !== '') {
		$xml .= '    <ram:BillingSpecifiedPeriod>'."\n";
		if ($doc['period_start'] !== '') {
			$xml .= '      <ram:StartDateTime>'."\n";
			$xml .= '        <udt:DateTimeString format="102">'.$doc['period_start'].'</udt:DateTimeString>'."\n";
			$xml .= '      </ram:StartDateTime>'."\n";
		}
		if ($doc['period_end'] !== '') {
			$xml .= '      <ram:EndDateTime>'."\n";
			$xml .= '        <udt:DateTimeString format="102">'.$doc['period_end'].'</udt:DateTimeString>'."\n";
			$xml .= '      </ram:EndDateTime>'."\n";
		}
		$xml .= '    </ram:BillingSpecifiedPeriod>'."\n";
	}

	// BG-20 : remises document
	foreach ($m['allowances'] as $al) {
		$xml .= '    <ram:SpecifiedTradeAllowanceCharge>'."\n";
		$xml .= '      <ram:ChargeIndicator><udt:Indicator>false</udt:Indicator></ram:ChargeIndicator>'."\n";
		$xml .= '      <ram:ActualAmount>'.$amt($al['amount']).'</ram:ActualAmount>'."\n";
		$xml .= '      <ram:Reason>'.$e($al['reason']).'</ram:Reason>'."\n";
		$xml .= '      <ram:CategoryTradeTax>'."\n";
		$xml .= '        <ram:TypeCode>VAT</ram:TypeCode>'."\n";
		$xml .= '        <ram:CategoryCode>'.$e($al['vat_category']).'</ram:CategoryCode>'."\n";
		if ($al['vat_rate'] !== null) {
			$xml .= '        <ram:RateApplicablePercent>'.$amt($al['vat_rate']).'</ram:RateApplicablePercent>'."\n";
		}
		$xml .= '      </ram:CategoryTradeTax>'."\n";
		$xml .= '    </ram:SpecifiedTradeAllowanceCharge>'."\n";
	}

	// BT-20, BT-9, BT-89
	$xml .= '    <ram:SpecifiedTradePaymentTerms>'."\n";
	if ($pay['terms'] !== '') {
		$xml .= '      <ram:Description>'.$e($pay['terms']).'</ram:Description>'."\n";
	}
	$xml .= '      <ram:DueDateDateTime>'."\n";
	$xml .= '        <udt:DateTimeString format="102">'.$e($pay['due_date']).'</udt:DateTimeString>'."\n";
	$xml .= '      </ram:DueDateDateTime>'."\n";
	if ($pay['mandate_id'] !== '') {
		$xml .= '      <ram:DirectDebitMandateID>'.$e($pay['mandate_id']).'</ram:DirectDebitMandateID>'."\n";
	}
	$xml .= '    </ram:SpecifiedTradePaymentTerms>'."\n";

	// BG-22 : totaux
	$t = $m['totals'];
	$xml .= '    <ram:SpecifiedTradeSettlementHeaderMonetarySummation>'."\n";
	$xml .= '      <ram:LineTotalAmount>'.$amt($t['line_total']).'</ram:LineTotalAmount>'."\n";
	if ($t['allowance_total'] > 0) {
		$xml .= '      <ram:AllowanceTotalAmount>'.$amt($t['allowance_total']).'</ram:AllowanceTotalAmount>'."\n";
	}
	$xml .= '      <ram:TaxBasisTotalAmount>'.$amt($t['tax_basis']).'</ram:TaxBasisTotalAmount>'."\n";
	$xml .= '      <ram:TaxTotalAmount currencyID="'.$e($doc['currency']).'">'.$amt($t['tax_total']).'</ram:TaxTotalAmount>'."\n";
	$xml .= '      <ram:GrandTotalAmount>'.$amt($t['grand_total']).'</ram:GrandTotalAmount>'."\n";
	if ($t['prepaid'] > 0) {
		$xml .= '      <ram:TotalPrepaidAmount>'.$amt($t['prepaid']).'</ram:TotalPrepaidAmount>'."\n";
	}
	$xml .= '      <ram:DuePayableAmount>'.$amt($t['due_payable']).'</ram:DuePayableAmount>'."\n";
	$xml .= '    </ram:SpecifiedTradeSettlementHeaderMonetarySummation>'."\n";

	// BG-3 : factures antérieures
	foreach ($m['preceding_invoices'] as $prev) {
		$xml .= '    <ram:InvoiceReferencedDocument>'."\n";
		$xml .= '      <ram:IssuerAssignedID>'.$e($prev['ref']).'</ram:IssuerAssignedID>'."\n";
		if (!empty($prev['date'])) {
			$xml .= '      <ram:FormattedIssueDateTime>'."\n";
			$xml .= '        <qdt:DateTimeString format="102">'.$e($prev['date']).'</qdt:DateTimeString>'."\n";
			$xml .= '      </ram:FormattedIssueDateTime>'."\n";
		}
		$xml .= '    </ram:InvoiceReferencedDocument>'."\n";
	}

	$xml .= '  </ram:ApplicableHeaderTradeSettlement>'."\n";
	$xml .= '</rsm:SupplyChainTradeTransaction>'."\n";
	$xml .= '</rsm:CrossIndustryInvoice>';

	return $xml;
}

/**
 * Bloc CII d'une partie (SellerTradeParty / BuyerTradeParty), dans l'ordre de
 * la séquence XSD du TradePartyType : ID, GlobalID, Name,
 * SpecifiedLegalOrganization, DefinedTradeContact, PostalTradeAddress,
 * URIUniversalCommunication, SpecifiedTaxRegistration.
 *
 * L'identifiant qualifié par un schéma ISO 6523 (BT-29/BT-46) va dans
 * ram:GlobalID, PAS dans ram:ID : EN16931 ne lit le schemeID que sur GlobalID
 * — sur ram:ID il est « not used in the given context » et fait échouer la
 * validation Schematron (FNFE-MPE).
 *
 * @param array $p Partie (cf. auvoerechnung_build_party_model)
 * @return string XML
 */
function auvoerechnung_cii_party($p)
{
	$e = 'auvoerechnung_xml_encode';
	$tag = ($p['role'] === 'Seller') ? 'SellerTradeParty' : 'BuyerTradeParty';

	$xml  = '    <ram:'.$tag.'>'."\n";
	if ($p['id'] !== '') {
		$xml .= '      <ram:ID>'.$e($p['id']).'</ram:ID>'."\n";
	}
	if ($p['global_id'] !== null) {
		$xml .= '      <ram:GlobalID schemeID="'.$e($p['global_id']['scheme']).'">'.$e($p['global_id']['id']).'</ram:GlobalID>'."\n";
	}
	$xml .= '      <ram:Name>'.$e($p['name']).'</ram:Name>'."\n";
	if ($p['legal_id'] !== null) {
		$xml .= '      <ram:SpecifiedLegalOrganization>'."\n";
		$scheme = $p['legal_id']['scheme'] !== '' ? ' schemeID="'.$e($p['legal_id']['scheme']).'"' : '';
		$xml .= '        <ram:ID'.$scheme.'>'.$e($p['legal_id']['id']).'</ram:ID>'."\n";
		$xml .= '      </ram:SpecifiedLegalOrganization>'."\n";
	}
	if ($p['contact'] !== null) {
		$c = $p['contact'];
		$xml .= '      <ram:DefinedTradeContact>'."\n";
		if ($c['name'] !== '') {
			$xml .= '        <ram:PersonName>'.$e($c['name']).'</ram:PersonName>'."\n";
		}
		if ($c['phone'] !== '') {
			$xml .= '        <ram:TelephoneUniversalCommunication>'."\n";
			$xml .= '          <ram:CompleteNumber>'.$e($c['phone']).'</ram:CompleteNumber>'."\n";
			$xml .= '        </ram:TelephoneUniversalCommunication>'."\n";
		}
		if ($c['email'] !== '') {
			$xml .= '        <ram:EmailURIUniversalCommunication>'."\n";
			$xml .= '          <ram:URIID>'.$e($c['email']).'</ram:URIID>'."\n";
			$xml .= '        </ram:EmailURIUniversalCommunication>'."\n";
		}
		$xml .= '      </ram:DefinedTradeContact>'."\n";
	}
	$a = $p['address'];
	$xml .= '      <ram:PostalTradeAddress>'."\n";
	$xml .= '        <ram:PostcodeCode>'.$e($a['postcode']).'</ram:PostcodeCode>'."\n";
	$xml .= '        <ram:LineOne>'.$e($a['line1']).'</ram:LineOne>'."\n";
	$xml .= '        <ram:CityName>'.$e($a['city']).'</ram:CityName>'."\n";
	$xml .= '        <ram:CountryID>'.$e($a['country']).'</ram:CountryID>'."\n";
	$xml .= '      </ram:PostalTradeAddress>'."\n";
	if ($p['endpoint'] !== null) {
		$xml .= '      <ram:URIUniversalCommunication>'."\n";
		$xml .= '        <ram:URIID schemeID="'.$e($p['endpoint']['scheme']).'">'.$e($p['endpoint']['id']).'</ram:URIID>'."\n";
		$xml .= '      </ram:URIUniversalCommunication>'."\n";
	}
	if ($p['vat_id'] !== '') {
		$xml .= '      <ram:SpecifiedTaxRegistration>'."\n";
		$xml .= '        <ram:ID schemeID="VA">'.$e($p['vat_id']).'</ram:ID>'."\n";
		$xml .= '      </ram:SpecifiedTaxRegistration>'."\n";
	} elseif ($p['tax_id'] !== '') {
		$xml .= '      <ram:SpecifiedTaxRegistration>'."\n";
		$xml .= '        <ram:ID schemeID="FC">'.$e($p['tax_id']).'</ram:ID>'."\n";
		$xml .= '      </ram:SpecifiedTaxRegistration>'."\n";
	}
	$xml .= '    </ram:'.$tag.'>'."\n";

	return $xml;
}

/**
 * Sérialise le modèle en UBL 2.1 (Invoice, ou CreditNote pour un avoir 381),
 * selon la syntaxe UBL de la norme EN 16931-3-2 et l'ordre des XSD OASIS.
 *
 * Particularités par rapport à CII :
 *  - avoir : document CreditNote / CreditNoteLine / CreditedQuantity, et la
 *    date d'échéance (BT-9) passe dans PaymentMeans/PaymentDueDate ;
 *  - BT-8 : InvoicePeriod/DescriptionCode, liste UNTDID 2005 (3 = date de
 *    facture, 35 = livraison, 432 = paiement) au lieu des codes 2475 de CII ;
 *  - BG-1 : le code sujet (BT-21) n'a pas d'élément propre, convention
 *    « #CODE#texte » dans cbc:Note ;
 *  - BT-90 : identifiant créancier SEPA en PartyIdentification du vendeur
 *    (schemeID SEPA) ; mandat BT-89 et IBAN débiteur BT-91 dans
 *    PaymentMeans/PaymentMandate ;
 *  - tous les montants portent currencyID ; éléments vides omis
 *    (PEPPOL-EN16931-R008).
 *
 * @param array $m Modèle (cf. auvoerechnung_build_model)
 * @return string XML
 */
function auvoerechnung_model_to_ubl($m)
{
	$e = 'auvoerechnung_xml_encode';
	$doc = $m['document'];
	$cur = $e($doc['currency']);
	$money = function ($v) use ($cur) {
		return ' currencyID="'.$cur.'">'.auvoerechnung_format_amount($v);
	};
	$isCN = !empty($doc['is_credit_note']);
	$root = $isCN ? 'CreditNote' : 'Invoice';
	$date = function ($ymd) {
		return substr($ymd, 0, 4).'-'.substr($ymd, 4, 2).'-'.substr($ymd, 6, 2);
	};

	$xml  = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
	$xml .= '<'.$root.' xmlns="urn:oasis:names:specification:ubl:schema:xsd:'.$root.'-2"';
	$xml .= ' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"';
	$xml .= ' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'."\n";
	$xml .= '  <cbc:CustomizationID>'.$e($doc['specification']).'</cbc:CustomizationID>'."\n";      // BT-24
	if ($doc['business_process'] !== '') {
		$xml .= '  <cbc:ProfileID>'.$e($doc['business_process']).'</cbc:ProfileID>'."\n";            // BT-23
	}
	$xml .= '  <cbc:ID>'.$e($doc['id']).'</cbc:ID>'."\n";                                          // BT-1
	$xml .= '  <cbc:IssueDate>'.$date($doc['issue_date']).'</cbc:IssueDate>'."\n";                 // BT-2
	if (!$isCN && $m['payment']['due_date'] !== '') {
		$xml .= '  <cbc:DueDate>'.$date($m['payment']['due_date']).'</cbc:DueDate>'."\n";          // BT-9
	}
	$xml .= '  <cbc:'.$root.'TypeCode>'.$e($doc['type_code']).'</cbc:'.$root.'TypeCode>'."\n";    // BT-3
	foreach ($doc['notes'] as $note) {
		$xml .= '  <cbc:Note>'.$e('#'.$note['subject'].'#'.$note['content']).'</cbc:Note>'."\n";  // BG-1
	}
	$xml .= '  <cbc:DocumentCurrencyCode>'.$cur.'</cbc:DocumentCurrencyCode>'."\n";               // BT-5
	if ($doc['buyer_reference'] !== '') {
		$xml .= '  <cbc:BuyerReference>'.$e($doc['buyer_reference']).'</cbc:BuyerReference>'."\n"; // BT-10
	}
	// BG-14 + BT-8 (émis comme en CII : seulement s'il y a une catégorie S)
	$hasS = in_array('S', array_column($m['vat_breakdown'], 'category'), true);
	$bt8 = ($hasS && $doc['vat_due_date_code'] !== '') ? (['5' => '3', '29' => '35', '72' => '432'][$doc['vat_due_date_code']] ?? '') : '';
	if ($doc['period_start'] !== '' || $doc['period_end'] !== '' || $bt8 !== '') {
		$xml .= '  <cac:InvoicePeriod>'."\n";
		if ($doc['period_start'] !== '') {
			$xml .= '    <cbc:StartDate>'.$date($doc['period_start']).'</cbc:StartDate>'."\n";
		}
		if ($doc['period_end'] !== '') {
			$xml .= '    <cbc:EndDate>'.$date($doc['period_end']).'</cbc:EndDate>'."\n";
		}
		if ($bt8 !== '') {
			$xml .= '    <cbc:DescriptionCode>'.$bt8.'</cbc:DescriptionCode>'."\n";
		}
		$xml .= '  </cac:InvoicePeriod>'."\n";
	}
	if ($doc['order_reference'] !== '') {
		$xml .= '  <cac:OrderReference>'."\n";
		$xml .= '    <cbc:ID>'.$e($doc['order_reference']).'</cbc:ID>'."\n";                       // BT-13
		$xml .= '  </cac:OrderReference>'."\n";
	}
	foreach ($m['preceding_invoices'] as $prev) {                                                  // BG-3
		$xml .= '  <cac:BillingReference>'."\n";
		$xml .= '    <cac:InvoiceDocumentReference>'."\n";
		$xml .= '      <cbc:ID>'.$e($prev['ref']).'</cbc:ID>'."\n";
		if (!empty($prev['date'])) {
			$xml .= '      <cbc:IssueDate>'.$date($prev['date']).'</cbc:IssueDate>'."\n";
		}
		$xml .= '    </cac:InvoiceDocumentReference>'."\n";
		$xml .= '  </cac:BillingReference>'."\n";
	}
	if ($doc['contract_reference'] !== '') {
		$xml .= '  <cac:ContractDocumentReference>'."\n";
		$xml .= '    <cbc:ID>'.$e($doc['contract_reference']).'</cbc:ID>'."\n";                    // BT-12
		$xml .= '  </cac:ContractDocumentReference>'."\n";
	}
	foreach ($m['attachments'] as $att) {                                                          // BG-24
		$xml .= '  <cac:AdditionalDocumentReference>'."\n";
		$xml .= '    <cbc:ID>'.$e($att['id']).'</cbc:ID>'."\n";
		if (($att['description'] ?? '') !== '') {
			$xml .= '    <cbc:DocumentDescription>'.$e($att['description']).'</cbc:DocumentDescription>'."\n";
		}
		$xml .= '    <cac:Attachment>'."\n";
		$xml .= '      <cbc:EmbeddedDocumentBinaryObject mimeCode="'.$e($att['mime']).'" filename="'.$e($att['name']).'">'.base64_encode($att['content']).'</cbc:EmbeddedDocumentBinaryObject>'."\n";
		$xml .= '    </cac:Attachment>'."\n";
		$xml .= '  </cac:AdditionalDocumentReference>'."\n";
	}

	$xml .= '  <cac:AccountingSupplierParty>'."\n".auvoerechnung_ubl_party($m['seller'], $m['payment']['creditor_id']).'  </cac:AccountingSupplierParty>'."\n";
	$xml .= '  <cac:AccountingCustomerParty>'."\n".auvoerechnung_ubl_party($m['buyer']).'  </cac:AccountingCustomerParty>'."\n";

	// BT-72, BT-80
	$xml .= '  <cac:Delivery>'."\n";
	$xml .= '    <cbc:ActualDeliveryDate>'.$date($m['delivery']['date']).'</cbc:ActualDeliveryDate>'."\n";
	$st = $m['delivery']['ship_to'] ?? null;
	$stAddr = !empty($st) ? $st['address'] : ($m['delivery']['ship_to_country'] !== '' ? ['postcode' => '', 'line1' => '', 'city' => '', 'country' => $m['delivery']['ship_to_country']] : null);
	if ($stAddr !== null) {
		$xml .= '    <cac:DeliveryLocation>'."\n";
		$xml .= '      <cac:Address>'."\n";
		if ($stAddr['line1'] !== '') {
			$xml .= '        <cbc:StreetName>'.$e($stAddr['line1']).'</cbc:StreetName>'."\n";
		}
		if ($stAddr['city'] !== '') {
			$xml .= '        <cbc:CityName>'.$e($stAddr['city']).'</cbc:CityName>'."\n";
		}
		if ($stAddr['postcode'] !== '') {
			$xml .= '        <cbc:PostalZone>'.$e($stAddr['postcode']).'</cbc:PostalZone>'."\n";
		}
		$xml .= '        <cac:Country>'."\n";
		$xml .= '          <cbc:IdentificationCode>'.$e($stAddr['country']).'</cbc:IdentificationCode>'."\n";
		$xml .= '        </cac:Country>'."\n";
		$xml .= '      </cac:Address>'."\n";
		$xml .= '    </cac:DeliveryLocation>'."\n";
	}
	if (!empty($st) && $st['name'] !== '') {
		$xml .= '    <cac:DeliveryParty>'."\n";
		$xml .= '      <cac:PartyName>'."\n";
		$xml .= '        <cbc:Name>'.$e($st['name']).'</cbc:Name>'."\n";               // BT-70
		$xml .= '      </cac:PartyName>'."\n";
		$xml .= '    </cac:DeliveryParty>'."\n";
	}
	$xml .= '  </cac:Delivery>'."\n";

	// BG-16 : moyen de paiement
	$pay = $m['payment'];
	if ($pay['means_code'] !== '') {
		$xml .= '  <cac:PaymentMeans>'."\n";
		$xml .= '    <cbc:PaymentMeansCode>'.$e($pay['means_code']).'</cbc:PaymentMeansCode>'."\n";      // BT-81
		if ($isCN && $pay['due_date'] !== '') {
			$xml .= '    <cbc:PaymentDueDate>'.$date($pay['due_date']).'</cbc:PaymentDueDate>'."\n";     // BT-9 (avoir)
		}
		if ($pay['payee_iban'] !== '') {
			$xml .= '    <cac:PayeeFinancialAccount>'."\n";
			$xml .= '      <cbc:ID>'.$e($pay['payee_iban']).'</cbc:ID>'."\n";                            // BT-84
			if ($pay['payee_bic'] !== '') {
				$xml .= '      <cac:FinancialInstitutionBranch>'."\n";
				$xml .= '        <cbc:ID>'.$e($pay['payee_bic']).'</cbc:ID>'."\n";                       // BT-86
				$xml .= '      </cac:FinancialInstitutionBranch>'."\n";
			}
			$xml .= '    </cac:PayeeFinancialAccount>'."\n";
		}
		if ($pay['mandate_id'] !== '' || $pay['debtor_iban'] !== '') {
			$xml .= '    <cac:PaymentMandate>'."\n";
			if ($pay['mandate_id'] !== '') {
				$xml .= '      <cbc:ID>'.$e($pay['mandate_id']).'</cbc:ID>'."\n";                      // BT-89
			}
			if ($pay['debtor_iban'] !== '') {
				$xml .= '      <cac:PayerFinancialAccount>'."\n";
				$xml .= '        <cbc:ID>'.$e($pay['debtor_iban']).'</cbc:ID>'."\n";                     // BT-91
				$xml .= '      </cac:PayerFinancialAccount>'."\n";
			}
			$xml .= '    </cac:PaymentMandate>'."\n";
		}
		$xml .= '  </cac:PaymentMeans>'."\n";
	}
	if ($pay['terms'] !== '') {
		$xml .= '  <cac:PaymentTerms>'."\n";
		$xml .= '    <cbc:Note>'.$e($pay['terms']).'</cbc:Note>'."\n";                                // BT-20
		$xml .= '  </cac:PaymentTerms>'."\n";
	}

	// BG-20 : remises document
	foreach ($m['allowances'] as $al) {
		$xml .= '  <cac:AllowanceCharge>'."\n";
		$xml .= '    <cbc:ChargeIndicator>false</cbc:ChargeIndicator>'."\n";
		$xml .= '    <cbc:AllowanceChargeReason>'.$e($al['reason']).'</cbc:AllowanceChargeReason>'."\n";
		$xml .= '    <cbc:Amount'.$money($al['amount']).'</cbc:Amount>'."\n";
		$xml .= auvoerechnung_ubl_tax_category('TaxCategory', $al['vat_category'], $al['vat_rate'], '', '', '    ');
		$xml .= '  </cac:AllowanceCharge>'."\n";
	}

	// BG-23 : ventilation TVA
	$t = $m['totals'];
	$xml .= '  <cac:TaxTotal>'."\n";
	$xml .= '    <cbc:TaxAmount'.$money($t['tax_total']).'</cbc:TaxAmount>'."\n";                      // BT-110
	foreach ($m['vat_breakdown'] as $vb) {
		$xml .= '    <cac:TaxSubtotal>'."\n";
		$xml .= '      <cbc:TaxableAmount'.$money($vb['basis']).'</cbc:TaxableAmount>'."\n";          // BT-116
		$xml .= '      <cbc:TaxAmount'.$money($vb['tax']).'</cbc:TaxAmount>'."\n";                    // BT-117
		$xml .= auvoerechnung_ubl_tax_category('TaxCategory', $vb['category'], $vb['rate'], $vb['exemption_code'], $vb['exemption_reason'], '      ');
		$xml .= '    </cac:TaxSubtotal>'."\n";
	}
	$xml .= '  </cac:TaxTotal>'."\n";

	// BG-22 : totaux
	$xml .= '  <cac:LegalMonetaryTotal>'."\n";
	$xml .= '    <cbc:LineExtensionAmount'.$money($t['line_total']).'</cbc:LineExtensionAmount>'."\n";  // BT-106
	$xml .= '    <cbc:TaxExclusiveAmount'.$money($t['tax_basis']).'</cbc:TaxExclusiveAmount>'."\n";    // BT-109
	$xml .= '    <cbc:TaxInclusiveAmount'.$money($t['grand_total']).'</cbc:TaxInclusiveAmount>'."\n";  // BT-112
	if ($t['allowance_total'] > 0) {
		$xml .= '    <cbc:AllowanceTotalAmount'.$money($t['allowance_total']).'</cbc:AllowanceTotalAmount>'."\n"; // BT-107
	}
	if ($t['prepaid'] > 0) {
		$xml .= '    <cbc:PrepaidAmount'.$money($t['prepaid']).'</cbc:PrepaidAmount>'."\n";            // BT-113
	}
	$xml .= '    <cbc:PayableAmount'.$money($t['due_payable']).'</cbc:PayableAmount>'."\n";          // BT-115
	$xml .= '  </cac:LegalMonetaryTotal>'."\n";

	// BG-25 : lignes
	$lineTag = $isCN ? 'CreditNoteLine' : 'InvoiceLine';
	$qtyTag = $isCN ? 'CreditedQuantity' : 'InvoicedQuantity';
	foreach ($m['lines'] as $l) {
		$xml .= '  <cac:'.$lineTag.'>'."\n";
		$xml .= '    <cbc:ID>'.$e($l['id']).'</cbc:ID>'."\n";                                          // BT-126
		$xml .= '    <cbc:'.$qtyTag.' unitCode="'.$e($l['unit_code']).'">'.auvoerechnung_format_qty($l['quantity']).'</cbc:'.$qtyTag.'>'."\n"; // BT-129/130
		$xml .= '    <cbc:LineExtensionAmount'.$money($l['net_amount']).'</cbc:LineExtensionAmount>'."\n"; // BT-131
		$xml .= '    <cac:Item>'."\n";
		$xml .= '      <cbc:Name>'.$e($l['name']).'</cbc:Name>'."\n";                                  // BT-153
		$xml .= auvoerechnung_ubl_tax_category('ClassifiedTaxCategory', $l['vat_category'], $l['vat_rate'], '', '', '      ');
		$xml .= '    </cac:Item>'."\n";
		$xml .= '    <cac:Price>'."\n";
		$xml .= '      <cbc:PriceAmount currencyID="'.$cur.'">'.auvoerechnung_format_unit_price($l['net_price']).'</cbc:PriceAmount>'."\n"; // BT-146
		$xml .= '    </cac:Price>'."\n";
		$xml .= '  </cac:'.$lineTag.'>'."\n";
	}

	$xml .= '</'.$root.'>';
	return $xml;
}

/**
 * Catégorie de TVA UBL (TaxCategory / ClassifiedTaxCategory) : code, taux
 * (absent pour O), code et motif d'exonération, schéma VAT.
 */
function auvoerechnung_ubl_tax_category($tag, $category, $rate, $exemptionCode, $exemptionReason, $indent)
{
	$e = 'auvoerechnung_xml_encode';
	$xml  = $indent.'<cac:'.$tag.'>'."\n";
	$xml .= $indent.'  <cbc:ID>'.$e($category).'</cbc:ID>'."\n";
	if ($rate !== null) {
		$xml .= $indent.'  <cbc:Percent>'.auvoerechnung_format_amount($rate).'</cbc:Percent>'."\n";
	}
	if ($exemptionCode !== '') {
		$xml .= $indent.'  <cbc:TaxExemptionReasonCode>'.$e($exemptionCode).'</cbc:TaxExemptionReasonCode>'."\n";
	}
	if ($exemptionReason !== '') {
		$xml .= $indent.'  <cbc:TaxExemptionReason>'.$e($exemptionReason).'</cbc:TaxExemptionReason>'."\n";
	}
	$xml .= $indent.'  <cac:TaxScheme>'."\n";
	$xml .= $indent.'    <cbc:ID>VAT</cbc:ID>'."\n";
	$xml .= $indent.'  </cac:TaxScheme>'."\n";
	$xml .= $indent.'</cac:'.$tag.'>'."\n";
	return $xml;
}

/**
 * Bloc cac:Party UBL d'une partie, dans l'ordre du XSD (EndpointID,
 * PartyIdentification, PostalAddress, PartyTaxScheme, PartyLegalEntity,
 * Contact).
 *
 * @param array  $p           Partie (cf. auvoerechnung_build_party_model)
 * @param string $creditorId  BT-90 (vendeur, prélèvement SEPA)
 * @return string XML
 */
function auvoerechnung_ubl_party($p, $creditorId = '')
{
	$e = 'auvoerechnung_xml_encode';
	$xml = '    <cac:Party>'."\n";
	if ($p['endpoint'] !== null) {
		$xml .= '      <cbc:EndpointID schemeID="'.$e($p['endpoint']['scheme']).'">'.$e($p['endpoint']['id']).'</cbc:EndpointID>'."\n"; // BT-34/49
	}
	$ids = [];
	if ($p['id'] !== '') {
		$ids[] = ['', $p['id']];                                       // BT-29 sans schéma
	}
	if ($p['global_id'] !== null) {
		$ids[] = [$p['global_id']['scheme'], $p['global_id']['id']];   // BT-29/46 avec schéma
	}
	if ($creditorId !== '') {
		$ids[] = ['SEPA', $creditorId];                                // BT-90
	}
	foreach ($ids as [$scheme, $id]) {
		$xml .= '      <cac:PartyIdentification>'."\n";
		$xml .= '        <cbc:ID'.($scheme !== '' ? ' schemeID="'.$e($scheme).'"' : '').'>'.$e($id).'</cbc:ID>'."\n";
		$xml .= '      </cac:PartyIdentification>'."\n";
	}
	$a = $p['address'];
	$xml .= '      <cac:PostalAddress>'."\n";
	if ($a['line1'] !== '') {
		$xml .= '        <cbc:StreetName>'.$e($a['line1']).'</cbc:StreetName>'."\n";      // BT-35/50
	}
	if ($a['city'] !== '') {
		$xml .= '        <cbc:CityName>'.$e($a['city']).'</cbc:CityName>'."\n";            // BT-37/52
	}
	if ($a['postcode'] !== '') {
		$xml .= '        <cbc:PostalZone>'.$e($a['postcode']).'</cbc:PostalZone>'."\n";    // BT-38/53
	}
	$xml .= '        <cac:Country>'."\n";
	$xml .= '          <cbc:IdentificationCode>'.$e($a['country']).'</cbc:IdentificationCode>'."\n"; // BT-40/55
	$xml .= '        </cac:Country>'."\n";
	$xml .= '      </cac:PostalAddress>'."\n";
	// BT-31/48 (schéma VAT) ou BT-32 (identifiant fiscal, schéma FC)
	foreach ([[$p['vat_id'], 'VAT'], [$p['vat_id'] === '' ? $p['tax_id'] : '', 'FC']] as [$taxId, $scheme]) {
		if ($taxId !== '') {
			$xml .= '      <cac:PartyTaxScheme>'."\n";
			$xml .= '        <cbc:CompanyID>'.$e($taxId).'</cbc:CompanyID>'."\n";
			$xml .= '        <cac:TaxScheme>'."\n";
			$xml .= '          <cbc:ID>'.$scheme.'</cbc:ID>'."\n";
			$xml .= '        </cac:TaxScheme>'."\n";
			$xml .= '      </cac:PartyTaxScheme>'."\n";
		}
	}
	$xml .= '      <cac:PartyLegalEntity>'."\n";
	$xml .= '        <cbc:RegistrationName>'.$e($p['name']).'</cbc:RegistrationName>'."\n";   // BT-27/44
	if ($p['legal_id'] !== null) {
		$scheme = $p['legal_id']['scheme'] !== '' ? ' schemeID="'.$e($p['legal_id']['scheme']).'"' : '';
		$xml .= '        <cbc:CompanyID'.$scheme.'>'.$e($p['legal_id']['id']).'</cbc:CompanyID>'."\n"; // BT-30/47
	}
	$xml .= '      </cac:PartyLegalEntity>'."\n";
	if ($p['contact'] !== null) {
		$c = $p['contact'];
		$xml .= '      <cac:Contact>'."\n";
		if ($c['name'] !== '') {
			$xml .= '        <cbc:Name>'.$e($c['name']).'</cbc:Name>'."\n";                   // BT-41
		}
		if ($c['phone'] !== '') {
			$xml .= '        <cbc:Telephone>'.$e($c['phone']).'</cbc:Telephone>'."\n";        // BT-42
		}
		if ($c['email'] !== '') {
			$xml .= '        <cbc:ElectronicMail>'.$e($c['email']).'</cbc:ElectronicMail>'."\n"; // BT-43
		}
		$xml .= '      </cac:Contact>'."\n";
	}
	$xml .= '    </cac:Party>'."\n";
	return $xml;
}
