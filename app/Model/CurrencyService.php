<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 14/09/2016
 * Time: 23:32:26
 */

namespace App\Model;


use App\Model\Sparql\BindPattern;
use App\Model\Sparql\SparqlPattern;
use App\Model\Sparql\TriplePattern;
use Asparagus\QueryBuilder;
use Cache;

class CurrencyService extends SparqlModel
{
    public  function getRate($sourceCurrency, $targetCurrency, $year)
    {

        if (Cache::has("rates/$sourceCurrency/$targetCurrency/$year")) {
            return Cache::get("rates/$sourceCurrency/$targetCurrency/$year");
        }

        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));

        if ($sourceCurrency == $targetCurrency) {
            $rate = 1;
            Cache::forever("rates/$sourceCurrency/$targetCurrency/$year", $rate);
            return $rate;
        } elseif ($sourceCurrency != 'EUR' && $targetCurrency == 'EUR') {
            $queryBuilder->where("?xroInfo", 'a', 'xro:ExchangeRateInfo');
            $queryBuilder->where("?xroInfo", 'xro:yearOfConversion', $year);
            $queryBuilder->where("?xroInfo", 'xro:source', "<http://data.openbudgets.eu/codelist/currency/{$targetCurrency}>");
            $queryBuilder->where("?xroInfo", 'xro:target', "<http://data.openbudgets.eu/codelist/currency/{$sourceCurrency}>");
            $queryBuilder->where("?xroInfo", 'xro:rate', "?rate");
            $queryBuilder->selectDistinct("?rate");

            $result = $this->sparql->query(
                $queryBuilder->getSPARQL()
            );
            $results = $this->rdfResultsToArray($result);
            $rate = 1 / $results[0]["rate"];
            Cache::forever("rates/$sourceCurrency/$targetCurrency/$year", $rate);

            return $rate;
        } elseif ($sourceCurrency == 'EUR' && $targetCurrency != 'EUR') {
            $queryBuilder->where("?xroInfo", 'a', 'xro:ExchangeRateInfo');
            $queryBuilder->where("?xroInfo", 'xro:yearOfConversion', $year);
            $queryBuilder->where("?xroInfo", 'xro:source', "<http://data.openbudgets.eu/codelist/currency/{$sourceCurrency}>");
            $queryBuilder->where("?xroInfo", 'xro:target', "<http://data.openbudgets.eu/codelist/currency/{$targetCurrency}>");
            $queryBuilder->where("?xroInfo", 'xro:rate', "?rate");
            $queryBuilder->selectDistinct("?rate");
            $result = $this->sparql->query(
                $queryBuilder->getSPARQL()
            );
            // echo $queryBuilder->format();die;
            $results = $this->rdfResultsToArray($result);
            if (count($results) > 0)
                $rate = $results[0]["rate"];
            else $rate = 1;
            Cache::forever("rates/$sourceCurrency/$targetCurrency/$year", $rate);

            return $rate;

        } elseif ($sourceCurrency != 'EUR' && $targetCurrency != 'EUR') {

            $queryBuilder->where("?xroInfo_source", 'a', 'xro:ExchangeRateInfo');
            $queryBuilder->where("?xroInfo_source", 'xro:yearOfConversion', $year);
            $queryBuilder->where("?xroInfo_source", 'xro:source', "<http://data.openbudgets.eu/codelist/currency/EUR>");
            $queryBuilder->where("?xroInfo_source", 'xro:target', "<http://data.openbudgets.eu/codelist/currency/{$sourceCurrency}>");
            $queryBuilder->where("?xroInfo_source", 'xro:rate', "?rate_source");

            $queryBuilder->where("?xroInfo_target", 'a', 'xro:ExchangeRateInfo');
            $queryBuilder->where("?xroInfo_target", 'xro:yearOfConversion', $year);
            $queryBuilder->where("?xroInfo_target", 'xro:source', "<http://data.openbudgets.eu/codelist/currency/EUR>");
            $queryBuilder->where("?xroInfo_target", 'xro:target', "<http://data.openbudgets.eu/codelist/currency/{$targetCurrency}>");
            $queryBuilder->where("?xroInfo_target", 'xro:rate', "?rate_target");
            $queryBuilder->selectDistinct(["?rate_target", "?rate_source"]);

            $result = $this->sparql->query(
                $queryBuilder->getSPARQL()
            );

            //echo $queryBuilder->format();die;
            $results = $this->rdfResultsToArray($result);
            $rate = $results[0]["rate_source"] / $results[0]["rate_target"];
            Cache::forever("rates/$sourceCurrency/$targetCurrency/$year", $rate);

            return $rate;
        }
        return 1;

    }

    public  $dataSetRates = [];

    /**
     * @return SparqlPattern[]
     */
    public function currencyMagicTriples($subjectBinding, $attributePredicate, $objectBinding, $sourceCurrency, $targetCurrency, $year, $dataset)
    {
        $rate = $this->getRate($sourceCurrency, $targetCurrency, $year);
        $this->dataSetRates[$dataset][$targetCurrency] = $rate;
        /** @var SparqlPattern[] $patterns */
        $patterns = [];
//
//        if ($sourceCurrency == 'EUR' && $targetCurrency == 'EUR') {
//            $patterns[] = new TriplePattern($subjectBinding, $attributePredicate, $objectBinding, false);
//        } else {
            $patterns[] = new TriplePattern($subjectBinding, $attributePredicate, "{$objectBinding}__source", false);

            $patterns[] = new BindPattern("?rate__$targetCurrency*{$objectBinding}__source AS {$objectBinding}");
       // }

        return $patterns;
    }

}