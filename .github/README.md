# Codel SMS PHP Wrapper

[![CI Test Package](https://github.com/immachakata/codels-sms-php/actions/workflows/ci-test.yml/badge.svg)](https://github.com/immachakata/codels-sms-php/actions/workflows/ci-test.yml) 
![Packagist Version](https://img.shields.io/packagist/v/immachakata/codelsms)
![Packagist Downloads](https://img.shields.io/packagist/dt/immachakata/codelsms)
![Packagist License](https://img.shields.io/packagist/l/immachakata/codelsms)

This is an **unofficial** package for the [codel](https://sms.codel.tech) bulk sms (also known as [2waychat.com](https://2waychat.com)) designed to make sending bulk SMS messages from your PHP applications simple and efficient. 

## How It Works

This wrapper interacts with the core [Codel Sms API](https://2waychat.com) and has been updated to match the March 2025 api documentation update.

It is built on top of Guzzle, a popular PHP HTTP client, to handle all communication with the Codel SMS API and provides a simple, expressive API for sending single and bulk SMS messages.

- **`Client` Class:** This is the main entry point of the library. You instantiate it with your API token, and it handles all the interactions with the Codel SMS API.
- **`Sms` Class:** A data object that represents a single SMS message, containing the destination phone number and the message text.
- **`Response` Class:** A wrapper around the Guzzle response object that provides convenient methods for accessing the response data.
- **Mocking for Tests:** The test suite uses `MockHandler` from Guzzle to simulate API calls. This ensures that your tests run quickly and do not consume your SMS credits.

## Installation

You can install the package via Composer:

```bash
composer require immachakata/codelsms
```

## Usage

First, instantiate the `Client` class with your Codel SMS API token.

```php
require __DIR__ . '/vendor/autoload.php';

use IsaacMachakata\CodelSms\Client;

$apiToken = 'YOUR_API_TOKEN';
$client = new Client($apiToken);
```

### Sending a Single SMS

To send a message to a single recipient, use the `send` method:

```php
$response = $client->send('263771000001', 'Hello, this is a test message!');

if ($response->isOk()) {
    echo "Message sent successfully!";
}
```
You can also use the older method

```php
$sms = Sms::new('263771000001', 'Hello, this is a test message!');
$response = $client->send($sms); 
```

### Sending Bulk SMS

To send the same message to multiple recipients, you can pass an array of phone numbers or a comma-separated string of phone numbers, and an array of messages either indexed by phone number, or by index. Passing a string will result in all the users receiving the same message.

> **NB**: A sender id is required when sending multiple messages and an exception will be thrown if one is not provided.

**Using an array:**
```php
$phoneNumbers = ['263771000001', '263772000002', '263773000003'];
$response = $client->send($phoneNumbers, 'This is a bulk message to everyone.');
```

**Using a comma-separated string:**
```php
$phoneNumbers = '263771000001,263772000002,263773000003';
$response = $client->send($phoneNumbers, 'This is a bulk message to everyone.');
```

### Sending Personalized Messages in Bulk

For more advanced use cases, you can send different messages to different recipients in a single API call. Use the `personalize` method to define a template for your messages. The callback receives the phone number and should return either an `Sms` object or a string - which is the message.

```php
use IsaacMachakata\CodelSms\Sms;

$users = [
    '263771000001' => ['name' => 'John', 'bill' => 150.75],
    '263772000002' => ['name' => 'Jane', 'bill' => 200.00],
];

$phoneNumbers = array_keys($users);

// the message parameter is also passed here too to the callback function
$client->personalize(function ($receiver, $data) {
    return Sms::new("Dear {$data['name']}, your bill of \${$data['bill']} is due.");
});

// The message parameter can be skipped here
$response = $client->send($phoneNumbers, $users); 
```

### Setting a Sender ID

You can specify a custom sender ID for your messages. This can be a name or a number.

```php
$client->setSenderId('MyCompany');
$response = $client->send('263771000001', 'Message from MyCompany.');
```

You can also set the sender ID directly in the constructor:

```php
$client = new Client($apiToken, 'MyCompany');
$response = $client->send('263771000001', 'Message from MyCompany.');
```

### Checking Your Balance

To check your remaining SMS credits, use the `getBalance` method:

```php
$balance = $client->getBalance();
echo "You have {$balance} SMS credits remaining.";
```

## Testing

The package includes a comprehensive PHPUnit test suite. To maintain the integrity of your account, the tests are designed to run with mock data and will not make any real API calls.

To run the tests, execute the following command in your terminal:

```bash
./vendor/bin/phpunit
```

## Contributing

Contributions are what make the open-source community such an amazing place to learn, inspire, and create. Any contributions you make are **greatly appreciated**.

1.  Fork the Project
2.  Create your Feature Branch (`git checkout -b feature/AmazingFeature`)
3.  Commit your Changes (`git commit -m 'Add some AmazingFeature'`)
4.  Push to the Branch (`git push origin feature/AmazingFeature`)
5.  Open a Pull Request

## License

Distributed under the MIT License. See `LICENSE` for more information.

## Author

- **[Isaac Machakata](https://github.com/immachakata)** - PHP Developer
