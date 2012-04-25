<?php

/**
 * 
 * eWay payment gate way API wrapper
 * 
 * A rewrite of the PHP sample code provided by eWay
 * 
 * https://github.com/Sharnw/php-eway-api-wrapper
 * 
 */
class Eway {
	
	private $customerId, $gatewayURL, $antiFraud = false, $failedAttemptLimit = 0, $failedAttemptLifetime = 300, $transactionData = array(), $curlOptions, $xmlRequest, $xmlResponse;
	private $mandatoryFields = array('TotalAmount', 'CardHoldersName', 'CardNumber', 'CardExpiryMonth', 'CardExpiryYear');
	private $optionalFields = array('CustomerFirstName', 'CustomerLastName', 'CustomerEmail', 'CustomerAddress', 'CustomerPostcode', 'CustomerInvoiceDescription', 'CustomerInvoiceRef', 
									'TrxnNumber', 'Option1', 'Option2', 'Option3', 'CVN');
		
	public function __construct($config) {
		if (isset($config['customerId']) and isset($config['gatewayURL'])) {
			$this->customerId = $config['customerId'];
			$this->gatewayURL = $config['gatewayURL'];
			if (isset($config['antiFraud'])) {
				$this->antiFraud = $config['antiFraud'];
				if ($config['antiFraud'] == 1) {
					// if antiFraid is enabled  CustomerIPAddress and CustomerBillingCountry are mandatory fields
					$this->mandatoryFields = array_merge($this->mandatoryFields, array('CustomerIPAddress', 'CustomerBillingCountry'));
				}
			}
			if (isset($config['failedAttemptLimit'])) {
				$this->failedAttemptLimit = $config['failedAttemptLimit'];
			}
			if (isset($config['failedAttemptLifetime'])) {
				$this->failedAttemptLifetime = $config['failedAttemptLifetime'];
			}
		}
		else {
			throw new Exception('Invalid eway configuration provided.', 110);
		}
	}
	
	/**
	 * Loads transaction data as an array.
	 * 
	 * @param array $transactionData Transaction data values.
	 */
	public function loadTransactionData($transactionData) {
		foreach ($transactionData as $key => $value) {
			$this->transactionData[$key]  = htmlentities(trim($value));
		}
	}
	
	/**
	 * Sets a single transaction data value.
	 */
	public function setTransactionData($key, $value) {
		$this->transactionData[$key]  = htmlentities(trim($value));
	}
	
	/**
	 * Sets a single curl option value to be used for transaction curl.
	 * 
	 * EXAMPLES: CURLOPT_SSL_VERIFYPEER, CURLOPT_CAINFOCURLOPT_CAPATH, CURLOPT_PROXYTYPE, CURLOPT_PROXY
	 * 
	 */
	public function setCurlOption($key, $value) {
		$this->curlOptions[$key] = $value;
	}
	
