<?php

namespace Maxbanton\Cwh\Handler;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

class CloudWatch extends AbstractProcessingHandler
{
    /**
     * @var CloudWatchLogsClient
     */
    private $client;

    /**
     * @var string
     */
    private $group;

    /**
     * @var string
     */
    private $stream;

    /**
     * @var integer
     */
    private $retention;

    /**
     * @var bool
     */
    private $initialized = false;

    /**
     * @var string
     */
    private $sequenceToken;

    /**
     * @var int
     */
    private $batchSize;

    /**
     * @var array
     */
    private $buffer = [];

    /**
     * @var array
     */
    private $tags = [];

    /**
     * Data amount limit (http://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_PutLogEvents.html)
     *
     * @var int
     */
    private $dataAmountLimit = 1048576;

    /**
     * @var int
     */
    private $currentDataAmount = 0;

    /**
     * Requests per second limit (https://docs.aws.amazon.com/AmazonCloudWatch/latest/logs/cloudwatch_limits_cwl.html)
     */
    const RPS_LIMIT = 5;

    /**
     * @var int
     */
    private $remainingRequests = self::RPS_LIMIT;

    /**
     * @var \DateTime
     */
    private $savedTime;

    /**
     * CloudWatchLogs constructor.
     * @param CloudWatchLogsClient $client
     *
     *  Log group names must be unique within a region for an AWS account.
     *  Log group names can be between 1 and 512 characters long.
     *  Log group names consist of the following characters: a-z, A-Z, 0-9, '_' (underscore), '-' (hyphen),
     * '/' (forward slash), and '.' (period).
     * @param string $group
     *
     *  Log stream names must be unique within the log group.
     *  Log stream names can be between 1 and 512 characters long.
     *  The ':' (colon) and '*' (asterisk) characters are not allowed.
     * @param string $stream
     *
     * @param int $retention
     * @param int $batchSize
     * @param array $tags
     * @param int $level
     * @param bool $bubble
     */
    public function __construct(
        CloudWatchLogsClient $client,
        $group,
        $stream,
        $retention = 14,
        $batchSize = 10000,
        array $tags = [],
        $level = Logger::DEBUG,
        $bubble = true
    ) {
        if ($batchSize > 10000) {
            throw new \InvalidArgumentException('Batch size can not be greater than 10000');
        }

        $this->client = $client;
        $this->group = $group;
        $this->stream = $stream;
        $this->retention = $retention;
        $this->batchSize = $batchSize;
        $this->tags = $tags;

        parent::__construct($level, $bubble);

        $this->savedTime = new \DateTime;
    }

    /**
     * {@inheritdoc}
     */
    protected function write(array $record)
    {
        $record = $this->formatRecord($record);

        if ($this->currentDataAmount + $this->getMessageSize($record) >= $this->dataAmountLimit ||
            count($this->buffer) >= $this->batchSize
        ) {
            $this->flushBuffer();
            $this->addToBuffer($record);
        } else {
            $this->addToBuffer($record);
        }
    }

    /**
     * @param array $record
     */
    private function addToBuffer(array $record)
    {
        $this->currentDataAmount += $this->getMessageSize($record);

        $this->buffer[] = $record;
    }

    private function flushBuffer()
    {
        if (!empty($this->buffer)) {
            // send items
            $this->send($this->buffer);
            // clear buffer
            $this->buffer = [];
            // clear data amount
            $this->currentDataAmount = 0;
        }
    }

    private function checkThrottle()
    {
        $current = new \DateTime();
        $diff = $current->diff($this->savedTime)->s;
        $sameSecond = $diff === 0;

        if ($sameSecond && $this->remainingRequests > 0) {
            $this->remainingRequests--;
        } elseif ($sameSecond && $this->remainingRequests === 0) {
            sleep(1);
            $this->remainingRequests = self::RPS_LIMIT;
        } elseif (!$sameSecond) {
            $this->remainingRequests = self::RPS_LIMIT;
        }

        $this->savedTime = new \DateTime();
    }

    /**
     * http://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_PutLogEvents.html
     *
     * @param $record
     * @return int
     */
    private function getMessageSize($record)
    {
        return strlen($record['message']) + 26;
    }

    /**
     * @param array $entry
     * @return array
     */
    private function formatRecord(array $entry)
    {
        return [
            'message' => $entry['formatted'],
            'timestamp' => $entry['datetime']->format('U.u') * 1000
        ];
    }

    /**
     * The batch of events must satisfy the following constraints:
     *  - The maximum batch size is 1,048,576 bytes, and this size is calculated as the sum of all event messages in
     * UTF-8, plus 26 bytes for each log event.
     *  - None of the log events in the batch can be more than 2 hours in the future.
     *  - None of the log events in the batch can be older than 14 days or the retention period of the log group.
     *  - The log events in the batch must be in chronological ordered by their timestamp (the time the event occurred,
     * expressed as the number of milliseconds since Jan 1, 1970 00:00:00 UTC).
     *  - The maximum number of log events in a batch is 10,000.
     *  - A batch of log events in a single request cannot span more than 24 hours. Otherwise, the operation fails.
     *
     * @param array $entries
     */
    private function send(array $entries)
    {
        if (false === $this->initialized) {
            $this->initialize();
        }

        $data = [
            'logGroupName' => $this->group,
            'logStreamName' => $this->stream,
            'logEvents' => $entries
        ];

        if (!empty($this->sequenceToken)) {
            $data['sequenceToken'] = $this->sequenceToken;
        }

        $this->checkThrottle();

        $response = $this->client->putLogEvents($data);

        $this->sequenceToken = $response->get('nextSequenceToken');
    }

    private function initialize()
    {
        // fetch existing groups
        $existingGroups =
            $this
                ->client
                ->describeLogGroups(['logGroupNamePrefix' => $this->group])
                ->get('logGroups');

        // extract existing groups names
        $existingGroupsNames = array_map(
            function ($group) {
                return $group['logGroupName'];
            },
            $existingGroups
        );

        // create group and set retention policy if not created yet
        if (!in_array($this->group, $existingGroupsNames, true)) {
            $createLogGroupArguments = ['logGroupName' => $this->group];

            if (!empty($this->tags)) {
                $createLogGroupArguments['tags'] = $this->tags;
            }

            $this
                ->client
                ->createLogGroup($createLogGroupArguments);
            $this
                ->client
                ->putRetentionPolicy(
                    [
                        'logGroupName' => $this->group,
                        'retentionInDays' => $this->retention,
                    ]
                );
        }

        // fetch existing streams
        $existingStreams =
            $this
                ->client
                ->describeLogStreams(
                    [
                        'logGroupName' => $this->group,
                        'logStreamNamePrefix' => $this->stream,
                    ]
                )->get('logStreams');

        // extract existing streams names
        $existingStreamsNames = array_map(
            function ($stream) {

                // set sequence token
                if ($stream['logStreamName'] === $this->stream && isset($stream['uploadSequenceToken'])) {
                    $this->sequenceToken = $stream['uploadSequenceToken'];
                }

                return $stream['logStreamName'];
            },
            $existingStreams
        );

        // create stream if not created
        if (!in_array($this->stream, $existingStreamsNames, true)) {
            $this
                ->client
                ->createLogStream(
                    [
                        'logGroupName' => $this->group,
                        'logStreamName' => $this->stream
                    ]
                );
        }

        $this->initialized = true;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultFormatter()
    {
        return new LineFormatter("%level_name%: %message% %context% %extra%\n");
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->flushBuffer();
    }
}
