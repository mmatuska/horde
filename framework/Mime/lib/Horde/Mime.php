<?php
/**
 * The Horde_Mime:: class provides methods for dealing with various MIME (see,
 * e.g., RFC 2045-2049; 2183; 2231) standards.
 *
 * Copyright 1999-2009 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @author  Michael Slusarz <slusarz@curecanti.org>
 * @package Horde_Mime
 */
class Horde_Mime
{
    /**
     * Attempt to work around non RFC 2231-compliant MUAs by generating both
     * a RFC 2047-like parameter name and  also the correct RFC 2231
     * parameter.  See:
     * http://lists.horde.org/archives/dev/Week-of-Mon-20040426/014240.html
     *
     * @var boolean
     */
    static public $brokenRFC2231 = false;

    /**
     * Determines if a string contains 8-bit (non US-ASCII) characters.
     *
     * @param string $string   The string to check.
     * @param string $charset  The charset of the string. Defaults to
     *                         US-ASCII.
     *
     * @return boolean  True if string contains non US-ASCII characters.
     */
    static public function is8bit($string, $charset = null)
    {
        /* ISO-2022-JP is a 7bit charset, but it is an 8bit representation so
         * it needs to be entirely encoded. */
        return is_string($string) &&
               ((stristr('iso-2022-jp', $charset) &&
                (strstr($string, "\x1b\$B"))) ||
                preg_match('/[\x80-\xff]/', $string));
    }

    /**
     * Encodes a string containing non-ASCII characters according to RFC 2047.
     *
     * @param string $text     The text to encode.
     * @param string $charset  The character set of the text.
     *
     * @return string  The text, encoded only if it contains non-ASCII
     *                 characters.
     */
    static public function encode($text, $charset = null)
    {
        if (is_null($charset)) {
            require_once 'Horde/NLS.php';
            $charset = NLS::getCharset();
        }
        $charset = String::lower($charset);

        if (($charset == 'us-ascii') || !self::is8bit($text, $charset)) {
            return $text;
        }

        /* Get the list of elements in the string. */
        $size = preg_match_all('/([^\s]+)([\s]*)/', $text, $matches, PREG_SET_ORDER);

        $line = '';

        /* Return if nothing needs to be encoded. */
        foreach ($matches as $key => $val) {
            if (self::is8bit($val[1], $charset)) {
                if ((($key + 1) < $size) &&
                    self::is8bit($matches[$key + 1][1], $charset)) {
                    $line .= self::_encode($val[1] . $val[2], $charset) . ' ';
                } else {
                    $line .= self::_encode($val[1], $charset) . $val[2];
                }
            } else {
                $line .= $val[1] . $val[2];
            }
        }

        return rtrim($line);
    }

    /**
     * Internal recursive function to RFC 2047 encode a string.
     *
     * @param string $text     The text to encode.
     * @param string $charset  The character set of the text.
     *
     * @return string  The text, encoded only if it contains non-ASCII
     *                 characters.
     */
    static protected function _encode($text, $charset)
    {
        $encoded = trim(base64_encode($text));
        $c_size = strlen($charset) + 7;

        if ((strlen($encoded) + $c_size) > 75) {
            $parts = explode("\r\n", rtrim(chunk_split($encoded, intval((75 - $c_size) / 4) * 4)));
        } else {
            $parts[] = $encoded;
        }

        $p_size = count($parts);
        $out = '';

        foreach ($parts as $key => $val) {
            $out .= '=?' . $charset . '?b?' . $val . '?=';
            if ($p_size > $key + 1) {
                /* RFC 2047 [2]: no encoded word can be more than 75
                 * characters long. If longer, you must split the word with
                 * CRLF SPACE. */
                $out .= "\r\n ";
            }
        }

        return $out;
    }

    /**
     * Encodes a line via quoted-printable encoding.
     *
     * @param string $text   The text to encode.
     * @param string $eol    The EOL sequence to use.
     * @param integer $wrap  Wrap a line at this many characters.
     *
     * @return string  The quoted-printable encoded string.
     */
    static public function quotedPrintableEncode($text, $eol, $wrap = 76)
    {
        $line = $output = '';
        $curr_length = 0;

        /* We need to go character by character through the data. */
        for ($i = 0, $length = strlen($text); $i < $length; ++$i) {
            $char = $text[$i];

            /* If we have reached the end of the line, reset counters. */
            if ($char == "\n") {
                $output .= $eol;
                $curr_length = 0;
                continue;
            } elseif ($char == "\r") {
                continue;
            }

            /* Spaces or tabs at the end of the line are NOT allowed. Also,
             * ASCII characters below 32 or above 126 AND 61 must be
             * encoded. */
            $ascii = ord($char);
            if ((($ascii === 32) &&
                 ($i + 1 != $length) &&
                 (($text[$i + 1] == "\n") || ($text[$i + 1] == "\r"))) ||
                (($ascii < 32) || ($ascii > 126) || ($ascii === 61))) {
                $char_len = 3;
                $char = '=' . String::upper(sprintf('%02s', dechex($ascii)));
            } else {
                $char_len = 1;
            }

            /* Lines must be $wrap characters or less. */
            $curr_length += $char_len;
            if ($curr_length > $wrap) {
                $output .= '=' . $eol;
                $curr_length = $char_len;
            }
            $output .= $char;
        }

        return $output;
    }

