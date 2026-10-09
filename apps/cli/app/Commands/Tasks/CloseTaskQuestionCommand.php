<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Tasks\CloseTaskQuestionRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskQuestionsRequest;
use Orbit\Sdk\Responses\Tasks\TaskQuestionResponse;
use Orbit\Sdk\Responses\Tasks\TaskQuestionsResponse;

final class CloseTaskQuestionCommand extends TaskCommand
{
    public const int REASON_MAX = 2000;

    /** @var array<string, string> */
    private const array STATUSES = [
        'superseded' => 'superseded: later work or a resolution made the question moot',
        'answered' => 'answered: the reason is the answer',
    ];

    #[\Override]
    protected $signature = 'tasks:question:close
        {question? : Numeric question ID}
        {--status= : answered or superseded}
        {--reason= : Why the question is closed}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Close an open or escalated question as answered or superseded.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $questionId = $this->idArgument('question', 'Question', 'question');
        $status = $questionId === false ? false : $this->choiceInput($this->option('status'), 'Status', 'status', array_keys(self::STATUSES));
        $reason = $status === false ? false : $this->textInput($this->option('reason'), 'Reason', 'reason', self::REASON_MAX);

        if ($questionId === false || $status === false || $reason === false) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $questionId ??= $this->selectQuestion($connector);

        if ($questionId === null) {
            return self::FAILURE;
        }

        $status ??= $this->promptChoice('Status', self::STATUSES);
        $reason ??= $this->promptText('Reason', self::REASON_MAX, multiline: true);

        $question = $this->sendWithProgress(
            $connector,
            new CloseTaskQuestionRequest($questionId, $status, $reason),
            TaskQuestionResponse::class,
            ['Close question', 'Closing question', 'Closed question'],
        );

        if (! $question instanceof TaskQuestionResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($question->toArray());

            return self::SUCCESS;
        }

        $this->writeQuestion($question);
        $this->writeHumanMessage("Request ID: {$question->requestId}");

        return self::SUCCESS;
    }

    /** Offers the open and escalated questions, newest first. */
    private function selectQuestion(GatewayConnector $connector): ?int
    {
        $questions = $this->sendWithProgress(
            $connector,
            new ListTaskQuestionsRequest,
            TaskQuestionsResponse::class,
            ['List questions', 'Loading questions', 'Loaded questions'],
        );

        if (! $questions instanceof TaskQuestionsResponse) {
            return null;
        }

        $choices = [];

        foreach ($questions->questions as $question) {
            if (in_array($question->status, ['open', 'escalated'], true)) {
                $choices["#{$question->id}"] = "#{$question->id} · {$question->reference()} · {$question->status} · {$question->question}";
            }
        }

        if ($choices === []) {
            $this->renderGatewayFailure('tasks.question_required', 'No open or escalated question to close.');

            return null;
        }

        return (int) ltrim($this->promptChoice('Question', $choices), '#');
    }
}
