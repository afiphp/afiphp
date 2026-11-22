<?php

declare(strict_types=1);

namespace Afiphp\Internal\Storage;

use Afiphp\Billing\Authorization;
use Afiphp\Billing\InvoiceClass;
use Afiphp\Billing\InvoiceKind;
use Afiphp\Internal\Billing\Amounts;
use Afiphp\Internal\Billing\InvoiceMapper;
use Afiphp\Storage\IssuanceJournal;
use RuntimeException;

/** @internal Called only while FileIssuanceStore owns its stable lock inode. */
final class FileJournal implements IssuanceJournal
{
    private array $records;
    private bool $closed = false;

    private const JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private string $filename,
    ) {
        if (!file_exists($filename)) {
            $this->records = [];
            return;
        }
        $contents = @file_get_contents($filename);
        if ($contents === false) {
            throw new RuntimeException('Unable to read the issuance journal. No emission is allowed.');
        }
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['records'] ?? null)) {
            throw new RuntimeException('Invalid issuance journal. Restore it; never replace it with an empty file.');
        }
        $checksum = $data['checksum'] ?? null;
        if (!is_string($checksum) || !hash_equals(self::checksum($data['records']), $checksum)) {
            throw new RuntimeException('Issuance journal integrity check failed. No emission is allowed.');
        }
        foreach ($data['records'] as $key => $record) {
            if (
                !is_string($key)
                || !is_array($record)
                || !is_string($record['operation'] ?? null)
                || !hash_equals(hash('sha256', $record['operation']), $key)
            ) {
                throw new RuntimeException('Corrupt issuance record. No emission is allowed.');
            }
            self::validateRecord($record);
        }
        $this->records = $data['records'];
    }

    public function all(): array
    {
        $this->assertOpen();
        return $this->records;
    }

    public function save(string $key, array $record): void
    {
        $this->assertOpen();
        if ($key !== hash('sha256', $record['operation'] ?? '')) {
            throw new RuntimeException('Invalid issuance record key.');
        }
        self::validateRecord($record);
        $records = array_replace($this->records, [$key => $record]);
        PrivateFiles::write($this->filename, json_encode([
            'version' => 1,
            'records' => $records,
            'checksum' => self::checksum($records),
        ], self::JSON));
        $this->records = $records;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \LogicException('Issuance journal may only be used inside its exclusive callback.');
        }
    }

    private static function checksum(array $records): string
    {
        return hash('sha256', json_encode($records, self::JSON));
    }

    private static function validateRecord(array $record): void
    {
        if (!is_array($record['request'] ?? null)) {
            throw new RuntimeException('Invalid fiscal journal record. No emission is allowed.');
        }
        $header = $record['request']['FeCAEReq']['FeCabReq'] ?? null;
        $details = $record['request']['FeCAEReq']['FeDetReq']['FECAEDetRequest'] ?? null;
        // Validate the request structure before reading its fiscal fields.
        if (
            !is_array($header)
            || !is_array($details)
            || count($details) !== 1
            || !isset($details[0])
            || !is_array($details[0])
        ) {
            throw new RuntimeException('Invalid fiscal journal record. No emission is allowed.');
        }
        $detail = $details[0];

        // Validate stored metadata before comparing it with the request.
        if (
            !is_string($record['class'] ?? null)
            || !is_string($record['kind'] ?? null)
            || !is_int($record['salesPoint'] ?? null)
            || $record['salesPoint'] < 1
            || $record['salesPoint'] > 99999
            || !is_int($record['number'] ?? null)
            || $record['number'] < 1
            || $record['number'] > 99999999
            || !is_string($record['date'] ?? null)
            || !is_int($record['total'] ?? null)
            || !is_int($record['attempts'] ?? null)
            || $record['attempts'] < 0
            || !is_array($record['history'] ?? null)
            || !array_is_list($record['history'])
            || !is_string($record['operation'] ?? null)
            || $record['operation'] === ''
            || !is_string($record['issuerCuit'] ?? null)
            || !preg_match('/^[0-9]{11}$/D', $record['issuerCuit'])
            || !in_array($record['environment'] ?? null, ['homologation', 'production'], true)
            || !is_string($record['fingerprint'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $record['fingerprint'])
            || !in_array($record['status'] ?? null, ['pending', 'authorized', 'rejected', 'conflict'], true)
        ) {
            throw new RuntimeException('Invalid fiscal journal record. No emission is allowed.');
        }

        $class = InvoiceClass::tryFrom($record['class']);
        $kind = InvoiceKind::tryFrom($record['kind']);
        if (
            $class === null
            || $kind === null
            || ($header['CantReg'] ?? null) !== 1
            || ($header['PtoVta'] ?? null) !== $record['salesPoint']
            || ($header['CbteTipo'] ?? null) !== InvoiceMapper::type($class, $kind)
            || ($record['series'] ?? null) !== $record['salesPoint'] . ':' . $header['CbteTipo']
            || ($detail['CbteDesde'] ?? null) !== $record['number']
            || ($detail['CbteHasta'] ?? null) !== $record['number']
        ) {
            throw new RuntimeException('Invalid fiscal journal record. No emission is allowed.');
        }

        try {
            $date = InvoiceMapper::parseDate($record['date']);
            if (
                ($detail['ImpTotal'] ?? null) !== Amounts::decimal($record['total'])
                || ($detail['CbteFch'] ?? null) !== $date->format('Ymd')
            ) {
                throw new \InvalidArgumentException('Journal header differs from request.');
            }
            if ($record['status'] === 'authorized') {
                new Authorization($record['cae'] ?? '', InvoiceMapper::parseDate($record['expires'] ?? ''));
            }
        } catch (\InvalidArgumentException $exception) {
            throw new RuntimeException('Invalid fiscal data in issuance journal.', previous: $exception);
        }
    }
}
