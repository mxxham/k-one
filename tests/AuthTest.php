<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Port of v2 apps/api/test/auth.e2e-spec.ts — Auth + gateway dispatch chain.
 */
final class AuthTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        ApiTestHelpers::resetDb();
    }

    public function testRejectsRequestsWithoutAToken(): void
    {
        $res = ApiTestHelpers::api('putaway', 'zones');
        $this->assertSame(401, $res['status']);
        $this->assertFalse($res['body']['success']);
    }

    public function testLogsInWithSeededTestadminAndReturnsAToken(): void
    {
        $res = ApiTestHelpers::api('auth', 'login', '', ['username' => 'testadmin', 'password' => 'admin123']);
        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['body']['success']);
        $this->assertIsString($res['body']['token']);
        $this->assertSame('testadmin', $res['body']['user']['username']);
        $this->assertSame('admin', $res['body']['user']['role']);
    }

    public function testRejectsWrongPassword(): void
    {
        $res = ApiTestHelpers::api('auth', 'login', '', ['username' => 'testadmin', 'password' => 'nope']);
        $this->assertSame(401, $res['status']);
    }

    public function testReturns404ForUnknownModule(): void
    {
        $token = ApiTestHelpers::login();
        $res = ApiTestHelpers::api('nope', 'x', $token);
        $this->assertSame(404, $res['status']);
    }
}