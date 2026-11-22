<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Arca;
use Afiphp\Billing\Invoice;
use Afiphp\Billing\Item;
use Afiphp\Billing\Outcome;
use Afiphp\Billing\Receiver;
use Afiphp\Billing\VatCondition;
use Afiphp\Billing\VatRate;
use Afiphp\Configuration;
use Afiphp\Exceptions\IssuancePersistenceException;
use Afiphp\Internal\Billing\FiscalGateway;
use Afiphp\Storage\IssuanceJournal;
use Afiphp\Storage\Storage;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeFiscalGateway;

final class IssuanceConcurrencyTest extends TestCase
{
    private function intent(): Invoice
    {
        return new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
            [Item::netAmount(15000, VatRate::STANDARD_21)],
            new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires')),
        );
    }

    private function config(?Storage $store = null): Configuration
    {
        return new Configuration(
            '20111111112',
            VatCondition::RESPONSABLE_INSCRIPTO,
            $this->identity(),
            $store ?? $this->storage(),
        );
    }

    #[DataProvider('operationStrategies')]
    public function testWorkersShareOneReservationBoundaryAndNeverDuplicateAnOperationOrNumber(
        bool $sameOperation,
    ): void {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('Requires pcntl.');
        }
        $state = $this->directory . '/simulated-arca-number';
        file_put_contents($state, '49');
        $configuration = $this->config();
        $children = [];
        for ($i = 0; $i < 4; ++$i) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Unable to fork test worker.');
            }
            if ($pid === 0) {
                try {
                    $gateway = new class($state) implements FiscalGateway {
                        public function __construct(
                            private string $state,
                        ) {}

                        public function lastNumber(int $type, int $salesPoint): int
                        {
                            usleep(10000); // Widen the overlap between workers.
                            return (int) file_get_contents($this->state);
                        }

                        public function authorize(array $request): object
                        {
                            $number = $request['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0]['CbteDesde'];
                            if ($number !== ((int) file_get_contents($this->state) + 1)) {
                                throw new \RuntimeException('Duplicate or skipped number.');
                            }
                            file_put_contents($this->state, (string) $number);
                            return FakeFiscalGateway::approved($request);
                        }

                        public function lookup(int $type, int $salesPoint, int $number): object
                        {
                            throw new \LogicException('Not used in this test.');
                        }

                        public function dollarRate(?\DateTimeImmutable $date): string
                        {
                            throw new \LogicException('Not used in this test.');
                        }
                    };
                    $result = (new Arca($configuration, $gateway))->issue(
                        $this->intent(),
                        'parallel:' . ($sameOperation ? 'same' : $i),
                    );
                    exit($result->outcome === Outcome::AUTHORIZED ? 0 : 1);
                } catch (\Throwable) {
                    exit(2);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        self::assertSame($sameOperation ? '50' : '53', file_get_contents($state));
        $saved = json_decode(
            file_get_contents(glob($this->directory . '/storage/issuance/*.json')[0]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $numbers = array_column($saved['records'], 'number');
        sort($numbers);
        self::assertSame($sameOperation ? [50] : [50, 51, 52, 53], $numbers);
        self::assertSame(['authorized'], array_values(array_unique(array_column($saved['records'], 'status'))));
    }

    public static function operationStrategies(): array
    {
        return [[false], [true]];
    }

    public function testProcessDeathAfterSendRetainsClaimAndAnotherWorkerRecoversWithoutWriting(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('Requires pcntl.');
        }
        $configuration = $this->config();
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::fail('Unable to fork test worker.');
        }
        if ($pid === 0) {
            $gateway = new FakeFiscalGateway();
            $gateway->onAuthorize = static function (): object {
                exit(0); // ARCA may have accepted; the worker never got an answer.
            };
            (new Arca($configuration, $gateway))->issue($this->intent(), 'crashed-sale');
            exit(1);
        }
        pcntl_waitpid($pid, $status);
        self::assertSame(0, pcntl_wexitstatus($status));
        $gateway = new FakeFiscalGateway();
        $client = new Arca($configuration, $gateway);
        self::assertSame(Outcome::INDETERMINATE, $client->issue($this->intent(), 'crashed-sale')->outcome);
        self::assertSame(Outcome::BLOCKED, $client->issue($this->intent(), 'next-sale')->outcome);
        self::assertSame([], $gateway->calls);
        $saved = json_decode(
            file_get_contents(glob($this->directory . '/storage/issuance/*.json')[0]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $request = $saved['records'][hash('sha256', 'crashed-sale')]['request'];
        $gateway->onLookup = static fn (): object => FakeFiscalGateway::found($request);
        self::assertSame(Outcome::AUTHORIZED, $client->recover('crashed-sale')->outcome);
        self::assertSame(['lookup'], array_column($gateway->calls, 0));
    }

    public function testFinalStorageFailureCannotReleaseAnAlreadySubmittedNumber(): void
    {
        $store = new class($this->storage()) implements Storage {
            public function __construct(
                private Storage $store,
            ) {}

            public function readTicket(string $key): ?string
            {
                return $this->store->readTicket($key);
            }

            public function writeTicket(string $key, string $ticket): void
            {
                $this->store->writeTicket($key, $ticket);
            }

            public function withTicketLock(string $key, int $timeout, callable $callback): mixed
            {
                return $this->store->withTicketLock($key, $timeout, $callback);
            }

            public function writeDiagnostic(string $key, string $xml): void
            {
                $this->store->writeDiagnostic($key, $xml);
            }

            public function exclusively(string $scope, callable $callback): mixed
            {
                return $this->store->exclusively($scope, static function (IssuanceJournal $inner) use (
                    $callback,
                ): mixed {
                    return $callback(new class($inner) implements IssuanceJournal {
                        private int $writes = 0;

                        public function __construct(
                            private IssuanceJournal $inner,
                        ) {}

                        public function all(): array
                        {
                            return $this->inner->all();
                        }

                        public function save(string $key, array $record): void
                        {
                            if (++$this->writes === 3) {
                                throw new \RuntimeException('Disk failed after authorization.');
                            }
                            $this->inner->save($key, $record);
                        }
                    });
                });
            }
        };
        $gateway = new FakeFiscalGateway();
        try {
            (new Arca($this->config($store), $gateway))->issue($this->intent(), 'sale');
            self::fail('Expected persistence failure.');
        } catch (IssuancePersistenceException $exception) {
            self::assertSame('Disk failed after authorization.', $exception->getPrevious()->getMessage());
            self::assertSame(Outcome::AUTHORIZED, $exception->result->outcome);
        }
        $request = $gateway->calls[1][1];
        $restarted = new FakeFiscalGateway();
        $client = new Arca($this->config(), $restarted);
        self::assertSame(Outcome::INDETERMINATE, $client->issue($this->intent(), 'sale')->outcome);
        self::assertSame(Outcome::BLOCKED, $client->issue($this->intent(), 'another-sale')->outcome);
        self::assertSame([], $restarted->calls);
        $restarted->onLookup = static fn (): object => FakeFiscalGateway::found($request);
        self::assertSame(Outcome::AUTHORIZED, $client->recover('sale')->outcome);
    }
}
