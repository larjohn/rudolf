<?php

use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class ParserTest extends TestCase
{

    /**
     * A basic functional test example.
     *
     * @return void
     */
    public function testBasicExample()
    {
        $parser = new App\Model\Parsers\Parser();
        $parser->parse("foo:bar");
        $this->json("GET","api/3/cubes")->assertResponseOk();

    }
}
