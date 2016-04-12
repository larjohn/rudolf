<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 17/03/2016
 * Time: 13:57:23
 */

namespace App\Model;


use EasyRdf_Literal;
use EasyRdf_Literal_Decimal;
use EasyRdf_Literal_Integer;
use EasyRdf_Namespace;
use EasyRdf_Sparql_Client;
use EasyRdf_Sparql_Result;

class SparqlModel
{
    public function  __construct(){
        \EasyRdf_Http::setDefaultHttpClient(new \EasyRdf_Http_Client(null, ['maxredirects'    => 5,
            'useragent'       => 'EasyRdf_Http_Client',
            'timeout'         => 100]));
        $this->sparql = new EasyRdf_Sparql_Client(config("sparql.endpoint"));
        foreach (config("sparql.prefixes") as $prefix=>$uri) {
            //dd($prefix);
            EasyRdf_Namespace::set($prefix, $uri);
        }
    }

    /**
     * @var EasyRdf_Sparql_Client
     */
    protected $sparql;


    protected static function expand(string $shortUri){

        //dd(EasyRdf_Namespace::namespaces());
        return '<'.EasyRdf_Namespace::expand($shortUri).'>';

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

    protected function modelFieldsToPatterns(BabbageModel $model, $fields){
        $selectedDimensions = [];

        foreach ($fields as $field) {
            $fieldNames = explode(".", $field);
            foreach ($model->measures as $name => $attribute) {
                if($fieldNames[0] == $name){
                    $selectedDimensions[$attribute->getUri()] = [];
                }
            }
            foreach ($model->dimensions as $name =>$attribute) {
                //var_dump($fieldNames);
                if($fieldNames[0] == $name){
                    if(!isset($selectedDimensions[$attribute->getUri()] )){
                        $selectedDimensions[$attribute->getUri()] = [];
                    }
                    $currentAttribute = $attribute;
                    for ($i=1;$i<count($fieldNames);$i++){
                        foreach ($currentAttribute->attributes as $innerAttributeName=> $innerAttribute) {
                            if($fieldNames[$i]==$fieldNames[$i-1])continue;

                            if($fieldNames[$i]==$innerAttributeName) {

                                $selectedDimensions[$attribute->getUri()][$innerAttribute->getUri()] = [];
                                break;

                            }
                        }
                    }

                }

            }

        }

        return ($selectedDimensions);
    }

    protected function rdfResultsToArray3(EasyRdf_Sparql_Result $result, array $attributes, BabbageModel $model, array $selectedFields)
    {
        $results = [];
        $actualFields = $result->getFields();
        foreach ($result as $row) {
            $added = [];

            foreach ($selectedFields as  $selectedFieldName=>$selectedField) {
                if(count($selectedField)<1) {
                    $aggregateSuffix = "";
                    if (isset($attributes[$selectedFieldName]["value"])) {
                        $selectedBinding = $attributes[$selectedFieldName]["value"];
                    }
                    else if(isset($attributes[$selectedFieldName]["sum"])){
                        $selectedBinding = $attributes[$selectedFieldName]["sum"];
                        $aggregateSuffix = ".sum";

                    }
                    else {
                        $selectedBinding = $attributes[$selectedFieldName]["uri"];

                    }
                    $value = $row->$selectedBinding;

                    if ($value instanceof EasyRdf_Literal ) {
                        /** @var EasyRdf_Literal $value */
                        $val  = $value->getValue();
                        if($value instanceof EasyRdf_Literal_Decimal)
                            $val = floatval($val);
                        elseif($value instanceof EasyRdf_Literal_Integer)
                            $val = intval($val);


                    } else {
                        /** @var \EasyRdf_Resource $value */
                        $val = $value->dumpValue('text');
                    }
                    $finalName = $this->getAttributeRef($model, [$selectedFieldName]).$aggregateSuffix;
                    $added[$finalName] = $val;

                }

                else{
                    foreach ($selectedField as $subPropertyName=>$subProperty){
                        $selectedBinding = $attributes[$selectedFieldName][$subPropertyName];
                        if(!isset($row->$selectedBinding))continue;
                        $value = $row->$selectedBinding;

                        if ($value instanceof EasyRdf_Literal ) {
                            /** @var EasyRdf_Literal $value */
                            $val  = $value->getValue();
                            if($value instanceof EasyRdf_Literal_Decimal)
                                $val = floatval($val);
                            elseif($value instanceof EasyRdf_Literal_Integer)
                                $val = intval($val);


                        } else {
                            /** @var \EasyRdf_Resource $value */
                            $val = $value->dumpValue('text');
                        }

                        $finalName = $this->getAttributeRef($model, [$selectedFieldName, $subPropertyName]);
                        $added[$finalName] = $val;

                    }
                }

            }
            /** @var EasyRdf_Sparql_Result $row */
            if(in_array("_count", $actualFields)){
                $added["_count"] = intval($row->_count->getValue());
            }
            $results[] = $added;
        }
        return $results;
    }

    protected function array_get($arr, $segments)
    {

        $cur =& $arr;
        foreach ($segments as $segment) {
            if (!isset($cur[$segment]))
                return null;

            $cur = $cur[$segment];
        }

        return $cur;
    }

    protected function array_set(&$arr, $segments, $value)
    {
        $cur =& $arr;
        foreach ($segments as $segment) {
            if (!isset($cur[$segment]))
                $cur[$segment] = array();
            $cur =& $cur[$segment];
        }
        $cur = $value;
    }
    protected function getAttributePathByName(BabbageModel $model, array $path){
        $result = [];
        foreach ($model->dimensions as $dimensionName => $dimension){

            if($path[0]==$dimensionName){
                $result[] = $dimension->getUri();

                if(count($path)>1){
                    if($path[0]==$path[1]){
                        return $result;
                    }
                    foreach ($dimension->attributes as $attributeName=>$attribute){
                        if($attributeName== $path[1]){
                            $result[] = $attribute->getUri();
                            return $result;
                        }
                    }
                }
                else{
                    return [$dimensionName];
                }
            }
        }

        foreach ($model->measures as $measureName => $measure){
            if($measureName == $path[0]){
                $result[] = $measure->getUri();
                return $result;
            }
        }

        return $result;

    }

    private function getAttributeRef(BabbageModel $model, array $path){
        foreach ($model->dimensions as $dimensionName => $dimension){
            if($path[0]==$dimension->getUri()){
                if(count($path)>1){
                    foreach ($dimension->attributes as $attribute){
                        if($attribute->getUri()== $path[1]){
                            return $attribute->ref;
                        }
                    }
                }
                else{
                    return $dimensionName.".".$dimensionName;
                }
            }
        }

        foreach ($model->measures as $measureName => $measure){
            if($measure->getUri() == $path[0]){
                return $measure->ref;
            }
        }

    }

}