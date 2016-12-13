#AWS CloudWatch Handler for Monolog library
[![Build Status](https://travis-ci.org/maxbanton/cwh.svg?branch=master)](https://travis-ci.org/maxbanton/cwh) [![Coverage Status](https://coveralls.io/repos/github/maxbanton/cwh/badge.svg?branch=master)](https://coveralls.io/github/maxbanton/cwh?branch=master) [![License](https://img.shields.io/packagist/l/maxbanton/cwh.svg?maxAge=2592000)](https://github.com/maxbanton/cwh/blob/master/LICENSE) [![Version](https://img.shields.io/packagist/v/maxbanton/cwh.svg?maxAge=2592000)](https://packagist.org/packages/maxbanton/cwh)

Custom handler for PHP logging library [Monolog](https://github.com/Seldaek/monolog).
Allows to send log messages in batches.

Uses [AWS CloudWatch Logs](http://docs.aws.amazon.com/AmazonCloudWatch/latest/logs/WhatIsCloudWatchLogs.html) service

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

## Frameworks integration

 - [Silex integration](http://silex.sensiolabs.org/doc/master/providers/monolog.html#customization)
 - Symfony Integration :

Add to /app/services.yml:

```
parameters:
    awsCredentialsClass:              Aws\Credentials\Credentials
    cloudWatchLogsClientClass:        Aws\CloudWatchLogs\CloudWatchLogsClient
    cloudWatchMonologHandlerClass:    Maxbanton\Cwh\Handler\CloudWatch

services:
  awsCredentials:
    class:      %awsCredentialsClass%
    arguments: [%aws_sdk_credentials_key%, %aws_sdk_credentials_secret%,  %aws_sdk_credentials_token%, %aws_sdk_credentials_expires%]

  cloudWatchLogsClient:
    class:      %cloudWatchLogsClientClass%
    arguments: [{version: %aws_sdk_version%, region: %aws_sdk_region%, credentials: "@awsCredentials"}]

  monolog.handler.cloudwatchmonologhandler:
      class:      %cloudWatchMonologHandlerClass%
      arguments : ["@cloudWatchLogsClient", %aws_cloud_watch_group%, %aws_cloud_watch_stream%, %aws_cloud_watch_retention%]
```

Add to /app/config.yml:
```
        cloudwatch:
            type:         buffer
            handler:      cloudWatchMonologHandler
            channels:     [!event]
            level:        warning
```

## Issues

Feel free to [report any issues](https://github.com/maxbanton/cwh/issues/new)


