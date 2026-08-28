<?php
declare(strict_types=1);

namespace Khatauat\Services;

final class PaylinkGateway
{
    public function isConfigured(): bool
    {
        $apiId = trim((string)(getenv('PAYLINK_API_ID') ?: ''));
        $secret = trim((string)(getenv('PAYLINK_SECRET_KEY') ?: ''));
        $base = trim((string)(getenv('PAYLINK_BASE_URL') ?: ''));

        return $apiId !== ''
            && $secret !== ''
            && $base !== ''
            && str_starts_with($base, 'https://');
    }
}
