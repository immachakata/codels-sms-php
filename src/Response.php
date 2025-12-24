<?php

namespace IsaacMachakata\CodelSms;

use GuzzleHttp\Psr7\Response as Psr7Response;
use IsaacMachakata\CodelSms\Interface\ResponseInterface;

class Response implements ResponseInterface
{
    private Psr7Response $response;
    private object|array $responseBody;
    private bool $bulkMessages;
    public function __construct(Psr7Response $response, bool $bulk = false)
    {
        $this->response = $response;
        $this->bulkMessages = $bulk;
        if ($this->response->getStatusCode() == 200) {
            $this->responseBody = json_decode($this->response->getBody());
        }
    }
    public function getCreditsUsed(): int
    {
        if ($this->bulkMessages) {
            return 0;
        }
        return !empty($this->responseBody) ? $this->responseBody->charge : 0;
    }
    public function getMessageStatus(): string
    {
        if ($this->bulkMessages) {
            return strtoupper($this->responseBody[0]->status->error_status);
        }
        return isset($this->responseBody->status) ? $this->responseBody->status : 'FAILED';
    }
    public function getMessageId(): string|null
    {
        if ($this->bulkMessages) return null;
        return !empty($this->responseBody) ? $this->responseBody->messageId : null;
    }
    public function messageIsScheduled(): bool
    {
        if ($this->bulkMessages) return false;
        return !empty($this->responseBody) ? $this->responseBody->scheduled : false;
    }
    public function isOk(): bool
    {
        $results = ['FAILED', 'ERROR'];
        return !in_array(strtoupper($this->getMessageStatus()), $results);
    }
    public function getBody(): array|object
    {
        if ($this->bulkMessages) {
            return $this->responseBody;
        }
        return $this->responseBody;
    }
}
