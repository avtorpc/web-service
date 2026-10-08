<?php

declare(strict_types=1);

namespace App\ApiClient;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class VerificationClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly RequestStack $requests,
        #[Autowire('%env(VERIFICATION_SERVICE_URL)%')]
        private readonly string $baseUrl,
        #[Autowire('%env(WEB_SERVICE_INTERNAL_TOKEN)%')]
        private readonly string $internalToken,
    ) {}

    public function register(array $payload): ResponseInterface
    {
        return $this->post('quick-signup', $payload);
    }

    public function verifyEmail(array $payload): ResponseInterface
    {
        return $this->post('check-code-email', $payload);
    }

    public function resendEmail(array $payload): ResponseInterface
    {
        return $this->post('resend-code-email', $payload);
    }

    public function cancel(array $payload): ResponseInterface { return $this->post('cancel', $payload); }
    public function status(array $payload): ResponseInterface { return $this->post('status', $payload); }

    private function post(string $endpoint, array $payload): ResponseInterface
    {
        $ip = $this->requests->getMainRequest()?->getClientIp();
        if ($this->internalToken === '' || $ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new \LogicException('Verification client requires an internal token and a valid browser request IP.');
        }

        // Never copy arbitrary browser headers, credentials or forwarded IPs.
        // No automatic retries: these POST requests change registration state.
        return $this->http->request('POST', rtrim($this->baseUrl, '/').'/'.$endpoint, [
            'headers' => [
                'Accept' => 'application/json',
                'X-Web-Client-IP' => $ip,
                'X-Web-Service-Token' => $this->internalToken,
            ],
            'json' => $payload,
            'timeout' => 5,
            'max_duration' => 10,
            'max_redirects' => 0,
        ]);
    }
}
