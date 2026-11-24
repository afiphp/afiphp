<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Arca;
use Afiphp\Billing\DocumentType;
use Afiphp\Billing\VatCondition;
use Afiphp\Exceptions\ArcaUnavailable;
use Afiphp\Exceptions\InvalidResponseException;
use Afiphp\Internal\WebServices\Failures;
use Afiphp\Internal\WebServices\PadronA13;
use Afiphp\Internal\WebServices\PadronA5;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeFiscalGateway;
use Tests\Support\FakeTicketProvider;
use Tests\Support\FakeTransport;

final class PadronTest extends TestCase
{
    public function testTaxpayerIsReadFromTheConstanciaWithAnAuthenticatedRequest(): void
    {
        $transport = new FakeTransport(static fn (): object => self::persona([
            'datosRegimenGeneral' => (object) ['impuesto' => [self::tax(30), self::tax(21)]],
        ]));
        $taxpayer = $this->padron($transport)->taxpayer('20111111112');

        self::assertSame('20111111112', $taxpayer->cuit);
        self::assertSame('ACME SA', $taxpayer->name);
        self::assertSame('JURIDICA', $taxpayer->personType);
        self::assertSame(VatCondition::RESPONSABLE_INSCRIPTO, $taxpayer->vatCondition);
        self::assertSame(['AV SIEMPRE VIVA 742', 'CAPITAL FEDERAL', '1001', 'CIUDAD AUTONOMA BUENOS AIRES'], [
            $taxpayer->address,
            $taxpayer->city,
            $taxpayer->postalCode,
            $taxpayer->province,
        ]);
        self::assertSame([], $taxpayer->errors);
        $receiver = $taxpayer->receiver();
        self::assertSame([VatCondition::RESPONSABLE_INSCRIPTO, DocumentType::CUIT], [
            $receiver->vatCondition,
            $receiver->documentType,
        ]);

        [$method, $params] = $transport->calls[0];
        self::assertSame('getPersona_v2', $method);
        self::assertSame(
            ['token' => 'token', 'sign' => 'sign', 'cuitRepresentada' => 20111111112, 'idPersona' => 20111111112],
            $params,
        );
    }

    #[DataProvider('registrations')]
    public function testVatConditionComesFromActiveRegistrations(array $persona, ?VatCondition $expected): void
    {
        $transport = new FakeTransport(static fn (): object => self::persona($persona));
        self::assertSame($expected, $this->padron($transport)->taxpayer('20111111112')->vatCondition);
    }

    public static function registrations(): array
    {
        $general = static fn (object ...$taxes): array => ['datosRegimenGeneral' => (object) ['impuesto' => $taxes]];

        return [
            'monotributo' => [
                ['datosMonotributo' => (object) ['impuesto' => self::tax(20)]],
                VatCondition::MONOTRIBUTISTA,
            ],
            'exento' => [$general(self::tax(32)), VatCondition::EXENTO],
            'no alcanzado' => [$general(self::tax(34)), VatCondition::NO_ALCANZADO],
            'no VAT registration' => [$general(self::tax(11)), VatCondition::CONSUMIDOR_FINAL],
            'inactive registration' => [$general(self::tax(30, 'BD')), VatCondition::CONSUMIDOR_FINAL],
            'contradictory' => [$general(self::tax(30), self::tax(32)), null],
            'errors may hide registrations' => [
                $general() + ['errorConstancia' => (object) ['error' => ['Domicilio fiscal no resuelto']]],
                null,
            ],
        ];
    }

    public function testUndecidedConditionCannotBecomeAReceiver(): void
    {
        $transport = new FakeTransport(static fn (): object => self::persona([
            'errorConstancia' => (object) ['error' => 'Domicilio fiscal no resuelto'],
        ]));
        $taxpayer = $this->padron($transport)->taxpayer('20111111112');
        self::assertSame(['Domicilio fiscal no resuelto'], $taxpayer->errors);
        $this->expectException(\LogicException::class);
        $taxpayer->receiver();
    }

