# AWS CloudWatch Logs Handler for Monolog

[![Build Status](https://img.shields.io/travis/maxbanton/cwh/master.svg)](https://travis-ci.org/maxbanton/cwh)
[![Coverage Status](https://img.shields.io/coveralls/maxbanton/cwh/master.svg)](https://coveralls.io/github/maxbanton/cwh?branch=master)
[![License](https://img.shields.io/packagist/l/maxbanton/cwh.svg)](https://github.com/maxbanton/cwh/blob/master/LICENSE)
[![Version](https://img.shields.io/packagist/v/maxbanton/cwh.svg)](https://packagist.org/packages/maxbanton/cwh)
[![Downloads](https://img.shields.io/packagist/dt/maxbanton/cwh.svg)](https://packagist.org/packages/maxbanton/cwh/stats)

Handler for PHP logging library [Monolog](https://github.com/Seldaek/monolog) for sending log entries to 
[AWS CloudWatch Logs](http://docs.aws.amazon.com/AmazonCloudWatch/latest/logs/WhatIsCloudWatchLogs.html) service.

Before using this library, it's recommended to get acquainted with the [pricing](https://aws.amazon.com/en/cloudwatch/pricing/) for AWS CloudWatch services.

Please press **&#9733; Star** button if you find this library useful, aslo you can [donate](#donate) if you like to.

## Features
* Up to 10000 batch logs sending in order to avoid _Rate exceeded_ errors 
* Log Groups creating with tags
* AWS CloudWatch Logs staff lazy loading
* Suitable for web applications and for long-living CLI daemons and workers
* Compatible with PHP >= 5.6

## Installation
Install the latest version with [Composer](https://getcomposer.org/) by running

```bash
$ composer require maxbanton/cwh:^1.0
```

## Upgrade
Upgrade to the lastest version with [Composer](https://getcomposer.org/) by running

```
$ composer require maxbanton/cwh:^1.0 --update-with-dependencies
```

and change your code from

```php
<?php

use Maxbanton\Cwh\Handler\CloudWatch;

// Instantiate handler
$handler = new CloudWatch($client, $logGroupName, $logStreamName, $daysToRetention);
```
to

```php
<?php

use Maxbanton\Cwh\Handler\CloudWatch;

// Instantiate handler (tags are optional)
$handler = new CloudWatch($client, $groupName, $streamName, $retentionDays, 10000, ['my-awesome-tag' => 'tag-value']);
```

## Basic Usage
```php
<?php

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Maxbanton\Cwh\Handler\CloudWatch;
use Monolog\Logger;
use Monolog\Formatter\JsonFormatter;

$sdkParams = [
    'region' => 'eu-west-1',
    'version' => 'latest',
    'credentials' => [
        'key' => 'your AWS key',
        'secret' => 'your AWS secret',
        'token' => 'your AWS session token', // token is optional
    ]
];

// Instantiate AWS SDK CloudWatch Logs Client
$client = new CloudWatchLogsClient($sdkParams);

// Log group name, will be created if none
$groupName = 'php-logtest';

// Log stream name, will be created if none
$streamName = 'ec2-instance-1';

// Days to keep logs, 14 by default. Set to `null` to allow indefinite retention.
$retentionDays = 30;

// Instantiate handler (tags are optional)
$handler = new CloudWatch($client, $groupName, $streamName, $retentionDays, 10000, ['my-awesome-tag' => 'tag-value']);

// Optionally set the JsonFormatter to be able to access your log messages in a structured way
$handler->setFormatter(new JsonFormatter());

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
 - [Symfony](http://symfony.com/doc/current/logging.html) ([Example](https://github.com/maxbanton/cwh/issues/10#issuecomment-296173601))
 - [Lumen](https://lumen.laravel.com/docs/5.2/errors)
 - [Laravel](https://laravel.com/docs/5.4/errors) ([Example](https://stackoverflow.com/a/51790656/1856778))
  
 [And many others](https://github.com/Seldaek/monolog#framework-integrations)
 
# AWS IAM needed permissions
if you prefer to use a separate programmatic IAM user (recommended) or want to define a policy, make sure following permissions are included:
1. `CreateLogGroup` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_CreateLogGroup.html)
1. `CreateLogStream` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_CreateLogStream.html)
1. `PutLogEvents` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_PutLogEvents.html)
1. `PutRetentionPolicy` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_PutRetentionPolicy.html)
1. `DescribeLogStreams` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_DescribeLogStreams.html)
1. `DescribeLogGroups` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_DescribeLogGroups.html)

## AWS IAM Policy full json example
```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": "logs:CreateLogGroup",
            "Resource": "*"
        },
        {
            "Effect": "Allow",
            "Action": [
                "logs:DescribeLogGroups",
                "logs:CreateLogStream",
                "logs:DescribeLogStreams",
                "logs:PutRetentionPolicy"
            ],
            "Resource": "{LOG_GROUP_ARN}"
        },
        {
            "Effect": "Allow",
            "Action": [
                "logs:PutLogEvents"
            ],
            "Resource": [
                "{LOG_STREAM_1_ARN}",
                "{LOG_STREAM_2_ARN}"
            ]
        }
    ]
}
```

## Issues
Feel free to [report any issues](https://github.com/maxbanton/cwh/issues/new)

## Contributing
Please check [this document](https://github.com/maxbanton/cwh/blob/master/CONTRIBUTING.md)

## Donate
If you would like to, you can send any amount of BTC to the wallet `12d3VXfvPiQ5bFMfPppGqpwnNSkZwigBVt`

![Donate BTC](https://monosnap.com/file/uv8lk8VrWzEywdUCmkfy4NCRg9qok3.png)

or ETHER to the wallet `0xd6C9d9Af4b03a11223C67067782E30194D9adAEb`

![Donate ETHER](https://monosnap.com/image/4nsT44MoNd7Y4OkC9rJWmy1yyvCh8r.png)
