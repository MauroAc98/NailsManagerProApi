<?php

namespace App\Services;

final readonly class CloudApiEnvioResultado
{
    public function __construct(
        public ?string $messageId,
        public int $statusCode,
        public array $respuesta,
    ) {}
}
