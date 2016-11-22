<?php

namespace Maxbanton\Cwh\Test;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Maxbanton\Cwh\Handler\CloudWatch;
use Aws\Result;

class CloudWatchTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $clientMock;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $awsResultMock;


    public function setUp()
    {
        $this->clientMock =
            $this
                ->getMockBuilder(CloudWatchLogsClient::class)
                ->setMethods(
                    [
                        'describeLogGroups',
                        'CreateLogGroup',
                        'PutRetentionPolicy',
                        'DescribeLogStreams',
                        'CreateLogStream',
                        'PutLogEvents'
                    ]
                )
                ->disableOriginalConstructor()
                ->getMock();

        $this->awsResultMock =
            $this
                ->getMockBuilder(Result::class)
                ->setMethods(['get'])
                ->disableOriginalConstructor()
                ->getMock();
    }


    public function testShouldInitializeWithExistingLogGroup()
    {
        $logGroupName = 'test-log-group-name';
        $logStreamName = 'test-log-stream-name';

        $logGroupsResult = new Result(['logGroups' => [['logGroupName' => $logGroupName]]]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogGroups')
            ->with(['logGroupNamePrefix' => $logGroupName])
            ->willReturn($logGroupsResult);

        $logStreamResult = new Result([
            'logStreams' => [
                [
                    'logStreamName' => $logStreamName,
                    'uploadSequenceToken' => '49559307804604887372466686181995921714853186581450198322'
                ]
            ]
        ]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $logGroupName,
                'logStreamNamePrefix' => $logStreamName,
            ])
            ->willReturn($logStreamResult);

        $handler = new CloudWatch($this->clientMock, $logGroupName, $logStreamName);

        $reflection = new \ReflectionClass($handler);
        $reflectionMethod = $reflection->getMethod('initialize');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler);
    }


    public function testShouldInitializeWithMissingGroupAndStream()
    {
        $logGroupName = 'test-log-group-name';
        $logStreamName = 'test-log-stream-name';

        $logGroupsResult = new Result(['logGroups' => [['logGroupName' => $logGroupName . 'foo']]]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogGroups')
            ->with(['logGroupNamePrefix' => $logGroupName])
            ->willReturn($logGroupsResult);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogGroup')
            ->with(['logGroupName' => $logGroupName]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('putRetentionPolicy')
            ->with([
                'logGroupName' => $logGroupName,
                'retentionInDays' => 365,
            ]);

        $logStreamResult = new Result([
            'logStreams' => [
                [
                    'logStreamName' => $logStreamName . 'bar',
                    'uploadSequenceToken' => '49559307804604887372466686181995921714853186581450198324'
                ]
            ]
        ]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $logGroupName,
                'logStreamNamePrefix' => $logStreamName,
            ])
            ->willReturn($logStreamResult);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogStream')
            ->with([
                'logGroupName' => $logGroupName,
                'logStreamName' => $logStreamName
            ]);

        $handler = new CloudWatch($this->clientMock, $logGroupName, $logStreamName, 365);

        $reflection = new \ReflectionClass($handler);
        $reflectionMethod = $reflection->getMethod('initialize');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler);
    }

    public function testShouldWriteOnBatchOverflow()
    {
        $this
            ->clientMock
            ->expects($this->exactly(2))
            ->method('PutLogEvents')
            ->willReturn($this->awsResultMock);

        $handler = new CloudWatch($this->clientMock, 'test-log-group-name', 'test-log-stream-name');

        $reflection = new \ReflectionClass($handler);
        $reflectionProperty = $reflection->getProperty('initialized');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($handler, true);

        $reflectionMethod = $reflection->getMethod('write');
        $reflectionMethod->setAccessible(true);

        for ($i = 0; $i <= 50; $i++) {
            $reflectionMethod->invoke($handler, ['formatted' => 'Formatted log string' . $i]);
        }
    }


    public function testShouldWrite()
    {
        $logGroupName = 'test-log-group-name';
        $logStreamName = 'test-log-stream-name';
        $logString = 'Formatted log string';
        $token = 'token';

        $this
            ->clientMock
            ->expects($this->once())
            ->method('PutLogEvents')
            ->with(
                $this->callback(function ($array) use ($logGroupName, $logStreamName, $logString, $token) {
                    return
                        array_key_exists('logGroupName', $array) &&
                        $array['logGroupName'] === $logGroupName &&
                        array_key_exists('logStreamName', $array) &&
                        $array['logStreamName'] === $logStreamName &&
                        is_array($array['logEvents']) &&
                        array_key_exists('message', $array['logEvents'][0]) &&
                        $array['logEvents'][0]['message'] === $logString &&
                        array_key_exists('timestamp', $array['logEvents'][0]) &&
                        is_double($array['logEvents'][0]['timestamp']) &&
                        array_key_exists('sequenceToken', $array) &&
                        $array['sequenceToken'] === $token;
                }))
            ->willReturn($this->awsResultMock);

        $handler = new CloudWatch($this->clientMock, $logGroupName, $logStreamName);

        $reflection = new \ReflectionClass($handler);

        $reflectionProperty = $reflection->getProperty('initialized');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($handler, true);

        $reflectionProperty = $reflection->getProperty('uploadSequenceToken');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($handler, $token);

        $reflectionMethod = $reflection->getMethod('write');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler, ['formatted' => $logString]);
    }


    public function testShouldBatch()
    {
        $this
            ->clientMock
            ->expects($this->once())
            ->method('PutLogEvents')
            ->willReturn($this->awsResultMock);

        $handler = new CloudWatch($this->clientMock, 'test-log-group-name', 'test-log-stream-name');

        $reflection = new \ReflectionClass($handler);

        $reflectionProperty = $reflection->getProperty('initialized');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($handler, true);

        $handler->handleBatch(
            [
                [
                    'channel' => 'test',
                    'extra' => [],
                    'level' => 0,
                    'level_name' => 'TEST',
                    'message' => 'Unformatted log string 1',
                    'context' => []
                ],
                [
                    'channel' => 'test',
                    'extra' => [],
                    'level' => 100,
                    'level_name' => 'TEST',
                    'message' => 'Unformatted log string 2',
                    'context' => []
                ],
                [
                    'channel' => 'test',
                    'extra' => [],
                    'level' => 200,
                    'level_name' => 'TEST',
                    'message' => 'Unformatted log string 3',
                    'context' => []
                ],
                [
                    'channel' => 'test',
                    'extra' => [],
                    'level' => 300,
                    'level_name' => 'TEST',
                    'message' => 'Unformatted log string 4',
                    'context' => []
                ],
                [
                    'channel' => 'test',
                    'extra' => [],
                    'level' => 400,
                    'level_name' => 'TEST',
                    'message' => 'Unformatted log string 5',
                    'context' => []
                ]
            ]
        );
    }

}
