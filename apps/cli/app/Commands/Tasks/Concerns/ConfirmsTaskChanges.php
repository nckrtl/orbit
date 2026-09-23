<?php

declare(strict_types=1);

namespace App\Commands\Tasks\Concerns;

use Closure;

/** Default-No consent for the tasks commands that remove or delete, which all offer --yes. */
trait ConfirmsTaskChanges
{
    /**
     * Asks for default-No consent unless --yes supplies it. The label is built only when the
     * command may prompt, so a refused noninteractive call sends no read.
     *
     * @param  Closure(): ?string  $label  Returns null when the read that names the subject failed.
     */
    protected function consent(Closure $label, string $declined): bool
    {
        if ($this->option('yes') === true || ! $this->consoleMode()->mayPrompt) {
            return $this->confirmAction('', $declined);
        }

        $question = $label();

        return $question !== null && $this->confirmAction($question, $declined);
    }
}
