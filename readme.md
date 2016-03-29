# rudolf

**rudolf** is an HTTP API that wraps a SPARQL endpoint to expose [OpenBudgets.eu RDF-schema](https://github.com/openbudgets/data-model) - compatible datasets, according to OpenSpending's [babbage API](https://github.com/openspending/babbage) specification. **rudolf**'s name was chosen to remind the letters contained in the RDF acronym.

## Installation

**rudolf** is based on Laravel 5.2.
In order to install **rudolf**, you first have to setup a web server that supports the execution of PHP7 scripts. **rudolf** may also work with PHP >5.5.9, but it has not been yet tested with versions of PHP other than version 7.

To support friendly URL's please make sure you have enabled `mod_rewrite` in your PHP installation.

After setting up your environment, you can install **rudolf**:
1. Clone this repository:

    `git clone https://github.com/larjohn/rudolf.git`

2. Get into the newly created directory:

    `cd rudolf`

3. Run composer to install dependencies:

    `composer install`
## Configuration

After installing **rudolf**, change the configuration file located at `config/sparql.php` and set the appropriate endpoint URI.

## Official API Documentation

Documentation for the API can be found in the [official OpenSpending pages](http://docs.openspending.org/en/latest/developers/platform/).

## Roadmap
Current version:

* v0.1: Initial version - partial support of the babbage API

Next versions
* v0.2: Code cleanup, support hierarchical dimensions
* v0.3: Support cosmopolitan API equivalents
## License

The **rudolf** API is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)
