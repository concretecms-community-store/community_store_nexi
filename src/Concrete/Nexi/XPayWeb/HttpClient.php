<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Nexi\XPayWeb;

use Concrete\Package\CommunityStoreNexi\Nexi\HttpClient as BaseHttpClient;
use MLocati\Nexi\XPayWeb\Exception\HttpRequestFailed;
use MLocati\Nexi\XPayWeb\HttpClient as NexiHttpClient;
use Throwable;

class HttpClient extends BaseHttpClient implements NexiHttpClient
{
    /**
     * {@inheritdoc}
     *
     * @see \MLocati\Nexi\XPayWeb\HttpClient::invoke()
     */
    public function invoke(string $method, string $url, array $headers, string $rawBody): NexiHttpClient\Response
    {
        try {
            [$statusCode, $responseBody] = $this->_invoke($method, $url, $headers, $rawBody);
        } catch (Throwable $x) {
            throw new HttpRequestFailed($x->getMessage());
        }
        return new NexiHttpClient\Response($statusCode, $responseBody);
    }
}
