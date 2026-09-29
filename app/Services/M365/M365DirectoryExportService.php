<?php

namespace App\Services\M365;

use App\Models\Client;
use App\Services\Portal\ClientVisibilityService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Builds a licences-then-users workbook for client download (CSV or XLSX).
 */
class M365DirectoryExportService
{
    public function __construct(
        protected M365DirectoryService $directory,
        protected M365InsightsService $insights,
        protected ClientVisibilityService $visibility,
    ) {}

    /**
     * @return array{
     *     client_name: string,
     *     generated_at: Carbon,
     *     summary: list<array{0: string, 1: string}>,
     *     licences: list<array{name: string, sku: string, assigned: int, purchased: int, utilisation: string, type: string}>,
     *     users: list<array{name: string, email: string, type: string, account: string, licences: string}>
     * }
     */
    public function buildWorkbook(Client $client, ?User $scopedUser = null): array
    {
        $insights = $this->insights->summaryForClient($client);
        $display = $this->directory->displaySnapshot($client);
        $snapshot = $display?->snapshot;

        $people = $snapshot?->people ?? collect();
        if ($scopedUser !== null) {
            $people = $people->filter(function (array $person) use ($scopedUser): bool {
                return $this->visibility->matchesEmail($person['email'] ?? null, $scopedUser)
                    || $this->visibility->matchesPerson($scopedUser, [
                        $person['email'] ?? null,
                        $person['displayName'] ?? null,
                    ]);
            })->values();
        }

        $licences = $this->licenceRows($client, $insights);
        $users = $this->userRows($people);

        $summary = [
            ['Organisation', $client->name],
            ['Generated (UK)', now()->timezone('Europe/London')->format('d M Y H:i')],
            ['Licensed users', $insights->licensedUserCount === null ? '-' : (string) $insights->licensedUserCount],
            ['Paid seats assigned', $insights->totalSeatsAssigned === null ? '-' : (string) $insights->totalSeatsAssigned],
            ['Paid seats purchased', $insights->totalSeatsPurchased === null ? '-' : (string) $insights->totalSeatsPurchased],
            [
                'Paid utilisation',
                $insights->overallUtilizationPct === null
                    ? '-'
                    : number_format($insights->overallUtilizationPct, 1).'%',
            ],
            ['Directory people in export', (string) $users->count()],
            ['Licence products in export', (string) count($licences)],
        ];

        return [
            'client_name' => $client->name,
            'generated_at' => now(),
            'summary' => $summary,
            'licences' => $licences,
            'users' => $users->all(),
        ];
    }

    public function filename(Client $client, string $extension): string
    {
        $slug = Str::slug($client->name) ?: 'client';
        $stamp = now()->timezone('Europe/London')->format('Y-m-d');

        return "{$slug}-m365-licences-users-{$stamp}.{$extension}";
    }

    /**
     * CSV/formula injection: directory display names etc. come from the customer's
     * tenant, and a cell starting with = + - @ (or tab / CR) is run as a formula when
     * the CSV is opened in Excel. Prefix those with an apostrophe (OWASP guidance).
     * Plain numbers and a lone "-" placeholder are left alone. XLSX uses inlineStr
     * and is not affected.
     */
    public static function neutraliseCsvFormula(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || $value === '-' || is_numeric($value)) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /**
     * @param  array{
     *     summary: list<array{0: string, 1: string}>,
     *     licences: list<array{name: string, sku: string, assigned: int, purchased: int, utilisation: string, type: string}>,
     *     users: list<array{name: string, email: string, type: string, account: string, licences: string}>
     * }  $workbook
     */
    public function toCsv(array $workbook): string
    {
        $buffer = fopen('php://temp', 'r+');
        if ($buffer === false) {
            throw new RuntimeException('Unable to open temp stream for CSV export.');
        }

        // UTF-8 BOM so Excel opens accents correctly.
        fwrite($buffer, "\xEF\xBB\xBF");

        $write = static function (array $row) use ($buffer): void {
            fputcsv($buffer, array_map(self::neutraliseCsvFormula(...), $row));
        };

        $write(['Microsoft 365 export']);
        $write([]);
        $write(['Summary']);
        foreach ($workbook['summary'] as [$label, $value]) {
            $write([$label, $value]);
        }

        $write([]);
        $write(['Licences']);
        $write(['Licence name', 'SKU', 'Assigned', 'Purchased', 'Utilisation', 'Type']);
        foreach ($workbook['licences'] as $row) {
            $write([
                $row['name'],
                $row['sku'],
                $row['assigned'],
                $row['purchased'],
                $row['utilisation'],
                $row['type'],
            ]);
        }

        $write([]);
        $write(['Users']);
        $write(['Name', 'Email', 'Type', 'Account', 'Licences']);
        foreach ($workbook['users'] as $row) {
            $write([
                $row['name'],
                $row['email'],
                $row['type'],
                $row['account'],
                $row['licences'],
            ]);
        }

        rewind($buffer);
        $csv = stream_get_contents($buffer) ?: '';
        fclose($buffer);

        return $csv;
    }

