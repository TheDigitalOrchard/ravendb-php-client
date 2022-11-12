# Official PHP client for RavenDB NoSQL Database


## Introduction
PHP client API (v5.2) for [RavenDB](https://ravendb.net/) , a NoSQL document database.

**Package has been made to match Java and other RavenDB clients**

## Installation

You can install library to your project via [Composer](https://getcomposer.org/)

``` bash
$ composer require ravendb/ravendb-php-client
```


## How to start with RavenDB in PHP?

```injectablephp

use RavenDB\Documents\DocumentStore;
use YourClass\Company;

$store = new DocumentStore(["http://localhost:8080" ], "Northwind")

try {
    $store->initialize();
    
    $companyId = null;
    
    //store new object
    $session = $store->openSession();
    try {
        $entity = new Company();
        $entity->setName("Company");
        $session->store($entity);
        $session->saveChanges();
    
        // after calling saveChanges(), an id field if exists
        // is filled by the entity's id
        $companyId = $entity->getId();
    } finally {
        $session->close();
    }
    
    $session = $store->openSession();
    try {
        //load by id
        $entity = $session->load(Company::class, $companyId);
    
        // do something with the loaded entity
    } finally {
        $session->close();
    }

} finally {
    $store->close();
}

```

Read more about **RavenDB** and how to use it in our [documentation](https://ravendb.net/docs/).

## Client features

- *session*
    - ability to track objects
    - crud
    - delete
    - include
    - no tracking
    - cluster transactions
    - conditional load

- *attachments*
    - crud
    - session
    - move, rename

- *indexes*
    - crud (static/auto)
    - modify state: (setting index priority, enabling/disabling indexes, start/stop index, list/clean indexing errors, getting terms)

- *query*
    - static/dynamic indexes
    - document query methos (where equals, starts with, etc)
    - aggregation (group by )
    - count, order, take/skip
    - boost, proximity, fuzzy
    - select fields (projection)
    - delete/patch by query

- *https support*
    - certificates crud
    - request executor

- *compare exchange*
    - crud
    - session

- *patch*
    - by script
    - by path

- *databases*
    - crud

----
#### RavenDB Documentation
[https://ravendb.net/docs/](https://ravendb.net/docs/)


-----
##### Bug Tracker
[http://issues.hibernatingrhinos.com/issues/RDBC](http://issues.hibernatingrhinos.com/issues/RDBC)

-----
##### License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
