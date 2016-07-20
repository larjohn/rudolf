<?php

use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class CubeTest extends TestCase
{

    /**
     * A basic functional test example.
     *
     * @return void
     */
    public function test_attr_table_different_from_dimension_key()
    {
        /*
         *   @raises(BindingException)
        def test_attr_table_different_from_dimension_key(self):
        model = self.cra_model.copy()
        model['dimensions']['cap_or_cur']['attributes']['code']['column'] = 'cap_or_cur'
        self.cube = Cube(self.engine, 'cra', model)
        self.cube.facts()

         */

        $model = $this->get("api/3/cubes/cra__dc073/model")->decodeResponseJson()["model"];
        $this->assertEquals('classification', $model["dimensions"]["classification"]["attributes"]["classification"]["column"]);
        $this->json("GET","api/3/cubes/cra__dc073/model")->assertResponseOk();

    }
}