    /**
     * @param  array{
     *     summary: list<array{0: string, 1: string}>,
     *     licences: list<array{name: string, sku: string, assigned: int, purchased: int, utilisation: string, type: string}>,
     *     users: list<array{name: string, email: string, type: string, account: string, licences: string}>
     * }  $workbook
     */
    public function toXlsx(array $workbook): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'm365xlsx');
        if ($tmp === false) {
            throw new RuntimeException('Unable to create temp file for Excel export.');
        }

        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Unable to open ZipArchive for Excel export.');
        }

        $sheet1Rows = [
            ['Microsoft 365 export - summary'],
            [''],
        ];
        foreach ($workbook['summary'] as [$label, $value]) {
            $sheet1Rows[] = [$label, $value];
        }
        $sheet1Rows[] = [''];
        $sheet1Rows[] = ['Licences'];
        $sheet1Rows[] = ['Licence name', 'SKU', 'Assigned', 'Purchased', 'Utilisation', 'Type'];
        foreach ($workbook['licences'] as $row) {
            $sheet1Rows[] = [
                $row['name'],
                $row['sku'],
                $row['assigned'],
                $row['purchased'],
                $row['utilisation'],
                $row['type'],
            ];
        }

        $sheet2Rows = [
            ['Users with licences'],
            [''],
            ['Name', 'Email', 'Type', 'Account', 'Licences'],
        ];
        foreach ($workbook['users'] as $row) {
            $sheet2Rows[] = [
                $row['name'],
                $row['email'],
                $row['type'],
                $row['account'],
                $row['licences'],
            ];
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml($sheet1Rows, headerRowIndexes: [0, 4]));
        $zip->addFromString('xl/worksheets/sheet2.xml', $this->sheetXml($sheet2Rows, headerRowIndexes: [0, 2]));
        $zip->close();

        $binary = file_get_contents($tmp);
        @unlink($tmp);

        if ($binary === false) {
            throw new RuntimeException('Unable to read generated Excel file.');
        }

        return $binary;
    }

    /**
     * @return list<array{name: string, sku: string, assigned: int, purchased: int, utilisation: string, type: string}>
     */
    private function licenceRows(Client $client, M365InsightsSummary $insights): array
    {
        $payload = Cache::get($this->insights->cacheKey($client->id));
        $raw = [];

        if (is_array($payload) && is_array($payload['all_skus'] ?? null) && $payload['all_skus'] !== []) {
            $raw = $payload['all_skus'];
        } elseif ($insights->topSkus !== []) {
            $raw = $insights->topSkus;
        }

        $rows = [];
        foreach ($raw as $sku) {
            if (! is_array($sku)) {
                continue;
            }
            $part = (string) ($sku['skuPartNumber'] ?? '');
            $purchased = (int) ($sku['purchased'] ?? 0);
            $assigned = (int) ($sku['assigned'] ?? 0);
            $pct = (float) ($sku['utilizationPct'] ?? ($purchased > 0 ? round(($assigned / $purchased) * 100, 1) : 0));
            $billable = (bool) ($sku['countsTowardUtilisation']
                ?? MicrosoftLicenseSkuNames::countsTowardOverallUtilisation($part, $purchased, $assigned));

            $rows[] = [
                'name' => (string) ($sku['displayName'] ?? MicrosoftLicenseSkuNames::displayName($part)),
                'sku' => $part,
                'assigned' => $assigned,
                'purchased' => $purchased,
                'utilisation' => number_format($pct, 1).'%',
                'type' => $billable ? 'Paid' : 'Free / trial',
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $people
     * @return Collection<int, array{name: string, email: string, type: string, account: string, licences: string}>
     */
    private function userRows(Collection $people): Collection
    {
        return $people
            ->map(function (array $person): array {
                $skus = is_array($person['licenses'] ?? null) ? $person['licenses'] : [];
                $labels = MicrosoftLicenseSkuNames::displayNames($skus);

                return [
                    'name' => (string) ($person['displayName'] ?? ''),
                    'email' => (string) ($person['email'] ?? ''),
                    'type' => (string) ($person['typeLabel'] ?? $person['type'] ?? ''),
                    'account' => ! empty($person['accountEnabled']) ? 'Enabled' : 'Disabled',
                    'licences' => $labels !== [] ? implode('; ', $labels) : '-',
                ];
            })
            ->sortBy([
                ['name', 'asc'],
                ['email', 'asc'],
            ])
            ->values();
    }

    private function contentTypesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML;
    }

    private function rootRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;
    }

    private function workbookXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Licences" sheetId="1" r:id="rId1"/>
    <sheet name="Users" sheetId="2" r:id="rId2"/>
  </sheets>
</workbook>
XML;
    }

    private function workbookRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
    }

    private function stylesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="2">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFF7000"/></font>
  </fonts>
  <fills count="2">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
  </fills>
  <borders count="1">
    <border><left/><right/><top/><bottom/><diagonal/></border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="2">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>
  </cellXfs>
</styleSheet>
XML;
    }

    /**
     * @param  list<list<string|int|float|null>>  $rows
     * @param  list<int>  $headerRowIndexes  zero-based
     */
    private function sheetXml(array $rows, array $headerRowIndexes): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>';

        foreach ($rows as $rIndex => $row) {
            $rowNumber = $rIndex + 1;
            $xml .= '<row r="'.$rowNumber.'">';
            $style = in_array($rIndex, $headerRowIndexes, true) ? '1' : '0';
            foreach ($row as $cIndex => $value) {
                $col = $this->columnLetter($cIndex);
                $ref = $col.$rowNumber;
                $text = $this->xmlEscape((string) ($value ?? ''));
                $xml .= '<c r="'.$ref.'" t="inlineStr" s="'.$style.'"><is><t xml:space="preserve">'.$text.'</t></is></c>';
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';

        return $xml;
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        $n = $index;
        do {
            $letter = chr(65 + ($n % 26)).$letter;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);

        return $letter;
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
