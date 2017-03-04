<?php

namespace Maxbanton\Cwh\Test\Handler;


use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\Result;
use Maxbanton\Cwh\Handler\CloudWatch;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class CloudWatchLogsTest extends TestCase
{

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject | CloudWatchLogsClient
     */
    private $clientMock;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject | Result
     */
    private $awsResultMock;

    /**
     * @var string
     */
    private $groupName = 'group';

    /**
     * @var string
     */
    private $streamName = 'stream';

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
    }

    public function testInitializeWithExistingLogGroup()
    {
        $logGroupsResult = new Result(['logGroups' => [['logGroupName' => $this->groupName]]]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogGroups')
            ->with(['logGroupNamePrefix' => $this->groupName])
            ->willReturn($logGroupsResult);

        $logStreamResult = new Result([
            'logStreams' => [
                [
                    'logStreamName' => $this->streamName,
                    'uploadSequenceToken' => '49559307804604887372466686181995921714853186581450198322'
                ]
            ]
        ]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamNamePrefix' => $this->streamName,
            ])
            ->willReturn($logStreamResult);

        $handler = $this->getCUT();

        $reflection = new \ReflectionClass($handler);
        $reflectionMethod = $reflection->getMethod('initialize');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler);
    }

    public function testInitializeWithMissingGroupAndStream()
    {
        $logGroupsResult = new Result(['logGroups' => [['logGroupName' => $this->groupName . 'foo']]]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogGroups')
            ->with(['logGroupNamePrefix' => $this->groupName])
            ->willReturn($logGroupsResult);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogGroup')
            ->with(['logGroupName' => $this->groupName, 'tags' => []]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('putRetentionPolicy')
            ->with([
                'logGroupName' => $this->groupName,
                'retentionInDays' => 14,
            ]);

        $logStreamResult = new Result([
            'logStreams' => [
                [
                    'logStreamName' => $this->streamName . 'bar',
                    'uploadSequenceToken' => '49559307804604887372466686181995921714853186581450198324'
                ]
            ]
        ]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamNamePrefix' => $this->streamName,
            ])
            ->willReturn($logStreamResult);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogStream')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamName' => $this->streamName
            ]);

        $handler = $this->getCUT();

        $reflection = new \ReflectionClass($handler);
        $reflectionMethod = $reflection->getMethod('initialize');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler);
    }

    public function testLimitExceeded()
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CloudWatch($this->clientMock, 'a', 'b', 14, 10001));
    }

    public function testHandleBuffers()
    {
        $this->prepareMocks();
        $handler = $this->getCUT();

        $handler->handle($this->getRecord(Logger::DEBUG));
        $handler->handle($this->getRecord(Logger::INFO));

        $handler->close();
    }

    private function prepareMocks()
    {
        $logGroupsResult = new Result(['logGroups' => [['logGroupName' => $this->groupName]]]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogGroups')
            ->with(['logGroupNamePrefix' => $this->groupName])
            ->willReturn($logGroupsResult);

        $logStreamResult = new Result([
            'logStreams' => [
                [
                    'logStreamName' => $this->streamName,
                    'uploadSequenceToken' => '49559307804604887372466686181995921714853186581450198322'
                ]
            ]
        ]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamNamePrefix' => $this->streamName,
            ])
            ->willReturn($logStreamResult);

        $this->awsResultMock =
            $this
                ->getMockBuilder(Result::class)
                ->setMethods(['get'])
                ->disableOriginalConstructor()
                ->getMock();

        $this
            ->clientMock
            ->method('PutLogEvents')
            ->willReturn($this->awsResultMock);
    }

    private function getCUT()
    {
        return new CloudWatch($this->clientMock, 'group', 'stream');
    }

    /**
     * @param int $level
     * @param string $message
     * @param array $context
     * @return array
     */
    private function getRecord($level = Logger::WARNING, $message = 'test', $context = array())
    {
        return array(
            'message' => $message,
            'context' => $context,
            'level' => $level,
            'level_name' => Logger::getLevelName($level),
            'channel' => 'test',
            'datetime' => \DateTime::createFromFormat('U.u', sprintf('%.6F', microtime(true))),
            'extra' => array(),
        );
    }

    /**
     * @return array
     */
    private function getMultipleRecords()
    {
        return array(
            $this->getRecord(Logger::DEBUG, 'debug message 1'),
            $this->getRecord(Logger::DEBUG, 'debug message 2'),
            $this->getRecord(Logger::INFO, 'information'),
            $this->getRecord(Logger::WARNING, 'warning'),
            $this->getRecord(Logger::ERROR, 'error'),
        );
    }
}