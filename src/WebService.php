<?php

namespace Afiphp;

class WebService
{
	/**
	 * Web service SOAP version
	 *
	 * @var intenger
	 **/
	protected int $soap_version;

	/**
	 * File name for the Web Services Description Language
	 *
	 * @var string
	 **/
	protected string $WSDL;
	
	/**
	 * The url to web service
	 *
	 * @var string
	 **/
	protected string $URL;

	/**
	 * File name for the Web Services Description 
	 * Language in test mode
	 **/
	protected string $WSDL_TEST;

	/**
	 * The url to web service in test mode
	 **/
	protected string $URL_TEST;
	
	/**
	 * The Afip parent Class
	 **/
	protected Afip $afip;
	
	public function __construct(Afip $afip)
	{
		$this->afip = $afip;

		if ($this->afip->options['production'] === TRUE) {
			$this->WSDL = __DIR__.'/assets/'.$this->WSDL;
		} else {
			$this->WSDL = __DIR__.'/assets/'.$this->WSDL_TEST;
			$this->URL 	= $this->URL_TEST;
		}

		if (!file_exists($this->WSDL)) {
			throw new \Exception("Failed to open {$this->WSDL}", 3);
        }
	}

	/**
	 * Sends request to AFIP servers
	 * 
	 * @since 1.0
	 *
	 * @param string 	$operation 	SOAP operation to do 
	 * @param array 	$params 	Parameters to send
	 *
	 * @return mixed Operation results 
	 **/
	public function execute(string $operation, array $params = [])
	{
		if (!isset($this->soap_client)) {
			$this->soap_client = new \SoapClient($this->WSDL, array(
				'soap_version' 	=> $this->soap_version,
				'location' 		=> $this->URL,
				'trace' 		=> 1,
				'exceptions' 	=> 0,
				'stream_context' => stream_context_create(['ssl'=> ['ciphers'=> 'AES256-SHA','verify_peer'=> false,'verify_peer_name'=> false]])
			)); 
		}

		$results = $this->soap_client->{$operation}($params);

        if (is_soap_fault($results)) {
			throw new \Exception("SOAP Fault: {$results->faultcode}\n{$results->faultstring}", 4);
        }

		return $results;
	}
}