    /**
     * Encodes a string containing email addresses according to RFC 2047.
     *
     * This differs from encode() because it keeps email addresses legal, only
     * encoding the personal information.
     *
     * @param mixed $addresses   The email addresses to encode (either a
     *                           string or an array of addresses).
     * @param string $charset    The character set of the text.
     * @param string $defserver  The default domain to append to mailboxes.
     *
     * @return string  The text, encoded only if it contains non-ASCII
     *                 characters.
     * @throws Horde_Mime_Exception
     */
    static public function encodeAddress($addresses, $charset = null,
                                         $defserver = null)
    {
        if (!is_array($addresses)) {
            /* parseAddressList() does not process the null entry
             * 'undisclosed-recipients:;' correctly. */
            $addresses = trim($addresses);
            if (preg_match('/undisclosed-recipients:\s*;/i', $addresses)) {
                return $addresses;
            }

            $addresses = Horde_Mime_Address::parseAddressList($addresses, array('defserver' => $defserver, 'nestgroups' => true));
        }

        $text = '';
        foreach ($addresses as $addr) {
            $addrobs = empty($addr['groupname'])
                ? array($addr)
                : $addr['addresses'];
            $addrlist = array();

            foreach ($addrobs as $val) {
                if (empty($val['personal'])) {
                    $personal = '';
                } else {
                    if (($val['personal'][0] == '"') &&
                        (substr($val['personal'], -1) == '"')) {
                        $addr['personal'] = stripslashes(substr($val['personal'], 1, -1));
                    }
                    $personal = self::encode($val['personal'], $charset);
                }
                $addrlist[] = Horde_Mime_Address::writeAddress($val['mailbox'], $val['host'], $personal);
            }

            if (empty($addr['groupname'])) {
                $text .= reset($addrlist) . ', ';
            } else {
                $text .= Horde_Mime_Address::writeGroupAddress($addr['groupname'], $addrlist) . ' ';
            }
        }

        return rtrim($text, ' ,');
    }

    /**
     * Decodes an RFC 2047-encoded string.
     *
     * @param string $string      The text to decode.
     * @param string $to_charset  The charset that the text should be decoded
     *                            to.
     *
     * @return string  The decoded text.
     */
    static public function decode($string, $to_charset = null)
    {
        if (($pos = strpos($string, '=?')) === false) {
            return $string;
        }

        /* Take out any spaces between multiple encoded words. */
        $string = preg_replace('|\?=\s+=\?|', '?==?', $string);

        /* Save any preceding text. */
        $preceding = substr($string, 0, $pos);

        $search = substr($string, $pos + 2);
        $d1 = strpos($search, '?');
        if ($d1 === false) {
            return $string;
        }

        $charset = substr($string, $pos + 2, $d1);
        $search = substr($search, $d1 + 1);

        $d2 = strpos($search, '?');
        if ($d2 === false) {
            return $string;
        }

        $encoding = substr($search, 0, $d2);
        $search = substr($search, $d2 + 1);

        $end = strpos($search, '?=');
        if ($end === false) {
            $end = strlen($search);
        }

        $encoded_text = substr($search, 0, $end);
        $rest = substr($string, (strlen($preceding . $charset . $encoding . $encoded_text) + 6));

        if (is_null($to_charset)) {
            require_once 'Horde/NLS.php';
            $to_charset = NLS::getCharset();
        }

        switch ($encoding) {
        case 'Q':
        case 'q':
            $decoded = preg_replace('/=([0-9a-f]{2})/ie', 'chr(0x\1)', str_replace('_', ' ', $encoded_text));
            $decoded = String::convertCharset($decoded, $charset, $to_charset);
            break;

        case 'B':
        case 'b':
            $decoded = String::convertCharset(base64_decode($encoded_text), $charset, $to_charset);
            break;

        default:
            $decoded = '=?' . $charset . '?' . $encoding . '?' . $encoded_text . '?=';
            break;
        }

        return $preceding . $decoded . self::decode($rest, $to_charset);
    }

