<?php
defined('ABSPATH') || exit;

/**
 * Cryptography utilities for TD Booking
 * - Envelope encryption using libsodium XChaCha20-Poly1305 (ietf)
 * - Deterministic HMAC indexes for exact-match lookups (e.g., email/phone)
 *
 * Expected wp-config.php constants (outside of this plugin):
 *   define('TD_BKG_KMS_ACTIVE_KID', 'v1');
 *   define('TD_BKG_KMS_KEY_V1', '<base64 32-byte key>');
 *   define('TD_BKG_HMAC_KEY_V1', '<base64 32-byte key>');
 */

function td_bkg_crypto_available() {
    if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
        return false;
    }
    $keys = td_bkg_crypto_keys();
    if (empty($keys)) return false;
    $kid = td_bkg_crypto_active_kid();
    // If no explicit active kid, consider crypto available if any key exists
    if (!$kid) return true;
    return isset($keys[$kid]) && !empty($keys[$kid]);
}

function td_bkg_crypto_active_kid() {
    // Preferred: booking-specific active KID
    if (defined('TD_BKG_KMS_ACTIVE_KID') && is_string(TD_BKG_KMS_ACTIVE_KID) && TD_BKG_KMS_ACTIVE_KID !== '') {
        return TD_BKG_KMS_ACTIVE_KID;
    }
    // Fallback: shared active KID from sibling plugin(s)
    if (defined('TD_KMS_ACTIVE_KID') && is_string(TD_KMS_ACTIVE_KID) && TD_KMS_ACTIVE_KID !== '') {
        return TD_KMS_ACTIVE_KID;
    }
    // If only one key is present, infer its KID
    $keys = td_bkg_crypto_keys();
    if (count($keys) === 1) {
        $kids = array_keys($keys);
        return $kids[0];
    }
    // No explicit active KID and multiple keys exist
    return null;
}

function td_bkg_crypto_keys() {
    // Collect KMS keys from multiple naming schemes for compatibility
    // Patterns supported: TD_BKG_KMS_KEY_V{N}, TD_KMS_KEY_V{N}, TD_PII_ENC_KEY_V{N}
    $keys = [];
    $consts = get_defined_constants(true);
    $userConsts = isset($consts['user']) ? $consts['user'] : [];
    $decode = function($value) {
        if (!is_string($value) || $value === '') return false;
        $v = trim($value);
        if (stripos($v, 'base64:') === 0) {
            return base64_decode(substr($v, 7), true);
        }
        // Accept raw base64 value too
        $b64 = base64_decode($v, true);
        if ($b64 !== false) return $b64;
        // Optionally accept hex: prefix
        if (stripos($v, 'hex:') === 0) {
            $hex = substr($v, 4);
            return ctype_xdigit($hex) ? hex2bin($hex) : false;
        }
        // If looks like hex
        if (ctype_xdigit($v) && (strlen($v) % 2) === 0) {
            return hex2bin($v);
        }
        return false;
    };
    foreach ($userConsts as $name => $value) {
        if (!is_string($value) || $value === '') continue;
        if (preg_match('/^(TD_BKG_KMS_KEY_V|TD_KMS_KEY_V|TD_PII_ENC_KEY_V)(\d+)$/', $name, $m)) {
            $kid = 'v' . $m[2];
            $bin = $decode($value);
            if ($bin !== false) {
                $keys[$kid] = $bin;
            }
        }
    }
    return array_filter($keys);
}

