<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 17/03/2016
 * Time: 13:57:23
 */

namespace App\Model;


use App\Model\Globals\GlobalDimension;
use App\Model\Globals\GlobalMeasure;
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
            'timeout'         => 350]));
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


    protected static function expand(string $shortUri, $transitivity=null){

        //dd(EasyRdf_Namespace::namespaces());
        //var_dump($shortUri);
        if($shortUri[0]=="?") return  $shortUri;
        return '<'.EasyRdf_Namespace::expand($shortUri).'>'.$transitivity;

    }

    protected function rdfResultsToArray(EasyRdf_Sparql_Result $result)
    {
        $results = [];
        foreach ($result as $row) {
            $added = [];
            $fields = $result->getFields() ;
            foreach ($fields as $field) {
                if(!isset($row->$field))continue;
                $value = $row->$field;
                if ($value instanceof EasyRdf_Literal) {
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
        //dd($model);
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
                           // dd($model);
                         /*   var_dump($innerAttributeName);
                            var_dump($innerAttribute->getVirtual());*/
                            if($innerAttribute->getVirtual())continue;
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

    private $nameCache = [];


    protected function rdfResultsToArray3(EasyRdf_Sparql_Result $result, array $attributes, BabbageModel $model, array $selectedFields, $forceCompleteness=false)
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
                        $alternativeBinding = $attributes[$selectedFieldName]["value"]."_";

                    }
                    else if(isset($attributes[$selectedFieldName]["sum"])){
                        $selectedBinding = $attributes[$selectedFieldName]["sum"];
                        /* Support for variable scope with alternative binding name */
                        $alternativeBinding = $attributes[$selectedFieldName]["sum"]."__";
                        $aggregateSuffix = ".sum";

                    }
                    else {
                        $selectedBinding = $attributes[$selectedFieldName]["uri"];
                        $alternativeBinding = $attributes[$selectedFieldName]["uri"]."_";

                    }
                    if(isset($row->$selectedBinding))$value = $row->$selectedBinding;
                    elseif(isset($alternativeBinding) && isset($row->$alternativeBinding))$value = $row->$alternativeBinding;
                    else continue;

                    if ($value instanceof EasyRdf_Literal ) {

                        /** @var EasyRdf_Literal $value */
                        $val  = $value->getValue();

                        if($value instanceof EasyRdf_Literal_Decimal || $value->getDatatype()=="xsd:float" || $value->getDatatype()=="xsd:decimal" )
                            $val = floatval($val);
                        elseif($value instanceof EasyRdf_Literal_Integer || $value->getDatatype()=="xsd:integer" )
                            $val = intval($val);
                        if(!is_numeric($val) || is_nan($val) ) $val = 0;


                    } else {
                        /** @var \EasyRdf_Resource $value */
                        $val = $value->dumpValue('text');
                    }
                    $finalName = $this->getAttributeRef($model, [$selectedFieldName]).$aggregateSuffix;
                    $added[$finalName] = $val;

                }

                else{
                   // dump($actualFields);
                    $alternativeBinding = $attributes[$selectedFieldName]["uri"];

                    $altName = $this->getAttributeRef($model, [$selectedFieldName, $selectedFieldName]);
                    $alternativeBinding2 = $attributes[$selectedFieldName]["uri"].'_';
                    if($forceCompleteness && isset($altName) && (isset($alternativeBinding)||isset($alternativeBinding2))){
                        if(is_array($altName)){
                            foreach ($altName as $item) {
                                if(isset($row->$alternativeBinding))
                                    $added[$item] = $row->$alternativeBinding->dumpValue('text');
                                if(isset($row->$alternativeBinding2))
                                    $added[$item] = $row->$alternativeBinding2->dumpValue('text');

                            }
                        }
                        else{
                            if(isset($row->$alternativeBinding))
                                $added[$altName] = $row->$alternativeBinding->dumpValue('text');
                            if(isset($row->$alternativeBinding2))
                                $added[$altName] = $row->$alternativeBinding2->dumpValue('text');

                        }
                    }
                    foreach ($selectedField as $subPropertyName=>$subProperty){
                        $finalName = $this->getAttributeRef($model, [$selectedFieldName, $subPropertyName]);


                        if(!isset($attributes[$selectedFieldName][$subPropertyName])){
                           $val =($row->$alternativeBinding->dumpValue('text'));
                        }
                        else{
                            $selectedBinding = $attributes[$selectedFieldName][$subPropertyName];
                            if(!isset($row->$selectedBinding)){

                                if(!isset($row->{"{$selectedBinding}_"}))
                                    continue;
                                $value = $row->{"{$selectedBinding}_"};
                            }
                            else
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

                        }
                        $added[$finalName] = $val;


                    }
                }


            }
            /** @var EasyRdf_Sparql_Result $row */
            if(in_array("_count", $actualFields)){
                if(isset($row->_count))
                    $added["_count"] = intval($row->_count->getValue());
            }
            if(in_array("count", $actualFields)){
                if(isset($row->count))
                    $added["_count"] = intval($row->count->getValue());
            }
            if(!empty($added))
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

        if(isset($this->nameCache[implode(".",$path)])) return $this->nameCache[implode(".",$path)];
       // dump($path);
        foreach ($model->dimensions as $dimensionName => $dimension){
            if($path[0]==$dimension->getUri()){

                if(count($path)>1){
                    if($path[0]==$path[1]) {
                        $this->nameCache[implode(".",$path)] =[$dimension->label_ref, $dimension->key_ref];

                        return [$dimension->label_ref, $dimension->key_ref];
                    }
                    foreach ($dimension->attributes as $attribute){
                        if($attribute->getUri()== $path[1]){
                            $this->nameCache[implode(".",$path)] = $attribute->ref;
                            return $attribute->ref;
                        }
                    }
                }
                else{
                    $this->nameCache[implode(".",$path)] =$dimension->key_ref;

                    return $dimension->key_ref;
                }
            }
            elseif($dimension instanceof GlobalDimension){

                /** @var Dimension $inner */
                foreach ($dimension->getInnerDimensions() as $innerName=>$inner) {
                    if($path[0]==$inner->getUri()){
                        if(count($path)>1){
                            if($path[0]==$path[1]) {
                                $this->nameCache[implode(".",$path)] =[$dimension->label_ref, $dimension->key_ref];

                                return [$dimension->label_ref, $dimension->key_ref];
                            }
                            foreach ($inner->attributes as $attribute){
                                if($attribute->getUri()== $path[1]){
                                    if($attribute->ref == $inner->label_ref){
                                        $this->nameCache[implode(".",$path)] =$dimension->label_ref;

                                        return $dimension->label_ref;
                                    }
                                    else{
                                        $this->nameCache[implode(".",$path)] =$dimension->key_ref;

                                        return $dimension->key_ref;

                                    }
                                }
                            }
                        }
                        else{
                            $this->nameCache[implode(".",$path)] =$dimension->key_ref;
                            return $dimension->key_ref;
                        }
                    }
                }
            }
        }

        foreach ($model->measures as $measureName => $measure){
            if($measure instanceof GlobalMeasure){
                if($measure->getUri() == $path[0] || $measure->getSpecialUri() == $path[0]){
                    $this->nameCache[implode(".",$path)] =$measure->ref;

                    return $measure->ref;
                }
            }
            else {
                if($measure->getUri() == $path[0]){
                    $this->nameCache[implode(".",$path)] =$measure->ref;

                    return $measure->ref;
                }
            }

        }

        return null;

    }

    protected $bindingsToLanguages = [];

    public function buildLanguageFilterExpression($binding){

        if(!isset($this->bindingsToLanguages[$binding]))return null;
        $language = $this->resolveLanguages($this->bindingsToLanguages[$binding]);
        return "LANG({$binding}) = '$language' || LANG({$binding}) = ''";
    }

    public function resolveLanguages($availableLanguages){
        $preferredLanguages = config("sparql.languagesOrder", ["en"]);
        $selectedLanguage = "en";
            foreach ($preferredLanguages as $preferredLanguage) {
                foreach ($availableLanguages as $availableLanguage){
                    if($preferredLanguage==$availableLanguage){
                    $selectedLanguage = $preferredLanguage;
                    break 2;
                }

            }
        }

        return $selectedLanguage;
    }

}