<?php

namespace Modules\Correspondence\Support;

use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\StreamReader;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** Make an unsigned source readable without rendering or changing attachment files. */
class SourcePdf
{
    public function prepare(string $content): string
    {
        $this->unsigned($content);
        try {
            $this->read($content);
        } catch (CrossReferenceException $e) {
            if ($e->getCode() === CrossReferenceException::COMPRESSED_XREF) {
                $content = $this->uncompress($content);
                $this->unsigned($content);
                try {
                    $this->read($content);
                } catch (\Exception $e) {
                    $this->reject($e);
                }
            } else {
                $this->reject($e);
            }
        } catch (\Exception $e) {
            $this->reject($e);
        }

        return $content;
    }

    private function unsigned(string $content): void
    {
        if (preg_match('/\/ByteRange\s*\[/', $content)) {
            throw ValidationException::withMessages(['pdf' => 'Asıl yazı imzasız olmalıdır. İmzalı ek belgeleri ayrı Ek dosyalar alanından yükleyin. Asıl yazı, doğrulama bilgileri eklendikten sonra imzalanır.']);
        }
    }

    private function read(string $content): void
    {
        (new Fpdi)->setSourceFile(StreamReader::createByString($content));
    }

    private function uncompress(string $content): string
    {
        $binary = (new ExecutableFinder)->find('qpdf');
        if ($binary === null) {
            throw ValidationException::withMessages(['pdf' => 'Bu standart PDF sıkıştırılmış nesneler içeriyor. Sunucudaki qpdf uyumluluk aracı kurulmalıdır.']);
        }
        $input = tempnam(sys_get_temp_dir(), 'letter-pdf-');
        $output = tempnam(sys_get_temp_dir(), 'letter-pdf-');
        if ($input === false || $output === false) {
            foreach ([$input, $output] as $path) {
                if (is_string($path)) {
                    unlink($path);
                }
            }
            throw new \RuntimeException('PDF için geçici dosya oluşturulamadı.');
        }
        try {
            file_put_contents($input, $content);
            $process = new Process([$binary, '--object-streams=disable', '--stream-data=preserve', $input, $output]);
            $process->setTimeout(30);
            $process->run();
            if (! in_array($process->getExitCode(), [0, 3], true) || filesize($output) === 0) {
                throw ValidationException::withMessages(['pdf' => 'PDF açılamadı. Dosya şifreli veya hasarlı olabilir; özgün PDF dosyasını yükleyin.']);
            }

            return file_get_contents($output);
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            throw ValidationException::withMessages(['pdf' => 'PDF okunurken süre sınırı aşıldı. Daha küçük bir asıl yazı PDF’si yükleyin; ekleri ayrı dosyalar olarak seçin.']);
        } finally {
            unlink($input);
            unlink($output);
        }
    }

    private function reject(\Exception $e): never
    {
        $message = $e instanceof CrossReferenceException && $e->getCode() === CrossReferenceException::ENCRYPTED
            ? 'Asıl PDF şifreli. Şifresiz kopyasını yükleyin; imzalı ekleri ayrı ekleyebilirsiniz.'
            : 'PDF yapısı okunamadı. Dosya hasarlı veya eksik olabilir; özgün PDF dosyasını yükleyin.';
        throw ValidationException::withMessages(['pdf' => $message]);
    }
}
