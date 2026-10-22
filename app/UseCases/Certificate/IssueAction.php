<?php

declare(strict_types=1);

namespace App\UseCases\Certificate;

use App\Enums\EnrollmentStatus;
use App\Exceptions\Certification\CertificateAlreadyIssuedException;
use App\Exceptions\Certification\CertificateGenerationException;
use App\Exceptions\Certification\EnrollmentNotPassedException;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Services\CertificatePdfService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 修了証を発行するユースケース。受講生自己発火型の修了処理 `\App\UseCases\Enrollment\ReceiveCertificateAction` から呼び出される。
 *
 * 業務分岐:
 * - Enrollment が `status=passed` + `passed_at != null` でない: EnrollmentNotPassedException（409）
 * - 同一 Enrollment に対する二重呼出: CertificateAlreadyIssuedException（409、受講登録行をロックして検出）
 *
 * 日本語PDFをprivate保存してからレコードを作り、失敗時は今回のファイルだけを削除する。
 */
final class IssueAction
{
    public function __construct(
        private readonly CertificatePdfService $pdf,
    ) {}

    /**
     * @throws EnrollmentNotPassedException 受講登録が修了状態ではない
     * @throws CertificateAlreadyIssuedException 同一 Enrollment で修了証が既発行
     * @throws CertificateGenerationException PDF生成・保存またはレコードの作成に失敗
     */
    public function __invoke(Enrollment $enrollment, ?CarbonInterface $issuedAt = null): Certificate
    {
        $path = null;

        try {
            return DB::transaction(function () use ($enrollment, $issuedAt, &$path) {
                // 初回発行でも存在する受講登録行をロックし、状態確認と二重発行判定を直列化する。
                $locked = Enrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
                if ($locked->status !== EnrollmentStatus::Passed || $locked->passed_at === null) {
                    throw new EnrollmentNotPassedException;
                }

                if (Certificate::query()->where('enrollment_id', $locked->id)->exists()) {
                    throw new CertificateAlreadyIssuedException;
                }

                $path = 'certificates/'.Str::ulid().'.pdf';
                $certificate = Certificate::make([
                    'user_id' => $locked->user_id,
                    'enrollment_id' => $locked->id,
                    'certification_id' => $locked->certification_id,
                    'pdf_path' => $path,
                    'issued_at' => $issuedAt ?? now(),
                ]);

                $contents = $this->pdf->generate($certificate);
                if (! Storage::disk('private')->put($path, $contents)) {
                    throw new RuntimeException('Certificate PDF could not be stored.');
                }
                if (! $certificate->save()) {
                    throw new RuntimeException('Certificate record could not be saved.');
                }

                return $certificate;
            });
        } catch (CertificateAlreadyIssuedException|EnrollmentNotPassedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($path !== null) {
                Storage::disk('private')->delete($path);
            }

            throw new CertificateGenerationException($e);
        }
    }
}
