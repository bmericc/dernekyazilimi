<?php

namespace Modules\Correspondence\Support;

use Illuminate\Support\Facades\Storage;
use Modules\Correspondence\Models\Letter;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** A viewing copy with PDF attachments; originals and EYP inputs stay intact. */
class LetterPreview
{
    public function __construct(private LetterPdf $pdf)
    {
    }

    public function render(Letter $letter): string
    {
        $main = $this->pdf->render($letter);
        $attachments = $letter->attachments()->where('mime', 'application/pdf')->get();
        if ($attachments->isEmpty()) {
            return $main;
        }
        $binary = (new ExecutableFinder)->find('qpdf');
        abort_if($binary === null, 503, 'PDF eklerini görüntülemek için qpdf gereklidir.');
        $directory = sys_get_temp_dir().'/letter-preview-'.bin2hex(random_bytes(12));
        if (! mkdir($directory, 0700)) {
            throw new \RuntimeException('PDF önizlemesi için geçici dizin oluşturulamadı.');
        }
        $files = [];
        try {
            $mainPath = $directory.'/main.pdf';
            file_put_contents($mainPath, $main);
            $files[] = $mainPath;
            $arguments = [$binary, '--empty', '--pages', $mainPath, '1-z'];
            foreach ($attachments as $attachment) {
                abort_unless($attachment->hasFile() && Storage::disk('local')->exists($attachment->path), 404, 'Ek dosyası bulunamadı.');
                $path = $directory.'/attachment-'.$attachment->id.'.pdf';
                file_put_contents($path, Storage::disk('local')->get($attachment->path));
                $files[] = $path;
                array_push($arguments, $path, '1-z');
            }
            $output = $directory.'/combined.pdf';
            $files[] = $output;
            array_push($arguments, '--', $output);
            $process = new Process($arguments);
            $process->setTimeout(60);
            $process->run();
            abort_unless(in_array($process->getExitCode(), [0, 3], true) && is_file($output), 422, 'Ek PDF’lerden biri birleştirilemedi. Asıl dosyaları ayrı ayrı indirebilirsiniz.');

            return file_get_contents($output);
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }
}
