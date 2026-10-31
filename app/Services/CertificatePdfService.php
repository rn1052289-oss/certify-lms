<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certificate;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * 修了証 PDF の生成を提供する Service。
 *
 * `certificates.pdf` の Blade を mPDF(A4 / 日本語フォント sun-exta)で PDF に変換する。ファイルには保存せず、バイナリ文字列を返す。
 */
class CertificatePdfService
{
    /**
     * 修了証 1 件分の PDF を生成し、バイナリ文字列で返す。
     */
    public function generate(Certificate $certificate): string
    {
        $certificate->loadMissing(['user', 'certification']);

        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'sun-exta',
            'autoLangToFont' => true,
            'tempDir' => storage_path('framework/cache/mpdf'),
        ]);
        $pdf->SetTitle('修了証');
        $pdf->SetAuthor('Certify LMS');
        $pdf->WriteHTML(view('certificates.pdf', ['certificate' => $certificate])->render());

        return $pdf->Output('', Destination::STRING_RETURN);
    }
}