    /**
     * Decodes an RFC 2047-encoded address string.
     *
     * @param string $string      The text to decode.
     * @param string $to_charset  The charset that the text should be decoded
     *                            to.
     *
     * @return string  The decoded text.
     * @throw Horde_Mime_Exception
     */
    static public function decodeAddrString($string, $to_charset = null)
    {
        $addr_list = array();
        foreach (Horde_Mime_Address::parseAddressList($string) as $ob) {
            $ob['personal'] = isset($ob['personal'])
                ? self::decode($ob['personal'], $to_charset)
                : '';
            $addr_list[] = $ob;
        }

        return Horde_Mime_Address::addrArray2String($addr_list);
    }

    /**
     * Encodes a MIME parameter string pursuant to RFC 2183 & 2231
     * (Content-Type and Content-Disposition headers).
     *
     * @param string $name     The parameter name.
     * @param string $val      The parameter value.
     * @param string $charset  The charset the text should be encoded with.
     * @param array $opts      Additional options:
     * <pre>
     * 'escape' - (boolean) If true, escape param values as described in
     *            RFC 2045 [Appendix A].
     *            DEFAULT: false
     * 'lang' - (string) The language to use when encoding.
     *          DEFAULT: None specified
     * </pre>
     *
     * @return array  The encoded parameter string.
     */
    static public function encodeParam($name, $val, $charset, $opts = array())
    {
        $encode = $wrap = false;
        $output = array();
        $curr = 0;

        // 2 = '=', ';'
        $pre_len = strlen($name) + 2;

        if (self::is8bit($val, $charset)) {
            $string = String::lower($charset) . '\'' . (empty($opts['lang']) ? '' : String::lower($opts['lang'])) . '\'' . rawurlencode($val);
            $encode = true;
            /* Account for trailing '*'. */
            ++$pre_len;
        } else {
            $string = $val;
        }

        if (($pre_len + strlen($string)) > 75) {
            /* Account for continuation '*'. */
            ++$pre_len;
            $wrap = true;

            while ($string) {
                $chunk = 75 - $pre_len - strlen($curr);
                $pos = min($chunk, strlen($string) - 1);

                /* Don't split in the middle of an encoded char. */
                if (($chunk == $pos) && ($pos > 2)) {
                    for ($i = 0; $i <= 2; ++$i) {
                        if ($string[$pos - $i] == '%') {
                            $pos -= $i + 1;
                            break;
                        }
                    }
                }

                $lines[] = substr($string, 0, $pos + 1);
                $string = substr($string, $pos + 1);
                ++$curr;
            }
        } else {
            $lines = array($string);
        }

        foreach ($lines as $i => $line) {
            $output[$name . (($wrap) ? ('*' . $i) : '') . (($encode) ? '*' : '')] = $line;
        }

        if (self::$brokenRFC2231 && !isset($output[$name])) {
            $output = array_merge(array($name => self::encode($val, $charset)), $output);
        }

        /* Escape certain characters in params (See RFC 2045 [Appendix A]. */
        if (!empty($opts['escape'])) {
            foreach (array_keys($output) as $key) {
                if (strcspn($output[$key], "\11\40\"(),/:;<=>?@[\\]") != strlen($output[$key])) {
                    $output[$key] = '"' . addcslashes($output[$key], '\\"') . '"';
                }
            }
        }

        return $output;
    }

    /**
     * Decodes a MIME parameter string pursuant to RFC 2183 & 2231
     * (Content-Type and Content-Disposition headers).
     *
     * @param string $type     Either 'Content-Type' or 'Content-Disposition'
     *                         (case-insensitive).
     * @param mixed $data      The text of the header or an array of
     *                         param name => param values.
     * @param string $charset  The charset the text should be decoded to.
     *                         Defaults to system charset.
     *
     * @return array  An array with the following entries:
     * <pre>
     * 'params' - (array) The header's parameter values.
     * 'val' - (string) The header's "base" value.
     * </pre>
     */
    static public function decodeParam($type, $data, $charset = null)
    {
        $convert = array();
        $ret = array('params' => array(), 'val' => '');

        if (is_array($data)) {
            // Use dummy base values
            $ret['val'] = (String::lower($type) == 'content-type')
                ? 'text/plain'
                : 'attachment';
            $params = $data;
        } else {
            /* Give $string a bogus body part or else decode() will
             * complain. */
            require_once 'Mail/mimeDecode.php';
            $mime_decode = new Mail_mimeDecode($type . ': ' . $data . "\n\nA");
            $res = $mime_decode->decode();

            /* Are we dealing with content-type or content-disposition? */
            if (isset($res->disposition)) {
                $ret['val'] = $res->disposition;
                $params = isset($res->d_parameters) ? $res->d_parameters : array();
            } elseif (isset($res->ctype_primary)) {
                $ret['val'] = $res->ctype_primary . '/' . $res->ctype_secondary;
                $params = isset($res->ctype_parameters) ? $res->ctype_parameters : array();
            } else {
                return $ret;
            }
        }

        /* Sort the params list. Prevents us from having to manually keep
         * track of continuation values below. */
        uksort($params, 'strnatcasecmp');

        foreach ($params as $name => $val) {
            /* Asterisk at end indicates encoded value. */
            if (substr($name, -1) == '*') {
                $name = substr($name, 0, -1);
                $encode = true;
            } else {
                $encode = false;
            }

            /* This asterisk indicates continuation parameter. */
            if (($pos = strrpos($name, '*')) !== false) {
                $name = substr($name, 0, $pos);
            }

            if (!isset($ret['params'][$name]) ||
                ($encode && !isset($convert[$name]))) {
                $ret['params'][$name] = '';
            }

            $ret['params'][$name] .= $val;

            if ($encode) {
                $convert[$name] = true;
            }
        }

        foreach (array_keys($convert) as $name) {
            $val = $ret['params'][$name];
            $quote = strpos($val, "'");
            $orig_charset = substr($val, 0, $quote);
            /* Ignore language. */
            $quote = strpos($val, "'", $quote + 1);
            substr($val, $quote + 1);
            $ret['params'][$name] = String::convertCharset(urldecode(substr($val, $quote + 1)), $orig_charset, $charset);
        }

        /* MIME parameters are supposed to be encoded via RFC 2231, but many
         * mailers do RFC 2045 encoding instead. However, if we see at least
         * one RFC 2231 encoding, then assume the sending mailer knew what
         * it was doing. */
        if (empty($convert)) {
            foreach (array_diff(array_keys($ret['params']), array_keys($convert)) as $name) {
                $ret['params'][$name] = self::decode($ret['params'][$name]);
            }
        }

        return $ret;
    }

