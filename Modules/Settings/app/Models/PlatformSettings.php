<?php

namespace Modules\Settings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Platform Settings Model
 *
 * Stores platform-level configuration settings.
 * Supports encryption for sensitive values and JSON storage for complex data.
 */
class PlatformSettings extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'platform_settings';

    /**
     * The primary key type is UUID (string).
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'group',
        'key',
        'value',
        'is_encrypted',
        'updated_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
            'is_encrypted' => 'boolean',
        ];
    }

    /**
     * Get the decrypted/unwrapped value.
     *
     * @return mixed
     */
    public function getValue(): mixed
    {
        $value = $this->value;

        // If encrypted, decrypt it
        if ($this->is_encrypted && is_array($value) && isset($value['encrypted'])) {
            try {
                return json_decode(Crypt::decryptString($value['encrypted']), true);
            } catch (\Throwable) {
                return null;
            }
        }

        // If wrapped in 'raw' array, unwrap it
        if (is_array($value) && array_key_exists('raw', $value)) {
            return $value['raw'];
        }

        // Return as-is (already JSON decoded by cast)
        return $value;
    }

    /**
     * Set the value, optionally encrypting it.
     *
     * @param  mixed  $value
     * @param  bool  $encrypt
     * @return void
     */
    public function setValue(mixed $value, bool $encrypt = false): void
    {
        if ($encrypt) {
            $this->value = ['encrypted' => Crypt::encryptString(json_encode($value))];
            $this->is_encrypted = true;
        } elseif (! is_array($value) && ! is_object($value)) {
            // Wrap simple values in 'raw' array for consistency
            $this->value = ['raw' => $value];
            $this->is_encrypted = false;
        } else {
            // Store complex values as-is (will be JSON encoded by cast)
            $this->value = $value;
            $this->is_encrypted = false;
        }
    }
}
