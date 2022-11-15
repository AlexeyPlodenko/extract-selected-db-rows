# extract-selected-db-rows

## Description

To be able to extract only the used data from the production/staging database into the local/development one.

## Motivation

As a developer, I have faced a problem, that there is a huge production/staging database, which I do not want to copy/dump/download/import/... locally, everytime I need to test/debug/develop something. As the process would take hours.

Instead, I would prefer to have a "slice" of data, that is really being used in the current run, which I can import into my local database quickly and have fun with that.

The idea is simple. To enable logging on the MySQL server. Access all possible pages of the website, so the database can accumulate queries used. Export the queries into a file. Use a tool, that would automatically process the file and extract the data from the source (staging) database into the destination (local) database.

## How to use

1. Execute `composer install` in your CLI.
2. Create the `/config.php` file:
   ```php
   <?php
   return [
       'logFilePath' => __DIR__ .'/db.log', # the path to the file with queries logged in mysql.general_log
       'stopOnUnsupportedNestedQueryException' => false, # should the processing be stopped, when a nested query is found or not. The nested queries are not supported yet.
       'stopOnMissingIdColumnsException' => true, # should the processing be stopped, when script could not identify the identifying columns of the used table. This usually happens, when there are no PRIMARY and no UNIQUE indexes.
       'insertIgnore' => true, # should the INSERT IGNORE construction be used or just INSERT, to save the data. Useful to set this to TRUE if you running the script multiple times.
       'memoryLimit' => '1G', # PHP memory limit. See the docs for help https://www.php.net/manual/en/ini.core.php#ini.memory-limit
       'timeLimit' => 0, # PHP time limit. See the docs for help https://www.php.net/manual/en/info.configuration.php#ini.max-execution-time
   
       # the source database connection configuration
       'srcDbHost' => '127.0.0.1',
       'srcDbPort' => '3306',
       'srcDbUser' => 'root',
       'srcDbPassword' => '',
       'srcDbDefault' => 'test', # the database that should be used, when the query does not specify the database explicitly
   
       # the destination database connection configuration
       'dstDbHost' => '127.0.0.1',
       'dstDbPort' => '3306',
       'dstDbUser' => 'root',
       'dstDbPassword' => '',
       'dstDbDefault' => 'test_new', # the database that should be used, when the query does not specify the database explicitly
   
       'srcToDstDbMapping' => [ # this maps the source to the destination databases. We should take the rows from the source database and put them into the destination one
           'test' => 'test_new',
       ]
   ];
   ```
3. In your source (staging) MySQL instance execute `SET GLOBAL general_log = 'ON';`, to enable the logging of the queries.
4. Visit the pages of your website, that you want to use locally.
5. In your source (staging) MySQL instance execute `SET GLOBAL general_log = 'OFF';`, to stop the logging.
6. Save into a text plain file the `argument` column from the table `mysql`.`general_log`.
   1. Basically execute the query:
      ```sql
      SELECT `argument`
      FROM `mysql`.`general_log`
      ```
    2. And save the result, as one request per line. I have used HeidiSQL application. Where in the Query tab, I have ran the query, then right clicked the result set and chose `Export grid rows`. The output format should be `Delimited text`.
7. Run the script from the CLI or the browser - `php extract-selected-db-rows.php`

## The log file

One any query per row. The script would process only the SELECT queries.

## Destination databases

The destination databases must be created and have the tables in them.

The simplest way to replicate the existing tables from the source database to the destination one, is to dump the table schemas only (without data) from the source database, and then import the dumps into the destination database.

## Possible improvements

* Combine SQL queries;
* Do SELECT-INSERT in a single command, for the cases when the source and destination servers are the same;
* Refactor the processLogFile() method.