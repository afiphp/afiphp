<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Exceptions\ConfigurationError;
use Afiphp\Internal\WebServices\NativeSoapTransport;
use Afiphp\NetworkOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use SoapClient;

final class NativeSoapTransportTest extends TestCase
{
    #[DataProvider('environments')]
    public function testWsdlSerializesActualTaxAndReceiverFields(string $environment): void
    {
        $wsdl = dirname(__DIR__) . "/src/Internal/WebServices/Wsdl/wsfe-{$environment}.xml";
        $client = $this->client($wsdl, SOAP_1_2);
        $client->reply = $this->envelope(
            '<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECAESolicitarResult><FeCabResp>'
            . '<Cuit>20111111112</Cuit><PtoVta>2</PtoVta><CbteTipo>1</CbteTipo>'
            . '<CantReg>1</CantReg><Resultado>A</Resultado>'
            . '</FeCabResp><FeDetResp><FECAEDetResponse>'
            . '<Concepto>1</Concepto><DocTipo>80</DocTipo><DocNro>20111111112</DocNro>'
            . '<CbteDesde>50</CbteDesde><CbteHasta>50</CbteHasta><Resultado>A</Resultado>'
            . '<CAE>71372966559657</CAE><CAEFchVto>20261124</CAEFchVto>'
            . '</FECAEDetResponse></FeDetResp></FECAESolicitarResult></FECAESolicitarResponse>',
        );
        $transport = new NativeSoapTransport($wsdl, 'https://unused.test/', false, new NetworkOptions(), $client);
        $request = $this->soapDetail();
        foreach (['Iva', 'Tributos', 'Opcionales', 'CbtesAsoc'] as $field) {
            $request[$field] = [$request[$field][0]];
        }
        $result = $transport->call('FECAESolicitar', [
            'Auth' => ['Token' => 'token', 'Sign' => 'sign', 'Cuit' => '20111111112'],
            'FeCAEReq' => [
                'FeCabReq' => ['CantReg' => 1, 'PtoVta' => 2, 'CbteTipo' => 1],
                'FeDetReq' => ['FECAEDetRequest' => [$request]],
            ],
        ]);
        $xml = simplexml_load_string($transport->getLastRequest());
        foreach (['AlicIva', 'Tributo', 'Opcional', 'CbteAsoc'] as $element) {
            self::assertCount(1, $xml->xpath('//*[local-name()="' . $element . '"]'));
        }
        self::assertSame('1', (string) $xml->xpath('//*[local-name()="CondicionIVAReceptorId"]')[0]);
        self::assertSame('N', (string) $xml->xpath('//*[local-name()="CanMisMonExt"]')[0]);
        self::assertCount(1, $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse);
    }

    #[DataProvider('environments')]
    public function testOriginalFlatCollectionsAndExplicitWrappersProduceIdenticalXml(string $environment): void
    {
        $wsdl = dirname(__DIR__) . "/src/Internal/WebServices/Wsdl/wsfe-{$environment}.xml";
        $collections = [
            'Iva' => 'AlicIva',
            'Tributos' => 'Tributo',
            'Opcionales' => 'Opcional',
            'CbtesAsoc' => 'CbteAsoc',
        ];
        $original = $this->soapDetail();

        foreach ([1, 2] as $count) {
            $flat = $original;
            $wrapped = $original;
            foreach ($collections as $outer => $inner) {
                $flat[$outer] = array_slice($original[$outer], 0, $count);
                $wrapped[$outer] = [$inner => $flat[$outer]];
            }
            $requests = [];
            foreach ([$flat, $wrapped] as $detail) {
                $client = $this->client($wsdl, SOAP_1_2);
                $client->reply = $this->envelope('<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
                . '<FECAESolicitarResult/></FECAESolicitarResponse>');
                $client->FECAESolicitar([
                    'FeCAEReq' => [
                        'FeCabReq' => ['CantReg' => 1, 'PtoVta' => 2, 'CbteTipo' => 1],
                        'FeDetReq' => ['FECAEDetRequest' => [$detail]],
                    ],
                ]);
                $xml = simplexml_load_string($client->__getLastRequest());
                foreach ($collections as $inner) {
                    self::assertCount($count, $xml->xpath('//*[local-name()="' . $inner . '"]'));
                }
                $document = new \DOMDocument();
                $document->loadXML($client->__getLastRequest());
                $requests[] = $document->C14N();
            }
            self::assertSame($requests[0], $requests[1]);
        }
    }

