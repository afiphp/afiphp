<?php

namespace Afiphp;

class Afip
{
	/**
	 * File name for the WSDL corresponding to WSAA
	 **/
	protected string $WSAA_WSDL;

	/**
	 * The url to get WSAA token
	 **/
	protected string $WSAA_URL;

	/**
	 * File name for the X.509 certificate in PEM format
	 **/
	protected string $CERT;

	/**
	 * File name for the private key correspoding to CERT (PEM)
	 **/
	protected string $PRIVATEKEY;

	/**
	 * The passphrase (if any) to sign
	 **/
	protected string $PASSPHRASE;

	/**
	 * Afip resources folder
	 **/
	protected string $RES_FOLDER;

	/**
	 * Afip ta folder
	 **/
	protected string $TA_FOLDER;

	/**
	 * The CUIT to use
	 **/
	protected int $CUIT;

	/**
	 * Implemented Web Services
	 **/
	protected array $implemented_ws = [
		'ElectronicBilling',
		'RegisterScopeFour',
		'RegisterScopeFive',
		'RegisterInscriptionProof',
		'RegisterScopeTen',
		'RegisterScopeThirteen'
	];

	public function __construct(array $options)
	{
		ini_set("soap.wsdl_cache_enabled", "0");

		if (!isset($options['CUIT'])) {
			throw new Exception("CUIT field is required in options array");
		} else {
			$this->CUIT = $options['CUIT'];
		}

		if (!isset($options['production'])) {
			$options['production'] = FALSE;
		}

		if (!isset($options['passphrase'])) {
			$options['passphrase'] = 'xxxxx';
		}

		if (!isset($options['cert'])) {
			$options['cert'] = 'cert';
		}

		if (!isset($options['key'])) {
			$options['key'] = 'key';
		}

		if (!isset($options['res_folder'])) {
			$this->RES_FOLDER = __DIR__ . '/Afip_res/';
		} else {
			$this->RES_FOLDER = $options['res_folder'];
		}

		if (!isset($options['ta_folder'])) {
			$this->TA_FOLDER = __DIR__ . '/Afip_res/';
		} else {
			$this->TA_FOLDER = $options['ta_folder'];
		}

		$this->PASSPHRASE = $options['passphrase'];

		$this->options = $options;

		$this->CERT 		= $this->RES_FOLDER . $options['cert'];
		$this->PRIVATEKEY 	= $this->RES_FOLDER . $options['key'];

		$this->WSAA_WSDL 	= __DIR__ . '/Afip_res/' . 'wsaa.wsdl';
		if ($options['production'] === TRUE) {
			$this->WSAA_URL = 'https://wsaa.afip.gov.ar/ws/services/LoginCms';
		} else {
			$this->WSAA_URL = 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms';
		}

		if (!file_exists($this->CERT)) {
			throw new \Exception("Failed to open " . $this->CERT . "\n", 1);
		}

		if (!file_exists($this->PRIVATEKEY)) {
			throw new \Exception("Failed to open " . $this->PRIVATEKEY . "\n", 2);
		}

		if (!file_exists($this->WSAA_WSDL)) {
			throw new \Exception("Failed to open " . $this->WSAA_WSDL . "\n", 3);
		}
	}

	/**
	 * Gets token authorization for an AFIP Web Service
	 *
	 * @since 0.1
	 *
	 * @param string $service Service for token authorization
	 *
	 * @throws Exception if an error occurs
	 *
	 * @return TokenAutorization Token Autorization for AFIP Web Service 
	 **/
	public function GetServiceTA($service, $continue = TRUE)
	{
		if (file_exists($this->TA_FOLDER . 'TA-' . $this->options['CUIT'] . '-' . $service . ($this->options['production'] === TRUE ? '-production' : '') . '.xml')) {
			$TA = new \SimpleXMLElement(file_get_contents($this->TA_FOLDER . 'TA-' . $this->options['CUIT'] . '-' . $service . ($this->options['production'] === TRUE ? '-production' : '') . '.xml'));

			$actual_time 		= new \DateTime(date('c', date('U') + 600));
			$expiration_time 	= new \DateTime($TA->header->expirationTime);

			if ($actual_time < $expiration_time) {
				return new TokenAutorization($TA->credentials->token, $TA->credentials->sign);
			} else if ($continue === FALSE) {
				throw new \Exception("Error Getting TA", 5);
			}
		}

		if ($this->CreateServiceTA($service))
			return $this->GetServiceTA($service, FALSE);
	}

