<?php

declare(strict_types=1);

namespace Tests\Support\Mail;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/** 外部SMTPへ接続せず、worker内の送信失敗を再現する。 */
final class FailingTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        throw new \RuntimeException('mail unavailable');
    }

    public function __toString(): string
    {
        return 'queue-failing';
    }
}
