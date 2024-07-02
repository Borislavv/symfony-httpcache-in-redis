# Symfony HttpCache implementation through Redis as a storage. 

## Up: 
```
docker-compose up
```

## Example: 
Visit http://0.0.0.0:8080/. The first request will take approximate 5 seconds, but further will handle for 5-30 milliseconds (of course it depends of your hardware).

## Note: 
Check the composer.json file, exactly the extra block, there is redeclared the SymfonyRuntime.
