<?php

namespace Modules\Correspondence\Support;

use Illuminate\Validation\ValidationException;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfParser\Type\PdfArray;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfHexString;
use setasign\Fpdi\PdfParser\Type\PdfName;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;
use setasign\Fpdi\PdfParser\Type\PdfType;

class SignedLetterPdf
{
    /** Check content integrity; certificate trust/revocation is checked by the signing application. */
    public function validate(string $pdf, string $prepared, bool $placeholder = false): void
    {
        if (! $placeholder && ! str_starts_with($pdf, $prepared)) {
            $this->reject('İmza için indirilen PDF’i değiştirmeden, artımlı PDF imzası ile imzalayıp yükleyin.');
        }
        foreach ($this->signatures($pdf) as [$range, $signatureHex]) {
            [$zero, $start, $end, $length] = $range;
            if ($zero !== 0 || $start < 0 || $end < 0 || $length < 0) {
                continue;
            }
            if ((! $placeholder && $start < strlen($prepared)) || $end <= $start || $end + $length !== strlen($pdf)) {
                continue;
            }
            if ($placeholder && (strlen($pdf) !== strlen($prepared) || substr($pdf, 0, $start) !== substr($prepared, 0, $start) || substr($pdf, $end) !== substr($prepared, $end))) {
                continue;
            }
            $gap = substr($pdf, $start, $end - $start);
            if (! preg_match('/^<([a-fA-F0-9\s]+)>$/D', $gap, $contents)) {
                continue;
            }
            $hex = preg_replace('/\s+/', '', $contents[1]);
            if (strlen($hex) % 2 !== 0 || strtolower($hex) !== strtolower(preg_replace('/\s+/', '', $signatureHex))) {
                continue;
            }
            $data = tmpfile();
            $signature = tmpfile();
            $certificates = tmpfile();
            if ($data === false || $signature === false || $certificates === false) {
                foreach ([$data, $signature, $certificates] as $file) {
                    if (is_resource($file)) {
                        fclose($file);
                    }
                }
                throw new \RuntimeException('İmza kontrolü için geçici dosya oluşturulamadı.');
            }
            try {
                fwrite($data, substr($pdf, 0, $start).substr($pdf, $end, $length));
                fwrite($signature, hex2bin($hex));
                $valid = @openssl_cms_verify(
                    stream_get_meta_data($data)['uri'],
                    OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED | OPENSSL_CMS_NOVERIFY,
                    stream_get_meta_data($certificates)['uri'], [], null, null, null,
                    stream_get_meta_data($signature)['uri'], OPENSSL_ENCODING_DER,
                );
                rewind($certificates);
                if ($valid && str_contains(stream_get_contents($certificates), '-----BEGIN CERTIFICATE-----')) {
                    return;
                }
            } finally {
                fclose($data);
                fclose($signature);
                fclose($certificates);
            }
        }
        $this->reject('PDF’in tüm içeriğini kapsayan geçerli bir elektronik imza bulunamadı.');
    }

    /** Read actual signature fields, so a ByteRange hidden in a comment is not accepted. */
    private function signatures(string $pdf): array
    {
        $parser = new PdfParser(StreamReader::createByString($pdf));
        try {
            $form = PdfType::resolve(PdfDictionary::get($parser->getCatalog(), 'AcroForm'), $parser);
            $fields = PdfType::resolve(PdfDictionary::get($form, 'Fields'), $parser);
            if (! $fields instanceof PdfArray) {
                return [];
            }
            $queue = $fields->value;
            $signatures = [];
            // Bound traversal of malformed or cyclic field trees.
            for ($visited = 0; $queue !== [] && $visited < 1000; $visited++) {
                $field = PdfType::resolve(array_shift($queue), $parser);
                $kids = PdfType::resolve(PdfDictionary::get($field, 'Kids'), $parser);
                if ($kids instanceof PdfArray) {
                    array_push($queue, ...$kids->value);
                }
                $type = PdfType::resolve(PdfDictionary::get($field, 'FT'), $parser);
                if (! $type instanceof PdfName || $type->value !== 'Sig') {
                    continue;
                }
                $signature = PdfType::resolve(PdfDictionary::get($field, 'V'), $parser);
                if (! $signature instanceof PdfDictionary) {
                    continue;
                }
                $range = PdfType::resolve(PdfDictionary::get($signature, 'ByteRange'), $parser);
                $contents = PdfType::resolve(PdfDictionary::get($signature, 'Contents'), $parser);
                if (! $range instanceof PdfArray || count($range->value) !== 4 || ! $contents instanceof PdfHexString) {
                    continue;
                }
                $numbers = [];
                foreach ($range->value as $value) {
                    $value = PdfType::resolve($value, $parser);
                    if (! $value instanceof PdfNumeric || ! is_int($value->value)) {
                        continue 2;
                    }
                    $numbers[] = $value->value;
                }
                $signatures[] = [$numbers, $contents->value];
            }

            return array_reverse($signatures);
        } catch (\Exception $e) {
            return [];
        } finally {
            $parser->cleanUp();
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['signed_pdf' => $message]);
    }
}
