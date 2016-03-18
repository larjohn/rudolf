<?php

namespace App\Http\Controllers;

use EasyRdf_Literal;
use EasyRdf_Literal_Decimal;
use EasyRdf_Literal_Integer;
use EasyRdf_Sparql_Client;
use EasyRdf_Sparql_Result;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\{
    ValidatesRequests
};
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    public function  __construct(){
        $this->sparql = new EasyRdf_Sparql_Client('http://localhost:9999/blazegraph/namespace/obeu/sparql');
    }

    /**
     * @var EasyRdf_Sparql_Client
     */
    protected $sparql;

    protected function flatten_data_type(string $dataType){
        /*
         *   'string': types.Unicode,
    'integer': types.Integer,
    'bool': types.Boolean,
    'float': types.Float,
    'decimal': types.Float,
    'date': types.Date
         */

        switch ($dataType){
            case "xsd:decimal":
                return "decimal";

            case "rdf:langString":
            default:
                return "string";
        }
    }




    protected function rdfResultsToArray(EasyRdf_Sparql_Result $result)
    {
        $results = [];
        foreach ($result as $row) {
            $added = [];
            foreach ($result->getFields() as $field) {
                if(!isset($row->$field))continue;
                $value = $row->$field;
                if (get_class($value) == EasyRdf_Literal::class || is_subclass_of($value, EasyRdf_Literal::class)) {
                    /** @var EasyRdf_Literal $value */
                    $val  = $value->getValue();
                    if($value instanceof EasyRdf_Literal_Decimal)
                        $val = floatval($val);
                    elseif($value instanceof EasyRdf_Literal_Integer)
                        $val = intval($val);
                    $added[$field] = $val;
                } else {
                    /** @var \EasyRdf_Resource $value */
                    $added[$field] = $value->dumpValue('text');
                }

            }
            $results[] = $added;
        }
        return $results;
    }

}
