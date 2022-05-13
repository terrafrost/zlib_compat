<?php

/**
 * Deflate (zlib) Emulation Class
 *
 * PHP version 7 and 8
 *
 * Useful resources:
 * - https://blog.za3k.com/understanding-gzip-2/
 * - https://www.bolet.org/~pornin/deflate-flush.html
 *
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2022 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 */

namespace zlib_compat;

/**
 * Deflate (zlib) Emulation Class
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 * @access  public
 */
class Deflate
{
    /**
     * Encoding.
     *
     * Can be ZLIB_ENCODING_RAW, ZLIB_ENCODING_DEFLATE or ZLIB_ENCODING_GZIP
     */
    private $encoding;

    /**
     * Parameters for when no compression is used
     */
    private $noCompressionParams = [];

    /**
     * Consumed bits
     *
     * How many bits of the current byte have been consumed. Some flush modes
     * will persist this
     */
    private $consumed = 0;

    /**
     * Bytes to prepend
     *
     * Some flush modes may not finish off a byte array, at which point, the
     * portions not fully consumed should persist and be prepended to the next
     * payload
     */
    private $prepend = [];
    private $prependStr = '';

    /**
     * State
     *
     * zlib preserves the "state" of the function when the input has been exhausted
     */
    private $state = [];

    /**
     * Output
     *
     * Run-length encoding can reference old decompressed blocks
     */
    private $output = '';

    /**
     * Temp Output
     *
     * $this->output is used in conjunction with $output to determine what should be returned.
     * With recursive calls, however, $output needs to be saved somewhere other than $this->output
     */
    private $tempOutput = '';

    /**
     * Process Header
     */
    private $processHeader = true;

    /**
     * Process Footer
     */
    private $processFooter = false;

    /**
     * Base Length.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc1951#page-12
     * @see self::handleRLE()
     */
    private static $baseLength = [
        1,       2,    3,    4,    5,    7,    9,    13,    17,    25,
        33,     49,   65,   97,  129,  193,  257,   385,   513,   769,
        1025, 1537, 2049, 3073, 4097, 6145, 8193, 12289, 16385, 24577,
    257 => 3,    4,    5,    6,    7,    8,    9,    10,    11,    13,
          15,   17,   19,   23,   27,   31,   35,    43,    51,    59,
          67,   83,   99,  115,  131,  163,  195,   227,   258
    ];

    /**
     * Extra Bits.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc1951#page-12
     * @see self::handleRLE()
     */
    private static $extraBits = [
        0, 0,  0,  0,  1,  1,  2,  2,  3,  3,
        4, 4,  5,  5,  6,  6,  7,  7,  8,  8, 
        9, 9, 10, 10, 11, 11, 12, 12, 13, 13,
 257 => 0, 0,  0,  0,  0,  0,  0,  0,  1,  1,
        1, 1,  2,  2,  2,  2,  3,  3,  3,  3,
        4, 4,  4,  4,  5,  5,  5,  5,  0
    ];

    /**
     * Code Map
     *
     * @see https://datatracker.ietf.org/doc/html/rfc1951#page-14
     */
    private static $codeMap = [16, 17, 18, 0, 8, 7, 9, 6, 10, 5, 11, 4, 12, 3, 13, 2, 14, 1, 15];

    /**
     * Constructor
     *
     * @param int $encoding
     */
    public function __construct($encoding)
    {
        switch ($encoding) {
            case ZLIB_ENCODING_RAW:
            case ZLIB_ENCODING_GZIP:
            case ZLIB_ENCODING_DEFLATE:
                break;
            default:
                throw new \ValueError('Argument #1 ($encoding) must be one of ZLIB_ENCODING_RAW, ZLIB_ENCODING_GZIP, or ZLIB_ENCODING_DEFLATE');
        }
        $this->encoding = $encoding;
    }

    /**
     * Decode the gzip algorithm footer as described in RFC1952
     *
     * @param string $payload
     * @param string $output
     * @param int $encoding
     * @return string
     */
    private static function decodeFooter(&$result, $output, $encoding)
    {
        switch ($encoding) {
            case ZLIB_ENCODING_RAW:
                break;
            case ZLIB_ENCODING_DEFLATE:
                if (strlen($result) < 4) {
                    return false;
                }
                if (hash('adler32', $output, true) !== self::shift($result, 4)) {
                    throw new \Exception('Adler32 mismatch');
                }
                break;
            case ZLIB_ENCODING_GZIP:
                if (strlen($result) < 8) {
                    return false;
                }
                extract(unpack('Vcrc32/Visize', self::shift($result, 8)));
                if ($crc32 != crc32($output)) {
                    throw new \Exception('CRC32 mismatch');
                }
                if ($isize != strlen($output)) {
                    throw new \Exception('Size mismatch');
                }
        }

        return true;
    }