    #[DataProvider('environments')]
    public function testWsdlKnowsTheReceiverVatCatalog(string $environment): void
    {
        $wsdl = dirname(__DIR__) . "/src/Internal/WebServices/Wsdl/wsfe-{$environment}.xml";
        $client = $this->client($wsdl, SOAP_1_2);
        $client->reply = $this->envelope(
            '<FEParamGetCondicionIvaReceptorResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FEParamGetCondicionIvaReceptorResult><ResultGet><CondicionIvaReceptor>'
            . '<Id>1</Id><Desc>Responsable Inscripto</Desc><Cmp_Clase>A</Cmp_Clase>'
            . '</CondicionIvaReceptor></ResultGet></FEParamGetCondicionIvaReceptorResult>'
            . '</FEParamGetCondicionIvaReceptorResponse>',
        );
        $transport = new NativeSoapTransport($wsdl, 'https://unused.test/', false, new NetworkOptions(), $client);
        $response = $transport->call('FEParamGetCondicionIvaReceptor', ['ClaseCmp' => 'A']);
        self::assertSame(1, $response->FEParamGetCondicionIvaReceptorResult->ResultGet->CondicionIvaReceptor[0]->Id);
        $xml = simplexml_load_string($transport->getLastRequest());
        self::assertSame('A', (string) $xml->xpath('//*[local-name()="ClaseCmp"]')[0]);
    }

    public function testWsaaSerializesSoap11AndParsesEmbeddedXml(): void
    {
        $wsdl = dirname(__DIR__) . '/src/Internal/WebServices/Wsdl/wsaa-homologation.xml';
        $client = $this->client($wsdl, SOAP_1_1);
        $client->reply = $this->envelope(
            '<loginCmsResponse xmlns="http://wsaa.view.sua.dvadac.desein.afip.gov">'
            . '<loginCmsReturn>&lt;loginTicketResponse/&gt;</loginCmsReturn></loginCmsResponse>',
            SOAP_1_1,
        );
        $transport = new NativeSoapTransport($wsdl, 'https://unused.test/', true, new NetworkOptions(), $client);
        self::assertSame('<loginTicketResponse/>', $transport->call('loginCms', ['in0' => 'signed'])->loginCmsReturn);
        self::assertStringContainsString('http://schemas.xmlsoap.org/soap/envelope/', $transport->getLastRequest());
    }

    #[DataProvider('environments')]
    public function testPadronA5SerializesSoap11AndParsesNestedRegistrations(string $environment): void
    {
        $wsdl = dirname(__DIR__) . "/src/Internal/WebServices/Wsdl/padron-a5-{$environment}.xml";
        $client = $this->client($wsdl, SOAP_1_1);
        $client->reply = $this->envelope(
            '<ns2:getPersona_v2Response xmlns:ns2="http://a5.soap.ws.server.puc.sr/"><personaReturn>'
            . '<datosGenerales><idPersona>20111111112</idPersona><razonSocial>ACME SA</razonSocial></datosGenerales>'
            . '<datosRegimenGeneral><impuesto><estadoImpuesto>AC</estadoImpuesto><idImpuesto>30</idImpuesto>'
            . '</impuesto></datosRegimenGeneral></personaReturn></ns2:getPersona_v2Response>',
            SOAP_1_1,
        );
        $transport = new NativeSoapTransport($wsdl, 'https://unused.test/', true, new NetworkOptions(), $client);
        $response = $transport->call('getPersona_v2', [
            'token' => 't',
            'sign' => 's',
            'cuitRepresentada' => 20111111112,
            'idPersona' => 30111111118,
        ]);
        self::assertSame('ACME SA', $response->personaReturn->datosGenerales->razonSocial);
        self::assertSame(30, $response->personaReturn->datosRegimenGeneral->impuesto[0]->idImpuesto);
        $xml = simplexml_load_string($transport->getLastRequest());
        self::assertSame('30111111118', (string) $xml->xpath('//*[local-name()="idPersona"]')[0]);
        self::assertSame('20111111112', (string) $xml->xpath('//*[local-name()="cuitRepresentada"]')[0]);
    }

    #[DataProvider('environments')]
    public function testPadronA13SerializesTheDocumentAndParsesTheCuitList(string $environment): void
    {
        $wsdl = dirname(__DIR__) . "/src/Internal/WebServices/Wsdl/padron-a13-{$environment}.xml";
        $client = $this->client($wsdl, SOAP_1_1);
        $client->reply = $this->envelope(
            '<ns2:getIdPersonaListByDocumentoResponse xmlns:ns2="http://a13.soap.ws.server.puc.sr/">'
            . '<idPersonaListReturn><idPersona>20111111112</idPersona><idPersona>27111111117</idPersona>'
            . '</idPersonaListReturn></ns2:getIdPersonaListByDocumentoResponse>',
            SOAP_1_1,
        );
        $transport = new NativeSoapTransport($wsdl, 'https://unused.test/', true, new NetworkOptions(), $client);
        $response = $transport->call('getIdPersonaListByDocumento', [
            'token' => 't',
            'sign' => 's',
            'cuitRepresentada' => 20111111112,
            'documento' => '11111111',
        ]);
        self::assertSame([20111111112, 27111111117], $response->idPersonaListReturn->idPersona);
        $xml = simplexml_load_string($transport->getLastRequest());
        self::assertSame('11111111', (string) $xml->xpath('//*[local-name()="documento"]')[0]);
    }

