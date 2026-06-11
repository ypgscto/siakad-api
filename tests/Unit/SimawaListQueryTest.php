<?php

namespace Tests\Unit;

use App\Support\Simawa\SimawaListQuery;
use Illuminate\Http\Request;
use Tests\TestCase;

class SimawaListQueryTest extends TestCase
{
    public function test_parses_limit_and_offset(): void
    {
        $request = Request::create('/api/simawa/prodi', 'GET', [
            'limit' => '10',
            'offset' => '5',
        ]);

        $query = SimawaListQuery::fromRequest($request);

        $this->assertSame(10, $query->limit);
        $this->assertSame(5, $query->offset);
    }

    public function test_caps_limit_to_max(): void
    {
        config(['siakad_api.simawa.max_limit' => 100]);

        $request = Request::create('/api/simawa/prodi', 'GET', [
            'limit' => '9999',
        ]);

        $query = SimawaListQuery::fromRequest($request);

        $this->assertSame(100, $query->limit);
    }

    public function test_parses_updated_after(): void
    {
        $dt = SimawaListQuery::parseUpdatedAfter('2026-01-15 08:30:00');
        $this->assertInstanceOf(\DateTimeImmutable::class, $dt);
        $this->assertSame('2026-01-15 08:30:00', $dt?->format('Y-m-d H:i:s'));
    }
}
