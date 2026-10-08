<?php

namespace Modules\Correspondence\Support;

use App\Support\Organization;
use BahriCanli\EYazisma\Dosya;
use BahriCanli\EYazisma\Enums\DagitimTuru;
use BahriCanli\EYazisma\Exceptions\EYazismaException;
use BahriCanli\EYazisma\Guid;
use BahriCanli\EYazisma\Model\GercekSahis;
use BahriCanli\EYazisma\Model\IletisimBilgisi;
use BahriCanli\EYazisma\Model\Ilgi;
use BahriCanli\EYazisma\Model\Imza;
use BahriCanli\EYazisma\Model\Kisi;
use BahriCanli\EYazisma\Model\KurumKurulus;
use BahriCanli\EYazisma\Model\NihaiUstveri;
use BahriCanli\EYazisma\Model\Sdp;
use BahriCanli\EYazisma\Model\Tanimlayici;
use BahriCanli\EYazisma\Model\Taraf;
use BahriCanli\EYazisma\Model\TuzelSahis;
use BahriCanli\EYazisma\Paket;
use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;
use Modules\Correspondence\Models\Letter;
use Modules\Correspondence\Models\LetterRecipient;

/**
 * The e-Yazışma package (.eyp) of a numbered letter, kept on the private disk.
 *
 * The package is built from the letter, then completed in two steps with
 * signatures made elsewhere: the electronic signature over the package digest
 * and the electronic seal over the final digest.
 */
class LetterPackage
{
    public function __construct(
        private Organization $organization,
        private CorrespondenceSettings $settings,
        private LetterPdf $pdf,
    ) {
    }

    public function create(Letter $letter): Paket
    {
        if (! $letter->isNumbered()) {
            throw new PackageException('Paket yalnız sayı verilmiş yazı için oluşturulabilir.');
        }

        if (! $this->settings->identifier()) {
            throw new PackageException('Paket için kurum ayarlarında MERSİS numarası tanımlanmalıdır.');
        }

        $letter->loadMissing(['recipients', 'attachments']);

        $builder = Paket::yeni()
            ->belgeId($letter->document_id)
            ->konu($letter->subject)
            ->ozId((string) $letter->id, 'ID')
            ->olusturan($this->creator())
            ->dogrulamaAdresi(route('correspondence.verify'))
            ->ustYazi(Dosya::icerikten($this->pdf->render($letter), $this->pdf->filename($letter), 'application/pdf'));

        foreach ($letter->recipients as $recipient) {
            $builder->dagitim($this->party($recipient), DagitimTuru::from($recipient->delivery));
        }

        foreach (array_values($letter->references ?? []) as $index => $reference) {
            $builder->ilgi(new Ilgi(Guid::uret(), chr(ord('a') + $index), ad: $reference));
        }

        foreach ($letter->attachments as $attachment) {
            $attachment->hasFile()
                ? $builder->ek(Dosya::icerikten(Storage::disk('local')->get($attachment->path), $attachment->original_name, $attachment->mime), ad: $attachment->name)
                : $builder->fizikselEk($attachment->name);
        }

        if ($letter->file_code) {
            $builder->sdp(new Sdp($letter->file_code, $letter->file_name ?: $letter->file_code));
        }

        try {
            $package = $builder->olustur();
        } catch (EYazismaException $e) {
            throw new PackageException($e->getMessage(), previous: $e);
        }

        $this->store($letter, $package);

        return $package;
    }

    public function open(Letter $letter): ?Paket
    {
        if (! $letter->package_path || ! Storage::disk('local')->exists($letter->package_path)) {
            return null;
        }

        return Paket::icerikten(Storage::disk('local')->get($letter->package_path));
    }

    /**
     * Add the signature made over the package digest.
     */
    public function sign(Letter $letter, string $signature): Paket
    {
        $package = $this->open($letter) ?? throw new PackageException('Yazının paketi yok.');

        try {
            $package->imzaEkle($signature, new NihaiUstveri(
                DateTimeImmutable::createFromInterface($letter->approved_at ?? $letter->document_date),
                $letter->document_no,
                array_map(fn (array $signer) => new Imza(new GercekSahis(
                    new Kisi($signer['first_name'], $signer['last_name']),
                    gorev: $signer['title'] ?? null,
                )), $letter->signers ?? []),
            ));
        } catch (EYazismaException $e) {
            throw new PackageException($e->getMessage(), previous: $e);
        }

        $this->store($letter, $package);

        return $package;
    }

    /**
     * Add the seal made over the final digest; the package is complete.
     */
    public function seal(Letter $letter, string $seal): Paket
    {
        $package = $this->open($letter) ?? throw new PackageException('Yazının paketi yok.');

        try {
            $package->muhurEkle($seal);
        } catch (EYazismaException $e) {
            throw new PackageException($e->getMessage(), previous: $e);
        }

        $this->store($letter, $package);

        return $package;
    }

    public function delete(Letter $letter): void
    {
        if ($letter->package_path) {
            Storage::disk('local')->delete($letter->package_path);
            $letter->forceFill(['package_path' => null])->save();
        }
    }

    private function store(Letter $letter, Paket $package): void
    {
        $path = $letter->directory().'/'.$package->dosyaAdi();
        Storage::disk('local')->put($path, $package->icerik());
        $letter->forceFill(['package_path' => $path])->save();
    }

    private function creator(): TuzelSahis
    {
        $contact = array_filter([
            'telefon' => $this->organization->get('phone'),
            'ePosta' => $this->organization->get('contact_email'),
            'webAdresi' => $this->organization->get('website_url'),
            'adres' => $this->organization->get('address'),
        ]);

        return new TuzelSahis(
            new Tanimlayici($this->settings->identifier(), $this->settings->identifierScheme()),
            $this->organization->name(),
            $contact ? new IletisimBilgisi(...$contact) : null,
        );
    }

    private function party(LetterRecipient $recipient): Taraf
    {
        $contact = $recipient->address ? new IletisimBilgisi(adres: $recipient->address) : null;

        if ($recipient->kind === LetterRecipient::PERSON) {
            [$first, $last] = self::splitName($recipient->name);

            return new GercekSahis(new Kisi($first, $last), $recipient->identifier ?: null, iletisimBilgisi: $contact);
        }

        if (! $recipient->identifier) {
            throw new PackageException("Paket için alıcının ".LetterRecipient::IDENTIFIERS[$recipient->kind]." değeri gerekir: {$recipient->name}");
        }

        return $recipient->kind === LetterRecipient::INSTITUTION
            ? new KurumKurulus($recipient->identifier, $recipient->name, $contact)
            : new TuzelSahis(new Tanimlayici($recipient->identifier, 'MERSIS'), $recipient->name, $contact);
    }

    /**
     * @return array{0: string, 1: string} first names and the last name
     */
    private static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name));
        $last = count($parts) > 1 ? array_pop($parts) : '';

        return [implode(' ', $parts), $last];
    }
}
