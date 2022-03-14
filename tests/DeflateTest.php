<?php

use zlib_compat\Deflate;

/**
 * @requires extension zlib
 */
class DeflateTest extends PHPUnit\Framework\TestCase
{
    /**
     * Codes need to be stored as strings and not ints as this test demonstrates.
     * eg. if a is 0101 and b is 10110 then b will match as q after 101 if ints
     * are used because the leading 0 won't actually be required
     */
    public function testStringsVSInts()
    {
        $orig = 'ffuqrnkhpdxchzzxrqkpssuxrzizshfhyusmshhlhcwozqirevugnomxopesscem';
        $context = deflate_init(ZLIB_ENCODING_RAW, ['strategy' => ZLIB_FILTERED]);
        $data = deflate_add($context, $orig, ZLIB_FINISH);

        $this->runTests($orig, $data);
    }

    /**
     * This test demonstrates why the distances and lengths need to be flipped
     */
    public function testFlipped()
    {
        $orig = "abaabbbabaababbaababaaaabaaabbbbbaa\n";
        $orig.= $orig;
        $orig.= $orig;

        $context = deflate_init(ZLIB_ENCODING_RAW, ['strategy' => ZLIB_FILTERED]);
        $data = deflate_add($context, $orig, ZLIB_FINISH);
        $this->runTests($orig, $data);

        $context = deflate_init(ZLIB_ENCODING_RAW);
        $data = deflate_add($context, $orig, ZLIB_FINISH);
        $this->runTests($orig, $data);
    }

    public function testLengths()
    {
        $orig = 'gtishzaeiyiyyckjjbkvxlvkbffethbznxqhihzhzugbaouxkmcgggbddqymjipp';
        $context = deflate_init(ZLIB_ENCODING_RAW, ['strategy' => ZLIB_FILTERED]);
        $data = deflate_add($context, $orig, ZLIB_FINISH);

        $this->runTests($orig, $data);
    }

    /**
     * This test demonstrates why you need to do $processed < $payloadLength vs <=
     */
    public function testOffByOne()
    {
        $orig = 'ccddcdccbacdadaabbbabbcdbddbdbdabddadcbcabccbadacdcadaacaaabcdacddcacdbadbcdccaaabdcddaabdbdcacddcbadbbdaccbccbcabddcdcdacabbacd';
        $context = deflate_init(ZLIB_ENCODING_RAW, ['strategy' => ZLIB_FILTERED]);
        $data = deflate_add($context, $orig, ZLIB_FINISH);

        $this->runTests($orig, $data);
    }

    public function testLengths2()
    {
        $orig = 'ccddcdccbacdadaabbbabbcdbddbdbdabddadcbcabccbadacdcadaacaaabcdacddcacdbadbcdccaaabdcddaabdbdcacddcbadbbdaccbccbcabddcdcdacabbacd';
        $orig.= $orig;
        $orig.= $orig;

        $context = deflate_init(ZLIB_ENCODING_RAW, ['strategy' => ZLIB_FIXED, 'level' => 9]);
        $data = deflate_add($context, $orig, ZLIB_FINISH);

        $this->runTests($orig, $data);
    }

    public function testTwoBlocks()
    {
        $orig = 'ccdcbbccdadcbcdacaadbacccdcbbaba';

        $context = deflate_init(ZLIB_ENCODING_RAW, ['strategy' => ZLIB_FILTERED]);
        $data = deflate_add($context, $orig, ZLIB_PARTIAL_FLUSH);
        $data.= deflate_add($context, $orig, ZLIB_FINISH);

        $this->runTests($orig . $orig, $data);
    }

    /**
     * Sync Flushes add a "no compression" block of 00 00 FF FF between the two main blocks
     */
    public function testTwoBlocksWithExtraNoCompression()
    {
        $orig = 'ccdcbbccdadcbcdacaadbacccdcbbaba';

        $context = deflate_init(ZLIB_ENCODING_RAW, ['strategy' => ZLIB_FILTERED]);
        $data = deflate_add($context, $orig, ZLIB_SYNC_FLUSH);
        $data.= deflate_add($context, $orig, ZLIB_FINISH);

        $this->runTests($orig . $orig, $data);
    }

    public function testTwoBlocks2()
    {
        $orig = 'ccdcbbccdadcbcdacaadbacccdcbbaba';

        $context = deflate_init(ZLIB_ENCODING_RAW, ['strategy' => ZLIB_FILTERED]);
        $data = deflate_add($context, $orig, ZLIB_FULL_FLUSH);
        $data.= deflate_add($context, $orig, ZLIB_FINISH);

        $this->runTests($orig . $orig, $data);
    }

    public function testTwoBlocks3()
    {
        $orig = 'ccdcbbccdadcbcdacaadbacccdcbbaba';

        $context = deflate_init(ZLIB_ENCODING_RAW, ['strategy' => ZLIB_FILTERED]);
        $data = deflate_add($context, $orig, ZLIB_BLOCK);
        $data.= deflate_add($context, $orig, ZLIB_FINISH);

        $this->runTests($orig . $orig, $data);
    }

    public function testTwoBlocks4()
    {
        $orig = 'ccdcbbccdadcbcdacaadbacccdcbbaba';

        $context = deflate_init(ZLIB_ENCODING_RAW, ['strategy' => ZLIB_FILTERED]);
        $data = deflate_add($context, $orig, ZLIB_FINISH);
        $data.= deflate_add($context, $orig, ZLIB_FINISH);

        $this->runTests($orig . $orig, $data);
    }

    private function runTests($expected, $compressed)
    {
        // test decompression the entire string
        $deflate = new Deflate(ZLIB_ENCODING_RAW);
        $output = $deflate->decompress($compressed);
        $this->assertSame($expected, $output);

        // test decompressing the string with one byte at a time
        $deflate = new Deflate(ZLIB_ENCODING_RAW);
        $context = inflate_init(ZLIB_ENCODING_RAW);
        $aFull = $bFull = '';
        for ($i = 0; $i < strlen($compressed); $i++) {
            $aFull.= ($a = inflate_add($context, $compressed[$i]));
            $bFull.= ($b = $deflate->decompress($compressed[$i]));
            $this->assertSame($a, $b);
        }
        $this->assertSame($aFull, $bFull);
    }
}