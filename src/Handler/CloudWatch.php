<?php

namespace Maxbanton\Cwh\Handler;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

class CloudWatch extends AbstractProcessingHandler
{
    /**
     * Event size limit (https://docs.aws.amazon.com/AmazonCloudWatch/latest/logs/cloudwatch_limits_cwl.html)
     *
     * @var int
     */
    const EVENT_SIZE_LIMIT = 1048550; // 1048576 (1 MB) - reserved 26 byte AWS overhead

    /**
     * The batch of log events in a single PutLogEvents request cannot span more than 24 hours.
     *
     * @var int
     */
    const TIMESPAN_LIMIT = 86400000;

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
     * @var int|null
     */
    private $retention;

    /**
     * @var bool
     */
    private $initialized = false;

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
     * @var bool
     */
    private $createGroup;

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
     * @var int|null
     */
    private $earliestTimestamp = null;

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
     * @param int|null $retention Days to retain logs. Pass null for indefinite retention.
     * @param int $batchSize
     * @param array $tags
     * @param int $level
     * @param bool $bubble
     * @param bool $createGroup
     *
     * @throws \Exception
     */
    public function __construct(
        CloudWatchLogsClient $client,
        $group,
        $stream,
        ?int $retention = 14,
        $batchSize = 10000,
        array $tags = [],
        $level = Logger::DEBUG,
        $bubble = true,
        $createGroup = true
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
        $this->createGroup = $createGroup;

        parent::__construct($level, $bubble);
    }

    /**
     * {@inheritdoc}
     */
    protected function write(array $record): void
    {
        $records = $this->formatRecords($record);

        foreach ($records as $record) {
            if ($this->willMessageSizeExceedLimit($record) || $this->willMessageTimestampExceedLimit($record)) {
                $this->flushBuffer();
            }

            $this->addToBuffer($record);

            if (count($this->buffer) >= $this->batchSize) {
                $this->flushBuffer();
            }
        }
    }

    /**
     * @param array $record
     */
    private function addToBuffer(array $record): void
    {
        $this->currentDataAmount += $this->getMessageSize($record);

        $timestamp = $record['timestamp'];

        if (!$this->earliestTimestamp || $timestamp < $this->earliestTimestamp) {
            $this->earliestTimestamp = $timestamp;
        }

        $this->buffer[] = $record;
    }

    private function flushBuffer(): void
    {
        if (!empty($this->buffer)) {
            if (false === $this->initialized) {
                $this->initialize();
            }

            $this->send($this->buffer);

            // clear buffer
            $this->buffer = [];

            // clear the earliest timestamp
            $this->earliestTimestamp = null;

            // clear data amount
            $this->currentDataAmount = 0;
        }
    }

    /**
     * http://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_PutLogEvents.html
     *
     * @param array $record
     * @return int
     */
    private function getMessageSize($record): int
    {
        return strlen($record['message']) + 26;
    }

    /**
     * Determine whether the specified record's message size in addition to the
     * size of the current queued messages will exceed AWS CloudWatch's limit.
     *
     * @param array $record
     * @return bool
     */
    protected function willMessageSizeExceedLimit(array $record): bool
    {
        return $this->currentDataAmount + $this->getMessageSize($record) >= $this->dataAmountLimit;
    }

    /**
     * Determine whether the specified record's timestamp exceeds the 24 hour timespan limit
     * for all batched messages written in a single call to PutLogEvents.
     *
     * @param array $record
     * @return bool
     */
    protected function willMessageTimestampExceedLimit(array $record): bool
    {
        return $this->earliestTimestamp && $record['timestamp'] - $this->earliestTimestamp > self::TIMESPAN_LIMIT;
    }

    /**
     * Each log event can not be bigger than 1 MB.
     * https://docs.aws.amazon.com/AmazonCloudWatch/latest/logs/cloudwatch_limits_cwl.html
     *
     * @param array $entry
     * @return array
     */
    private function formatRecords(array $entry): array
    {
        $entries = str_split($entry['formatted'], self::EVENT_SIZE_LIMIT);
        $timestamp = $entry['datetime']->format('U.u') * 1000;
        $records = [];

        foreach ($entries as $entry) {
            $records[] = [
                'message' => $entry,
                'timestamp' => $timestamp
            ];
        }

        return $records;
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
     *
     * @throws \Aws\CloudWatchLogs\Exception\CloudWatchLogsException Thrown by putLogEvents on AWS-side errors
     *                                                               (e.g. IAM denial, persistent throttling).
     */
    private function send(array $entries): void
    {
        // AWS expects to receive entries in chronological order...
        usort($entries, static function (array $a, array $b) {
            if ($a['timestamp'] < $b['timestamp']) {
                return -1;
            } elseif ($a['timestamp'] > $b['timestamp']) {
                return 1;
            }

            return 0;
        });

        $data = [
            'logGroupName' => $this->group,
            'logStreamName' => $this->stream,
            'logEvents' => $entries
        ];

        $this->client->putLogEvents($data);
    }

    private function initializeGroup(): void
    {
        $createLogGroupArguments = ['logGroupName' => $this->group];

        if (!empty($this->tags)) {
            $createLogGroupArguments['tags'] = $this->tags;
        }

        $created = false;
        try {
            $this->client->createLogGroup($createLogGroupArguments);
            $created = true;
        } catch (\Aws\CloudWatchLogs\Exception\CloudWatchLogsException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceAlreadyExistsException') {
                throw $e;
            }
        }

        // Only set retention on freshly-created groups. Externally-managed retention
        // (Terraform, manual, infra-as-code) must not be silently overwritten on each init.
        if ($created && $this->retention !== null) {
            $this
                ->client
                ->putRetentionPolicy(
                    [
                        'logGroupName' => $this->group,
                        'retentionInDays' => $this->retention,
                    ]
                );
        }
    }

    private function initialize(): void
    {
        if ($this->createGroup) {
            $this->initializeGroup();
        }

        $this->initializeStream();
    }

    private function initializeStream(): void
    {
        try {
            $this
                ->client
                ->createLogStream(
                    [
                        'logGroupName' => $this->group,
                        'logStreamName' => $this->stream,
                    ]
                );
        } catch (\Aws\CloudWatchLogs\Exception\CloudWatchLogsException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceAlreadyExistsException') {
                throw $e;
            }
        }

        $this->initialized = true;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultFormatter(): FormatterInterface
    {
        return new LineFormatter("%channel%: %level_name%: %message% %context% %extra%", null, false, true);
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        $this->flushBuffer();
    }

    /**
     * Flush buffered records to CloudWatch immediately.
     *
     * Useful for long-lived workers (Laravel queues, Symfony messenger,
     * PHP-FPM with persistent state) that cannot rely on close() being called.
     */
    public function flush(): void
    {
        $this->flushBuffer();
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        $this->flush();
        parent::reset();
    }
}
