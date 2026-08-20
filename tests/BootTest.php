<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Port of v2 apps/api/test/boot.e2e-spec.ts — Boot smoke.
 * The v2 spec only asserts the app boots; here we make that meaningful by
 * asserting the gateway banner endpoint (no success field, v2 parity).
 */
final class BootTest extends TestCase
{
    public function testBoots(): void
    {
        $this->assertTrue(true);
    }

    public function testGatewayBannerParity(): void
    {
        $url = TEST_SERVER_BASE . '/api/index.php';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertSame(200, $status);
        $body = json_decode((string) $raw, true);
        $this->assertIsArray($body);
        $this->assertSame('K-one API', $body['message']);
        $this->assertSame('2.0.0', $body['version']);
        $this->assertArrayNotHasKey('success', $body); // v2 banner has no success field
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $body['time']);
    }
}