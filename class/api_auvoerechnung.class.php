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
 * AuVo E-Rechnung REST API, exposed through Dolibarr's standard API
 * (REST API module enabled and a user API key configured).
 *
 * Endpoints:
 *  GET /auvoerechnung/invoice/{id}/xml     Factur-X XML regenerated from the invoice
 *  GET /auvoerechnung/invoice/{id}/status  Presence/validity of XML embedded in the PDF
 */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

class AuvoerechnungApi extends DolibarrApi
{
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * Generates and returns the EN16931 Factur-X XML for a customer invoice.
	 *
	 * The XML is rebuilt on demand from the invoice data using the same engine
	 * as PDF embedding, with compliance warnings and any business-rule violations.
	 *
 * @param int $id Invoice ID
	 * @return array
	 *
	 * @url GET invoice/{id}/xml
	 */
	public function getXml($id)
	{
		global $mysoc;

		$invoice = $this->loadInvoice($id);

		$modulePath = dirname(__DIR__);
		require_once $modulePath.'/core/lib/auvoerechnung.lib.php';
		require_once $modulePath.'/core/lib/auvoerechnung_rules.php';

		$unsupported = auvoerechnung_check_supported($invoice);
		if ($unsupported !== null) {
			throw new RestException(422, 'Unsupported invoice: '.$unsupported);
		}

		$warnings = auvoerechnung_check_mandatory($invoice, $mysoc);
		$buildWarnings = [];
		$xml = auvoerechnung_build_xml($invoice, $mysoc, $buildWarnings);
		$violations = auvoerechnung_validate_business_rules($xml);

		return [
			'ref'        => $invoice->ref,
			'xml'        => $xml,
			'warnings'   => array_merge($warnings, $buildWarnings),
			'violations' => $violations,
		];
	}

	/**
	 * Generates and returns the invoice's XRechnung (KoSIT CIUS, CII syntax).
	 *
	 * Read-only: the XML is rebuilt on demand and no file is written (the
	 * {ref}-XRECHNUNG.xml file is created during PDF generation or from the
	 * invoice card menu). 'problems' lists missing data (preflight checks);
	 * 'violations' lists EN16931/BR-DE rules that are not met. 'xml' is null
	 * while required data is missing. BG-24 attachments (base64, potentially
	 * large) are included only when requested (?with_attachments=1).
	 *
 * @param int $id Invoice ID
	 * @param int $with_attachments 1 to include BG-24 attachments in the XML {@from query}
	 * @return array
	 *
	 * @url GET invoice/{id}/xrechnung
	 */
	public function getXRechnung($id, $with_attachments = 0)
	{
		global $mysoc, $conf;

		$invoice = $this->loadInvoice($id);

		$modulePath = dirname(__DIR__);
		require_once $modulePath.'/core/lib/auvoerechnung.lib.php';
		require_once $modulePath.'/core/lib/auvoerechnung_rules.php';

		$unsupported = auvoerechnung_check_supported($invoice);
		if ($unsupported !== null) {
			throw new RestException(422, 'Unsupported invoice: '.$unsupported);
		}

		$problems = auvoerechnung_check_xrechnung($invoice, $mysoc);
		$xml = null;
		$syntax = auvoerechnung_xrechnung_syntax($invoice->thirdparty);
		$violations = [];
		$buildWarnings = [];
		if (empty($problems)) {
			$xr = auvoerechnung_build_xrechnung($invoice, $mysoc, $buildWarnings, ['attachments' => $with_attachments ? auvoerechnung_collect_attachments($invoice, $buildWarnings) : []], $syntax);
			$xml = $xr['xml'];
			$xsdError = auvoerechnung_validate_xsd($xml, $modulePath);
			$violations = array_merge($xsdError !== null ? [$xsdError] : [], auvoerechnung_validate_business_rules($xr['cii']));
		}
		$file = auvoerechnung_xrechnung_path($invoice->ref, (int) ($invoice->entity ?? $conf->entity));

		return [
			'ref'              => $invoice->ref,
			'customer_expects' => auvoerechnung_wants_xrechnung($invoice->thirdparty, $invoice),
			'file_exists'      => ($file !== '' && file_exists($file)),
			'problems'         => $problems,
			'syntax'           => $syntax,
			'xml'              => $xml,
			'warnings'         => $buildWarnings,
			'violations'       => $violations,
		];
	}

	/**
	 * Factur-X status for the invoice PDF: is embedded XML present and valid?
	 *
 * @param int $id Invoice ID
	 * @return array
	 *
	 * @url GET invoice/{id}/status
	 */
	public function getStatus($id)
	{
		global $conf;

		$invoice = $this->loadInvoice($id);

		$modulePath = dirname(__DIR__);
		require_once $modulePath.'/core/lib/auvoerechnung.lib.php';
		require_once $modulePath.'/core/lib/auvoerechnung_rules.php';

		$pdfPath = auvoerechnung_invoice_pdf_path($invoice->ref, $invoice->entity ?? $conf->entity, (string) ($invoice->last_main_doc ?? ''));

		$result = [
			'ref'            => $invoice->ref,
			'pdf_exists'     => false,
			'facturx_found'  => false,
			'br_violations'  => [],
		];
		if ($pdfPath === null) {
			return $result;
		}
		$result['pdf_exists'] = true;

		$xml = auvoerechnung_extract_xml_from_pdf($pdfPath);
		if ($xml === null) {
			return $result;
		}
		$result['facturx_found'] = true;
		$result['br_violations'] = auvoerechnung_validate_business_rules($xml);

		return $result;
	}

	/**
	 * Loads the invoice and checks the API user's read permissions.
	 *
	 * @param int $id
	 * @return object Loaded invoice (generic type avoids the core Facture Swagger model,
	 *                which breaks generation of /explorer/swagger.json)
	 * @throws RestException
	 */
	protected function loadInvoice($id)
	{
		require_once dirname(__DIR__).'/core/lib/auvoerechnung.lib.php';
		if (!auvoerechnung_user_can(DolibarrApiAccess::$user, 'read')) {
			throw new RestException(403, 'Insufficient rights to read invoices or e-invoices');
		}

		$invoice = new Facture($this->db);
		if ($invoice->fetch((int) $id) <= 0) {
			throw new RestException(404, 'Invoice not found');
		}
		if (!DolibarrApi::_checkAccessToResource('facture', $invoice->id)) {
			throw new RestException(403, 'Access to this invoice is not allowed for this API user');
		}
		$invoice->fetch_thirdparty();
		if (empty($invoice->lines)) {
			$invoice->fetch_lines();
		}
		return $invoice;
	}
}
