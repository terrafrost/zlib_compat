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

    /**
     * Produces all combinations of test values.
     *
     * @return array
     */
    public function twoBlockCombos()
    {
        $strats = [
            ZLIB_FILTERED,        // 1
            ZLIB_HUFFMAN_ONLY,    // 2
            ZLIB_RLE,             // 3
            ZLIB_FIXED,           // 4
            ZLIB_DEFAULT_STRATEGY // 0
        ];
        $flushes = [
            ZLIB_BLOCK,           // 5
            ZLIB_NO_FLUSH,        // 0
            ZLIB_PARTIAL_FLUSH,   // 1
            // Sync Flushes add a "no compression" block of 00 00 FF FF between the two main blocks
            ZLIB_SYNC_FLUSH,      // 2
            ZLIB_FULL_FLUSH,      // 3
            ZLIB_FINISH           // 4
        ];

        $result = [];

        foreach ($strats as $strat) {
            foreach ($flushes as $flush1) {
                foreach ($flushes as $flush2) {
                    $result[] = [$strat, $flush1, $flush2];
                }
            }
        }

        return $result;
    }

    /**
     * @dataProvider twoBlockCombos
     */
    public function testTwoBlocks($strat, $flush1, $flush2)
    {
        $orig = 'ccdcbbccdadcbcdacaadbacccdcbbaba';

        $context = deflate_init(ZLIB_ENCODING_RAW, ['strategy' => $strat]);
        $data = deflate_add($context, $orig, $flush1);
        $data.= deflate_add($context, $orig, $flush2);

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