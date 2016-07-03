<?php

namespace Maxbanton\Cwh\Test;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Maxbanton\Cwh\Handler\CloudWatch;

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
                ->setMethods(['describeLogGroups', 'CreateLogGroup', 'PutRetentionPolicy', 'DescribeLogStreams', 'CreateLogStream', 'PutLogEvents'])
                ->disableOriginalConstructor()
                ->getMock();

        $this->awsResultMock =
            $this
            ->getMockBuilder(\Aws\Result::class)
            ->setMethods(['get'])
            ->disableOriginalConstructor()
            ->getMock();
    }


    /**
     * @test
     */
    public function shouldWrite()
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

        $handler = new CloudWatch($this->clientMock, 'test-log-group-name', 'test-log-stream-name');

        $reflection = new \ReflectionClass($handler);

        $reflectionProperty = $reflection->getProperty('uploadSequenceToken');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($handler, 'test-token');

        $reflectionMethod = $reflection->getMethod('write');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler, ['formatted' => 'Formatted log string']);
    }
}
