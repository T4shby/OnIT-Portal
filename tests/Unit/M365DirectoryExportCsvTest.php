<?php

namespace Tests\Unit;

use App\Services\M365\M365DirectoryExportService;
use Tests\TestCase;

class M365DirectoryExportCsvTest extends TestCase
{
    public function test_csv_neutralises_formula_prefixed_cells(): void
    {
        $csv = app(M365DirectoryExportService::class)->toCsv([
            'summary' => [['Licensed users', '12'], ['Secure score', '-']],
            'licences' => [[
                'name' => 'Business Premium', 'sku' => 'SPB', 'assigned' => 3, 'purchased' => -1,
                'utilisation' => '100%', 'type' => 'Paid',
            ]],
            'users' => [
                ['name' => '=HYPERLINK("http://evil.example","x")', 'email' => 'a@acme.com', 'type' => 'User', 'account' => 'Enabled', 'licences' => 'SPB'],
                ['name' => '@SUM(1+1)', 'email' => 'b@acme.com', 'type' => 'User', 'account' => 'Enabled', 'licences' => '+cmd'],
                ['name' => '-2+3', 'email' => 'c@acme.com', 'type' => 'User', 'account' => 'Enabled', 'licences' => ''],
                ['name' => 'Zoë Ølsen', 'email' => 'd@acme.com', 'type' => 'User', 'account' => 'Enabled', 'licences' => ''],
            ],
        ]);

        $this->assertStringContainsString('"\'=HYPERLINK(""http://evil.example"",""x"")"', $csv);
        $this->assertStringContainsString("'@SUM(1+1)", $csv);
        $this->assertStringContainsString("'+cmd", $csv);
        $this->assertStringContainsString("'-2+3", $csv);
        $this->assertStringNotContainsString(',=', $csv);
        // Ordinary values untouched.
        $this->assertStringContainsString('Zoë Ølsen', $csv);
        $this->assertStringContainsString('"Secure score",-', $csv);
        $this->assertStringContainsString(',-1,', $csv);
    }
}
