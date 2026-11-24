<?php

declare(strict_types=1);

namespace Afiphp\Internal\WebServices;

use Afiphp\Exceptions\ArcaUnavailable;
use Afiphp\Exceptions\ConfigurationError;
use Afiphp\Exceptions\SoapException;
use Afiphp\Exceptions\WsException;
use RuntimeException;
use SoapFault;
use Throwable;

/** @internal Classifies a failed call: ARCA unavailable, the issuer's configuration, or neither. */
final class Failures
{
    /** WSFE's internal errors. */
    private const UNAVAILABLE_CODES = [500, 501, 502];

    /** WSFE: invalid token or sign (600), CUIT not authorized for the service (601). */
    private const CONFIGURATION_CODES = [600, 601];

    /**
     * WSAA faults about the certificate, its authorization, the clock or a ticket already issued
     * to another storage.
     */
    private const CONFIGURATION_FAULTS = [
        'cms.',
        'coe.notAuthorized',
        'coe.alreadyAuthenticated',
        'xml.generationTime',
        'xml.expirationTime',
    ];

    /**
     * @param string $warnings What ext-soap reported besides the fault: it tells a TLS failure apart
     *     from a refused connection, which share the fault "Could not connect to host".
     */
    public static function fromFault(SoapFault $fault, string $warnings = ''): RuntimeException
    {
        $code = (string) $fault->faultcode;
        $text = (string) $fault->faultstring;
        $message = "SOAP Error: ({$code}) {$text}";
        if ($code === 'HTTP') {
            if (preg_match('/\b(SSL|TLS|certificate|crypto)\b/i', $warnings)) {
                return new ConfigurationError("{$message}: TLS failure. {$warnings}", 0, $fault);
            }
            if (preg_match('/\b(Forbidden|Unauthorized|Not Found)\b/i', $text)) {
                return new ConfigurationError($message, 0, $fault);
            }
            // The connection was never established: nothing reached ARCA.
            $sent = !str_contains($text, 'Could not connect to host');

            return new ArcaUnavailable($message, $sent, $fault);
        }
        $local = substr($code, (int) strrpos($code, ':') + (str_contains($code, ':') ? 1 : 0));
        foreach (self::CONFIGURATION_FAULTS as $prefix) {
            if (str_starts_with($local, $prefix)) {
                return new ConfigurationError($message, 0, $fault);
            }
        }
        if (in_array($local, ['Server', 'Receiver'], true)) {
            return new ArcaUnavailable($message, true, $fault);
        }

        return new SoapException($fault);
    }

    /** An ARCA error list from WSFE, already wrapped as a WsException. */
    public static function fromWsfeError(WsException $error): \Exception
    {
        return match (true) {
            in_array($error->getCode(), self::UNAVAILABLE_CODES, true) => new ArcaUnavailable(
                $error->getMessage(),
                true,
                $error,
            ),
            in_array($error->getCode(), self::CONFIGURATION_CODES, true) => new ConfigurationError(
                $error->getMessage(),
                0,
                $error,
            ),
            default => $error,
        };
    }

    /** WSFE's "no results": error 602 alone, with no data in the response. */
    public static function isNotFound(Throwable $exception): bool
    {
        if (!$exception instanceof WsException || $exception->getErrors() === []) {
            return false;
        }
        $response = $exception->getResponse();
        if (
            $response === null
            || isset($response->ResultGet)
            || isset($response->FeDetResp)
            || isset($response->CodAutorizacion)
            || isset($response->CAE)
            || ($response->Resultado ?? null) === 'A'
        ) {
            return false;
        }
        foreach ($exception->getErrors() as $error) {
            if ((string) WsResponse::field($error, 'Code') !== '602') {
                return false;
            }
        }

        return true;
    }
}
