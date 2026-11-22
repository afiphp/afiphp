<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Exceptions\SoapException;
use Afiphp\Internal\WebServices\NativeSoapTransport;
use Afiphp\NetworkOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use SoapClient;

final class NativeSoapTlsTest extends TestCase
{
    #[DataProvider('modes')]
    public function testTlsVerificationAgainstALoopbackOnlySelfSignedServer(string $mode): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('The loopback-only TLS test requires pcntl.');
        }
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $csr = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_pkey_export($key, $privatePem);
        openssl_x509_export($certificate, $certificatePem);
        $pem = $this->directory . '/local-server.pem';
        file_put_contents($pem, $certificatePem . $privatePem);
        $context = stream_context_create(['ssl' => ['local_cert' => $pem, 'verify_peer' => false]]);
        $server = stream_socket_server(
            'tcp://127.0.0.1:0',
            $error,
            $message,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );
        self::assertNotFalse($server);
        $url = 'https://' . stream_socket_get_name($server, false) . '/';
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            $socket = @stream_socket_accept($server, 5);
            if ($socket !== false) {
                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
                    $length = 0;
                    while (($line = fgets($socket)) !== false && trim($line) !== '') {
                        if (stripos($line, 'Content-Length:') === 0) {
                            $length = (int) substr($line, strlen('Content-Length:'));
                        }
                    }
                    while ($length > 0 && ($body = fread($socket, $length)) !== false && $body !== '') {
                        $length -= strlen($body);
                    }
                    $reply =
                        '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"><s:Body>'
                        . '<FEDummyResponse xmlns="http://ar.gov.afip.dif.FEV1/"><FEDummyResult>'
                        . '<AppServer>OK</AppServer><DbServer>OK</DbServer><AuthServer>OK</AuthServer>'
                        . '</FEDummyResult></FEDummyResponse></s:Body></s:Envelope>';
                    @fwrite(
                        $socket,
                        "HTTP/1.1 200 OK\r\nContent-Type: application/soap+xml\r\nContent-Length: "
                        . strlen($reply)
                        . "\r\nConnection: close\r\n\r\n"
                        . $reply,
                    );
                }
                fclose($socket);
            }
            fclose($server);
            exit(0);
        }
        try {
            $wsdl = dirname(__DIR__) . '/src/Internal/WebServices/Wsdl/wsfe-homologation.xml';
            // This deliberately insecure injected client is a test control: prove the server
            // really can answer SOAP, so a rejected connection is not a broken fixture.
            $control = $mode === 'insecure-test-control'
                ? new SoapClient($wsdl, [
                    'soap_version' => SOAP_1_2,
                    'location' => $url,
                    'exceptions' => true,
                    'stream_context' => stream_context_create(['ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ]]),
                ]) : null;
            $transport = new NativeSoapTransport(
                $wsdl,
                $url,
                false,
                new NetworkOptions(connectionTimeout: 3, readTimeout: 3, allowLegacyTls: $mode === 'legacy'),
                $control,
            );
            if ($control !== null) {
                self::assertSame('OK', $transport->call('FEDummy', [])->FEDummyResult->AppServer);
            } else {
                try {
                    $transport->call('FEDummy', []);
                    self::fail('An untrusted TLS certificate was accepted.');
                } catch (SoapException $exception) {
                    self::assertNotNull($exception->getPrevious());
                }
            }
        } finally {
            pcntl_waitpid($pid, $status);
            fclose($server);
        }
    }

    public static function modes(): array
    {
        return [
            ['default'],
            ['legacy'],
            ['insecure-test-control'],
        ];
    }
}
