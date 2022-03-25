<?php

use zlib_compat\Deflate;

/**
 * @requires extension zlib
 */
class DeflateTest extends PHPUnit\Framework\TestCase
{
    /**
     * Uncompressed example from https://blog.za3k.com/understanding-gzip-2/
     */
    public function testZA3KUncompressed()
    {
        $data = pack('H*', '010f00f0fffffefdfcfbfaf9f8f7f6f5f4f3f2f1');
        $this->runTests($data);
    }

    /**
     * Fixed Huffman example from https://blog.za3k.com/understanding-gzip-2/
     */
    public function testZA3KFixed()
    {
        $data = pack('H*', 'cb48cdc9c957c84027b900');
        $this->runTests($data);
    }

    /**
     * Dynamic Huffman example from https://blog.za3k.com/understanding-gzip-2/
     */
    public function testZA3KDynamic()
    {
        $data = pack('H*', '1dc6490100001040c0aca37f883d3c202a979d375e1d0c');
        $this->runTests($data);
    }

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

        $this->runTests($data);
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
        $this->runTests($data);

        $context = deflate_init(ZLIB_ENCODING_RAW);
        $data = deflate_add($context, $orig, ZLIB_FINISH);
        $this->runTests($data);
    }

    public function testLengths()
    {
        $orig = 'gtishzaeiyiyyckjjbkvxlvkbffethbznxqhihzhzugbaouxkmcgggbddqymjipp';
        $context = deflate_init(ZLIB_ENCODING_RAW, ['strategy' => ZLIB_FILTERED]);
        $data = deflate_add($context, $orig, ZLIB_FINISH);

        $this->runTests($data);
    }

    /**
     * This test demonstrates why you need to do $processed < $payloadLength vs <=
     */
    public function testOffByOne()
    {
        $orig = 'ccddcdccbacdadaabbbabbcdbddbdbdabddadcbcabccbadacdcadaacaaabcdacddcacdbadbcdccaaabdcddaabdbdcacddcbadbbdaccbccbcabddcdcdacabbacd';
        $context = deflate_init(ZLIB_ENCODING_RAW, ['strategy' => ZLIB_FILTERED]);
        $data = deflate_add($context, $orig, ZLIB_FINISH);

        $this->runTests($data);
    }

    public function testLengths2()
    {
        $orig = 'ccddcdccbacdadaabbbabbcdbddbdbdabddadcbcabccbadacdcadaacaaabcdacddcacdbadbcdccaaabdcddaabdbdcacddcbadbbdaccbccbcabddcdcdacabbacd';
        $orig.= $orig;
        $orig.= $orig;

        $context = deflate_init(ZLIB_ENCODING_RAW, ['strategy' => ZLIB_FIXED, 'level' => 9]);
        $data = deflate_add($context, $orig, ZLIB_FINISH);

        $this->runTests($data);
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

        $this->runTests($data);
    }

    public function testLongString()
    {
        $orig = 'e4ad43f1f2c48a2619f44a6f0aae5f0e7233795f1ed7888c9da3dbc4ccc427' .
                'faf75a9b2f5d685911d1765387ea1b7b5e8a4776c4f2bc33f962f54d6af262' .
                '5311e8a749663597047138abc451249410a9109b8a8762a5f8279ba47ede6d' .
                'af86278bc5eabb3f6d80f6e9e4302507eb3e87ba84e45c5c3ae4bdee4af3a0' .
                'eb36c4e0c4f14d671e156c3e5fecd419a4af353577821003d2e662028dc2bb' .
                'b3a5af0a0dbf83af6e3c771f67ecb20d4332136e92e0a305d6d1c940aaa0a4' .
                '9c81e494901c5c6b166a15e2cd98a1155bd585c824d5ea4b2ddf6f9a7e6ffc' .
                '1d6202a225a6d0b6222bae14cb138ff9c0017d0b62c09bb75bbcbc4d1a4446' .
                '533fbcecc47af0ba59ee7c5db65a7a9527fdcf79503707da2117678fd7156d' .
                '7dbf350c44a4a29cbde7928d433b27bd200c6f6ad1bf7f7c90d2884135d326' .
                '7328d1eeccc47b990bd9ea79d9df79183c1b7bc60f12a4bdb431a0116d9c8a' .
                '622c3f42eea388d161443e7d708c7971d997b1cabfaa1e5a81603be66a441f' .
                'c80de31439514367977982142f92bf86d42b91fd772ffffb135344ec39e0aa' .
                '0c5b8b271f5acbdb0e09cffd4e4ee779a1e9027cf1a5e61ad811d37932f4be' .
                '1ff128a449a63e5d624338e8f59a77a740f0466c53cca963c47c18f9e44534' .
                '4b254b10b53abb5b860c38d2fc1c41f12d8cc4caeffe72846eb6c5e16f7da3' .
                '9e5b33c2d9e8a08898a60b3ec7a18d11';

        $this->runTests(gzdeflate($orig));
    }

    private function runTests($compressed)
    {
        // test decompression the entire string
        $deflate = new Deflate(ZLIB_ENCODING_RAW);
        $output = $deflate->decompress($compressed);
        // when the last block was flushed with ZLIB_NO_FLUSH it won't be included in the
        // decompressed string
        // gzinflate($compressed) doesn't work because it seems to return the empty string
        // if the last block isn't final (?) so we use inflate_add()
        $ref = inflate_add(inflate_init(ZLIB_ENCODING_RAW), $compressed);
        $this->assertSame($ref, $output);

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