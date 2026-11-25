<?php
/*
 * Stub for Dolibarr's Account class for standalone unit tests.
 * Returns the account set in $GLOBALS['lfx_test_bank'] (or fails if absent).
 */
if (!class_exists('Account')) {
	class Account
	{
		public $iban = '';
		public $bic = '';

		public function __construct($db)
		{
		}

		public function fetch($id)
		{
			$bank = $GLOBALS['lfx_test_bank'] ?? null;
			if (!$bank) {
				return 0;
			}
			$this->iban = $bank['iban'] ?? '';
			$this->bic = $bank['bic'] ?? '';
			return 1;
		}
	}
}
