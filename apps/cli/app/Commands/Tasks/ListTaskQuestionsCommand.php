<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use DateTimeImmutable;
use Orbit\Sdk\Requests\Tasks\ListTaskQuestionsRequest;
use Orbit\Sdk\Responses\Tasks\TaskQuestionsResponse;

final class ListTaskQuestionsCommand extends TaskCommand
{
    /** @var list<string> */
    private const array CAUSES = ['brief_unclear', 'contract_gap', 'scope', 'environment', 'missed_contract'];

    /** @var list<string> */
    private const array STATUSES = ['open', 'escalated', 'answered', 'superseded'];

    private const int MAXIMUM_OFFSET_HOUR = 24;

    private const int MAXIMUM_OFFSET_MINUTE = 59;

    #[\Override]
    protected $signature = 'tasks:question:list
        {--project= : Only questions from this numeric Project ID}
        {--cause= : Only questions with this cause}
        {--status= : Only questions in this status}
        {--since= : Only questions asked on or after this ISO 8601 date or time}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List the questions agents asked, across tasks.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $projectId = $this->projectFilter();
        $cause = $projectId === false ? false : $this->optionalChoice('cause', 'Cause', 'cause', self::CAUSES);
        $status = $cause === false ? false : $this->optionalChoice('status', 'Status', 'status', self::STATUSES);
        $since = $status === false ? false : $this->sinceFilter();

        if ($projectId === false || $cause === false || $status === false || $since === false) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $questions = $this->sendWithProgress(
            $connector,
            new ListTaskQuestionsRequest($projectId, $cause, $status, $since),
            TaskQuestionsResponse::class,
            ['List questions', 'Loading questions', 'Loaded questions'],
        );

        if (! $questions instanceof TaskQuestionsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($questions->toArray());

            return self::SUCCESS;
        }

        if ($questions->questions === []) {
            $this->writeHumanMessage('No questions match.');
        }

        foreach ($questions->questions as $question) {
            $this->writeQuestion($question);
        }

        $this->writeHumanMessage("Request ID: {$questions->requestId}");

        return self::SUCCESS;
    }

    private function projectFilter(): int|false|null
    {
        $project = $this->option('project');

        if ($project === null) {
            return null;
        }

        $projectId = filter_var($project, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_int($projectId)) {
            $this->renderGatewayFailure('tasks.project_invalid', 'Project ID must be a positive integer.');

            return false;
        }

        return $projectId;
    }

    /** @param list<string> $choices */
    private function optionalChoice(string $option, string $label, string $code, array $choices): string|false|null
    {
        $value = $this->option($option);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || ! in_array($value, $choices, true)) {
            $this->renderGatewayFailure("tasks.{$code}_invalid", "{$label} must be one of ".implode(', ', $choices).'.');

            return false;
        }

        return $value;
    }

    private function sinceFilter(): string|false|null
    {
        $since = $this->option('since');

        if ($since === null) {
            return null;
        }

        $normalized = self::iso8601($since);

        if ($normalized === null) {
            $this->renderGatewayFailure('tasks.since_invalid', 'Since must be an ISO 8601 date or time.');

            return false;
        }

        return $normalized;
    }

    private static function iso8601(string $value): ?string
    {
        if (preg_match(
            '/\A(?<date>\d{4}-\d{2}-\d{2})(?:T(?<hour>\d{2})(?::(?<minute>\d{2})(?::(?<second>\d{2}))?)?(?:\.(?<fraction>\d{1,6}))?(?<zone>Z|[+-](?<offsetHour>\d{2}):(?<offsetMinute>\d{2})))?\z/',
            $value,
            $match,
        ) !== 1) {
            return null;
        }

        $date = (string) $match['date'];

        if (! self::exact($date, 'Y-m-d')) {
            return null;
        }

        $hour = (string) ($match['hour'] ?? '');

        if ($hour === '') {
            return $value;
        }

        $zone = (string) ($match['zone'] ?? '');

        if ($zone !== 'Z' && ! self::offset($match)) {
            return null;
        }

        $minute = (string) ($match['minute'] ?? '');
        $second = (string) ($match['second'] ?? '');
        $fraction = (string) ($match['fraction'] ?? '');

        if ((int) $hour > 23 || ($minute !== '' && (int) $minute > 59) || ($second !== '' && (int) $second > 59)) {
            return null;
        }

        if ($fraction !== '' && $second === '') {
            return self::expandFraction($date, (int) $hour, $minute === '' ? null : (int) $minute, $fraction, $zone);
        }

        $fraction = $fraction !== '' ? '.'.str_pad($fraction, 6, '0') : '';
        $checkedZone = $zone === 'Z' ? '+00:00' : $zone;
        $format = $fraction === '' ? 'Y-m-d\TH:i:sP' : 'Y-m-d\TH:i:s.uP';

        return self::exact(
            $date.'T'.$hour.':'.($minute === '' ? '00' : $minute).':'.($second === '' ? '00' : $second).$fraction.$checkedZone,
            $format,
        ) ? $value : null;
    }

    private static function expandFraction(string $date, int $hour, ?int $minute, string $fraction, string $zone): ?string
    {
        $scale = 1;

        for ($i = 0, $length = strlen($fraction); $i < $length; $i++) {
            $scale *= 10;
        }

        $micro = intdiv((int) $fraction * ($minute === null ? 3600 : 60) * 1_000_000, $scale);
        $total = ($minute ?? 0) * 60 + intdiv($micro, 1_000_000);
        $micros = $micro % 1_000_000;
        $outMinute = $total % 3600;
        $outHour = $hour + intdiv($total, 3600);
        $outSecond = $outMinute % 60;
        $outMinute = intdiv($outMinute, 60);
        $clock = sprintf('%02d:%02d:%02d', $outHour, $outMinute, $outSecond);

        if ($micros > 0) {
            $clock .= '.'.str_pad((string) $micros, 6, '0', STR_PAD_LEFT);
        }

        $checkedZone = $zone === 'Z' ? '+00:00' : $zone;
        $format = $micros > 0 ? 'Y-m-d\TH:i:s.uP' : 'Y-m-d\TH:i:sP';

        return $outHour <= 23 && self::exact($date.'T'.$clock.$checkedZone, $format) ? $date.'T'.$clock.$zone : null;
    }

    /** @param array<int|string, string> $match */
    private static function offset(array $match): bool
    {
        $hour = (string) ($match['offsetHour'] ?? '');
        $minute = (string) ($match['offsetMinute'] ?? '');

        return $hour !== ''
            && $minute !== ''
            && (int) $hour <= self::MAXIMUM_OFFSET_HOUR
            && (int) $minute <= self::MAXIMUM_OFFSET_MINUTE;
    }

    private static function exact(string $value, string $format): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $parsed !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }
}
