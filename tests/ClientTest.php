<?php

use PHPUnit\Framework\TestCase;
use IsaacMachakata\CodelSms\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use IsaacMachakata\CodelSms\Exception\MalformedConfigException;
use IsaacMachakata\CodelSms\Sms;

class ClientTest extends TestCase
{
    private Client $client;
    private MockHandler $mockHandler;
    const FAKE_SUCCESS_RESPONSE =  [
        ['status' => [
            'error_status' => 'success'
        ]]
    ];
    const FAKE_ERROR_RESPONSE = [
        ['status' => [
            'error_status' => 'failed'
        ]]
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);
        $mockGuzzleClient = new GuzzleClient(['handler' => $handlerStack]);

        $this->client = new Client('a-valid-api-token-key');

        // Use reflection to replace the Guzzle client with our mock
        $reflection = new ReflectionClass($this->client);
        $property = $reflection->getProperty('client');
        $property->setValue($this->client, $mockGuzzleClient);
    }

    protected function mockSuccess(?int $httpCode = 200, ?array $response = null)
    {
        if (null === $response) {
            $response = self::FAKE_SUCCESS_RESPONSE;
        }
        $this->mockHandler->append(new Response($httpCode, [
            // nothing here
        ], json_encode($response)));
    }
    protected function mockFailure(?int $httpCode = 400, ?array $response = null)
    {
        if (null === $response) {
            $response = self::FAKE_ERROR_RESPONSE;
        }
        $this->mockHandler->append(new Response($httpCode, [
            // nothing here
        ], json_encode($response)));
    }

    public function testInvalidConfigurationsThrowsErrors()
    {
        $this->expectException(MalformedConfigException::class);
        new Client('');
        new Client();
    }

    public function testGetBalance()
    {
        // Queue a mock response
        $this->mockSuccess(200, [
            'sms_credit_balance' => 500
        ]);

        $balance = $this->client->getBalance();
        $this->assertIsInt($balance);
        $this->assertEquals(500, $balance);
    }

    public function testSendSingleMessage()
    {
        // Queue a mock response
        $this->mockSuccess(200, ['status' => 'success']);
        $response = $this->client->send('263771000001', 'Test message');
        $this->assertInstanceOf(\IsaacMachakata\CodelSms\Response::class, $response);
        $this->assertTrue($response->isOk());

        $this->mockSuccess(200, ['status' => 'success']);
        $response = $this->client->send(Sms::new("263771000001", 'Test message'));
        $this->assertInstanceOf(\IsaacMachakata\CodelSms\Response::class, $response);
        $this->assertTrue($response->isOk());

        $this->mockFailure(200, ['status' => 'error']);
        $response = $this->client->send('263771000001', 'Test message');
        $this->assertInstanceOf(\IsaacMachakata\CodelSms\Response::class, $response);
        $this->assertFalse($response->isOk());
    }

    public function testSendBulkMessages()
    {
        $phoneNumbers = [
            '263771000001',
            '263772000002',
        ];
        $this->expectException(\Exception::class);
        $response = $this->client->send($phoneNumbers, 'Test message');

        $this->mockSuccess(200);
        $this->client->from('test-sender');
        $response = $this->client->send($phoneNumbers, 'Test message');
        $this->assertInstanceOf(\IsaacMachakata\CodelSms\Response::class, $response);
        $this->assertTrue($response->isOk());

        $this->mockSuccess(200);
        $response = $this->client->send('263771000001,263771000002', 'Test message');
        $this->assertInstanceOf(\IsaacMachakata\CodelSms\Response::class, $response);
        $this->assertTrue($response->isOk());

        $this->mockSuccess(200);
        $response = $this->client->send('263771000001,263771000002', [
            Sms::new('263771000001', "Hie there from #[sbm]!", null, strtotime("+5 minutes")),
            Sms::new('263771000002', "Hie there from #[sbm]!", null, strtotime("+5 minutes")),
        ]);
        $this->assertInstanceOf(\IsaacMachakata\CodelSms\Response::class, $response);
        $this->assertTrue($response->isOk());

        $this->mockSuccess();
        $timestamp = strtotime("+2 minutes");
        $response = $this->client->from('demo')->send(
            ["263771000001", "263771000002"],
            Sms::new('263771000001', "Test message scheduled for " . date('H:i', $timestamp), null, $timestamp)
        );
        $this->assertInstanceOf(\IsaacMachakata\CodelSms\Response::class, $response);
        $this->assertFalse($response->isOk());
    }

    public function testServerErrorSendingMessage()
    {
        $this->mockFailure(200);
        $response = $this->client->send('263771000001,', 'Test message');
        $this->assertInstanceOf(\IsaacMachakata\CodelSms\Response::class, $response);
        $this->assertFalse($response->isOk());
    }

    public function testBulkSmsWithMultipleSmsObjectOnly()
    {
        $this->mockSuccess(200);
        $response = $this->client->from('sender-id')->send([
            Sms::new('263771000001', "Hie there from #[sbm]!", null, strtotime("+5 minutes")),
            Sms::new('263771000002', "Hie there from #[sbm]!", null, strtotime("+5 minutes")),
        ]);
        $this->assertInstanceOf(\IsaacMachakata\CodelSms\Response::class, $response);
        $this->assertTrue($response->isOk());
    }

    public function testSendThrowsExceptionWithMismatchedReceiversAndMessages()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Number of receivers and messages do not match.');
        $this->client->send(['263771000001', '2637710000002', '2637710000003'], ['message1', 'message2']);
    }

    public function testSendThrowsExceptionWithEmptyMessage()
    {
        $this->mockSuccess();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Message can not be empty.');
        $this->client->send('263771000001');

        $this->mockSuccess();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Message can not be empty.');
        $this->client->send('263771000001', '');

        $this->mockSuccess();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Message can not be empty.');
        $this->client->send(['263771000001'], '');
    }

    public function testCantSendMessagesWhenBlank()
    {
        $this->mockSuccess();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Messages can not be empty');
        $this->client->from('sender-id')->send(['263771000001', '263771000002'], '');
    }

    public function testPersonalizedMessagesWithoutSenderName()
    {
        $this->mockSuccess();
        $this->client->personalize(function ($receiver, $message) {
            return Sms::new($receiver, "Hie there!", null, strtotime("+5 minutes"));
        });
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Sender ID is required for bulk messages.');
        $this->client->send('263771000001,263771000002');
    }

    public function testSendPersonalizedMessagesWithSuccess()
    {
        $this->mockSuccess();
        $this->client->from('test-sender')->personalize(function ($receiver, $message) {
            return Sms::new($receiver, "Hie there!", null, strtotime("+5 minutes"));
        });
        $response = $this->client->send('263771000001,263771000002');
        $this->assertInstanceOf(\IsaacMachakata\CodelSms\Response::class, $response);
        $this->assertTrue($response->isOk());

        $this->mockSuccess();
        $this->client->from('test-sender')->personalize(function ($receiver) {
            return Sms::new($receiver, "Hie there!", null, strtotime("+5 minutes"));
        });
        $response = $this->client->send('263771000001,263771000002', '');
        $this->assertInstanceOf(\IsaacMachakata\CodelSms\Response::class, $response);
        $this->assertTrue($response->isOk());

        $users = [
            '263771000001' => ['name' => 'John', 'bill' => 150.75],
            '263772000002' => ['name' => 'Jane', 'bill' => 200.00],
        ];
        $phoneNumbers = array_keys($users);
        $this->client->personalize(function ($receiver, $user) {
            return Sms::new("Dear {$user['name']}, your bill of \${$user['bill']} is due.");
        });
        $this->mockSuccess();
        $response = $this->client->send($phoneNumbers, $users);
        $this->assertInstanceOf(\IsaacMachakata\CodelSms\Response::class, $response);
        $this->assertTrue($response->isOk());

        $this->mockSuccess();
        $time = strtotime("+2 minutes");
        $response = $this->client->send(
            ["263771000001", "263771000002"],
            Sms::new('263782192384', "Test message scheduled for " . date('H:i', $time), null, $time)
        );
        $this->assertTrue($response->isOk());
    }
}