    /**
     * Generates a Message-ID string conforming to RFC 2822 [3.6.4] and the
     * standards outlined in 'draft-ietf-usefor-message-id-01.txt'.
     *
     * @param string  A message ID string.
     */
    static public function generateMessageId()
    {
        return '<' . date('YmdHis') . '.' . self::generateRandomId() . '@' . $_SERVER['SERVER_NAME'] . '>';
    }

    /**
     * Generates a Random-ID string suitable for use with MIME features that
     * require a random string.
     *
     * @return string  A random string.
     */
    static public function generateRandomId($length = 24)
    {
        return substr(base_convert(dechex(strtr(microtime(), array('0.' => '', ' ' => ''))) . uniqid(mt_rand(), true), 16, 36), $length);
    }

    /**
     * Performs MIME ID "arithmetic" on a given ID.
     *
     * @param string $id      The MIME ID string.
     * @param string $action  One of the following:
     * <pre>
     * 'down' - ID of child. Note: down will first traverse to "$id.0" if
     *          given an ID *NOT* of the form "$id.0". If given an ID of the
     *          form "$id.0", down will traverse to "$id.1". This behavior
     *          can be avoided if 'norfc822' option is set.
     * 'next' - ID of next sibling.
     * 'prev' - ID of previous sibling.
     * 'up' - ID of parent. Note: up will first traverse to "$id.0" if
     *        given an ID *NOT* of the form "$id.0". If given an ID of the
     *        form "$id.0", down will traverse to "$id". This behavior can be
     *        avoided if 'norfc822' option is set.
     * </pre>
     * @param array $options  Additional options:
     * <pre>
     * 'count' - (integer) How many levels to traverse.
     *           DEFAULT: 1
     * 'norfc822' - (boolean) Don't traverse rfc822 sub-levels
     *              DEFAULT: false
     * </pre>
     *
     * @return mixed  The resulting ID string, or null if that ID can not
     *                exist.
     */
    static public function mimeIdArithmetic($id, $action, $options = array())
    {
        $pos = strrpos($id, '.');
        $end = ($pos === false) ? $id : substr($id, $pos + 1);

        switch ($action) {
        case 'down':
            if ($end == '0') {
                $id = ($pos === false) ? 1 : substr_replace($id, '1', $pos + 1);
            } else {
                $id .= empty($options['norfc822']) ? '.0' : '.1';
            }
            break;

        case 'next':
            ++$end;
            $id = ($pos === false) ? $end : substr_replace($id, $end, $pos + 1);
            break;

        case 'prev':
            if (($end == '0') ||
                (empty($options['norfc822']) && ($end == '1'))) {
                $id = null;
            } elseif ($pos === false) {
                $id = --$end;
            } else {
                $id = substr_replace($id, --$end, $pos + 1);
            }
            break;

        case 'up':
            if ($pos === false) {
                $id = ($end == '0') ? null : '0';
            } elseif (!empty($options['norfc822']) || ($end == '0')) {
                $id = substr($id, 0, $pos);
            } else {
                $id = substr_replace($id, '0', $pos + 1);
            }
            break;
        }

        return (!is_null($id) && !empty($options['count']) && --$options['count'])
            ? self::mimeIdArithmetic($id, $action, $options)
            : $id;
    }

}
