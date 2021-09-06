<?php

namespace Afiphp;

class TokenAutorization {
	/**
	 * Authorization and authentication web service Token
	 **/
	protected string $token;

	/**
	 * Authorization and authentication web service Sign
	 **/
	protected string $sign;

	function __construct(string $token, string $sign)
	{
		$this->token 	= $token;
		$this->sign 	= $sign;
	}
}
