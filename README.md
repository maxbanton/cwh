[![Stand With Ukraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://vshymanskyy.github.io/StandWithUkraine)

# AWS CloudWatch Logs Handler for Monolog

[![CI](https://github.com/maxbanton/cwh/actions/workflows/php.yml/badge.svg?branch=master)](https://github.com/maxbanton/cwh/actions/workflows/php.yml)
[![Coverage Status](https://img.shields.io/coveralls/maxbanton/cwh/master.svg)](https://coveralls.io/github/maxbanton/cwh?branch=master)
[![License](https://img.shields.io/packagist/l/maxbanton/cwh.svg)](https://github.com/maxbanton/cwh/blob/master/LICENSE)
[![Version](https://img.shields.io/packagist/v/maxbanton/cwh.svg)](https://packagist.org/packages/maxbanton/cwh)
[![Downloads](https://img.shields.io/packagist/dt/maxbanton/cwh.svg)](https://packagist.org/packages/maxbanton/cwh/stats)

Handler for PHP logging library [Monolog](https://github.com/Seldaek/monolog) for sending log entries to 
[AWS CloudWatch Logs](http://docs.aws.amazon.com/AmazonCloudWatch/latest/logs/WhatIsCloudWatchLogs.html) service.

Before using this library, it's recommended to get acquainted with the [pricing](https://aws.amazon.com/en/cloudwatch/pricing/) for AWS CloudWatch services.

Please press **&#9733; Star** button if you find this library useful.

## Disclaimer
This library uses AWS API through AWS PHP SDK, which has limits on concurrent requests. It means that on high concurrent or high load applications it may not work on it's best way. Please consider using another solution such as logging to the stdout and redirecting logs with fluentd.

## Requirements
* PHP ^8.1
* Monolog ^3.0
* AWS account with proper permissions (see list of permissions below)

## Features
* Up to 10000 batch logs sending in order to avoid _Rate exceeded_ errors 
* Log Groups creating with tags
* AWS CloudWatch Logs staff lazy loading
* Suitable for web applications and for long-living CLI daemons and workers

## Installation
Install the latest version with [Composer](https://getcomposer.org/) by running

```bash
$ composer require maxbanton/cwh:^3.0
```

## Basic Usage
```php
<?php

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Maxbanton\Cwh\Handler\CloudWatch;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\Logger;

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
$handler = new CloudWatch($client, $groupName, $streamName, $retentionDays, 10000, ['my-awesome-tag' => 'tag-value'], Level::Debug);

// Set $createStream to false (10th argument) when the log stream is provisioned out of band
// (e.g. via Terraform `aws_cloudwatch_log_stream`). This skips DescribeLogStreams + CreateLogStream
// and lets you drop the matching IAM permissions.
// $handler = new CloudWatch($client, $groupName, $streamName, $retentionDays, 10000, [], Level::Debug, true, true, false);

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

## Using IAM Task Roles on ECS / EC2

When running on ECS or EC2, prefer the task/instance IAM role over hard-coded credentials. Wrap the credential provider in `memoize()` so long-running workers don't hit metadata-endpoint timeouts:

```php
<?php

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\Credentials\CredentialProvider;

$provider = CredentialProvider::memoize(
    CredentialProvider::ecsCredentials()        // or ::instanceProfile() on EC2
);

$client = new CloudWatchLogsClient([
    'region'      => 'eu-west-1',
    'version'     => 'latest',
    'credentials' => $provider,
]);
```

## Frameworks integration
 - [Symfony](https://symfony.com/doc/current/logging.html) ([Example](https://github.com/maxbanton/cwh/issues/10#issuecomment-296173601))
 - [Laravel](https://laravel.com/docs/12.x/logging) ([Example](https://stackoverflow.com/a/51790656/1856778))

 [And many others](https://github.com/Seldaek/monolog#framework-integrations)

## Migrating from 2.x to 3.x

3.x is a breaking release that drops Monolog 2 and PHP 7.x support. The constructor parameter order, names, and defaults are preserved — existing Symfony YAML and Laravel `with`/`handler_with` configs continue to work after the platform upgrades below.

**Required changes:**
- Bump PHP to 8.1 or newer.
- Bump Monolog to 3.x.
- The `Monolog\Logger::DEBUG` (and other) constants were removed in Monolog 3. Use `Monolog\Level::Debug` instead — `int|string|Level` are all accepted by the `$level` constructor argument.
- Symfony service definitions referencing `!php/const Monolog\Logger::WARNING` must change to `!php/const Monolog\Level::Warning`.
- Laravel channels using `'level' => 'warning'` (string form) keep working unchanged.

**New (optional):**
- A 10th constructor argument `$createStream` (default `true`). Set to `false` to skip `DescribeLogStreams`/`CreateLogStream` for pre-provisioned streams; lets you drop those IAM permissions.
 
# AWS IAM needed permissions
if you prefer to use a separate programmatic IAM user (recommended) or want to define a policy, make sure following permissions are included:
1. `CreateLogGroup` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_CreateLogGroup.html)
1. `CreateLogStream` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_CreateLogStream.html)
1. `PutLogEvents` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_PutLogEvents.html)
1. `PutRetentionPolicy` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_PutRetentionPolicy.html)
1. `DescribeLogStreams` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_DescribeLogStreams.html)
1. `DescribeLogGroups` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_DescribeLogGroups.html)

When setting the `$createGroup` argument to `false`, permissions `DescribeLogGroups` and `CreateLogGroup` can be omitted.

When setting the `$createStream` argument to `false`, permissions `DescribeLogStreams` and `CreateLogStream` can be omitted. Use this when the log stream is provisioned out of band (e.g. via Terraform). If the stream does not exist at runtime, `PutLogEvents` will fail with `ResourceNotFoundException`.

## AWS IAM Policy full json example
```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "logs:CreateLogGroup",
                "logs:DescribeLogGroups"
            ],
            "Resource": "*"
        },
        {
            "Effect": "Allow",
            "Action": [
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

___

Made in Ukraine 🇺🇦
