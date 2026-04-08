<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Wupex_Crypto {

    private const CIPHER = 'aes-256-cbc';

    /**
     * Encrypt a plain-text string using AES-256-CBC.
     * Returns base64-encoded IV + ciphertext, separated by ':'.
     */
    public static function encrypt( string $plaintext ): string {
        $key = self::get_key();
        $iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER ) );

        $ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
        if ( $ciphertext === false ) {
            throw new RuntimeException( 'Wupex: encryption failed.' );
        }

        return base64_encode( $iv ) . ':' . base64_encode( $ciphertext );
    }

    /**
     * Decrypt a value previously encrypted with self::encrypt().
     */
    public static function decrypt( string $encrypted ): string {
        $key   = self::get_key();
        $parts = explode( ':', $encrypted, 2 );

        if ( count( $parts ) !== 2 ) {
            throw new InvalidArgumentException( 'Wupex: invalid encrypted format.' );
        }

        $iv         = base64_decode( $parts[0] );
        $ciphertext = base64_decode( $parts[1] );

        $plaintext = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
        if ( $plaintext === false ) {
            throw new RuntimeException( 'Wupex: decryption failed.' );
        }

        return $plaintext;
    }

    /**
     * Generate a cryptographically random 32-character hex token.
     */
    public static function generate_token(): string {
        return bin2hex( random_bytes( 16 ) );
    }

    /**
     * Derive a 32-byte key from the stored encryption key setting.
     */
    private static function get_key(): string {
        $raw = get_option( 'wupex_encryption_key', '' );
        if ( empty( $raw ) ) {
            throw new RuntimeException( 'Wupex: encryption key not configured.' );
        }
        // Use SHA-256 to normalise the key to exactly 32 bytes
        return hash( 'sha256', $raw, true );
    }
}