	/**
	 * Create an TA from WSAA
	 *
	 * Request to WSAA for a tokent authorization for service and save this
	 * in a xml file
	 *
	 * @since 0.1
	 *
	 * @param string $service Service for token authorization
	 *
	 * @throws Exception if an error occurs creating token authorization
	 *
	 * @return true if token authorization is created success
	 **/
	private function CreateServiceTA($service)
	{
		//Creating TRA
		$TRA = new \SimpleXMLElement(
			'<?xml version="1.0" encoding="UTF-8"?>' .
				'<loginTicketRequest version="1.0">' .
				'</loginTicketRequest>'
		);
		$TRA->addChild('header');
		$TRA->header->addChild('uniqueId', date('U'));
		$TRA->header->addChild('generationTime', date('c', date('U') - 600));
		$TRA->header->addChild('expirationTime', date('c', date('U') + 600));
		$TRA->addChild('service', $service);
		$TRA->asXML($this->TA_FOLDER . 'TRA-' . $this->options['CUIT'] . '-' . $service . '.xml');

		//Signing TRA
		$STATUS = openssl_pkcs7_sign(
			$this->TA_FOLDER . "TRA-" . $this->options['CUIT'] . '-' . $service . ".xml",
			$this->TA_FOLDER . "TRA-" . $this->options['CUIT'] . '-' . $service . ".tmp",
			"file://" . $this->CERT,
			array("file://" . $this->PRIVATEKEY, $this->PASSPHRASE),
			array(),
			!PKCS7_DETACHED
		);
		if (!$STATUS) {
			return FALSE;
		}
		$inf = fopen($this->TA_FOLDER . "TRA-" . $this->options['CUIT'] . '-' . $service . ".tmp", "r");
		$i = 0;
		$CMS = "";
		while (!feof($inf)) {
			$buffer = fgets($inf);
			if ($i++ >= 4) {
				$CMS .= $buffer;
			}
		}
		fclose($inf);
		unlink($this->TA_FOLDER . "TRA-" . $this->options['CUIT'] . '-' . $service . ".xml");
		unlink($this->TA_FOLDER . "TRA-" . $this->options['CUIT'] . '-' . $service . ".tmp");

		//Request TA to WSAA
		$client = new \SoapClient($this->WSAA_WSDL, array(
			'soap_version'   => SOAP_1_2,
			'location'       => $this->WSAA_URL,
			'trace'          => 1,
			'exceptions'     => 0,
			'stream_context' => stream_context_create(['ssl' => ['ciphers' => 'AES256-SHA', 'verify_peer' => false, 'verify_peer_name' => false]])
		));
		$results = $client->loginCms(array('in0' => $CMS));
		
		if (is_soap_fault($results)) {
			throw new \Exception("SOAP Fault: " . $results->faultcode . "\n" . $results->faultstring . "\n", 4);
		}

		$TA = $results->loginCmsReturn;

		if (file_put_contents($this->TA_FOLDER . 'TA-' . $this->options['CUIT'] . '-' . $service . ($this->options['production'] === TRUE ? '-production' : '') . '.xml', $TA)) {
			return TRUE;
		}

		throw new \Exception('Error writing "TA-' . $this->options['CUIT'] . '-' . $service . '.xml"', 5);
	}
}
