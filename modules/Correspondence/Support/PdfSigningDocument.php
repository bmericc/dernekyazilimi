<?php

namespace Modules\Correspondence\Support;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfReader\PageBoundaries;

/** An invisible PDF signature field with a fixed-size CMS placeholder. */
class PdfSigningDocument extends Fpdi
{
    public const CAPACITY = 32768;

    private int $field;

    public static function prepare(string $source): string
    {
        $pdf = new self;
        $pdf->SetAutoPageBreak(false);
        $count = $pdf->setSourceFile(StreamReader::createByString($source));
        for ($i = 1; $i <= $count; $i++) {
            $template = $pdf->importPage($i, PageBoundaries::CROP_BOX, true, true);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);
        }
        $content = $pdf->Output('S');
        $start = strpos($content, '/Contents <') + strlen('/Contents ');
        $end = $start + self::CAPACITY * 2 + 2;
        $range = sprintf('/ByteRange [0 %010d %010d %010d]', $start, $end, strlen($content) - $end);

        return str_replace('/ByteRange [0 0000000000 0000000000 0000000000]', $range, $content);
    }

    public static function bytes(string $pdf): string
    {
        preg_match('/\/ByteRange \[0 (\d+) (\d+) (\d+)\]/', $pdf, $range);

        return substr($pdf, 0, (int) $range[1]).substr($pdf, (int) $range[2], (int) $range[3]);
    }

    public static function complete(string $pdf, string $cms): string
    {
        // The existing signing application returns encapsulated CMS. Removing
        // eContent makes it detached; the signed attributes remain unchanged.
        $outer = self::children($cms);
        if (count($outer) !== 2 || ord($outer[1][0]) !== 0xa0) {
            throw new PackageException('İmza CMS biçiminde değil.');
        }
        $info = self::children($outer[1]);
        if (count($info) !== 1) {
            throw new PackageException('İmza CMS biçiminde değil.');
        }
        $signed = self::children($info[0]);
        if (count($signed) < 4) {
            throw new PackageException('İmza CMS biçiminde değil.');
        }
        $encapsulated = self::children($signed[2]);
        if (count($encapsulated) < 1) {
            throw new PackageException('İmza CMS biçiminde değil.');
        }
        $signed[2] = self::der(0x30, $encapsulated[0]);
        $cms = self::der(0x30, $outer[0].self::der(0xa0, self::der(0x30, implode('', $signed))));
        if (strlen($cms) > self::CAPACITY) {
            throw new PackageException('PDF imzası ayrılan alana sığmıyor.');
        }
        preg_match('/\/ByteRange \[0 (\d+) (\d+) (\d+)\]/', $pdf, $range);

        return substr_replace($pdf, str_pad(bin2hex($cms), self::CAPACITY * 2, '0'), (int) $range[1] + 1, self::CAPACITY * 2);
    }

    private static function children(string $der): array
    {
        $offset = 1;
        $length = self::length($der, $offset);
        $limit = $offset + $length;
        if ($limit !== strlen($der)) {
            throw new PackageException('İmza CMS biçiminde değil.');
        }
        $children = [];
        while ($offset < $limit) {
            $start = $offset++;
            $size = self::length($der, $offset);
            $offset += $size;
            if ($offset > $limit) {
                throw new PackageException('İmza CMS biçiminde değil.');
            }
            $children[] = substr($der, $start, $offset - $start);
        }

        return $children;
    }

    private static function length(string $der, int &$offset): int
    {
        if (! isset($der[$offset])) {
            throw new PackageException('İmza CMS biçiminde değil.');
        }
        $length = ord($der[$offset++]);
        if ($length < 128) {
            return $length;
        }
        $count = $length & 127;
        if ($count < 1 || $count > 4 || $offset + $count > strlen($der)) {
            throw new PackageException('İmza CMS biçiminde değil.');
        }
        $length = 0;
        while ($count--) {
            $length = ($length << 8) | ord($der[$offset++]);
        }

        return $length;
    }

    private static function der(int $tag, string $content): string
    {
        $length = strlen($content);
        $bytes = '';
        if ($length < 128) {
            $bytes = chr($length);
        } else {
            while ($length > 0) {
                $bytes = chr($length & 255).$bytes;
                $length >>= 8;
            }
            $bytes = chr(128 + strlen($bytes)).$bytes;
        }

        return chr($tag).$bytes.$content;
    }

    protected function _putresources()
    {
        parent::_putresources();
        $this->_newobj();
        $signature = $this->n;
        $this->_put('<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached');
        $this->_put('/ByteRange [0 0000000000 0000000000 0000000000]');
        $this->_put('/Contents <'.str_repeat('0', self::CAPACITY * 2).'> >>');
        $this->_put('endobj');
        $this->_newobj();
        $this->field = $this->n;
        $this->_put('<< /Type /Annot /Subtype /Widget /FT /Sig /T (DocumentSignature) /Rect [0 0 0 0] /F 132 /V '.$signature.' 0 R >>');
        $this->_put('endobj');
    }

    protected function _putcatalog()
    {
        parent::_putcatalog();
        $this->_put('/AcroForm << /SigFlags 3 /Fields ['.$this->field.' 0 R] >>');
    }
}