    /**
     * Decode zlib algorithm header as described in RFC1950 or the gzip algorithm header
     * as described in RFC1952
     *
     * @param string $payload
     * @return string
     */
    private static function decodeHeader(&$result, $encoding)
    {
        // inflate_add() simply does Warning: inflate_add(): data error instead of throwing
        // exceptions
        switch ($encoding) {
            case ZLIB_ENCODING_RAW:
                break;
            case ZLIB_ENCODING_DEFLATE:
                if (strlen($result) < 2) {
                    return false;
                }
                $cmf = ord($result[0]); // Compression Method and flags
                $cm = $cmf & 0x0F; // Compression flag
                if ($cm != 8) { // deflate
                    throw new \Exception("Only CM = 8 ('deflate') is supported ($cm)");
                }
                $cinfo = ($cmf & 0xF0) >> 4; // Compression info
                if ($cinfo > 7) {
                    throw new \Exception("CINFO above 7 is not allowed ($cinfo)");
                }
                $windowSize = 1 << ($cinfo + 8);

                $flg = ord($result[1]); // FLaGs)
                //$fcheck = $flg & 0x0F; // check bits for CMF and FLG
                if ((($cmf << 8) | $flg) % 31) {
                    throw new \Exception('fcheck failed');
                }
                $fdict = boolval($flg & 0x20); // preset dictionary
                $flevel = ($flg & 0xC0) >> 6; // compression level
                if ($fdict) {
                    throw new \Exception('FDICT being set is currently unsupported. Please email this example to terrafrost@php.net so that support might be added');
                }
                $result = substr($result, 2);
                break;
            case ZLIB_ENCODING_GZIP:
                if (strlen($result) < 10) {
                    return false;
                }
                if (substr($result, 0, 2) != "\x1F\x8B") {
                    throw new \Exception('gzip file signature not present');
                }
                if ($result[2] != "\x08") {
                    $cm = ord($result[2]);
                    throw new \Exception("Only CM = 8 ('deflate') is supported ($cm)");
                }
                $flg = ord($result[3]); // FLaGs
                $ftext = boolval($flg & 1);
                $fcrc = boolval($flg & 2);
                $fextra = boolval($flg & 4);
                $fname = boolval($flg & 8);
                $fcomment = boolval($flg & 16);
                list(, $mtime) = unpack('N', substr($result, 4, 4));
                $xfl = $result[8]; // eXtra FLags
                $os = ord($result[9]); // Operating System; see https://datatracker.ietf.org/doc/html/rfc1952#page-8
                $message = self::shift($result, 10);
                if ($fextra) {
                    if (strlen($result) < 4) {
                        return false;
                    }
                    // see RFC1952 section 2.3.1.1. Extra field
                    $temp = self::shift($result, 2);
                    $message.= $temp;
                    list(, $xlen) = unpack('n', $temp);
                    if (strlen($result) < $xlen) {
                        return false;
                    }
                    $extra = self::shift($result, $xlen);
                    $message.= $extra;
                }
                if ($fname) {
                    // original file name
                    $pos = strpos($result, "\0");
                    if ($pos === false) {
                        return false;
                    }
                    $message.= self::shift($result, $pos + 1);
                }
                if ($fcomment) {
                    // file comment
                    $pos = strpos($result, "\0");
                    if ($pos === false) {
                        return false;
                    }
                    $message.= self::shift($result, $pos + 1);
                }
                if ($fcrc) {
                    if (strlen($result) < 2) {
                        return false;
                    }
                    list(, $expected) = unpack('n', self::shift($result));
                    $actual = crc32($message) & 0xFFFF;
                    if ($expected != $actual) {
                        throw new \Exception('CRC16 does not match');
                    }
                }
        }

        return true;
    }

