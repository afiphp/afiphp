<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Exceptions\ArcaUnavailable;
use Afiphp\Internal\WebServices\NativeSoapTransport;
use Afiphp\NetworkOptions;

final class NativeSoapTimeoutTest extends TestCase
{
    public function testNativeSocketReadTimeoutIsAppliedAndProcessSettingIsRestored(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('The loopback-only timeout test requires pcntl.');
        }
        $server = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        self::assertNotFalse($server);
        $address = stream_socket_get_name($server, false);
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            $socket = @stream_socket_accept($server, 5);
            if ($socket !== false) {
                // Never contact ARCA: accept one local request and intentionally delay its reply.
                while (($line = fgets($socket)) !== false && trim($line) !== '') {
                }
                usleep(2500000);
                fclose($socket);
            }
            fclose($server);
            exit(0);
        }

        $previous = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '8');
        try {
            $transport = new NativeSoapTransport(
                dirname(__DIR__) . '/src/Internal/WebServices/Wsdl/wsfe-homologation.xml',
                "http://{$address}/",
                false,
                new NetworkOptions(connectionTimeout: 5, readTimeout: 1),
            );
            $started = microtime(true);
            try {
                $transport->call('FEDummy', []);
                self::fail('Expected the local read timeout.');
            } catch (ArcaUnavailable $exception) {
                self::assertLessThan(2.0, microtime(true) - $started);
                self::assertTrue($exception->sent);
            }
            self::assertSame('8', ini_get('default_socket_timeout'));
        } finally {
            ini_set('default_socket_timeout', $previous);
            pcntl_waitpid($pid, $status);
            fclose($server);
        }
    }
}
