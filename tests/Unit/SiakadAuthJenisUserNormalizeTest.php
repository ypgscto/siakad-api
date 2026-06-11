<?php

namespace Tests\Unit;

use App\Services\SiakadAuthService;
use Tests\TestCase;

class SiakadAuthJenisUserNormalizeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'siakad_api.sso_jenis_user_to_obe' => [
                '1' => '9',
                '2' => '8',
                '3' => '7',
                '4' => null,
            ],
            'siakad_api.sso_user_type_to_obe' => [
                'admin' => '9',
                'dosen' => '7',
            ],
            'siakad_api.app_login_denied_jenis_user' => ['0', '1'],
            'siakad_api.app_login_jenis_user' => ['9', '8', '7', '6', '5', '4'],
        ]);
    }

    public function test_normalize_sso_admin_to_superadmin(): void
    {
        $service = new SiakadAuthService;
        $method = new \ReflectionMethod(SiakadAuthService::class, 'normalizeAppJenisUser');
        $method->setAccessible(true);

        $this->assertSame('9', $method->invoke($service, ['jenis_user' => '1', 'nama_user' => 'Test']));
    }

    public function test_allowed_after_normalize(): void
    {
        $service = new SiakadAuthService;
        $normalize = new \ReflectionMethod(SiakadAuthService::class, 'normalizeAppJenisUser');
        $normalize->setAccessible(true);
        $allowed = new \ReflectionMethod(SiakadAuthService::class, 'isAppJenisUserAllowed');
        $allowed->setAccessible(true);

        $jenis = $normalize->invoke($service, ['jenis_user' => '3']);
        $this->assertTrue($allowed->invoke($service, $jenis));
    }

    public function test_raw_jenis_user_one_denied_for_simutu_login(): void
    {
        $service = new SiakadAuthService;
        $denied = new \ReflectionMethod(SiakadAuthService::class, 'isRawAppJenisUserDenied');
        $denied->setAccessible(true);

        $this->assertTrue($denied->invoke($service, ['jenis_user' => '1']));
        $this->assertTrue($denied->invoke($service, ['jenis_user' => '0']));
        $this->assertFalse($denied->invoke($service, ['jenis_user' => '9']));
    }

    public function test_sso_jenis_one_allowed_for_app_login_after_normalize(): void
    {
        $service = new SiakadAuthService;
        $loginDenied = new \ReflectionMethod(SiakadAuthService::class, 'isAppUserRowLoginDenied');
        $loginDenied->setAccessible(true);

        $this->assertFalse($loginDenied->invoke($service, ['jenis_user' => '1']));
        $this->assertTrue($loginDenied->invoke($service, ['jenis_user' => '0']));
        $this->assertTrue($loginDenied->invoke($service, ['jenis_user' => '4']));
    }
}
