<?php

declare(strict_types=1);

namespace Rector\ChangesReporting\Contract\Output;

use Rector\Core\Contract\Rector\RectorInterface;
use Rector\Core\ValueObject\ProcessResult;

interface OutputFormatterInterface
{
    public function getName(): string;

    public function report(ProcessResult $processResult): void;

    /**
     * @param RectorInterface[] $rectors
     */
    public function show(array $rectors): void;
}
