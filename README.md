# Laravel PubSub Queue

[![Build Status](https://travis-ci.org/kainxspirits/laravel-pubsub-queue.svg?branch=master)](https://travis-ci.org/kainxspirits/laravel-pubsub-queue)
[![StyleCI](https://styleci.io/repos/131718560/shield)](https://styleci.io/repos/131718560)

This package is a Laravel queue driver that uses the [Google PubSub](https://github.com/GoogleCloudPlatform/google-cloud-php-pubsub) service.

## Acknowledgement behaviour

By default a message is acknowledged as soon as it is pulled. That is lossy: if processing then
fails, Pub/Sub already considers the message delivered and never redelivers it. This remains the
default so existing consumers are unaffected.

A consumer can opt into acknowledging only once it is done with the message:

```php
$queue->acknowledgeOnPop(false);
```

The consumer is then responsible for calling `$job->delete()`, which is where the acknowledgement
is sent. Until that happens Pub/Sub still owns the message and redelivers it after the
subscription's ack deadline, so a message survives a failed or crashed consumer.

A consumer that does this should also extend the deadline before it starts work, so a handler
slower than the subscription's ack deadline does not have a duplicate released back to the
subscription while the original is still being processed:

```php
$job->extendLease(70);
```

Pub/Sub caps the deadline at 600 seconds. Handlers must be idempotent either way: delivery
becomes genuinely at-least-once, so a handler that fails part-way through will see the message
again.

## Installation

You can easily install this package with [Composer](https://getcomposer.org) by running this command :

```bash
composer require kainxspirits/laravel-pubsub-queue
```

If you disabled package discovery, you can still manually register this package by adding the following line to the providers of your `config/app.php` file :

```php
Kainxspirits\PubSubQueue\PubSubQueueServiceProvider::class,
```

## Configuration

Add a `pubsub` connection to your `config/queue.php` file. From there, you can use any configuration values from the original pubsub client. Just make sure to use snake_case for the keys name.

You can check [Google Cloud PubSub client](http://googleapis.github.io/google-cloud-php/#/docs/cloud-pubsub/master/pubsub/pubsubclient?method=__construct) for more details about the different options.

```php
'pubsub' => [
    'driver' => 'pubsub',
    'queue' => env('PUBSUB_QUEUE', 'default'),
    'queue_prefix' => env('PUBSUB_QUEUE_PREFIX', ''),
    'project_id' => env('PUBSUB_PROJECT_ID', 'your-project-id'),
    'retries' => 3,
    'request_timeout' => 60,
    'subscriber' => 'subscriber-name',
],
```

## Testing

You can run the tests with :

```bash
vendor/bin/phpunit
```

## License

This project is licensed under the terms of the MIT license. See [License File](LICENSE) for more information.
