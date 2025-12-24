<?php

namespace IsaacMachakata\CodelSms;

use Exception;
use GuzzleHttp\Client as GuzzleClient;
use IsaacMachakata\CodelSms\Exception\MalformedConfigException;
use IsaacMachakata\CodelSms\Interface\ClientInterface;

/**
 * Allows you to send sms's from your PHP app.
 * 
 * @throws MalformedConfigException
 * @final
 */
final class Client implements ClientInterface
{
    const MAX_RECEIVERS = 3500;
    const MAX_SMS_LENGTH = 6 * 160;

    private GuzzleClient $client;
    private array|string $config;

    protected $templateCallback;
    protected Sms|null $sms = null;
    protected string|null $senderID = null;
    protected array|string|null $messages = null;
    protected array|string|null $receivers = null;

    /**
     * @param string $config
     */
    function __construct(?string $config = null, ?string $senderID = null)
    {
        $this->config = $config;
        $this->client = new GuzzleClient();
        $this->processConfigurations();
        $this->from($senderID);
    }

    /** 
     * Specify sender id for the message to be sent.
     * 
     * @param string $senderID
     * @return Client
     */
    public function from(string|null $senderID): Client
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
    public function personalize(callable $templateCallback): Client
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
        $this->configMessageParts($receivers, $messages);
        return $this->sendMessages();
    }

    /**
     * Gets the current credit balance for the account.
     * 
     * @return int|object
     */
    public function getBalance(): int|object
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
    private function sendMessages()
    {
        if ($this->isBulkSms()) {
            return $this->sendBulkMessages();
        }

        // send single sms
        return $this->sendSingleMessage();
    }

    /**
     * Processes configurations and sends a single message
     *
     * @param string $receiver
     * @param string|Sms $message
     * @throws \Exception
     * @return Response
     */
    private function sendSingleMessage()
    {
        if (!$this->sms || !$this->sms instanceof Sms || empty($this->sms->toArray()['messageText'])) {
            throw new \Exception("Message can not be empty.");
        }

        $requestJson = ['token' => $this->config, ...$this->sms->toArray()];

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
    private function sendBulkMessages()
    {
        if (empty($this->messages) && !$this->templateCallback) {
            throw new \Exception('Messages can not be empty!');
        }

        if (count($this->messages) !== count($this->receivers) && !$this->templateCallback) {
            throw new \Exception('Number of receivers and messages do not match.');
        }

        if (empty($this->senderID)) {
            throw new \Exception('Sender ID is required for bulk messages.');
        }

        if (count($this->receivers) > self::MAX_RECEIVERS) {
            throw new \Exception('Recipients cannot exceed 3500.');
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
        foreach ($this->receivers as $index => $receiver) {

            if ($this->templateCallback) {

                // message should not be an Sms instance
                if ($this->sms instanceof Sms) {
                    $smsObject = call_user_func($this->templateCallback, $receiver, $this->sms);
                } else {
                    $smsObject = call_user_func($this->templateCallback, $receiver, null);
                }

                if (is_string($smsObject)) {
                    $smsObject = Sms::new($receiver, $smsObject);
                }

                if ($smsObject instanceof Sms) {
                    if (!$smsObject->toArray()['destination']) {
                        $smsObject = $smsObject::setReceiver($receiver);
                    }
                    $smsObjects[] = $smsObject->toArray();
                } else {
                    throw new \Exception('Callback function should return an Sms instance or message string.');
                }
            } else {
                $smsObject = null;
                if ($this->sms instanceof Sms) {
                    $smsObject = $this->sms->toArray();
                    $smsObject['destination'] = $receiver;
                }

                $smsObjects[] = $smsObject;
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

    private function isBulkSms()
    {
        return is_array($this->receivers) && count($this->receivers) > 1;
    }

    private function configMessageParts(string|array|Sms $receivers, string|array|Sms|null $message = null)
    {
        if (is_string($receivers) && strpos($receivers, ',') !== false) {
            $receivers = explode(',', $receivers);
        }

        if (is_array($receivers) && !array_is_list($receivers)) {
            $receivers = array_keys($receivers);
        } else {
            $receivers = (array) $receivers;
        }

        if (is_array($receivers) && is_array($message) && count($receivers) !== count($message)) {
            throw new Exception('Number of receivers and messages do not match.');
        }

        if ($receivers instanceof Sms && is_null($message)) {
            $this->sms = $receivers;
        }

        if (is_array($receivers) && count($receivers) > 1 && !$receivers[1] instanceof Sms) {
            $this->receivers = array_filter($receivers);
        }

        // if first param is a list of Sms'
        // get receivers numbers from the object
        if (is_array($receivers) && count($receivers) > 1 && $receivers[1] instanceof Sms) {
            $this->sms = null;
            foreach ($receivers as $receiver) {
                if ($receiver instanceof Sms) {
                    $this->receivers[] = $receiver->toArray()['destination'];
                }
            }
        }

        // if user passed an array with one receiver and a message
        // create an Sms object with the message
        if (is_array($receivers) && count($receivers) === 1) {
            if (is_string($message)) {
                $this->sms = Sms::new($receivers[0], $message);
            } else if ($message instanceof Sms) {
                $this->sms = $message;
            }
        }

        // clear messages variable
        $this->messages = [];

        // set messages to an Sms object with new correct receiver
        if ($message instanceof Sms && $this->isBulkSms()) {
            foreach ($this->receivers as $index => $receiver) {
                if (!$receiver) continue;
                $this->messages[] = $message::setReceiver($receiver);
            }
        }

        if ($this->isBulkSms() && $receivers[1] instanceof Sms) {
            $this->sms = null;
            foreach ($receivers as $receiver) {
                if ($receiver instanceof Sms) {
                    $this->messages[] = $receiver;
                }
            }
        }

        if (is_string($message) && !empty($message) && $this->isBulkSms()) {
            foreach ($this->receivers as $index => $receiver) {
                if (!$receiver) continue;
                $this->messages[] = Sms::new($receiver, $message);
            }
        }

        if ($this->isBulkSms() && empty($message) && !empty($this->templateCallback)) {
            foreach ($this->receivers as $index => $receiver) {
                if (!$receiver) continue;
                $text = call_user_func($this->templateCallback, $receiver, $message);

                if (is_string(trim($text))) {
                    $this->messages[] = Sms::new($receiver, $text);
                } else if ($text instanceof Sms) {
                    $this->messages[] = $text;
                } else {
                    throw new \Exception('Callback function should return an Sms instance or message string.');
                }
            }
        }

        // check messages lengths
        if ($this->isBulkSms()) {
            foreach ($this->messages as $message) {
                if ($message instanceof Sms && strlen($message->toArray()['messageText']) > self::MAX_SMS_LENGTH) {
                    throw new \Exception('Message length cannot exceed 6 message parts.');
                }
            }
        } else if ($this->sms && strlen($this->sms->toArray()['messageText']) > self::MAX_SMS_LENGTH) {
            throw new \Exception('Message length cannot exceed 6 message parts.');
        }
    }
}
