<?php

namespace Tests\Unit;

use App\Support\Simawa\SimawaApiResponse;
use Tests\TestCase;

class SimawaApiResponseTest extends TestCase
{
    public function test_success_envelope(): void
    {
        $response = SimawaApiResponse::success(
            [['siakad_id' => '1']],
            'Data berhasil diambil',
            SimawaApiResponse::meta(1, 50, 0),
        );

        $payload = $response->getData(true);
        $this->assertTrue($payload['success']);
        $this->assertSame('Data berhasil diambil', $payload['message']);
        $this->assertCount(1, $payload['data']);
        $this->assertSame(1, $payload['meta']['total']);
    }

    public function test_error_envelope(): void
    {
        $response = SimawaApiResponse::error('Gagal', 422);
        $payload = $response->getData(true);

        $this->assertFalse($payload['success']);
        $this->assertSame([], $payload['data']);
        $this->assertNull($payload['meta']);
        $this->assertSame(422, $response->getStatusCode());
    }
}
