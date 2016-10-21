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

    protected function mockClient()
    {
        $this
            ->awsResultMock
            ->expects($this->exactly(3))
            ->method('get')
            ->willReturn([]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogGroups')
            ->willReturn($this->awsResultMock);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->willReturn($this->awsResultMock);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('PutLogEvents')
            ->willReturn($this->awsResultMock);
    }

    /**
     * @test
     */
    public function shouldWrite()
    {
        $this->mockClient();
        $handler = new CloudWatch($this->clientMock, 'test-log-group-name', 'test-log-stream-name');

        $reflection = new \ReflectionClass($handler);

        $reflectionProperty = $reflection->getProperty('uploadSequenceToken');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($handler, 'test-token');

        $reflectionMethod = $reflection->getMethod('write');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler, ['formatted' => 'Formatted log string']);
    }


    /**
     * @test
     */
    public function shouldBatch()
    {
        $this->mockClient();

        $handler = new CloudWatch($this->clientMock, 'test-log-group-name', 'test-log-stream-name');

        $reflection = new \ReflectionClass($handler);

        $reflectionProperty = $reflection->getProperty('uploadSequenceToken');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($handler, 'test-token');

        $handler->handleBatch(
            [
                ['channel' => 'test', 'extra' => [], 'level' => 0, 'level_name' => 'TEST', 'message' => 'Unformatted log string 1', 'context' => []],
                ['channel' => 'test', 'extra' => [], 'level' => 100, 'level_name' => 'TEST', 'message' => 'Unformatted log string 2', 'context' => []],
                ['channel' => 'test', 'extra' => [], 'level' => 200, 'level_name' => 'TEST', 'message' => 'Unformatted log string 3', 'context' => []],
                ['channel' => 'test', 'extra' => [], 'level' => 300, 'level_name' => 'TEST', 'message' => 'Unformatted log string 4', 'context' => []],
                ['channel' => 'test', 'extra' => [], 'level' => 400, 'level_name' => 'TEST', 'message' => 'Unformatted log string 5', 'context' => []]
            ]
        );
    }
}