function td_bkg_hmac_key() {
    $consts = get_defined_constants(true);
    $userConsts = isset($consts['user']) ? $consts['user'] : [];
    $activeKid = td_bkg_crypto_active_kid() ?: 'v1';
    $candidates = [];
    $decode = function($value) {
        if (!is_string($value) || $value === '') return false;
        $v = trim($value);
        if (stripos($v, 'base64:') === 0) {
            return base64_decode(substr($v, 7), true);
        }
        $b64 = base64_decode($v, true);
        if ($b64 !== false) return $b64;
        if (stripos($v, 'hex:') === 0) {
            $hex = substr($v, 4);
            return ctype_xdigit($hex) ? hex2bin($hex) : false;
        }
        if (ctype_xdigit($v) && (strlen($v) % 2) === 0) {
            return hex2bin($v);
        }
        return false;
    };
    foreach ($userConsts as $name => $value) {
        if (!is_string($value) || $value === '') continue;
        // Two families: TD_BKG_HMAC_KEY_V{N} and TD_PII_IDX_KEY_V{N}
        if (preg_match('/^(TD_BKG_HMAC_KEY_V|TD_PII_IDX_KEY_V)(\d+)$/', $name, $m)) {
            $kid = 'v' . $m[2];
            $bin = $decode($value);
            if ($bin !== false) {
                $candidates[$kid] = $bin;
            }
        }
    }
    if (isset($candidates[$activeKid])) return $candidates[$activeKid];
    // Fallback to any available
    return reset($candidates) ?: null;
}

/**
 * Compute deterministic HMAC index for exact-match queries.
 * Returns hex string of HMAC-SHA256(value) using HMAC key; returns null if not available.
 */
function td_bkg_hmac_index($value) {
    $key = td_bkg_hmac_key();
    if (!$key || $value === '' || $value === null) return null;
    $value = (string) $value;
    return hash_hmac('sha256', $value, $key);
}

/**
 * Encrypt plaintext using the active KMS key into a JSON envelope.
 * Envelope shape: {"alg":"XChaCha20-Poly1305","kid":"<kid>","n":"<b64>","c":"<b64>"}
 * Returns JSON string on success; false on failure or if crypto is unavailable.
 */
function td_bkg_encrypt($plaintext, $aad = 'td-booking') {
    if ($plaintext === null) $plaintext = '';
    $plaintext = (string) $plaintext;
    if ($plaintext === '') return $plaintext; // do not encrypt empty strings
    if (!td_bkg_crypto_available()) return false;
    $kid = td_bkg_crypto_active_kid();
    $keys = td_bkg_crypto_keys();
    $key = $keys[$kid] ?? null;
    if (!$key || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) return false;
    $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    try {
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $key);
    } catch (Throwable $e) {
        return false;
    }
    $env = [
        'alg' => 'XChaCha20-Poly1305',
        'kid' => $kid,
        'n'   => base64_encode($nonce),
        'c'   => base64_encode($cipher),
    ];
    return wp_json_encode($env);
}

/**
 * Decrypt JSON envelope, or return original if it's not an envelope.
 * Returns plaintext string on success; null on failure (corrupt/unknown key); or original if not encrypted.
 */
function td_bkg_decrypt($maybe_envelope, $aad = 'td-booking') {
    if (!is_string($maybe_envelope) || $maybe_envelope === '') return $maybe_envelope;
    $trim = ltrim($maybe_envelope);
    if ($trim === '' || $trim[0] !== '{') {
        // Not JSON -> assume plaintext
        return $maybe_envelope;
    }
    $env = json_decode($maybe_envelope, true);
    if (!is_array($env) || empty($env['alg']) || empty($env['kid']) || empty($env['n']) || empty($env['c'])) {
        return $maybe_envelope; // not an envelope we understand
    }
    if (strcasecmp($env['alg'], 'XChaCha20-Poly1305') !== 0) {
        return null; // unknown algorithm
    }
    // If sodium is not available, avoid fatal and signal failure
    if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
        return null;
    }
    $keys = td_bkg_crypto_keys();
    $kid = (string) $env['kid'];
    $key = $keys[$kid] ?? null;
    if (!$key) return null;
    $nonce = base64_decode((string)$env['n'], true);
    $cipher = base64_decode((string)$env['c'], true);
    if ($nonce === false || $cipher === false) return null;
    try {
        $pt = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, $aad, $nonce, $key);
        if ($pt === false) return null;
        return $pt;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Simple helper to check if a string looks like an encrypted envelope (ours).
 */
function td_bkg_is_encrypted_envelope($s) {
    if (!is_string($s) || $s === '') return false;
    $s = ltrim($s);
    if ($s === '' || $s[0] !== '{') return false;
    $env = json_decode($s, true);
    return is_array($env) && isset($env['alg'], $env['kid'], $env['n'], $env['c']);
}
