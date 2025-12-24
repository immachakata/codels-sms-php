<?php

namespace IsaacMachakata\CodelSms\Interface;

use IsaacMachakata\CodelSms\Client;
use IsaacMachakata\CodelSms\Response;
use IsaacMachakata\CodelSms\Sms;

interface ClientInterface
{
    public function getBalance(): int|object;
    public function from(string|null $senderID): Client;
    public function personalize(callable $templateCallback): Client;
    public function send(string|array|Sms $receivers, $messages = null): Response;
}