	/**
	 * Attempts to process a transaction.
	 * 
	 * @return array('success', 'responseCode', 'responseMessage') Transaction result values.
	 */
	public function processTransaction() {
		// if failed attempts limit set check if too many failed attempts
		if ($this->failedAttemptLimit > 0) {
			if (session_id() == '') {
				session_start();
			}

			// count attempts in last 5 minutes
			if (isset($_SESSION['failedAttempts'])) {
				$lifetime = strtotime('now -' . $this->failedAttemptLifetime . 'seconds');
				$failedAttempts = 0;
				foreach ($_SESSION['failedAttempts'] as $timestamp) {
					if ($timestamp >= $lifetime) {
						$failedAttempts++;
					}
				}
				
				if ($failedAttempts >= $this->failedAttemptLimit) {
					return array('success' => false, 'responseMessage' => 'Too many failed payment attempts. Please wait a while before attempting another payment.');
				}
			}
		}
		
		// validate transaction data before attempting to process transaction
		$validationResult = $this->validateTransactionData();
		if ($validationResult['success']) {
			// prepare xml request
			$this->xmlRequest = $this->prepareXmlRequest();

			// send xml request
			$this->xmlResponse = $this->sendXmlRequest();
			
			// parse xml response
			$requestResults = $this->parseXmlResponse();

			$returnValues = array();
			if (isset($requestResults['EWAYTRXNSTATUS'])) {
				
				if ($requestResults['EWAYTRXNSTATUS'] == 'TRUE') {
					$returnValues['success'] = true;
				}
				else {
					$returnValues['success'] = false;
					// if lockoutAttempts set add a failed attempt to session
					if ($this->failedAttemptLimit > 0) {
						if (isset($_SESSION['failedAttempts'])) {
							$_SESSION['failedAttempts'][] = strtotime('now');
						}
						else {
							$_SESSION['failedAttempts'] = array(strtotime('now'));
						}
					}
				}

				
				if (isset($requestResults['EWAYTRXNNUMBER'])) {
					$returnValues['transactionNumber'] = $requestResults['EWAYTRXNNUMBER'];
				}
				
				// retrieve response code and message from EWAYTRXNERROR
				if (isset($requestResults['EWAYTRXNERROR'])) {
					$errorParts = explode(',', $requestResults['EWAYTRXNERROR']);
					if (isset($errorParts[0])) {
						$returnValues['responseCode'] = $errorParts[0];
					}
					if (isset($errorParts[1])) {
						$returnValues['responseMessage'] = $errorParts[1];
					}
				}
			}
			else {
				$returnValues = array('success' => false, 'responseMessage' => 'No status in response.');
			}
			
			return $returnValues;
		}
		else {
			return $validationResult;
		}
	}
	
	/**
	 * Validates $this->transactionData.
	 * 
	 * @return array('success', 'messages')
	 */
	private function validateTransactionData() {
		// compare transaction data keys to mandatory fields list
		$transactionKeys = array_keys($this->transactionData);
		$valid = true;
		$messages = array();
		foreach ($this->mandatoryFields as $field) {
			if (!in_array($field, $transactionKeys)) {
				// if mandatory field not in transaction data return an error
				$valid = false;
				$messages[] = 'Missing mandatory field ' . $field;
			}
		}

		return array('success' => $valid, 'messages' => $messages);
	}
	
	/**
	 * Prepares an xml request using $this->customerId and $this->transactionData.
	 * 
	 * @return string Xml request string
	 */
	private function prepareXmlRequest() {
		$xmlRequest = "<ewaygateway><ewayCustomerID>" . $this->customerId . "</ewayCustomerID>";
		foreach($this->transactionData as $key=>$value) {
			$xmlRequest .= "<eway$key>$value</eway$key>";
		}
		
		// add unused optional fields
		foreach ($this->optionalFields as $field) {
			if (!array_key_exists($field, $this->transactionData)) {
				$xmlRequest .= "<eway$field></eway$field>";
			}
		}

		$xmlRequest .= "</ewaygateway>";
		return $xmlRequest;
	}
	
	/**
	 * Sends xml request to eway using curl.
	 * 
	 * @return string|false Xml response string
	 */
	private function sendXmlRequest() {
		$ch = curl_init($this->gatewayURL);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $this->xmlRequest);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		if (!empty($this->curlOptions)) {
			foreach ($this->curlOptions as $key => $value) {
				curl_setopt($ch, $key, $value);
			}
		}

		$xmlResponse = curl_exec($ch);
		if(curl_errno( $ch ) == CURLE_OK) {
			return $xmlResponse;
		}
		
		return false;
	}
	
	/**
	 * Parses xml response string from eWay.
	 * 
	 * @return array $responseFields Response values.
	 */
	private function parseXmlResponse() {
		$xml_parser = xml_parser_create();
		xml_parse_into_struct($xml_parser,  $this->xmlResponse, $xmlData, $index);
   	 	$responseFields = array();
    	foreach($xmlData as $data) {
    		if(isset($data['value']) and $data['level'] == 2) {
    			$responseFields[$data['tag']] = $data['value'];
    		}
		}
		
		return $responseFields;
	}
	
	/**
	 * Returns the clients IP address.
	 * 
	 * @return string $ip Client IP address
	 */
	public function getVisitorIP(){
		$ip = $_SERVER["REMOTE_ADDR"];
		if (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
			if(ereg("^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$",$_SERVER["HTTP_X_FORWARDED_FOR"])) {
				$ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
			}
		}

		return $ip;
	}
		
}		
		
?>
	