    /**
     * Decompress with the Deflate algorithm as described in RFC1950
     *
     * @param string $payload
     * @param string $flush_mode
     * @return string
     */
    public function decompress($payload)
    {
        if ($this->processFooter) {
            $payload = $this->prependStr . $payload;
            if (!self::decodeFooter($payload, $this->tempOutput, $this->encoding)) {
                $this->prependStr = $payload;
                return '';
            }
            $this->size = -1;
            // zlib does $this->prependStr = '' and thus fails in instances
            // where zlib_compat succeeds. see DeflateTest::testTwoBlocksWithFailure()
            // for examples
            $this->prependStr = $payload;
            $this->processFooter = false;
            $this->processHeader = true;

            $temp = $this->output;
            $this->output = '';
            return $temp;
        }

        if ($this->processHeader) {
            $payload = $this->prependStr . $payload;
            if (!self::decodeHeader($payload, $this->encoding)) {
                $this->prependStr = $payload;
                $temp = $this->output;
                $this->output = '';
                return $temp;
            }
            $this->prependStr = '';
            $this->processHeader = false;
            $this->tempOutput = '';
        }

        $pos = 1;
        $consumed = &$this->consumed;
        $output = &$this->output;
        $tempOutput = &$this->tempOutput;
        $payload = unpack('C*', $payload);
        $state = &$this->state;

        if (count($this->prepend)) {
            array_unshift($payload, ...$this->prepend);
            array_unshift($payload, 0);
            unset($payload[0]);
            $this->prepend = [];
        }

        $payloadLength = count($payload);
        while ($pos <= $payloadLength) {
            try {
                $state['bfinal'] = $state['bfinal'] ?? self::consume($payload, 1, $pos, $consumed);
                $state['btype'] = $state['btype'] ?? self::consume($payload, 2, $pos, $consumed);
            } catch (\OutOfBoundsException $e) {
                if ($e->getCode()) {
                    $this->prepend = array_slice($payload, -$e->getCode());
                }
                break;
            }
            switch ($state['btype']) {
                case 0: // no compression
                    if (!isset($state['len'])) {
                        if (count($payload) - $pos < 4) {
                            $this->prepend = array_slice($payload, -count($payload) + $pos - 1);
                            $temp = $output;
                            $output = '';
                            return $temp;
                        }
                        list($lenLSB, $lenMSB, $nlenLSB, $nlenMSB) = array_slice($payload, $pos, 4);
                        $len = ($lenMSB << 8) | $lenLSB;
                        $nlen = ($nlenMSB << 8) | $nlenLSB;
                        $pos+= 5;
                        //$pos+= $consumed != 0;
                        $consumed = 0;
                        if ($len != (~$nlen & 0xFFFF)) {
                            throw new \Exception('"NLEN is the one\'s complement of LEN."');
                        }
                        $state['len'] = $len;
                        $state['i'] = 0;
                    }
                    for ($i = &$state['i']; $i < $state['len']; $i++, $pos++) {
                        if (!isset($payload[$pos])) {
                            $temp = $output;
                            $output = '';
                            return $temp;
                        }
                        $output.= chr($payload[$pos]);
                        $tempOutput.= chr($payload[$pos]);
                    }
                    if ($i == $state['len']) {
                        $state = [];
                    }
                    break;
                case 1: // compressed with fixed Huffman codes
                    while ($pos <= $payloadLength) {
                        try {
                            $data = $state['data'] = $state['data'] ?? self::consume($payload, 7, $pos, $consumed);
                            $data<<= 1;
                            self::flipBits($data);
                            switch (true) {
                                // end of block
                                case $data === 0:
                                    $bfinal = $state['bfinal'];
                                    $state = [];
                                    if ($bfinal) {
                                        $this->processFooter = true;
                                        $consumed = 0;
                                        return $this->decompress(pack('C*', ...array_slice($payload, $pos)));
                                    }

                                    break 2;
                                // length - 7 bits
                                case $data >= 1 && $data <= 23:
                                    $data+= 256; // 257 - 279
                                    $length = self::$baseLength[$data];
                                    $consume = self::$extraBits[$data];
                                    if ($consume) { // (if set) can be between 1 and 5
                                        $extra = $state['extra'] = $state['extra'] ?? self::consume($payload, $consume, $pos, $consumed);
                                        $length+= $extra;
                                    }
                                    // Distance codes 0-31 are represented by (fixed-length) 5-bit codes
                                    $state['distance'] = $state['distance'] ?? self::consume($payload, 5, $pos, $consumed);
                                    $distance = $state['distance'] << 3;
                                    self::flipBits($distance);
                                    self::handleRLE($output, $tempOutput, $length, $distance, $payload, $pos, $consumed, $state);
                                    unset($state['extra'], $state['distance']);
                                    break;
                                // literal byte - 8 bits
                                case $data >= 24 && $data <= 95:
                                    $data = ($data << 1) | self::consume($payload, 1, $pos, $consumed);
                                    $char = chr($data - 48); // 0 - 143 (143 = 95 * 2 - 1)

                                    $output.= $char;
                                    $tempOutput.= $char;
                                    break;
                                // length - 8 bits
                                case $data >= 96 && $data <= 99:
                                    if ($data === 99) {
                                        throw new \Exception('"Literal/length values 286-287 will never actually occur in the compressed data"');
                                    }
                                    $data = $state['data2'] = $state['data2'] ?? (($data << 1) | self::consume($payload, 1, $pos, $consumed)) + 88;
                                    $length = self::$baseLength[$data];
                                    $consume = self::$extraBits[$data];
                                    if ($consume) {
                                        $extra = $state['extra'] = $state['extra'] ?? self::consume($payload, $consume, $pos, $consumed);
                                        $length+= $extra;
                                    }
                                    // Distance codes 0-31 are represented by (fixed-length) 5-bit codes
                                    $state['distance'] = $state['distance'] ?? self::consume($payload, 5, $pos, $consumed);
                                    $distance = $state['distance'] << 3;
                                    self::flipBits($distance);
                                    self::handleRLE($output, $tempOutput, $length, $distance, $payload, $pos, $consumed, $state);
                                    unset($state['data2'], $state['extra'], $state['distance']);
                                    break;
                                // literal byte - 9 bits
                                case $data >= 100:
                                    $data = $state['data2'] = $state['data2'] ?? ($data << 2) | self::consume($payload, 2, $pos, $consumed);
                                    $char = chr($data - 400); // 144 - 255
                                    $output.= $char;
                                    $tempOutput.= $char;
                                    unset($state['data2']);
                            }
                            unset($state['data']);
                        } catch (\OutOfBoundsException $e) {
                            if ($e->getCode()) {
                                $this->prepend = array_slice($payload, -$e->getCode());
                            }
                            $temp = $output;
                            $output = '';
                            return $temp;
                        }
                    }
                    break;
                case 2: // compressed with dynamic Huffman codes
                    try {
                        // # of Literal/Length codes
                        $hlit = $state['hlit'] = $state['hlit'] ?? self::consume($payload, 5, $pos, $consumed);
                        $hlit+= 257;

                        // # of Distance codes
                        $hdist = $state['hdist'] = $state['hdist'] ?? self::consume($payload, 5, $pos, $consumed);
                        $hdist+= 1;

                        // # of Code Length codes
                        $hclen = $state['hclen'] = $state['hclen'] ?? self::consume($payload, 4, $pos, $consumed);
                        $hclen+= 4;

                        $maxBits = 0;
                        if (!isset($state['codeLengths'])) {
                            $state['codeLengths'] = [];
                        }

                        for ($i = count($state['hc'] ?? []); $i < $hclen; $i++) {
                            $state['hc'][] = $temp = self::consume($payload, 3, $pos, $consumed);
                            // a code length of 0 means the corresponding symbol
                            // (literal/length or distance code length) is not used
                            if ($temp) {
                                $state['codeLengths'][self::$codeMap[$i]] = $temp;
                            }
                        }

                        // "Find the numerical value of the smallest code for each code length"
                        $state['codes'] = $state['codes'] ?? self::createMapping($state['codeLengths']);

                        $state['literalLengths'] = $state['literalLengths'] ?? [];
                        $state['distanceLengths'] = $state['distanceLengths'] ?? [];
                        $lengths = &$state['literalLengths'];

                        $state['literalOffset'] = $state['literalOffset'] ?? 0;
                        $state['distanceOffset'] = $state['distanceOffset'] ?? 0;
                        $offset = &$state['literalOffset'];

                        while ($pos <= $payloadLength) {
                            if ($state['literalOffset'] >= $hlit) {
                                $lengths = &$state['distanceLengths'];
                                $offset = &$state['distanceOffset'];
                            }
                            if ($state['distanceOffset'] >= $hdist) {
                                break;
                            }
                            if (!isset($state['code'])) {
                                $state['code'] = self::getCode($payload, $pos, $consumed, $state, 'codes');
                            }

                            $code = $state['code'];

                            switch ($code) {
                                case 18:
                                    // Repeat a code length of 0 for 11 - 138 times
                                    // (7 bits of length)
                                    $temp = self::consume($payload, 7, $pos, $consumed);
                                    $offset+= $temp + 11;

                                    break;
                                case 17:
                                    // Repeat a code length of 0 for 3 - 10 times.
                                    // (3 bits of length)
                                    $temp = self::consume($payload, 3, $pos, $consumed);
                                    $offset+= $temp + 3;

                                    break;
                                case 16:
                                    $size = end($lengths);
                                    // Copy the previous code length 3 - 6 times.
                                    // The next 2 bits indicate repeat length

                                    $length = self::consume($payload, 2, $pos, $consumed);
                                    $length+= 3;

                                    for ($i = 0; $i < $length; $i++) {
                                        $lengths[$offset++] = $size;
                                    }
                                    break;
                                case 0:
                                    $offset++;

                                    break;
                                default:
                                    // Represent code lengths of 1 - 15
                                    $lengths[$offset++] = $code;

                            }
                            unset($state['code']);
                        }

                        if ($state['distanceOffset'] < $hdist) {
                            break 2;
                        }

                        $state['literals'] = $state['literals'] ?? self::createMapping($state['literalLengths']);
                        $state['distances'] = $state['distances'] ?? self::createMapping($state['distanceLengths']);

                        while ($pos <= $payloadLength) {
                            if (!isset($state['code'])) {
                                $state['code'] = self::getCode($payload, $pos, $consumed, $state, 'literals');
                            }

                            $code = $state['code'];

                            switch (true) {
                                case $code < 256:
                                    $output.= chr($code);
                                    $tempOutput.= chr($code);
                                    break;
                                case $code === 256:
                                    $bfinal = $state['bfinal'];
                                    $state = [];
                                    if ($bfinal) {
                                        $this->processFooter = true;
                                        $consumed = 0;
                                        return $this->decompress(pack('C*', ...array_slice($payload, $pos)));
                                    }
                                    break 3;
                                default: // $code > 256:
                                    $length = self::$baseLength[$code];
                                    $consume = self::$extraBits[$code];

                                    if ($consume) {
                                        $extra = $state['extra'] = $state['extra'] ?? self::consume($payload, $consume, $pos, $consumed);
                                        $length+= $extra;
                                    }

                                    if (!isset($state['distance'])) {
                                        $state['distance'] = self::getCode($payload, $pos, $consumed, $state, 'distances');
                                    }

                                    self::handleRLE($output, $tempOutput, $length, $state['distance'], $payload, $pos, $consumed, $state);

                                    unset($state['extra'], $state['distance']);
                            }

                            unset($state['code']);
                        }
                    } catch (\OutOfBoundsException $e) {
                        if ($e->getCode()) {
                            $this->prepend = array_slice($payload, -$e->getCode());
                        }
                        $temp = $output;
                        $output = '';
                        return $temp;
                    }
                    break;
                case 3: // reserved (error)
                    throw new \UnexpectedValueException('Invalid btype value');
            }
        }

        $temp = $output;
        $output = '';
        return $temp;
    }

