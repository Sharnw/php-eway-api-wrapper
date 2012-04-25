<?php

require('Eway.php');

// load eway config
$config  = array('customerId' => 87654321, 'gatewayURL' => 'https://www.eway.com.au/gateway/xmltest/testpage.asp', 'antiFraud' => 1, 
				'failedAttemptLimit' => 0, 'failedAttemptLifetime' => 360);

// create Eway object
$eway = new Eway($config);

//load transaction data
$transactionData = array('TotalAmount' => 1000, 'CardHoldersName' => 'Test User', 'CardNumber' => '4444333322221111', 'CardExpiryMonth' => '01', 'CardExpiryYear' => '2016',
						'CustomerIPAddress' => $eway->getVisitorIP(), 'CustomerBillingCountry' => 'AU');
$eway->loadTransactionData($transactionData);

// turn off SSL peer validation (for testing)
$eway->setCurlOption(CURLOPT_SSL_VERIFYPEER, 0);

//process transaction
$transactionResult = $eway->processTransaction();

// print results
echo '<pre>';
print_r($transactionResult);
		
?>