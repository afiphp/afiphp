<?php

declare(strict_types=1);

namespace Afiphp\Internal\WebServices;

use Afiphp\Exceptions\ArcaUnavailable;
use Afiphp\Internal\WebServices\Contracts\TicketProvider;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;

/** @internal WSAA login with a ticket cache shared by every worker that uses the same storage. */
class WsAa extends AbstractWsAfip implements TicketProvider
{
    private const CIPHER = 'aes-256-gcm';
    /** Seconds another worker's WSAA login may hold the ticket lock. */
    private const TICKET_LOCK_TIMEOUT = 10;

    public function ticket(string $serviceName): Ticket
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $serviceName)) {
            throw new InvalidArgumentException('Invalid WSAA service name.');
        }
        // WSAA authenticates the certificate, not the represented CUIT in a WSFE request.
        // Fingerprint the DER certificate, not PEM formatting, to avoid unnecessary logins.
        $fingerprint = $this->configuration->credentials->fingerprint;
        $key = "wsaa-{$this->configuration->environment->value}-ta-{$serviceName}-{$fingerprint}";
        $storage = $this->configuration->storage;

        return $this->cachedTicket($key) ?? $storage->withTicketLock($key, self::TICKET_LOCK_TIMEOUT, function () use (
            $key,
            $serviceName,
            $storage,
        ): Ticket {
            // Another PHP worker may have refreshed the ticket while we waited.
            $cached = $this->cachedTicket($key);
            if ($cached !== null) {
                return $cached;
            }
            $xml = $this->login($this->sign($this->loginRequest($serviceName)));
            $ticket = self::parse($xml);
            if (!$ticket->isValid()) {
                throw new ArcaUnavailable('WSAA returned an expired ticket.', true);
            }
            $storage->writeTicket($key, $this->seal($xml, $key));
            $this->configuration->logger->info('WSAA login for {service}, valid until {expires}', [
                'service' => $serviceName,
                'expires' => $ticket->expiresAt?->format(DATE_ATOM) ?? 'unknown',
            ]);

            return $ticket;
        });
    }

    protected function service(): string
    {
        return self::WSAA;
    }

    protected function sign(string $loginRequest): string
    {
        return $this->configuration->credentials->sign($loginRequest);
    }

    private function login(string $signature): string
    {
        $response = $this->execute('loginCms', ['in0' => $signature]);
        if (!isset($response->loginCmsReturn) || !is_string($response->loginCmsReturn)) {
            throw new ArcaUnavailable('WSAA did not return a login ticket.', true);
        }

        return $response->loginCmsReturn;
    }

    private function cachedTicket(string $key): ?Ticket
    {
        $sealed = $this->configuration->storage->readTicket($key);
        $xml = $sealed === null ? null : $this->open($sealed, $key);
        if ($xml === null) {
            return null;
        }
        try {
            $ticket = self::parse($xml);

            return $ticket->isValid() ? $ticket : null;
        } catch (Exception) {
            // An interrupted write or an obsolete ticket is a cache miss, not a fatal XML error.
            return null;
        }
    }

    /** The storage key is authenticated data: a ticket cannot be moved to another entry. */
    private function seal(string $xml, string $key): string
    {
        $iv = random_bytes(12);
        $secret = $this->configuration->credentials->ticketKey();
        $data = openssl_encrypt($xml, self::CIPHER, $secret, OPENSSL_RAW_DATA, $iv, $tag, $key);
        if ($data === false) {
            throw new RuntimeException('Unable to encrypt the WSAA ticket.');
        }

        return json_encode([
            'v' => 1,
            'iv' => base64_encode($iv),
            'data' => base64_encode($data),
            'tag' => base64_encode($tag),
        ], JSON_THROW_ON_ERROR);
    }

    /** Null for anything unreadable: a plain, foreign or tampered entry is a cache miss. */
    private function open(string $sealed, string $key): ?string
    {
        $entry = json_decode($sealed, true);
        if (!is_array($entry) || ($entry['v'] ?? null) !== 1) {
            return null;
        }
        $parts = [];
        foreach (['iv', 'data', 'tag'] as $field) {
            $decoded = is_string($entry[$field] ?? null) ? base64_decode($entry[$field], true) : false;
            if ($decoded === false) {
                return null;
            }
            $parts[] = $decoded;
        }
        [$iv, $data, $tag] = $parts;
        $secret = $this->configuration->credentials->ticketKey();
        $xml = openssl_decrypt($data, self::CIPHER, $secret, OPENSSL_RAW_DATA, $iv, $tag, $key);

        return $xml === false ? null : $xml;
    }

    private static function parse(string $xml): Ticket
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new RuntimeException('DOCTYPE is not allowed in a WSAA ticket.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $response = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
            if ($response === false || $response->getName() !== 'loginTicketResponse') {
                throw new RuntimeException('Invalid WSAA ticket XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $expiration = (string) $response->header->expirationTime;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $expiration)) {
            throw new RuntimeException('Invalid WSAA ticket expiration time.');
        }
        $expiresAt = new DateTimeImmutable($expiration);
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new RuntimeException('Invalid WSAA ticket expiration date.');
        }
        $token = (string) $response->credentials->token;
        $sign = (string) $response->credentials->sign;
        if (trim($token) === '' || trim($sign) === '') {
            throw new RuntimeException('WSAA ticket credentials are empty.');
        }

        return new Ticket($token, $sign, $expiresAt);
    }

    private function loginRequest(string $serviceName): string
    {
        $request = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><loginTicketRequest version="1.0"/>');
        $header = $request->addChild('header');
        $header->addChild('uniqueId', (string) time());
        $header->addChild('generationTime', date('c', time() - 600));
        $header->addChild('expirationTime', date('c', time() + 600));
        $request->addChild('service', $serviceName);
        $xml = $request->asXML();
        if ($xml === false) {
            throw new RuntimeException('Unable to construct the authentication request.');
        }

        return $xml;
    }
}