    public function testWrapsActualSoapFaultWithoutRetrying(): void
    {
        $wsdl = dirname(__DIR__) . '/src/Internal/WebServices/Wsdl/wsaa-homologation.xml';
        $client = $this->client($wsdl, SOAP_1_1);
        $client->reply = $this->envelope(
            '<soap:Fault><faultcode>coe.alreadyAuthenticated</faultcode>'
            . '<faultstring>Already authenticated</faultstring></soap:Fault>',
            SOAP_1_1,
        );
        $transport = new NativeSoapTransport($wsdl, 'https://unused.test/', true, new NetworkOptions(), $client);
        $this->expectException(ConfigurationError::class);
        $transport->call('loginCms', ['in0' => 'signed']);
    }

    public static function environments(): array
    {
        return [['homologation'], ['production']];
    }

    #[DataProvider('environments')]
    public function testExactDecimalStringsSurviveActualWsdlSerializationAtTheFiscalLimit(string $environment): void
    {
        $wsdl = dirname(__DIR__) . "/src/Internal/WebServices/Wsdl/wsfe-{$environment}.xml";
        $client = $this->client($wsdl, SOAP_1_2);
        $client->reply = $this->envelope('<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
        . '<FECAESolicitarResult/></FECAESolicitarResponse>');
        $detail = $this->soapDetail();
        $detail['ImpNeto'] = $detail['ImpTotal'] = '9999999999999.99';
        $detail['MonCotiz'] = '9999.999999';
        $detail['ImpIVA'] = $detail['ImpTrib'] = '0.00';
        unset($detail['Iva'], $detail['Tributos'], $detail['CbtesAsoc'], $detail['Opcionales']);
        $client->FECAESolicitar([
            'FeCAEReq' => [
                'FeCabReq' => ['CantReg' => 1, 'PtoVta' => 2, 'CbteTipo' => 11],
                'FeDetReq' => ['FECAEDetRequest' => [$detail]],
            ],
        ]);
        $xml = simplexml_load_string($client->__getLastRequest());
        self::assertSame('9999999999999.99', (string) $xml->xpath('//*[local-name()="ImpTotal"]')[0]);
        self::assertSame('9999999999999.99', (string) $xml->xpath('//*[local-name()="ImpNeto"]')[0]);
        self::assertSame('9999.999999', (string) $xml->xpath('//*[local-name()="MonCotiz"]')[0]);
    }

    #[DataProvider('environments')]
    public function testConsultationDecodesDecimalsAsExactStringsInsteadOfFloats(string $environment): void
    {
        $wsdl = dirname(__DIR__) . "/src/Internal/WebServices/Wsdl/wsfe-{$environment}.xml";
        $client = $this->client($wsdl, SOAP_1_2);
        $client->reply = $this->envelope(
            '<FECompConsultarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECompConsultarResult><ResultGet>'
            . '<ImpTotal>9999999999999.99</ImpTotal><ImpNeto>9999999999999.99</ImpNeto>'
            . '<MonCotiz>9999.999999</MonCotiz>'
            . '</ResultGet></FECompConsultarResult></FECompConsultarResponse>',
        );
        $result = $client->FECompConsultar(['FeCompConsReq' => [
            'CbteTipo' => 11,
            'PtoVta' => 2,
            'CbteNro' => 50,
        ]]);
        self::assertSame('9999999999999.99', $result->FECompConsultarResult->ResultGet->ImpTotal);
        self::assertSame('9999.999999', $result->FECompConsultarResult->ResultGet->MonCotiz);
    }

    private function envelope(string $body, int $version = SOAP_1_2): string
    {
        $namespace = $version === SOAP_1_1
            ? 'http://schemas.xmlsoap.org/soap/envelope/'
            : 'http://www.w3.org/2003/05/soap-envelope';

        return '<soap:Envelope xmlns:soap="' . $namespace . '"><soap:Body>' . $body . '</soap:Body></soap:Envelope>';
    }

    private function client(string $wsdl, int $version): SoapClient
    {
        return new class($wsdl, [
            'soap_version' => $version,
            'trace' => true,
            'exceptions' => true,
            'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'typemap' => NativeSoapTransport::decimalTypeMap(),
        ]) extends SoapClient {
            public string $reply = '';

            public function __doRequest(
                string $request,
                string $location,
                string $action,
                int $version,
                bool $oneWay = false,
                ?string $uriParserClass = null,
            ): ?string {
                return $this->reply;
            }
        };
    }
}
