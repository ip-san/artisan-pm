<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Webhooks\WebhookEndpoint;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Spatie\WebhookServer\CallWebhookJob;

/**
 * Delivers one webhook call (config `webhook-server.webhook_job`). Before the
 * request it resolves the URL's host again and checks every address
 * (WebhookEndpoint), then pins the connection to a checked address and does
 * not follow redirects — Redmine's Webhook::Executor, which connects with
 * `ipaddr:` to an address it just validated. A host that now resolves to a
 * refused address (DNS rebinding) or not at all is not called and the call is
 * not retried.
 *
 * A hook owned by a user (`meta.public_only`) is also refused private and
 * reserved ranges, as when it was saved.
 */
class DeliverWebhookJob extends CallWebhookJob
{
    public const string PUBLIC_ONLY = 'public_only';

    /**
     * The checked address this delivery connects to.
     */
    private ?string $pinnedAddress = null;

    public function handle(): void
    {
        $url = (string) $this->webhookUrl;
        $addresses = WebhookEndpoint::safeAddresses($url, publicOnly: (bool) ($this->meta[self::PUBLIC_ONLY] ?? false));

        if ($addresses === []) {
            Log::warning('Webhook not delivered: its URL does not resolve to an allowed address.', ['url' => $url]);
            $this->delete();

            return;
        }

        $this->pinnedAddress = $addresses[0];

        parent::handle();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function createRequest(array $body): Response
    {
        $url = (string) $this->webhookUrl;
        $parts = WebhookEndpoint::parse($url);

        if ($parts === null || $this->pinnedAddress === null) {
            throw new RuntimeException('Webhook URL was not checked before delivery.');
        }

        $address = str_contains($this->pinnedAddress, ':') ? "[{$this->pinnedAddress}]" : $this->pinnedAddress;

        $response = $this->getClient()->request($this->httpVerb, $url, array_merge(
            [
                'timeout' => $this->requestTimeout,
                'verify' => $this->verifySsl,
                'headers' => $this->headers,
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => ["{$parts['host']}:{$parts['port']}:{$address}"]],
                'on_stats' => function (TransferStats $stats): void {
                    $this->transferStats = $stats;
                },
            ],
            $body,
            is_null($this->proxy) ? [] : ['proxy' => $this->proxy],
            is_null($this->cert) ? [] : ['cert' => [$this->cert, $this->certPassphrase]],
            is_null($this->sslKey) ? [] : ['ssl_key' => [$this->sslKey, $this->sslKeyPassphrase]],
        ));

        if (! $response instanceof Response) {
            throw new RuntimeException('Unexpected webhook response type.');
        }

        return $response;
    }
}
