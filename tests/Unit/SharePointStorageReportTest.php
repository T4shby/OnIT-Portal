<?php

namespace Tests\Unit;

use App\Services\EntraSync\MicrosoftGraphClient;
use ReflectionMethod;
use Tests\TestCase;

class SharePointStorageReportTest extends TestCase
{
    public function test_csv_sums_used_and_allocated_bytes_and_skips_deleted_sites(): void
    {
        $csv = implode("\n", [
            'Site URL,Is Deleted,Storage Used (Byte),Storage Allocated (Byte)',
            'https://contoso.sharepoint.com/sites/a,False,1073741824,2147483648',
            'https://contoso.sharepoint.com/sites/old,True,999,999',
            'https://contoso.sharepoint.com/sites/b,False,1073741824,2147483648',
        ]);

        $method = new ReflectionMethod(MicrosoftGraphClient::class, 'parseSharePointUsageCsv');
        $method->setAccessible(true);
        $parsed = $method->invoke(new MicrosoftGraphClient, $csv);

        $this->assertSame(2, $parsed['site_count']);
        $this->assertSame(2147483648, $parsed['used_bytes']);
        $this->assertSame(4294967296, $parsed['allocated_bytes']);
    }
}
