<?php

namespace App\Support\Simawa;

use Illuminate\Http\Request;

final class SimawaListQuery
{
    public function __construct(
        public readonly int $limit,
        public readonly int $offset,
        public readonly ?string $updatedAfter,
        public readonly ?string $prodiId,
        public readonly ?string $angkatan,
        public readonly ?string $status,
        public readonly ?string $tipe,
        public readonly ?string $programId,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $defaultLimit = (int) config('siakad_api.simawa.default_limit', 50);
        $maxLimit = (int) config('siakad_api.simawa.max_limit', 500);

        $limit = (int) $request->query('limit', $defaultLimit);
        if ($limit < 1) {
            $limit = $defaultLimit;
        }
        if ($limit > $maxLimit) {
            $limit = $maxLimit;
        }

        $offset = max(0, (int) $request->query('offset', 0));

        return new self(
            limit: $limit,
            offset: $offset,
            updatedAfter: self::nullableString($request->query('updated_after')),
            prodiId: self::nullableString($request->query('prodi_id')),
            angkatan: self::nullableString($request->query('angkatan')),
            status: self::nullableString($request->query('status')),
            tipe: self::nullableString($request->query('tipe')),
            programId: self::nullableString($request->query('program_id')),
        );
    }

    public static function parseUpdatedAfter(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $formats = ['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d'];

        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt;
            }
        }

        $ts = strtotime($value);
        if ($ts !== false) {
            return (new \DateTimeImmutable)->setTimestamp($ts);
        }

        return null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $t = trim($value);

        return $t === '' ? null : $t;
    }
}
