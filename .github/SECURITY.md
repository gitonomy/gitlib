# SECURITY POLICY

## Supported Versions

Only 2.x gets security fixes: 1.x is no longer maintained. 2.0 fixes security
issues 1.x has, which won't be fixed there, like revisions and URLs starting
with a dash, which git reads as options, or the credentials of URLs in the
messages of exceptions. To upgrade, see
[UPGRADE-2.0.md](https://github.com/gitonomy/gitlib/blob/main/UPGRADE-2.0.md).

After each new major release from 3.0 on, the previous release will be
supported for no less than 12 months, unless explicitly stated otherwise.
This may mean that there are multiple supported versions at any given time.

## Reporting a Vulnerability

If you discover a security vulnerability within this package, please send an
email to security@tidelift.com. All security vulnerabilities will be promptly
addressed. Please do not disclose security-related issues publicly until a fix
has been announced.
