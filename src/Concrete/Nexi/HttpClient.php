<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Nexi;

use MLocati\Nexi\Exception\HttpRequestFailed;
use MLocati\Nexi\HttpClient as NexiHttpClient;
use Concrete\Core\Http\Client\Client;
use GuzzleHttp\Client as GuzzleHttpClient;
use Throwable;
use Zend\Http\Request as ZendRequest;

class HttpClient implements NexiHttpClient
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
     * {@inheritdoc}
     *
     * @see \MLocati\Nexi\HttpClient::invoke()
     */
    public function invoke(string $method, string $url, array $headers, string $rawBody): NexiHttpClient\Response
    {
        if ($this->coreClient instanceof GuzzleHttpClient) {
            return $this->invokeWithGuzzle($method, $url, $headers, $rawBody);
        }
        return $this->invokeWithZend($method, $url, $headers, $rawBody);
    }

    private function invokeWithGuzzle(string $method, string $url, array $headers, string $rawBody): NexiHttpClient\Response
    {
        $options = [
            'http_errors' => false,
            'headers' => $headers,
        ];
        if ($rawBody !== '') {
            $options['body'] = $rawBody;
        }
        try {
            $response = $this->coreClient->request($method, $url, $options);
        } catch (Throwable $x) {
            throw new HttpRequestFailed($x->getMessage());
        }

        return new NexiHttpClient\Response($response->getStatusCode(), $response->getBody()->getContents());
    }

    private function invokeWithZend(string $method, string $url, array $headers, string $rawBody): NexiHttpClient\Response
    {
        try {
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
        } catch (Throwable $x) {
            throw new HttpRequestFailed($x->getMessage());
        }

        return new NexiHttpClient\Response($response->getStatusCode(), $response->getBody());
    }
}
