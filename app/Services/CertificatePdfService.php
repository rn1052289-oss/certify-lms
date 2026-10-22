<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certificate;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class CertificatePdfService
{
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
