#AWS CloudWatch Handler for Monolog library
[![Build Status](https://travis-ci.org/maxbanton/cwh.svg?branch=master)](https://travis-ci.org/maxbanton/cwh) [![Coverage Status](https://coveralls.io/repos/github/maxbanton/cwh/badge.svg?branch=master)](https://coveralls.io/github/maxbanton/cwh?branch=master) [![License](https://img.shields.io/packagist/l/maxbanton/cwh.svg?maxAge=2592000)](https://github.com/maxbanton/cwh/blob/master/LICENSE) [![Version](https://img.shields.io/packagist/v/maxbanton/cwh.svg?maxAge=2592000)](https://packagist.org/packages/maxbanton/cwh)

Custom handler for PHP logging library [Monolog](https://github.com/Seldaek/monolog)

Uses [AWS CloudWatch](https://aws.amazon.com/en/cloudwatch/) Log services

Before using this library, it's recommended to get acquainted with the [pricing](https://aws.amazon.com/en/cloudwatch/pricing/) for AWS CloudWatch services

## Installation

Install the latest version with

```bash
$ composer require maxbanton/cwh
```
## Basic Usage

```php
<?php

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Maxbanton\Cwh\Handler\CloudWatch;
use Monolog\Logger;


$awsSdkParams = [
    'region' => 'eu-west-1',
    'version' => 'latest',
    'credentials' => [
        'key' => 'fill in your AWS key',
        'secret' => 'fill in your AWS secret',
        'token' => 'fill in your AWS session token', //optional
    ]
];

// Instantiate AWS SDK CloudWatch Logs Client
$client = new CloudWatchLogsClient($awsSdkParams);

// Log group name, will be created if none
$logGroupName = 'php-logtest';

// Log stream name, will be created if none
$logStreamName = 'ec2-instance-1';

// Days to keep logs, 7 by default
$daysToRetention = 14;

// Instantiate handler
$handler = new CloudWatch($client, $logGroupName, $logStreamName, $daysToRetention);

// Create a log channel
$log = new Logger('name');

// Set handler
$log->pushHandler($handler);

// Add records to the log
$log->warning('Foo');
$log->error('Bar');
```


