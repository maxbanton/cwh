#AWS CloudWatch Logs Handler for Monolog
[![Build Status](https://travis-ci.org/maxbanton/cwh.svg?branch=master)](https://travis-ci.org/maxbanton/cwh) 
[![Coverage Status](https://coveralls.io/repos/github/maxbanton/cwh/badge.svg?branch=master)](https://coveralls.io/github/maxbanton/cwh?branch=master) 
[![License](https://img.shields.io/packagist/l/maxbanton/cwh.svg?maxAge=2592000)](https://github.com/maxbanton/cwh/blob/master/LICENSE) 
[![Version](https://img.shields.io/packagist/v/maxbanton/cwh.svg?maxAge=2592000)](https://packagist.org/packages/maxbanton/cwh)

Handler for PHP logging library [Monolog](https://github.com/Seldaek/monolog) for sending log entries to 
[AWS CloudWatch Logs](http://docs.aws.amazon.com/AmazonCloudWatch/latest/logs/WhatIsCloudWatchLogs.html) service.

Before using this library, it's recommended to get acquainted with the [pricing](https://aws.amazon.com/en/cloudwatch/pricing/) for AWS CloudWatch services.

Please press **&#9733; Star** button if you find this library useful.

## Features
* Up to 10000 batch logs sending in order to avoid _Rate exceeded_ errors 
* Log Groups creating with tags
* AWS CloudWatch Logs staff lazy loading
* Suitable for web applications and for long-living CLI daemons and workers

## Installation
Install the latest version with [Composer](https://getcomposer.org/)

```bash
$ composer require maxbanton/cwh:^1.0
```

## Upgrade
Change in your composer.json
```
{
  "require": {
    "maxbanton/cwh": "^0.0.3"
  }
}
```
to
```
{
  "require": {
    "maxbanton/cwh": "^1.0"
  }
}
```
then run
```bash
$ composer update
```
and change your code
```php
<?php
// Instantiate handler
$handler = new CloudWatch($client, $logGroupName, $logStreamName, $daysToRetention);
```
to
```php
<?php
// Instantiate handler
$handler = new CloudWatch($client, $groupName, $streamName, $retentionDays, 10000, ['my-awesome-tag' => 'tag-value']);
```

## Basic Usage
```php
<?php

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Maxbanton\Cwh\Handler\CloudWatch;
use Monolog\Logger;

$sdkParams = [
    'region' => 'eu-west-1',
    'version' => 'latest',
    'credentials' => [
        'key' => 'your AWS key',
        'secret' => 'your AWS secret',
        'token' => 'your AWS session token',
    ]
];

// Instantiate AWS SDK CloudWatch Logs Client
$client = new CloudWatchLogsClient($sdkParams);

// Log group name, will be created if none
$groupName = 'php-logtest';

// Log stream name, will be created if none
$streamName = 'ec2-instance-1';

// Days to keep logs, 14 by default
$retentionDays = 30;

// Instantiate handler
$handler = new CloudWatch($client, $groupName, $streamName, $retentionDays, 10000, ['my-awesome-tag' => 'tag-value']);

// Create a log channel
$log = new Logger('name');

// Set handler
$log->pushHandler($handler);

// Add records to the log
$log->debug('Foo');
$log->warning('Bar');
$log->error('Baz');
```

## Frameworks integration
 - [Silex](http://silex.sensiolabs.org/doc/master/providers/monolog.html#customization)
 - [Symfony](http://symfony.com/doc/current/logging.html)
 - [Lumen](https://lumen.laravel.com/docs/5.2/errors)
 - [Laravel](https://laravel.com/docs/5.4/errors)
  
 [And many others](https://github.com/Seldaek/monolog#framework-integrations)
 
## Issues
Feel free to [report any issues](https://github.com/maxbanton/cwh/issues/new)
