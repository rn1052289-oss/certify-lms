<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Certificate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 修了証 PDF のダウンロード Controller。認可は `CertificatePolicy::download` に委譲する。
 */
class CertificateDownloadController extends Controller
{
    public function __invoke(Certificate $certificate): StreamedResponse
    {
        $this->authorize('download', $certificate);

        abort_unless(preg_match('/\Acertificates\/[a-zA-Z0-9_-]+\.pdf\z/', $certificate->pdf_path) === 1, 404);

        $disk = Storage::disk('private');
        abort_unless($disk->exists($certificate->pdf_path), 404);

        return $disk->download($certificate->pdf_path, 'certificate-'.$certificate->id.'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
