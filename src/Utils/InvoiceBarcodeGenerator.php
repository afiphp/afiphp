<?php

namespace Afiphp\Utils;

use Afiphp\Entities\Cae;
use DateTime;

class InvoiceBarcodeGenerator
{
    protected $barcode;

    /**
     * http://biblioteca.afip.gob.ar/dcp/REAG01001702_2004_07_13
     */
    public function __construct(
        string $issuerCuit,
        int $invoiceType,
        int $salesPoint,
        string $cae,
        DateTime $dueDate
    )
    {
        $code = $issuerCuit
            . str_pad($invoiceType, 3, '0', STR_PAD_LEFT)
            . str_pad($salesPoint, 5, '0', STR_PAD_LEFT)
            . $cae
            . $dueDate->format('Ymd');

        $this->barcode = $code . $this->getCheckDigit($code);
    }

    public static function make(string $cuit, int $invoiceType, int $salesPoint, string $cae, DateTime $dueDate): string
    {
        return (string)(new static($cuit, $invoiceType, $salesPoint, $cae, $dueDate));
    }

    public static function makeFromCae(string $cuit, Cae $cae): string
    {
        return (string)(new static($cuit, $cae->invoiceType, $cae->salesPoint, $cae->number, $cae->dueDate));
    }

    public function get(): string
    {
        return $this->barcode;
    }

    public function __toString(): string
    {
        return $this->get();
    }

    public function getCheckDigit(string $code): string
    {
        $evens = 0;
        $odds = 0;

        // Etapa 1: Comenzar desde la izquierda, sumar todos los caracteres ubicados en las posiciones impares.
        // Etapa 3: Comenzar desde la izquierda, sumar todos los caracteres que están ubicados en las posiciones pares.

        foreach (str_split($code) as $index => $number) {
            if (($index + 1) % 2 === 0) {
                $evens += (int) $number;
            } else {
                $odds += (int) $number;
            }
        }

        // Etapa 2: Multiplicar la suma obtenida en la etapa 1 por el número 3.
        // Etapa 4: Sumar los resultados obtenidos en las etapas 2 y 3.

        $sum = $odds * 3 + $evens;

        // Etapa 5: Buscar el menor número que sumado al resultado obtenido en la etapa 4 dé un número múltiplo de 10.
        // Este será el valor del dígito verificador del módulo 10.

        $digit = 10 - ($sum - intdiv($sum, 10) * 10);

        if ($digit === 10) {
            return '0';
        }

        return (string)$digit;
    }
}
