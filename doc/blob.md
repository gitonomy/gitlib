Blob
====

In git, a blob is the content of a file. A `Blob` object doesn't know the
name of its file: names are stored in trees, not in blobs. Two files with
different names but the same content have the same blob.

To get the blob of a file, resolve its path in the tree of a commit (see
[tree.md](tree.md)):

```php
use Gitonomy\Git\Repository;

$repository = new Repository('/path/to/repository');
$blob = $repository->getRevision('master')->getCommit()->getTree()->resolvePath('README.md');
```

`Repository::getBlob()` takes the hash of a blob, abbreviated or not:

```php
$blob = $repository->getBlob('07eeea9');

echo $blob->getHash().PHP_EOL; // "07eeea94584fb6f1d8fe55e5b78adc4668c02508", always the full hash
```

It throws a `ReferenceNotFoundException` for anything else than a hash, of
4 characters at least, and for an abbreviated hash naming nothing.

Get content
-----------

To get the content of a `Blob` object:

```php
echo $blob->getContent();
```

File informations
-----------------

`getMimetype()` returns the mimetype of the content, alone, like
`text/plain` or `image/jpeg`. It needs the `fileinfo` extension of PHP, and
throws a `RuntimeException` when it can't read the mimetype:

```php
echo $blob->getMimetype().PHP_EOL;
```

`isText()` and `isBinary()` tell if the blob is a text or a binary file.
They check its first 8000 bytes for a NUL character, like git does:

```php
if ($blob->isText()) {
    echo $blob->getContent().PHP_EOL;
} elseif ($blob->isBinary()) {
    echo 'File is binary'.PHP_EOL;
}
```
