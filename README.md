# Laravel PubSub Queue

This package is a Laravel queue driver that uses the [Google PubSub](https://github.com/GoogleCloudPlatform/google-cloud-php-pubsub) service.

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
    'return_immediately' => true,
    'pull_max_messages' => 1,
    'max_buffer_age' => 60,
],
```

### Pull behavior options

- `return_immediately` (default `true`): passed to the PubSub pull request. Google has deprecated `true` because such pulls may return zero messages even when a backlog exists; set it to `false` so the server holds the request until at least one message is available. With `false`, make sure `request_timeout` exceeds the server hold time and that your worker's termination grace period tolerates a pull blocking for up to `request_timeout` seconds.
- `pull_max_messages` (default `1`): how many messages to fetch per pull request. Messages beyond the first are buffered in-memory and handed out one per `pop()` call, saving one HTTP round-trip per message. Size this against your subscription ack deadline: buffered messages are not acked until handed out, so a batch should be fully consumable well within the ack deadline (for example, batch 10 with a 120s deadline). Do not enable batching on subscriptions with short ack deadlines (such as 10s).
- `max_buffer_age` (default `60` seconds): buffered messages older than this are dropped unacked, because their ack deadline may have expired and PubSub may have redelivered them to another consumer. Set to roughly half the subscription ack deadline. `0` disables the guard.

Note on delivery semantics: messages are acknowledged when they are handed out by `pop()`, before the job is processed (unchanged from previous versions). A worker crash mid-processing loses that message; buffered messages that were never handed out are redelivered by PubSub.

## Testing

You can run the tests with :

```bash
vendor/bin/phpunit
```

## License

This project is licensed under the terms of the MIT license. See [License File](LICENSE) for more information.