    /**
     * Flip the bits in an 8-bit integer
     *
     * @param int $char
     */
    private static function flipBits(&$char)
    {
        if (PHP_INT_SIZE === 8) {
            // 3 operations
            // from http://graphics.stanford.edu/~seander/bithacks.html#ReverseByteWith64BitsDiv
            $char = (($char * 0x0202020202) & 0x010884422010) % 1023;
        } else {
            // 7 operations
            // from http://graphics.stanford.edu/~seander/bithacks.html#ReverseByteWith32Bits
            $p1 = ($char * 0x0802) & 0x22110;
            $p2 = ($char * 0x8020) & 0x88440;
            $char = (($p1 | $p2) * 0x10101) >> 16;
        }
    }

    /**
     * Handle run-length encoding
     *
     * @param string $output
     * @param string $tempOutput
     * @param int $length
     * @param int $distance
     * @param string $payload
     * @param int $pos
     * @param int $consumed
     */
    private static function handleRLE(&$output, &$tempOutput, $length, $distance, $payload, &$pos, &$consumed, &$state = null)
    {
        if ($distance >= 30) {
            throw new \Exception('"distance codes 30-31 will never actually occur in the compressed data"');
        }
        $consume = self::$extraBits[(int) $distance];
        $distance = self::$baseLength[(int) $distance];
        if ($consume !== 0) {
            $extra = self::consume($payload, $consume, $pos, $consumed);
            $distance+= $extra;
        }
        $sub = substr($tempOutput, -$distance);
        while ($length > $distance) {
            $output.= $sub;
            $tempOutput.= $sub;
            $length-= $distance;
        }
        $temp = substr($sub, 0, $length);;
        $output.= $temp;
        $tempOutput.= $temp;
    }

