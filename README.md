# phpdatabaseconnection
Simple PHP Database Connection Class for MySQL

# Documentation

###### New instance
```php
$DatabaseConnection = new DatabaseConnection(SYSTEM_DB_HOST, SYSTEM_DB_USERNAME, SYSTEM_DB_PASSWORD, SYSTEM_DB_NAME);
```

###### Simple query
```php
$DatabaseConnection->QUERY('sql query') { // returns true or mysqli_result object otherwise ErrorInfo
```

###### Select one or multiple rows
```php
$DatabaseConnection->SELECT('users', ['id', 'mail'], 'id > 10 AND mail IS NOT NULL', 'name DESC, id ASC') {  // returns result; otherwise ErrorInfo
```

###### Select a single value
```php
$DatabaseConnection->GET('users', 'mail', 'id = 1') // returns result; otherwise ErrorInfo
```

###### Add
```php
$DatabaseConnection->ADD('users', ['firstname' => 'foo', 'lastname' => 'bar']) { // returns insertid; otherwise ErrorInfo
```

###### Update one or multiple rows
```php
$DatabaseConnection->UPDATE('users', ['firstname' => 'foo2', 'lastname' => 'bar2'], 'id = 1') { // returns number affectedrows (can be zero if no changes in records); otherwise ErrorInfo
```

###### Update an single value
```php
$DatabaseConnection->SET('users', 'firstname', 'foo2', 'id = 1') { // returns number affectedrows (can be zero if no changes in records); otherwise ErrorInfo
```

###### Delete
```php
$DatabaseConnection->DELETE('users', 'id = 1') { // returns number affectedrows (can be zero if no changes in records); otherwise ErrorInfo
```

###### Count
```php
$DatabaseConnection->COUNT('users', 'firstname = foo') { // returns count; otherwise ErrorInfo
```
