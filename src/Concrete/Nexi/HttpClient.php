<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Nexi;

use Concrete\Core\Http\Client\Client;
use GuzzleHttp\Client as GuzzleHttpClient;
use Throwable;
use Zend\Http\Request as ZendRequest;

abstract class HttpClient
{
    /**
     * @var \Concrete\Core\Http\Client\Client
     */
    private $coreClient;

    public function __construct(Client $coreClient)
    {
        $this->coreClient = $coreClient;
    }

    /**
     * @throws \Throwable
     */
    protected function _invoke(string $method, string $url, array $headers, string $rawBody): array
    {
        if ($this->coreClient instanceof GuzzleHttpClient) {
            return $this->invokeWithGuzzle($method, $url, $headers, $rawBody);
        }

        return $this->invokeWithZend($method, $url, $headers, $rawBody);
    }

    /**
     * @throws \Throwable
     */
    private function invokeWithGuzzle(string $method, string $url, array $headers, string $rawBody): array
    {
        $options = [
            'http_errors' => false,
            'headers' => $headers,
        ];
        if ($rawBody !== '') {
            $options['body'] = $rawBody;
        }
        $response = $this->coreClient->request($method, $url, $options);

        return [$response->getStatusCode(), $response->getBody()->getContents()];
    }

    /**
     * @throws \Throwable
     */
    private function invokeWithZend(string $method, string $url, array $headers, string $rawBody): array
    {
        $request = new ZendRequest();
        $request
            ->setMethod($method)
            ->setUri($url)
        ;
        if ($rawBody !== '') {
            $request->setContent($rawBody);
        }
        $headers = $request->getHeaders();
        foreach ($headers as $name => $value) {
            $headers->addHeaderLine($name, $value);
        }
        $response = $this->coreClient->send($request);

        return [$response->getStatusCode(), $response->getBody()];
    }
}