    public function testMissingCuitIsNullButOtherFailuresAreThrown(): void
    {
        $notFound = new FakeTransport(static fn (): object => self::persona([
            'errorConstancia' => (object) ['error' => 'No existe persona con ese Id'],
        ]));
        self::assertNull($this->padron($notFound)->taxpayer('20111111112'));

        $fault = new FakeTransport(
            static fn (): object => throw Failures::fromFault(new \SoapFault(
                'Server',
                'La clave solicitada no existe',
            )),
        );
        self::assertNull($this->padron($fault)->taxpayer('20111111112'));

        $unavailable = new FakeTransport(
            static fn (): object => throw Failures::fromFault(new \SoapFault('Server', 'Servicio no disponible')),
        );
        $this->expectException(ArcaUnavailable::class);
        $this->padron($unavailable)->taxpayer('20111111112');
    }

    public function testInvalidCuitNeverReachesArca(): void
    {
        $transport = new FakeTransport();
        try {
            $this->padron($transport)->taxpayer('20111111113');
            self::fail('Expected an invalid CUIT.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $transport->calls);
        }
    }

    public function testArcaExposesTheRegistry(): void
    {
        $transport = new FakeTransport(static fn (): object => self::persona([]));
        $arca = new Arca($this->configuration(), new FakeFiscalGateway(), $this->padron($transport));
        self::assertSame(VatCondition::CONSUMIDOR_FINAL, $arca->taxpayer('20111111112')->vatCondition);
    }

    public function testCuitsAreFoundByDniWithAnAuthenticatedRequest(): void
    {
        $transport = new FakeTransport(static fn (): object => (object) ['idPersonaListReturn' => (object) [
            'idPersona' => [20111111112, '27111111117', 20111111112],
        ]]);
        self::assertSame(['20111111112', '27111111117'], $this->documents($transport)->cuitsByDni('11111111'));
        self::assertSame(
            [[
                'getIdPersonaListByDocumento',
                ['token' => 'token', 'sign' => 'sign', 'cuitRepresentada' => 20111111112, 'documento' => '11111111'],
            ]],
            $transport->calls,
        );

        $single = new FakeTransport(static fn (): object => (object) ['idPersonaListReturn' => (object) [
            'idPersona' => 20111111112,
        ]]);
        $arca = new Arca($this->configuration(), new FakeFiscalGateway(), documents: $this->documents($single));
        self::assertSame(['20111111112'], $arca->cuitsByDni('11111111'));
    }

    public function testUnknownDniIsAnEmptyList(): void
    {
        $empty = new FakeTransport(static fn (): object => new \stdClass());
        self::assertSame([], $this->documents($empty)->cuitsByDni('11111111'));

        $fault = new FakeTransport(
            static fn (): object => throw Failures::fromFault(new \SoapFault(
                'Server',
                'No existe persona con ese documento',
            )),
        );
        self::assertSame([], $this->documents($fault)->cuitsByDni('11111111'));
    }

    public function testDniLookupRejectsBadInputAndBadAnswers(): void
    {
        $transport = new FakeTransport();
        foreach (['', '0123', '11.111.111', '123456789012'] as $dni) {
            try {
                $this->documents($transport)->cuitsByDni($dni);
                self::fail('Expected an invalid DNI: ' . $dni);
            } catch (\InvalidArgumentException) {
                self::assertSame([], $transport->calls);
            }
        }

        $invalid = new FakeTransport(static fn (): object => (object) ['idPersonaListReturn' => (object) [
            'idPersona' => 20111111113,
        ]]);
        $this->expectException(InvalidResponseException::class);
        $this->documents($invalid)->cuitsByDni('11111111');
    }

    private function documents(FakeTransport $transport): PadronA13
    {
        return new PadronA13($this->configuration(), $transport, new FakeTicketProvider());
    }

    private function padron(FakeTransport $transport): PadronA5
    {
        return new PadronA5($this->configuration(), $transport, new FakeTicketProvider());
    }

    private static function tax(int $id, string $state = 'AC'): object
    {
        return (object) ['idImpuesto' => $id, 'estadoImpuesto' => $state];
    }

    private static function persona(array $fields): object
    {
        return (object) [
            'personaReturn' => (object) (
                $fields
                + [
                    'datosGenerales' => (object) [
                        'idPersona' => 20111111112,
                        'tipoPersona' => 'JURIDICA',
                        'razonSocial' => 'ACME SA',
                        'domicilioFiscal' => (object) [
                            'direccion' => 'AV SIEMPRE VIVA 742',
                            'localidad' => 'CAPITAL FEDERAL',
                            'codPostal' => '1001',
                            'descripcionProvincia' => 'CIUDAD AUTONOMA BUENOS AIRES',
                        ],
                    ],
                ]
            ),
        ];
    }
}
