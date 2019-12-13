Developing gitlib
=================

If you plan to contribute to gitlib, here are few things you should know:

Documentation
-------------

Documentation is now in [Markdown](https://en.wikipedia.org/wiki/Markdown) and hosted directly on Github

Test against different git versions
-----------------------------------

A script `test-git-version.sh` is available in repository to test gitlib against many git versions.

This script is not usable on Travis-CI, they would hate me for this. It creates a local cache to avoid fetching from Github and compiling if already compiled.

Use it at your own risk, it's still under experiment.
