<?php

declare(strict_types=1);

namespace App\Exceptions\Certification;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class CertificateGenerationException extends HttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(500, '修了証の生成・保存に失敗しました。時間をおいて再度お試しください。', $previous);
    }
}
