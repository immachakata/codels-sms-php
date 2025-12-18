<?php

namespace IsaacMachakata\CodelSms;

use GuzzleHttp\Client as GuzzleClient;
use IsaacMachakata\CodelSms\Exception\MalformedConfigException;

/**
 * Allows you to send sms's from your PHP app.
 * 
 * @throws MalformedConfigException
 * @final
 */
final class Client //implements ClientInterface
{
    private GuzzleClient $client;
    private array|string $config;
    protected string $receivers;
    protected string $senderID = '';
    protected $templateCallback;
    protected Sms $message;

    /**
     * @param string $config
     */
    function __construct(?string $config = null, ?string $senderID = null)
    {
        $this->config = $config;
        $this->client = new GuzzleClient();
        $this->processConfigurations();

        // set sender id if not empty
        if (!is_null($senderID)) {
            $this->setSenderId($senderID);
        }
    }

    /** 
     * Uses set sender id instead of the default one.
     * 
     * @param string $senderID
     * @return Client
     */
    public function setSenderId(string $senderID)
    {
        $this->senderID = $senderID;
        return $this;
    }

    /**
     * Customize each message or phone number before sending. 
     * Callback must return either the final message or an Sms instance.
     * 
     * @param callable $templateCallback
     * @return Client
     */
    public function setCallback(callable $templateCallback)
    {
        $this->templateCallback = $templateCallback;
        return $this;
    }

    /**
     * Makes a request to the server and tries to send the message.
     * 
     * @param string|array|Sms $receivers
     * @param string|array $messages     
     * @return Response
     */
    public function send(string|array|Sms $receivers, $messages = null): Response
    {
        // if instance of Sms passed, break data
        if ($receivers instanceof Sms) {
            $data = $receivers->toArray();
            $messages = $data['messageText'];
            $receivers = $data['destination'];
        }

        if ($messages instanceof Sms) {
            $data = $messages->toArray();
            $messages = $data['messageText'];
        }
        return $this->sendMessages($receivers, $messages);
    }

    /**
     * Gets the current credit balance for the account.
     * 
     * @return int|object
     */
    public function getBalance()
    {
        $uri = Urls::BASE_URL . Urls::BALANCE_ENDPOINT;
        $response = $this->client->request('post', $uri, [
            'headers' => [
                'Accept' => 'application/json'
            ],
            'json' => [
                'token' => $this->config,
            ],
        ]);

        if ($response->getStatusCode() == 200) {
            return (int) json_decode($response->getBody())->sms_credit_balance;
        }
        return json_decode($response->getBody());
    }

    /**
     * Checks if provided configurations matche the expected format
     * 
     * @throws MalformedConfigException
     * @return void
     */
    private function processConfigurations()
    {
        if (!$this->configIsToken() || empty($this->config)) {
            throw new MalformedConfigException('Please provide an API Token for authentication.');
        }
    }

    /**
     * Checks if the user provided an API Token or login details
     * 
     * @return bool
     */
    private function configIsToken(): bool
    {
        return is_string($this->config);
    }

    /**
     * Processes messages and receivers, before deciding which method to use.
     *
     * @param string|array $receivers
     * @param string|array $messages
     */
    private function sendMessages(string|array $receivers, string|array|null $messages)
    {
        if (empty($messages) && !$this->templateCallback) {
            throw new \Exception('Message(s) can not be empty.');
        }

        // check if receivers is comma separated
        if (is_string($receivers) && is_int(strpos($receivers, ','))) {
            $receivers = explode(',', $receivers);
        }

        if (is_string($receivers)) {
            return $this->sendSingleMessage($receivers, $messages);
        }

        // send bulk sms
        return $this->sendBulkMessages($receivers, $messages);
    }

    /**
     * Processes configurations and sends a single message
     *
     * @param string $receiver
     * @param string $message
     * @throws \Exception
     * @return Response
     */
    private function sendSingleMessage(string $receiver, string $message)
    {
        if (empty($message)) {
            throw new \Exception('Message can not be empty.');
        }

        $requestJson = [
            ...Sms::new($receiver, $message)->toArray(),
            'token' => $this->config,
        ];
        if (!empty($this->senderID)) {
            $requestJson['sender_id'] = $this->senderID;
            $uri = Urls::BASE_URL . Urls::SINGLE_SMS_ENDPOINT;
        } else {
            $uri = Urls::BASE_URL . Urls::SINGLE_SMS_ENDPOINT_DEFAULT_SENDER;
        }
        $response = $this->client->request('post', $uri, [
            'headers' => [
                'Accept' => 'application/json'
            ],
            'json' => $requestJson,
        ]);
        return new Response($response);
    }

    /**
     * Processes configurations and sends a single message
     *
     * @param Sms $sms
     * @throws \Exception
     */
    private function sendBulkMessages(array $receivers, string|array|null $messages)
    {
        if (empty($messages) && !$this->templateCallback) {
            throw new \Exception('Message can not be empty.');
        }

        if (is_array($messages) && (count($receivers) > count($messages))) {
            throw new \Exception('Number of receivers and messages do not match.');
        }

        if (empty($this->senderID)) {
            throw new \Exception('Sender ID is required for bulk messages.');
        }

        $requestJson = [
            'auth' => [
                'token' => $this->config,
                'senderID' => $this->senderID
            ],
            'payload' => [
                'batchNumber' => uniqid(),
                'messages' => [],
            ]
        ];

        // create sms objects for all messages
        $smsObjects = [];
        foreach ($receivers as $index => $receiver) {
            // skip number if empty
            if (!$receiver) continue;

            // sometimes the message can be a string
            if (is_string($messages)) {
                $message = $messages;
            } else if (is_array($messages) && !array_is_list($messages)) {
                // sometimes the message can be an array of messages, indexed by phone no
                $message = $messages[$receiver];
            } else if (is_array($messages) && array_is_list($messages)) {
                // sometimes the message can be an array of messages, indexed by index
                $message = $messages[$index];
            } else {
                $message = null;
            }

            if ($this->templateCallback) {
                // callback function should return an Sms instance
                $smsObject = call_user_func($this->templateCallback, $receiver, $message);

                if (is_string($smsObject)) {
                    $smsObjects[] = Sms::new($receiver, $smsObject)->toArray();
                } else if ($smsObject instanceof Sms) {
                    if (!$smsObject->toArray()['destination']) {
                        $smsObject = $smsObject::setReceiver($receiver);
                    }
                    $smsObjects[] = $smsObject->toArray();
                } else {
                    throw new \Exception('Callback function should return an Sms instance or message string.');
                }
            } else {
                if (is_string($messages)) {
                    $message = Sms::new($receiver, $message);
                }

                if($message instanceof Sms) {
                    $message = $message->toArray();
                }

                $smsObjects[] = $message;
            }
        }
        $requestJson['payload']['messages'] = $smsObjects;

        // check if there are any messages to send
        if (count($requestJson['payload']['messages']) === 0) {
            throw new \Exception('No messages to send.');
        }

        // check if there is only one message to send
        if (count($requestJson['payload']['messages']) === 1) {
            $message = $requestJson['payload']['messages'][0];
            return $this->sendSingleMessage($message['destination'], $message['messageText']);
        }

        $uri = Urls::BASE_URL . Urls::MULTIPLE_SMS_ENDPOINT;
        $response = $this->client->request('post', $uri, [
            'headers' => [
                'Accept' => 'application/json'
            ],
            'json' => $requestJson,
        ]);
        return new Response($response, true);
    }
}