    /**
     * Map integers to codes
     *
     * The key in $codeLengths the code and the value is the bit length.
     * In the return value the key represents the bit sequence and the value
     * represents what the bit sequence corresponds to
     *
     * @param int[] $codeLengths
     * @return int[]
     */
    private static function createMapping($codeLengths)
    {
        $bitLengthCount = array_count_values($codeLengths);
        $maxBits = max(array_keys($bitLengthCount));
        $maxCode = max(array_keys($codeLengths));

        $code = 0;
        $nextCode = [];
        for ($bits = 1; $bits <= $maxBits; $bits++) {
            $code = ($code + ($bitLengthCount[$bits - 1] ?? 0)) << 1;
            $nextCode[$bits] = $code;
        }

        $codes = [];
        for ($i = 0; $i <= $maxCode; $i++) {
            if (isset($codeLengths[$i])) {
                $codes[$i] = sprintf('%0' . $codeLengths[$i] . 'b', $nextCode[$codeLengths[$i]]++);
            }
        }

        return array_flip($codes);
    }

    /**
     * Consume bits from an array of 8-bit integers
     *
     * @param int[] $str
     * @param int $len How many bits we want to consume. Must be between 1 and 7, inclusive
     * @param int $pos The current position in the array
     * @param int $consumed The current position in the 8-bit integer
     * @return int
     */
    private static function consume($str, $len, &$pos, &$consumed)
    {
        $origLen = $len;
        $origPos = $pos;
        $origConsumed = $consumed;

        $combined = $len + $consumed;

        if ($combined < 8) {
            $bytes = 0;
        } else {
            $temp = $len - (8 - $consumed);
            $temp2= ($len - $temp) % 8;
            $bytes = floor(($len - $temp - $temp2) / 8);
        }

        if ($consumed) {
            if (!isset($str[$pos])) {
                throw new \OutOfBoundsException("Undefined array key $pos", 0);
            }

            $sublen = min($len, 8 - $consumed);

            $mask = (1 << $sublen) - 1;

            $result = ($str[$pos] >> $consumed) & $mask;
            $len-= $sublen;
            $consumed+= $sublen;
            if ($consumed === 8) {
                $consumed = 0;
                $pos++;
            }
        } else {
            $result = $consumed = 0;
        }

        $offset = $sublen ?? 0;
        while ($bytes--) {
            if (!isset($str[$pos])) {
                throw new \OutOfBoundsException("Undefined array key $pos", $pos - $origPos);
            }
            $result|= $str[$pos++] << $offset;
            $offset+= 8;
            $len-= 8;
        }

        if ($len) {
            if (!isset($str[$pos])) {
                $consumed = $origConsumed;
                throw new \OutOfBoundsException("Undefined array key $pos", $pos - $origPos);
            }
            $mask = (1 << $len) - 1;
            $result|= ($str[$pos] & $mask) << ($origLen - $len);
            $consumed = $len;
        }

        return $result;
    }

    /**
     * Get the huffman code
     *
     * Used in dynamic huffman encoding
     *
     * @param string $string
     * @param int $index
     * @return string
     */
    private static function getCode($payload, &$pos, &$consumed, &$state, $key)
    {
        if (!isset($state['tempCode'])) {
            $state['tempCode'] = '';
        }
        $codeNum = &$state['tempCode'];
        $codeNum.= (string) self::consume($payload, 1, $pos, $consumed);
        while (!isset($state[$key][$codeNum])) {
            $codeNum.= self::consume($payload, 1, $pos, $consumed);
        }
        unset($state['tempCode']);

        return $state[$key][$codeNum];
    }

    /**
     * String Shift
     *
     * Inspired by array_shift
     *
     * @param string $string
     * @param int $index
     * @return string
     */
    private static function shift(&$string, $index = 1)
    {
        $substr = substr($string, 0, $index);
        $string = substr($string, $index);
        return $substr;
    }
}
