<?php

namespace Modules\Correspondence\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A single-use link for the signing application: whoever holds it may fetch
 * one digest of one letter and return its signature, until it expires.
 */
class SigningSession extends Model
{
    public const SIGNATURE = 'signature';

    public const SEAL = 'seal';

    public const STEPS = [
        self::SIGNATURE => 'Elektronik imza',
        self::SEAL => 'Elektronik mühür',
    ];

    /** Minutes a link stays valid. */
    public const LIFETIME = 15;

    /** Where the signing application listens on the signer's computer. */
    public const APPLICATION = 'http://127.0.0.1:51515/';

    /**
     * Its HTTPS address, there once the application has made and registered
     * a certificate for the computer; some browsers let an HTTPS page reach
     * only this one.
     */
    public const APPLICATION_SECURE = 'https://127.0.0.1:51516/';

    protected $table = 'correspondence_signing_sessions';

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /**
     * @return array{0: self, 1: string} the session and the token to put in the link
     */
    public static function issue(Letter $letter, string $step, ?User $user): array
    {
        // A new link replaces the ones still open for the letter.
        self::where('letter_id', $letter->id)->whereNull('used_at')->delete();

        $token = Str::random(64);
        $session = new self;
        $session->forceFill([
            'letter_id' => $letter->id,
            'step' => $step,
            'token_hash' => hash('sha256', $token),
            'created_by' => $user?->id,
            'expires_at' => now()->addMinutes(self::LIFETIME),
        ])->save();

        return [$session, $token];
    }

    /**
     * Address that hands a signing link to the application on the signer's computer.
     */
    public static function applicationUrl(string $link): string
    {
        return self::APPLICATION.'?link='.rawurlencode($link);
    }

    public static function findUsable(string $token): ?self
    {
        return self::where('token_hash', hash('sha256', $token))->whereNull('used_at')->where('expires_at', '>', now())->first();
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(Letter::class);
    }
}
