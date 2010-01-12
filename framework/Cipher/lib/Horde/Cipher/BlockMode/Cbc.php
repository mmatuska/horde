<?php
/**
 * The Horde_Cipher_BlockMode_Cbc:: class implements Horde_Cipher_BlockMode
 * using the Cipher Block Chaining method of encrypting blocks of data.
 *
 * Copyright 2002-2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @author  Mike Cochrane <mike@graftonhall.co.nz>
 * @package Horde_Cipher
 */
class Horde_Cipher_BlockMode_Cbc extends Horde_Cipher_BlockMode
{
    /**
     * Encrypt a string.
     *
     * @param Horde_Cipher $cipher  Cipher algorithm to use for encryption.
     * @param string $plaintext     The data to encrypt.
     *
     * @return string  The encrypted data.
     */
    public function encrypt($cipher, $plaintext)
    {
        $encrypted = '';

        $blocksize = $cipher->getBlockSize();
        $previousCipher = $this->_iv;

        $jMax = strlen($plaintext);
        for ($j = 0; $j < $jMax; $j += $blocksize) {
            $plain = substr($plaintext, $j, $blocksize);

            if (strlen($plain) < $blocksize) {
                // pad the block with \0's if it's not long enough
                $plain = str_pad($plain, 8, "\0");
            }

            $plain = $plain ^ $previousCipher;
            $previousCipher = $cipher->encryptBlock($plain);
            $encrypted .= $previousCipher;
        }

        return $encrypted;
    }

    /**
     * Decrypt a string.
     *
     * @param Horde_Cipher $cipher  Cipher algorithm to use for decryption.
     * @param string $ciphertext    The data to decrypt.
     *
     * @return string  The decrypted data.
     */
    public function decrypt($cipher, $ciphertext)
    {
        $decrypted = '';

        $blocksize = $cipher->getBlockSize();
        $previousCipher = $this->_iv;

        $jMax = strlen($ciphertext);
        for ($j = 0; $j < $jMax; $j += $blocksize) {
            $plain = substr($ciphertext, $j, $blocksize);
            $decrypted .= $cipher->decryptBlock($plain) ^ $previousCipher;
            $previousCipher = $plain;
        }

        // Remove trailing \0's used to pad the last block.
        while (substr($decrypted, -1, 1) == "\0") {
            $decrypted = substr($decrypted, 0, -1);
        }

        return $decrypted;
    }

}
